<?php
declare(strict_types=1);

namespace RSN\Veille\Admin;

use RSN\Veille\Collecte;
use RSN\Veille\Fiche;
use RSN\Veille\Nouvelles;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Radar des nouvelles de l'équipe : les nouvelles des flux `categorie_nouvelles` (jamais des fiches),
 * à garder pour l'infolettre ou à ignorer.
 *
 * Le résultat de la lecture des flux est mis en cache une heure (transient). Les nouvelles gardées
 * (200 au plus) et les adresses ignorées (500 au plus) sont des options. Les actions AJAX ne reçoivent
 * que l'adresse : titre, date et résumé viennent du cache, jamais de la requête.
 *
 * La logique (`garder`, `ignorer`, `retirer`, `visibles`) et le HTML sont des méthodes pures, testées
 * sans WordPress réel ; `afficher`, `donnees` et les rappels AJAX lisent la requête et appellent WordPress.
 */
final class PageRadar {
    public const NONCE = 'rsn_veille_radar';
    public const TRANSIENT = 'rsn_veille_radar';
    public const OPTION_GARDEES = 'rsn_veille_nouvelles_gardees';
    public const OPTION_IGNOREES = 'rsn_veille_nouvelles_ignorees';
    public const ACTION_GARDER = 'rsn_veille_garder';
    public const ACTION_IGNORER = 'rsn_veille_ignorer';
    public const ACTION_RETIRER = 'rsn_veille_retirer_gardee';
    public const ACTION_RAFRAICHIR = 'rsn_veille_radar_rafraichir';

    /** Cache d'une source (`rsn_veille_radar_{id}`), écrit dès qu'elle est lue : une visite interrompue le garde. */
    public const PREFIXE_TRANSIENT_SOURCE = 'rsn_veille_radar_';
    /** Verrou de lecture : un seul éditeur à la fois relance les flux. */
    public const TRANSIENT_VERROU = 'rsn_veille_radar_lecture';
    /** Copie longue durée du dernier résultat complet, pour Garder après l'expiration du cache. */
    public const TRANSIENT_DERNIER = 'rsn_veille_radar_dernier';

    /** Durée du cache des flux, en secondes. */
    public const DUREE_CACHE = 3600;
    /** Une source en erreur est relue plus tôt qu'une source lue. */
    public const DUREE_CACHE_ERREUR = 600;
    public const DUREE_DERNIER = 86400;
    public const DUREE_VERROU = 120;
    /** Délai d'attente réseau d'une source du radar, en secondes (15 s par défaut ailleurs). */
    public const DELAI_RESEAU = 8;
    public const MAX_GARDEES = 200;
    public const MAX_IGNOREES = 500;

    /** Champs conservés d'une nouvelle gardée, dans l'ordre. */
    private const CHAMPS_GARDEE = ['url', 'titre', 'date', 'source', 'organisateur', 'resume', 'gardee_le'];

    /** Longueur de l'extrait affiché. */
    private const LONGUEUR_EXTRAIT = 220;

    /** Longueur maximale d'une adresse acceptée. */
    private const LONGUEUR_URL = 2048;

    /** Temps d'exécution demandé quand les flux sont relus (douze sources, 8 s au plus chacune). */
    private const LIMITE_EXECUTION = 150;

    // --- WordPress : crochets, page, actions ---

    /** Accroche les actions AJAX (connectés seulement) et le rafraîchissement (chargement de l'extension). */
    public static function accrocher(): void {
        add_action('wp_ajax_' . self::ACTION_GARDER, [self::class, 'ajax_garder']);
        add_action('wp_ajax_' . self::ACTION_IGNORER, [self::class, 'ajax_ignorer']);
        add_action('wp_ajax_' . self::ACTION_RETIRER, [self::class, 'ajax_retirer']);
        add_action('admin_post_' . self::ACTION_RAFRAICHIR, [self::class, 'traiter_rafraichir']);
    }

    /** Affiche la page (rappel du menu). */
    public static function afficher(): void {
        if (!current_user_can(Menu::CAPACITE)) {
            wp_die(esc_html__("Vous n'avez pas accès à cette page.", 'rsn-veille'), '', 403);
        }
        // Sortie entièrement échappée par les méthodes de rendu.
        echo self::page_html(
            self::donnees(),
            self::lire_gardees(),
            self::lire_ignorees(),
            ['url_action' => admin_url('admin-post.php'), 'nonce' => wp_create_nonce(self::NONCE)]
        );
    }

    /** admin-post.php : « Rafraîchir » vide le cache, puis retour à la page (la lecture se fait à l'affichage). */
    public static function traiter_rafraichir(): void {
        if (!current_user_can(Menu::CAPACITE)) {
            wp_die(esc_html__("Vous n'avez pas le droit de modifier la veille.", 'rsn-veille'), '', 403);
        }
        check_admin_referer(self::NONCE);
        self::vider_cache();
        wp_safe_redirect(admin_url('admin.php?page=' . Menu::SLUG_RADAR));
        exit;
    }

    /**
     * Nouvelles du radar : le résultat complet en cache (une heure) s'il existe. Sinon, les sources dont
     * le cache est valide sont reprises, et les autres sont lues une à une (délai de 8 s chacune), chaque
     * source étant mise en cache dès qu'elle est lue. Un verrou de deux minutes empêche deux éditeurs de
     * relancer les flux en même temps : le second voit ce qui est déjà en cache (`en_cours` vrai).
     *
     * @return array{nouvelles: array<int,array<string,string>>, erreurs: string[], genere_le: string, en_cours: bool}
     */
    public static function donnees(): array {
        $cache = self::resultat_valide(get_transient(self::TRANSIENT));
        if ($cache !== null) {
            return $cache + ['en_cours' => false];
        }

        $parties = [];
        $manquantes = [];
        foreach (self::sources_radar() as $id => $source) {
            $partie = self::lire_cache_source($id);
            if ($partie === null) {
                $manquantes[$id] = $source;
            } else {
                $parties[$id] = $partie;
            }
        }

        $en_cours = false;
        if ($manquantes !== []) {
            if (get_transient(self::TRANSIENT_VERROU) !== false) {
                $en_cours = true;
            } else {
                set_transient(self::TRANSIENT_VERROU, 1, self::DUREE_VERROU);
                try {
                    if (function_exists('set_time_limit')) {
                        @set_time_limit(self::LIMITE_EXECUTION);
                    }
                    $pertinence = Collecte::pertinence();
                    $obtenir = static fn(string $url): array => Collecte::obtenir($url, ['accept' => 'xml', 'timeout' => self::DELAI_RESEAU]);
                    foreach ($manquantes as $id => $source) {
                        $partie = Nouvelles::lire_source($source, $obtenir, $pertinence, \rsn_veille_aujourdhui());
                        $partie['lu_le'] = wp_date('Y-m-d H:i');
                        set_transient(
                            self::PREFIXE_TRANSIENT_SOURCE . $id,
                            $partie,
                            $partie['erreurs'] === [] ? self::DUREE_CACHE : self::DUREE_CACHE_ERREUR
                        );
                        $parties[$id] = $partie;
                    }
                } finally {
                    delete_transient(self::TRANSIENT_VERROU);
                }
            }
        }

        $donnees = self::assembler_parties($parties);
        if (!$en_cours) {
            // Tout a échoué : pas de copie complète, la page réessaiera quand les caches d'erreur expireront.
            if ($donnees['nouvelles'] !== []) {
                set_transient(self::TRANSIENT, $donnees, self::DUREE_CACHE);
                set_transient(self::TRANSIENT_DERNIER, $donnees, self::DUREE_DERNIER);
            } elseif ($donnees['erreurs'] === []) {
                set_transient(self::TRANSIENT, $donnees, self::DUREE_CACHE);
            }
        }
        return $donnees + ['en_cours' => $en_cours];
    }

    /** Vide le cache courant et celui de chaque source ; la copie de 24 h reste (Garder en dépend). */
    public static function vider_cache(): void {
        delete_transient(self::TRANSIENT);
        delete_transient(self::TRANSIENT_VERROU);
        foreach (array_keys(self::sources_radar()) as $id) {
            delete_transient(self::PREFIXE_TRANSIENT_SOURCE . $id);
        }
    }

    /** @return array<string,array<string,mixed>> Sources actives qui alimentent le radar. */
    private static function sources_radar(): array {
        return array_filter(Collecte::sources(), static fn($s): bool => is_array($s) && !empty($s['categorie_nouvelles']));
    }

    /** @return ?array{nouvelles: array<int,array<string,string>>, erreurs: string[], lu_le: string} */
    private static function lire_cache_source(string|int $id): ?array {
        $partie = get_transient(self::PREFIXE_TRANSIENT_SOURCE . $id);
        if (!is_array($partie) || !isset($partie['nouvelles'], $partie['erreurs']) || !is_array($partie['nouvelles']) || !is_array($partie['erreurs'])) {
            return null;
        }
        return [
            'nouvelles' => array_values(array_filter($partie['nouvelles'], 'is_array')),
            'erreurs' => array_values(array_filter($partie['erreurs'], 'is_string')),
            'lu_le' => isset($partie['lu_le']) && is_string($partie['lu_le']) ? $partie['lu_le'] : '',
        ];
    }

    /**
     * Assemble les lectures de sources ; `genere_le` est la plus ancienne lecture.
     *
     * @param array<int|string,array<string,mixed>> $parties
     * @return array{nouvelles: array<int,array<string,string>>, erreurs: string[], genere_le: string}
     */
    private static function assembler_parties(array $parties): array {
        $donnees = Nouvelles::assembler($parties, \rsn_veille_aujourdhui());
        $lectures = array_filter(array_column($parties, 'lu_le'), static fn($d): bool => is_string($d) && $d !== '');
        $donnees['genere_le'] = $lectures !== [] ? min($lectures) : '';
        return $donnees;
    }

    /** @return ?array{nouvelles: array<int,array<string,string>>, erreurs: string[], genere_le: string} */
    private static function resultat_valide(mixed $cache): ?array {
        if (!is_array($cache) || !isset($cache['nouvelles']) || !is_array($cache['nouvelles'])) {
            return null;
        }
        return [
            'nouvelles' => array_values(array_filter($cache['nouvelles'], 'is_array')),
            'erreurs' => isset($cache['erreurs']) && is_array($cache['erreurs']) ? array_values(array_filter($cache['erreurs'], 'is_string')) : [],
            'genere_le' => isset($cache['genere_le']) && is_string($cache['genere_le']) ? $cache['genere_le'] : '',
        ];
    }

    /**
     * Cherche une nouvelle par adresse sans jamais toucher au réseau : résultat complet courant, caches
     * des sources lues, puis copie de 24 h du dernier résultat complet.
     *
     * @return ?array<string,string>
     */
    private static function chercher_en_cache(string $url): ?array {
        $parties = [];
        foreach (array_keys(self::sources_radar()) as $id) {
            $partie = self::lire_cache_source($id);
            if ($partie !== null) {
                $parties[$id] = $partie;
            }
        }
        $candidats = [
            self::resultat_valide(get_transient(self::TRANSIENT)),
            self::assembler_parties($parties),
            self::resultat_valide(get_transient(self::TRANSIENT_DERNIER)),
        ];
        foreach ($candidats as $candidat) {
            foreach ($candidat['nouvelles'] ?? [] as $nouvelle) {
                if (($nouvelle['url'] ?? null) === $url) {
                    return $nouvelle;
                }
            }
        }
        return null;
    }

    /** @return array<int,array<string,string>> Nouvelles gardées lisibles (option illisible : liste vide). */
    public static function lire_gardees(): array {
        $gardees = get_option(self::OPTION_GARDEES, []);
        if (!is_array($gardees)) {
            return [];
        }
        return array_values(array_filter($gardees, static fn($g): bool => is_array($g) && isset($g['url']) && is_string($g['url']) && $g['url'] !== ''));
    }

    /** @return string[] Adresses ignorées lisibles. */
    public static function lire_ignorees(): array {
        $ignorees = get_option(self::OPTION_IGNOREES, []);
        if (!is_array($ignorees)) {
            return [];
        }
        return array_values(array_filter($ignorees, static fn($u): bool => is_string($u) && $u !== ''));
    }

    // --- Actions AJAX ---

    /** Garde une nouvelle du radar pour l'infolettre (titre, date et résumé pris dans le cache, jamais de réseau). */
    public static function ajax_garder(): void {
        $url = self::url_de_la_requete();
        if ($url === null) {
            return;
        }
        // Jamais de réseau ici : le cache courant, puis la copie de 24 h.
        $trouvee = self::chercher_en_cache($url);
        if ($trouvee === null) {
            wp_send_json_error(['ok' => false, 'message' => __("Cette nouvelle n'est plus dans le radar : rafraîchissez la page.", 'rsn-veille')], 404);
            return;
        }
        $gardees = self::garder(self::lire_gardees(), $trouvee, wp_date('Y-m-d H:i'));
        update_option(self::OPTION_GARDEES, $gardees, false);
        wp_send_json_success(self::reponse_gardees($gardees));
    }

    /** Ignore une nouvelle : son adresse ne reparaît plus dans le radar. */
    public static function ajax_ignorer(): void {
        $url = self::url_de_la_requete();
        if ($url === null) {
            return;
        }
        update_option(self::OPTION_IGNOREES, self::ignorer(self::lire_ignorees(), $url), false);
        wp_send_json_success(self::reponse_gardees(self::lire_gardees()));
    }

    /** Retire une nouvelle des gardées. */
    public static function ajax_retirer(): void {
        $url = self::url_de_la_requete();
        if ($url === null) {
            return;
        }
        $gardees = self::retirer(self::lire_gardees(), $url);
        update_option(self::OPTION_GARDEES, $gardees, false);
        wp_send_json_success(self::reponse_gardees($gardees));
    }

    /**
     * Jeton `rsn_veille_radar` (champ `nonce`), capacité `rsn_veille_gerer` et adresse http(s) exigés.
     * Sinon la réponse d'erreur est envoyée (403 jeton ou droits, 400 adresse) et le résultat est null.
     */
    private static function url_de_la_requete(): ?string {
        if (check_ajax_referer(self::NONCE, 'nonce', false) === false) {
            wp_send_json_error(['ok' => false, 'message' => __('Jeton de sécurité expiré : rechargez la page.', 'rsn-veille')], 403);
            return null;
        }
        if (!current_user_can(Menu::CAPACITE)) {
            wp_send_json_error(['ok' => false, 'message' => __("Vous n'avez pas le droit de modifier la veille.", 'rsn-veille')], 403);
            return null;
        }
        $brut = isset($_POST['url']) && is_string($_POST['url']) ? trim((string) wp_unslash($_POST['url'])) : '';
        if (!self::url_valide($brut)) {
            wp_send_json_error(['ok' => false, 'message' => __('Adresse invalide.', 'rsn-veille')], 400);
            return null;
        }
        return $brut;
    }

    /** Adresse http ou https, sans espace, d'une longueur raisonnable. */
    public static function url_valide(string $url): bool {
        return $url !== ''
            && strlen($url) <= self::LONGUEUR_URL
            && preg_match('#^https?://[^\s]+$#i', $url) === 1
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /** @return array{ok: bool, gardees: int, libelle: string} */
    private static function reponse_gardees(array $gardees): array {
        return ['ok' => true, 'gardees' => count($gardees), 'libelle' => self::libelle_gardees(count($gardees))];
    }

    // --- Logique pure ---

    /**
     * Ajoute une nouvelle en tête des gardées (sans doublon d'adresse), 200 au plus : les plus
     * récemment gardées sont conservées.
     *
     * @param array<int,mixed> $gardees
     * @param array<string,mixed> $nouvelle Une nouvelle du radar (`url`, `titre`, `date`, `source`, `organisateur`, `resume`).
     * @return array<int,array<string,string>>
     */
    public static function garder(array $gardees, array $nouvelle, string $maintenant): array {
        $entree = ['gardee_le' => $maintenant];
        foreach (self::CHAMPS_GARDEE as $champ) {
            if ($champ !== 'gardee_le') {
                $entree[$champ] = isset($nouvelle[$champ]) && is_scalar($nouvelle[$champ]) ? (string) $nouvelle[$champ] : '';
            }
        }
        $entree = array_replace(array_flip(self::CHAMPS_GARDEE), $entree);
        $autres = array_filter($gardees, static fn($g): bool => is_array($g) && isset($g['url']) && is_string($g['url']) && $g['url'] !== $entree['url']);
        return array_slice(array_merge([$entree], array_values($autres)), 0, self::MAX_GARDEES);
    }

    /**
     * Ajoute une adresse à la fin des ignorées (déplacée si elle y était), 500 dernières gardées.
     *
     * @param string[] $ignorees
     * @return string[]
     */
    public static function ignorer(array $ignorees, string $url): array {
        $autres = array_filter($ignorees, static fn($u): bool => is_string($u) && $u !== $url);
        return array_slice(array_merge(array_values($autres), [$url]), -self::MAX_IGNOREES);
    }

    /**
     * @param array<int,mixed> $gardees
     * @return array<int,array<string,string>>
     */
    public static function retirer(array $gardees, string $url): array {
        return array_values(array_filter($gardees, static fn($g): bool => is_array($g) && isset($g['url']) && $g['url'] !== $url));
    }

    /**
     * Nouvelles du radar à montrer : ni gardées, ni ignorées. L'ordre est conservé.
     *
     * @param array<int,array<string,mixed>> $nouvelles
     * @param array<int,mixed> $gardees
     * @param string[] $ignorees
     * @return array<int,array<string,mixed>>
     */
    public static function visibles(array $nouvelles, array $gardees, array $ignorees): array {
        $exclues = array_flip($ignorees);
        foreach ($gardees as $gardee) {
            if (is_array($gardee) && isset($gardee['url']) && is_string($gardee['url'])) {
                $exclues[$gardee['url']] = true;
            }
        }
        return array_values(array_filter($nouvelles, static fn($n): bool => is_array($n) && isset($n['url']) && !isset($exclues[$n['url']])));
    }

    // --- Rendu ---

    /**
     * Page complète : barre (Rafraîchir, compteur), zone de message, gardées, radar par source, erreurs.
     *
     * @param array{nouvelles?: array<int,array<string,mixed>>, erreurs?: string[], genere_le?: string, en_cours?: bool} $donnees
     * @param array<int,mixed> $gardees
     * @param string[] $ignorees
     * @param array{url_action?: string, nonce?: string} $options Adresse d'admin-post.php et jeton du bouton Rafraîchir.
     */
    public static function page_html(array $donnees, array $gardees, array $ignorees, array $options): string {
        $gardees = array_values(array_filter($gardees, static fn($g): bool => is_array($g)));
        $visibles = self::visibles($donnees['nouvelles'] ?? [], $gardees, $ignorees);
        return '<div class="wrap rsn-veille-admin rsn-veille-radar">'
            . '<h1>' . esc_html__('Radar', 'rsn-veille') . '</h1>'
            . '<p class="description">' . esc_html__("Les nouvelles des organisations de l'écosystème qui touchent la santé ou le numérique. Gardez celles qui iront dans l'infolettre, ignorez les autres.", 'rsn-veille') . '</p>'
            . self::barre_html((string) ($donnees['genere_le'] ?? ''), count($gardees), $options)
            . (!empty($donnees['en_cours']) ? '<p class="rsn-veille-radar-en-cours">' . esc_html__("Rafraîchissement en cours par un autre utilisateur : certaines sources ne sont pas encore là. Rechargez la page dans une minute.", 'rsn-veille') . '</p>' : '')
            . '<div class="rsn-veille-message" role="status" aria-live="polite"></div>'
            . '<h2>' . esc_html__("Gardées pour l'infolettre", 'rsn-veille') . '</h2>'
            . self::gardees_html($gardees)
            . '<h2>' . esc_html__('Nouvelles du radar', 'rsn-veille') . '</h2>'
            . self::radar_html($visibles)
            . self::erreurs_html($donnees['erreurs'] ?? [])
            . '</div>';
    }

    /** @param array{url_action?: string, nonce?: string} $options */
    private static function barre_html(string $genere_le, int $nb_gardees, array $options): string {
        $mis_a_jour = Dates::date_heure($genere_le);
        return '<div class="rsn-veille-radar-barre">'
            . '<form method="post" action="' . esc_url((string) ($options['url_action'] ?? '')) . '">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_RAFRAICHIR) . '">'
            . '<input type="hidden" name="_wpnonce" value="' . esc_attr((string) ($options['nonce'] ?? '')) . '">'
            . '<button type="submit" class="button">' . esc_html__('Rafraîchir', 'rsn-veille') . '</button>'
            . '</form>'
            . '<span class="rsn-veille-compteur" data-compteur-gardees>' . esc_html(self::libelle_gardees($nb_gardees)) . '</span>'
            . ($mis_a_jour !== '' ? '<span class="description">' . esc_html(sprintf(
                /* translators: %s : date et heure de la dernière lecture des flux. */
                __('Flux lus le %s', 'rsn-veille'),
                $mis_a_jour
            )) . '</span>' : '')
            . '</div>';
    }

    /** @param array<int,array<string,mixed>> $gardees */
    private static function gardees_html(array $gardees): string {
        $lignes = '';
        foreach ($gardees as $gardee) {
            $lignes .= self::nouvelle_html($gardee, true);
        }
        return '<div data-section="gardees">'
            . '<p class="rsn-veille-vide"' . ($lignes !== '' ? ' hidden' : '') . '>' . esc_html__("Aucune nouvelle gardée pour l'instant.", 'rsn-veille') . '</p>'
            . '<ul class="rsn-veille-radar-liste">' . $lignes . '</ul>'
            . '</div>';
    }

    /** @param array<int,array<string,mixed>> $visibles Nouvelles à montrer, groupées par source (ordre de la première apparition). */
    private static function radar_html(array $visibles): string {
        $groupes = [];
        foreach ($visibles as $nouvelle) {
            $groupes[(string) ($nouvelle['source'] ?? '')][] = $nouvelle;
        }
        $html = '';
        foreach ($groupes as $source => $nouvelles) {
            $lignes = '';
            foreach ($nouvelles as $nouvelle) {
                $lignes .= self::nouvelle_html($nouvelle, false);
            }
            $html .= '<section class="rsn-veille-groupe"><h3>' . esc_html($source !== '' ? (string) $source : __('Source inconnue', 'rsn-veille')) . '</h3>'
                . '<ul class="rsn-veille-radar-liste">' . $lignes . '</ul></section>';
        }
        return '<div data-section="radar">'
            . '<p class="rsn-veille-vide"' . ($html !== '' ? ' hidden' : '') . '>' . esc_html__('Aucune nouvelle à trier pour le moment.', 'rsn-veille') . '</p>'
            . $html
            . '</div>';
    }

    /**
     * Une nouvelle : date, titre (lien), source des gardées ou statut du radar, extrait et boutons.
     * Vide si l'adresse n'est pas une adresse http(s) valide.
     *
     * @param array<string,mixed> $n
     */
    private static function nouvelle_html(array $n, bool $gardee): string {
        $url = (string) ($n['url'] ?? '');
        if (!self::url_valide($url)) {
            // Aucune action ne pourrait la traiter (adresse refusée par le serveur) : jamais affichée.
            return '';
        }
        $titre = (string) ($n['titre'] ?? '');
        $titre = $titre !== '' ? $titre : __('(sans titre)', 'rsn-veille');
        $titre_html = '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($titre) . '</a>';
        $date = Dates::date_longue((string) ($n['date'] ?? ''));

        $meta = [];
        if ($gardee) {
            $source = trim((string) ($n['source'] ?? ''));
            if ($source !== '') {
                $meta[] = esc_html($source);
            }
            $quand = Dates::date_heure((string) ($n['gardee_le'] ?? ''));
            if ($quand !== '') {
                /* translators: %s : date et heure à laquelle la nouvelle a été gardée. */
                $meta[] = esc_html(sprintf(__('gardée le %s', 'rsn-veille'), $quand));
            }
        } else {
            $meta[] = ($n['statut_radar'] ?? '') === 'reperee'
                ? '<span class="rsn-veille-radar-statut" data-statut="reperee">' . esc_html__('Repérée', 'rsn-veille') . '</span>'
                : '<span class="rsn-veille-radar-statut" data-statut="a_trier">' . esc_html__('À trier', 'rsn-veille') . '</span>';
        }

        $boutons = $gardee
            ? self::bouton_html('retirer', __('Retirer', 'rsn-veille'), false)
            : self::bouton_html('garder', __("Garder pour l'infolettre", 'rsn-veille'), true) . ' ' . self::bouton_html('ignorer', __('Ignorer', 'rsn-veille'), false);

        $resume = Fiche::court((string) ($n['resume'] ?? ''), self::LONGUEUR_EXTRAIT);
        return '<li class="rsn-veille-nouvelle" data-url="' . esc_attr($url) . '">'
            . '<div class="rsn-veille-nouvelle-tete">'
            . ($date !== '' ? '<span class="rsn-veille-nouvelle-date">' . esc_html($date) . '</span> ' : '')
            . '<strong>' . $titre_html . '</strong>'
            . ($meta !== [] ? ' <span class="rsn-veille-nouvelle-meta">' . implode(' · ', $meta) . '</span>' : '')
            . '</div>'
            . ($resume !== '' ? '<p class="rsn-veille-extrait">' . esc_html($resume) . '</p>' : '')
            . '<div class="rsn-veille-actions">' . $boutons . '</div>'
            . '</li>';
    }

    private static function bouton_html(string $acte, string $libelle, bool $principal): string {
        return '<button type="button" class="button' . ($principal ? ' button-primary' : '') . ' rsn-veille-radar-action" data-act="' . esc_attr($acte) . '">'
            . esc_html($libelle) . '</button>';
    }

    /** Sources en erreur, discrètement, repliées. @param string[] $erreurs */
    private static function erreurs_html(array $erreurs): string {
        $erreurs = array_values(array_filter($erreurs, 'is_string'));
        if ($erreurs === []) {
            return '';
        }
        $items = '';
        foreach ($erreurs as $erreur) {
            $items .= '<li>' . esc_html($erreur) . '</li>';
        }
        /* translators: %d : nombre de sources dont le flux n'a pas pu être lu. */
        $titre = sprintf(_n('%d source en erreur', '%d sources en erreur', count($erreurs), 'rsn-veille'), count($erreurs));
        return '<details class="rsn-veille-deroule rsn-veille-radar-erreurs"><summary>' . esc_html($titre) . '</summary><ul>' . $items . '</ul></details>';
    }

    /** « 1 gardée », « 3 gardées ». */
    private static function libelle_gardees(int $n): string {
        /* translators: %d : nombre de nouvelles gardées pour l'infolettre. */
        return sprintf(_n('%d gardée', '%d gardées', $n, 'rsn-veille'), $n);
    }
}

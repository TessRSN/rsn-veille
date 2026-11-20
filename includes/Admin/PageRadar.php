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
    /** Détails des nouvelles ignorées (adresse => titre, date, source…), pour les revoir et les remettre dans le radar. */
    public const OPTION_DETAILS_IGNOREES = 'rsn_veille_nouvelles_ignorees_details';
    public const ACTION_GARDER = 'rsn_veille_garder';
    public const ACTION_IGNORER = 'rsn_veille_ignorer';
    public const ACTION_RETIRER = 'rsn_veille_retirer_gardee';
    public const ACTION_RETABLIR = 'rsn_veille_retablir_ignoree';
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

    /** Onglets de la page, dans l'ordre : nouvelles à trier, gardées pour l'infolettre, écartées (« ignorées » dans le code). */
    public const ONGLETS = ['a_trier', 'gardees', 'ecartees'];

    /** Champs des détails d'une nouvelle ignorée (l'adresse est la clé). */
    private const CHAMPS_IGNOREE = ['titre', 'date', 'source', 'organisateur', 'resume', 'ignoree_le'];

    /** Longueur de l'extrait affiché. */
    private const LONGUEUR_EXTRAIT = 220;

    /** Longueur maximale d'une adresse acceptée. */
    private const LONGUEUR_URL = 2048;

    /** Temps d'exécution demandé quand les flux sont relus (treize sources, 8 s au plus chacune). */
    private const LIMITE_EXECUTION = 150;

    // --- WordPress : crochets, page, actions ---

    /** Accroche les actions AJAX (connectés seulement) et le rafraîchissement (chargement de l'extension). */
    public static function accrocher(): void {
        add_action('wp_ajax_' . self::ACTION_GARDER, [self::class, 'ajax_garder']);
        add_action('wp_ajax_' . self::ACTION_IGNORER, [self::class, 'ajax_ignorer']);
        add_action('wp_ajax_' . self::ACTION_RETIRER, [self::class, 'ajax_retirer']);
        add_action('wp_ajax_' . self::ACTION_RETABLIR, [self::class, 'ajax_retablir']);
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
            [
                'url_action' => admin_url('admin-post.php'), 'nonce' => wp_create_nonce(self::NONCE),
                'url_page' => admin_url('admin.php?page=' . Menu::SLUG_RADAR),
                'onglet' => self::parametre('onglet'), 'du' => self::parametre('du'), 'au' => self::parametre('au'),
                'source' => self::parametre('source'),
            ],
            self::details_a_afficher()
        );
    }

    /** Paramètre de l'adresse de la page (onglet, dates et source du filtre), en texte nettoyé. */
    private static function parametre(string $nom): string {
        return isset($_GET[$nom]) && is_string($_GET[$nom]) ? sanitize_text_field(wp_unslash($_GET[$nom])) : '';
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
        ] + self::alertes_de($partie);
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
        ] + self::alertes_de($cache);
    }

    /** @return array{alertes?: string[]} Alertes de gabarit d'une lecture (`Nouvelles`), seulement s'il y en a. */
    private static function alertes_de(array $lecture): array {
        $alertes = is_array($lecture['alertes'] ?? null) ? array_values(array_filter($lecture['alertes'], 'is_string')) : [];
        return $alertes !== [] ? ['alertes' => $alertes] : [];
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

    /** @return array<string,array<string,string>> Détails des ignorées par adresse (option illisible : aucun). */
    public static function lire_details_ignorees(): array {
        $details = get_option(self::OPTION_DETAILS_IGNOREES, []);
        if (!is_array($details)) {
            return [];
        }
        $propres = [];
        foreach ($details as $url => $detail) {
            if (is_string($url) && $url !== '' && is_array($detail)) {
                $propres[$url] = self::detail_propre($detail, '');
            }
        }
        return $propres;
    }

    /**
     * Détails à montrer : ceux enregistrés, complétés par le cache du radar pour une adresse ignorée avant la 0.8.0
     * (sans détails) encore présente dans les flux. Jamais de réseau.
     *
     * @return array<string,array<string,string>>
     */
    private static function details_a_afficher(): array {
        $details = self::lire_details_ignorees();
        foreach (self::lire_ignorees() as $url) {
            if (($details[$url]['titre'] ?? '') === '') {
                $trouvee = self::chercher_en_cache($url);
                if ($trouvee !== null) {
                    $details[$url] = self::detail_propre($trouvee, (string) ($details[$url]['ignoree_le'] ?? ''));
                }
            }
        }
        return $details;
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
            // Nouvelle ignorée puis sortie des flux : ses détails enregistrés suffisent pour la garder.
            $detail = self::lire_details_ignorees()[$url] ?? null;
            if ($detail !== null && $detail['titre'] !== '') {
                $trouvee = ['url' => $url] + $detail;
            }
        }
        if ($trouvee === null) {
            wp_send_json_error(['ok' => false, 'message' => __("Cette nouvelle n'est plus dans le radar : rafraîchissez la page.", 'rsn-veille')], 404);
            return;
        }
        $gardees = self::garder(self::lire_gardees(), $trouvee, wp_date('Y-m-d H:i'));
        update_option(self::OPTION_GARDEES, $gardees, false);
        // Une nouvelle gardée n'est plus ignorée.
        self::enregistrer_ignorees(self::retablir(self::lire_ignorees(), $url));
        wp_send_json_success(self::reponse_gardees($gardees));
    }

    /** Ignore une nouvelle : son adresse ne reparaît plus dans le radar. */
    public static function ajax_ignorer(): void {
        $url = self::url_de_la_requete();
        if ($url === null) {
            return;
        }
        $ignorees = self::ignorer(self::lire_ignorees(), $url);
        update_option(self::OPTION_IGNOREES, $ignorees, false);
        // Titre, date et source pris dans le cache (jamais de réseau), pour la retrouver dans « Nouvelles ignorées ».
        $nouvelle = (self::chercher_en_cache($url) ?? []) + ['url' => $url];
        update_option(self::OPTION_DETAILS_IGNOREES, self::noter_ignoree(self::lire_details_ignorees(), $nouvelle, wp_date('Y-m-d H:i'), $ignorees), false);
        wp_send_json_success(self::reponse_gardees(self::lire_gardees()));
    }

    /**
     * Remet une nouvelle écartée dans le radar : son adresse sort des ignorées. Si elle n'est plus dans les flux,
     * le message prévient qu'elle ne reparaîtra pas.
     */
    public static function ajax_retablir(): void {
        $url = self::url_de_la_requete();
        if ($url === null) {
            return;
        }
        self::enregistrer_ignorees(self::retablir(self::lire_ignorees(), $url));
        wp_send_json_success(self::reponse_gardees(self::lire_gardees()) + [
            'message' => self::chercher_en_cache($url) !== null
                ? __('Nouvelle remise dans le radar.', 'rsn-veille')
                : __("Nouvelle retirée des écartées. Elle n'est plus dans les flux : elle ne reparaîtra pas dans le radar.", 'rsn-veille'),
        ]);
    }

    /** Enregistre la liste des ignorées et retire les détails des adresses qui n'y sont plus. @param string[] $ignorees */
    private static function enregistrer_ignorees(array $ignorees): void {
        update_option(self::OPTION_IGNOREES, $ignorees, false);
        update_option(self::OPTION_DETAILS_IGNOREES, array_intersect_key(self::lire_details_ignorees(), array_flip($ignorees)), false);
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

    /**
     * Réponse d'une action : les compteurs des trois onglets (« à trier » d'après le cache du radar, jamais de réseau).
     *
     * @return array{ok: bool, gardees: int, libelle: string, compteurs: array{a_trier: int, gardees: int, ecartees: int}}
     */
    private static function reponse_gardees(array $gardees): array {
        $ignorees = self::lire_ignorees();
        return [
            'ok' => true, 'gardees' => count($gardees), 'libelle' => self::libelle_gardees(count($gardees)),
            'compteurs' => [
                'a_trier' => count(self::visibles(self::nouvelles_en_cache(), $gardees, $ignorees)),
                'gardees' => count($gardees),
                'ecartees' => count($ignorees),
            ],
        ];
    }

    /** @return array<int,array<string,mixed>> Nouvelles du radar d'après le cache (complet, sinon par source, sinon la copie de 24 h). */
    private static function nouvelles_en_cache(): array {
        $parties = [];
        foreach (array_keys(self::sources_radar()) as $id) {
            $partie = self::lire_cache_source($id);
            if ($partie !== null) {
                $parties[$id] = $partie;
            }
        }
        $candidats = [
            self::resultat_valide(get_transient(self::TRANSIENT)),
            $parties !== [] ? self::assembler_parties($parties) : null,
            self::resultat_valide(get_transient(self::TRANSIENT_DERNIER)),
        ];
        foreach ($candidats as $candidat) {
            if (is_array($candidat) && is_array($candidat['nouvelles'] ?? null)) {
                return array_values(array_filter($candidat['nouvelles'], static fn($n): bool => is_array($n) && self::url_valide((string) ($n['url'] ?? ''))));
            }
        }
        return [];
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
     * Retire une adresse des ignorées (elle peut reparaître dans le radar).
     *
     * @param array<int,mixed> $ignorees
     * @return string[]
     */
    public static function retablir(array $ignorees, string $url): array {
        return array_values(array_filter($ignorees, static fn($u): bool => is_string($u) && $u !== '' && $u !== $url));
    }

    /**
     * Note les détails d'une nouvelle qu'on vient d'ignorer et ne garde que ceux des adresses encore ignorées
     * ($ignorees : la liste à jour, bornée à 500).
     *
     * @param array<int|string,mixed> $details Détails déjà enregistrés, par adresse.
     * @param array<string,mixed> $nouvelle Une nouvelle du radar (`url` exigée ; le reste peut manquer).
     * @param string[] $ignorees
     * @return array<string,array<string,string>>
     */
    public static function noter_ignoree(array $details, array $nouvelle, string $maintenant, array $ignorees): array {
        $url = isset($nouvelle['url']) && is_string($nouvelle['url']) ? $nouvelle['url'] : '';
        $propres = [];
        foreach ($details as $adresse => $detail) {
            if (is_string($adresse) && is_array($detail)) {
                $propres[$adresse] = self::detail_propre($detail, '');
            }
        }
        if ($url !== '') {
            $propres[$url] = self::detail_propre($nouvelle, $maintenant);
        }
        return array_intersect_key($propres, array_flip(array_filter($ignorees, 'is_string')));
    }

    /**
     * Détail d'une ignorée réduit à ses champs, en chaînes. $ignoree_le remplace la date si elle est donnée.
     *
     * @param array<string,mixed> $source
     * @return array<string,string>
     */
    private static function detail_propre(array $source, string $ignoree_le): array {
        $detail = [];
        foreach (self::CHAMPS_IGNOREE as $champ) {
            $detail[$champ] = isset($source[$champ]) && is_scalar($source[$champ]) ? (string) $source[$champ] : '';
        }
        if ($ignoree_le !== '') {
            $detail['ignoree_le'] = $ignoree_le;
        }
        return $detail;
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

    /**
     * Nouvelles publiées entre deux dates (`date`, Y-m-d), bornes comprises. Une borne vide ou illisible ne filtre
     * pas ; des bornes inversées sont remises dans l'ordre ; sans borne, tout est rendu (même les nouvelles sans date).
     *
     * @param array<int,array<string,mixed>> $nouvelles
     * @return array<int,array<string,mixed>>
     */
    public static function dans_la_periode(array $nouvelles, string $du, string $au): array {
        [$du, $au] = self::bornes($du, $au);
        if ($du === '' && $au === '') {
            return array_values($nouvelles);
        }
        return array_values(array_filter($nouvelles, static function ($n) use ($du, $au): bool {
            $date = is_array($n) ? substr((string) ($n['date'] ?? ''), 0, 10) : '';
            return $date !== '' && ($du === '' || $date >= $du) && ($au === '' || $date <= $au);
        }));
    }

    /** Bornes du filtre nettoyées : dates Y-m-d valides (sinon vides), dans l'ordre. @return array{0: string, 1: string} */
    public static function bornes(string $du, string $au): array {
        $valide = static function (string $d): string {
            return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $d : '';
        };
        [$du, $au] = [$valide($du), $valide($au)];
        return $du !== '' && $au !== '' && $du > $au ? [$au, $du] : [$du, $au];
    }

    /**
     * Les nouvelles de la plus récente à la plus ancienne (jour de publication), toutes sources mêlées ; à date
     * égale, l'ordre reçu ; sans date, à la fin. Ce qui n'est pas une nouvelle est laissé.
     *
     * @param array<int,mixed> $nouvelles
     * @return array<int,array<string,mixed>>
     */
    public static function plus_recentes_d_abord(array $nouvelles): array {
        $nouvelles = array_values(array_filter($nouvelles, 'is_array'));
        $jour = static fn(array $n): string => is_string($n['date'] ?? null) ? substr($n['date'], 0, 10) : '';
        // usort est stable depuis PHP 8.0 : à date égale, l'ordre reçu est gardé.
        usort($nouvelles, static function (array $a, array $b) use ($jour): int {
            [$da, $db] = [$jour($a), $jour($b)];
            if ($da === '' || $db === '') {
                return ($da === '') <=> ($db === '');
            }
            return strcmp($db, $da);
        });
        return $nouvelles;
    }

    /** Nom de la source d'une nouvelle, nettoyé ('' si inconnu). @param mixed $n */
    private static function source_de($n): string {
        return is_array($n) && is_string($n['source'] ?? null) ? trim($n['source']) : '';
    }

    /**
     * Nouvelles d'une seule source (nom exact) ; sans source demandée, tout est rendu.
     *
     * @param array<int,array<string,mixed>> $nouvelles
     * @return array<int,array<string,mixed>>
     */
    public static function de_la_source(array $nouvelles, string $source): array {
        if ($source === '') {
            return array_values($nouvelles);
        }
        return array_values(array_filter($nouvelles, static fn($n): bool => self::source_de($n) === $source));
    }

    /**
     * Sources présentes dans une liste, sans doublon, en ordre alphabétique (pour le menu du filtre).
     *
     * @param array<int,mixed> $nouvelles
     * @return string[]
     */
    public static function sources_de(array $nouvelles): array {
        $sources = [];
        foreach ($nouvelles as $n) {
            $source = self::source_de($n);
            if ($source !== '') {
                $sources[$source] = true;
            }
        }
        $sources = array_map('strval', array_keys($sources));
        sort($sources, SORT_NATURAL | SORT_FLAG_CASE);
        return $sources;
    }

    // --- Rendu ---

    /**
     * Page complète : barre (Rafraîchir), alertes de gabarit, onglets avec leur nombre (À trier, Gardées pour
     * l'infolettre, Écartées), filtre par dates de publication et par source, zone de message, tableau de l'onglet,
     * erreurs. Le tableau ressemble à celui de la file d'approbation : une ligne par nouvelle, la source dans sa
     * colonne ; « À trier » va de la plus récente à la plus ancienne, toutes sources mêlées.
     *
     * @param array{nouvelles?: array<int,array<string,mixed>>, erreurs?: string[], alertes?: string[], genere_le?: string, en_cours?: bool} $donnees
     * @param array<int,mixed> $gardees
     * @param string[] $ignorees
     * @param array{url_action?: string, nonce?: string, url_page?: string, onglet?: string, du?: string, au?: string, source?: string} $options
     *        Adresse d'admin-post.php et jeton du bouton Rafraîchir ; adresse de la page ; onglet, dates et source du filtre.
     * @param array<string,array<string,string>> $details_ignorees Détails des écartées par adresse (`details_a_afficher`).
     */
    public static function page_html(array $donnees, array $gardees, array $ignorees, array $options, array $details_ignorees = []): string {
        $gardees = array_values(array_filter($gardees, static fn($g): bool => is_array($g)));
        $ignorees = array_values(array_filter($ignorees, 'is_string'));
        $visibles = self::visibles($donnees['nouvelles'] ?? [], $gardees, $ignorees);
        $onglet = in_array($options['onglet'] ?? '', self::ONGLETS, true) ? (string) $options['onglet'] : 'a_trier';
        [$du, $au] = self::bornes((string) ($options['du'] ?? ''), (string) ($options['au'] ?? ''));
        $source = is_string($options['source'] ?? null) ? trim($options['source']) : '';
        $url_page = (string) ($options['url_page'] ?? '');

        if ($onglet === 'gardees') {
            // Les plus récemment gardées d'abord (ordre de l'enregistrement).
            $toutes = $gardees;
        } elseif ($onglet === 'ecartees') {
            // Les plus récemment écartées d'abord, chacune avec ses détails.
            $toutes = [];
            foreach (array_reverse($ignorees) as $url) {
                $toutes[] = ['url' => $url] + (is_array($details_ignorees[$url] ?? null) ? $details_ignorees[$url] : []);
            }
        } else {
            $toutes = self::plus_recentes_d_abord($visibles);
        }
        $liste = self::de_la_source(self::dans_la_periode($toutes, $du, $au), $source);
        $corps = self::tableau_html($onglet, $liste);
        // Une adresse refusée par le serveur n'est jamais affichée : elle ne compte pas non plus.
        $affichees = count(array_filter($liste, static fn($n): bool => is_array($n) && self::url_valide((string) ($n['url'] ?? ''))));
        // Menu des sources : celles de l'onglet, et celle du filtre même si elle n'y a plus rien.
        $sources = self::sources_de($source !== '' ? array_merge($toutes, [['source' => $source]]) : $toutes);

        return '<div class="wrap rsn-veille-admin rsn-veille-radar" data-onglet="' . esc_attr($onglet) . '">'
            . '<h1>' . esc_html__('Radar', 'rsn-veille') . '</h1>'
            . '<p class="description">' . esc_html__("Les nouvelles des organisations de l'écosystème qui touchent la santé ou le numérique. Gardez celles qui iront dans l'infolettre, écartez les autres.", 'rsn-veille') . '</p>'
            . self::barre_html((string) ($donnees['genere_le'] ?? ''), $options)
            . self::alertes_html(is_array($donnees['alertes'] ?? null) ? $donnees['alertes'] : [])
            . (!empty($donnees['en_cours']) ? '<p class="rsn-veille-radar-en-cours">' . esc_html__("Rafraîchissement en cours par un autre utilisateur : certaines sources ne sont pas encore là. Rechargez la page dans une minute.", 'rsn-veille') . '</p>' : '')
            . self::onglets_html($onglet, ['a_trier' => count($visibles), 'gardees' => count($gardees), 'ecartees' => count($ignorees)], $du, $au, $url_page, $source)
            . self::filtre_html($onglet, $du, $au, $affichees, $url_page, $source, $sources)
            . '<div class="rsn-veille-message" role="status" aria-live="polite"></div>'
            . $corps
            . self::erreurs_html($donnees['erreurs'] ?? [])
            . '</div>';
    }

    /** @param array{url_action?: string, nonce?: string} $options */
    private static function barre_html(string $genere_le, array $options): string {
        $mis_a_jour = Dates::date_heure($genere_le);
        return '<div class="rsn-veille-radar-barre">'
            . '<form method="post" action="' . esc_url((string) ($options['url_action'] ?? '')) . '">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_RAFRAICHIR) . '">'
            . '<input type="hidden" name="_wpnonce" value="' . esc_attr((string) ($options['nonce'] ?? '')) . '">'
            . '<button type="submit" class="button">' . esc_html__('Rafraîchir', 'rsn-veille') . '</button>'
            . '</form>'
            . ($mis_a_jour !== '' ? '<span class="description">' . esc_html(sprintf(
                /* translators: %s : date et heure de la dernière lecture des flux. */
                __('Flux lus le %s', 'rsn-veille'),
                $mis_a_jour
            )) . '</span>' : '')
            . '</div>';
    }

    /** Adresse de la page pour un onglet, avec le filtre en cours (dates, source). */
    private static function url_onglet(string $url_page, string $onglet, string $du, string $au, string $source = ''): string {
        $parametres = array_filter(['onglet' => $onglet, 'du' => $du, 'au' => $au, 'source' => $source], static fn(string $v): bool => $v !== '');
        return $url_page . (str_contains($url_page, '?') ? '&' : '?') . http_build_query($parametres, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Onglets, comme ceux de la file d'approbation : le nombre de chaque onglet est un total (le filtre ne le change
     * pas) et suit les actions (`data-compteur`).
     *
     * @param array{a_trier: int, gardees: int, ecartees: int} $compteurs
     */
    private static function onglets_html(string $actif, array $compteurs, string $du, string $au, string $url_page, string $source = ''): string {
        $libelles = [
            'a_trier' => __('À trier', 'rsn-veille'),
            'gardees' => __("Gardées pour l'infolettre", 'rsn-veille'),
            'ecartees' => __('Écartées', 'rsn-veille'),
        ];
        $html = '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__('Nouvelles du radar', 'rsn-veille') . '">';
        foreach (self::ONGLETS as $onglet) {
            $classe = 'nav-tab' . ($onglet === $actif ? ' nav-tab-active' : '');
            $courant = $onglet === $actif ? ' aria-current="page"' : '';
            $html .= '<a class="' . $classe . '"' . $courant . ' href="' . esc_url(self::url_onglet($url_page, $onglet, $du, $au, $source)) . '">' . esc_html($libelles[$onglet])
                . ' <span class="count" data-compteur="' . esc_attr($onglet) . '">(' . (int) ($compteurs[$onglet] ?? 0) . ')</span></a>';
        }
        return $html . '</nav>';
    }

    /**
     * Filtre (formulaire GET : la page, l'onglet, les deux dates de publication et la source), puis, quand un
     * filtre est actif, le nombre de nouvelles montrées et le lien « Tout afficher ».
     *
     * @param string[] $sources Sources proposées dans le menu.
     */
    private static function filtre_html(string $onglet, string $du, string $au, int $affichees, string $url_page, string $source = '', array $sources = []): string {
        $choix = '<option value=""' . ($source === '' ? ' selected' : '') . '>' . esc_html__('Toutes les sources', 'rsn-veille') . '</option>';
        foreach ($sources as $nom) {
            $choix .= '<option value="' . esc_attr($nom) . '"' . ($nom === $source ? ' selected' : '') . '>' . esc_html($nom) . '</option>';
        }
        $html = '<form class="rsn-veille-filtres rsn-veille-radar-filtre" method="get" action="">'
            . '<input type="hidden" name="page" value="' . esc_attr(Menu::SLUG_RADAR) . '">'
            . '<input type="hidden" name="onglet" value="' . esc_attr($onglet) . '">'
            . '<label for="rsn-veille-radar-du">' . esc_html__('Publiées du', 'rsn-veille') . '</label> '
            . '<input type="date" id="rsn-veille-radar-du" name="du" value="' . esc_attr($du) . '"> '
            . '<label for="rsn-veille-radar-au">' . esc_html__('au', 'rsn-veille') . '</label> '
            . '<input type="date" id="rsn-veille-radar-au" name="au" value="' . esc_attr($au) . '"> '
            . '<label class="screen-reader-text" for="rsn-veille-radar-source">' . esc_html__('Source', 'rsn-veille') . '</label>'
            . '<select id="rsn-veille-radar-source" name="source">' . $choix . '</select> '
            . '<button type="submit" class="button">' . esc_html__('Filtrer', 'rsn-veille') . '</button>';
        $filtre = $du !== '' || $au !== '' || $source !== '';
        if ($filtre) {
            $html .= ' <a class="button-link" href="' . esc_url(self::url_onglet($url_page, $onglet, '', '')) . '">' . esc_html__('Tout afficher', 'rsn-veille') . '</a>';
        }
        $html .= '</form>';
        if (!$filtre) {
            return $html;
        }
        return $html . '<p class="rsn-veille-radar-periode" data-periode>' . esc_html(self::resume_filtre($affichees, $du, $au, $source)) . '</p>';
    }

    /**
     * « 3 nouvelles publiées du 10 au 31 octobre 2026 », « Aucune nouvelle publiée depuis le 1er janvier 2027 »,
     * « 2 nouvelles de « FRQ : nouvelles » », « 1 nouvelle publiée le 4 mai 2026, de « FRQ : nouvelles » ».
     */
    private static function resume_filtre(int $n, string $du, string $au, string $source): string {
        if ($du === '' && $au === '') {
            if ($n === 0) {
                /* translators: %s : nom de la source du filtre. */
                return sprintf(__('Aucune nouvelle de « %s »', 'rsn-veille'), $source);
            }
            /* translators: 1 : nombre de nouvelles ; 2 : nom de la source du filtre. */
            return sprintf(_n('%1$d nouvelle de « %2$s »', '%1$d nouvelles de « %2$s »', $n, 'rsn-veille'), $n, $source);
        }
        $resume = self::resume_periode($n, $du, $au);
        /* translators: 1 : « 3 nouvelles publiées du 10 au 31 octobre 2026 » ; 2 : nom de la source du filtre. */
        return $source === '' ? $resume : sprintf(__('%1$s, de « %2$s »', 'rsn-veille'), $resume, $source);
    }

    /** « 3 nouvelles publiées du 10 au 31 octobre 2026 », « Aucune nouvelle publiée depuis le 1er janvier 2027 ». */
    private static function resume_periode(int $n, string $du, string $au): string {
        if ($du !== '' && $au !== '') {
            // « Du 10 au 31 octobre 2026 » : minuscule initiale, la période suit le nombre.
            $periode = Dates::periode($du, $au);
            $periode = $du === $au
                /* translators: %s : date. */
                ? sprintf(__('le %s', 'rsn-veille'), $periode)
                : mb_strtolower(mb_substr($periode, 0, 1)) . mb_substr($periode, 1);
        } elseif ($du !== '') {
            /* translators: %s : date de début du filtre. */
            $periode = sprintf(__('depuis le %s', 'rsn-veille'), Dates::date_longue($du));
        } else {
            /* translators: %s : date de fin du filtre. */
            $periode = sprintf(__("jusqu'au %s", 'rsn-veille'), Dates::date_longue($au));
        }
        if ($n === 0) {
            /* translators: %s : période du filtre (« du 10 au 31 octobre 2026 », « depuis le … »). */
            return sprintf(__('Aucune nouvelle publiée %s', 'rsn-veille'), $periode);
        }
        /* translators: 1 : nombre de nouvelles ; 2 : période du filtre. */
        return sprintf(_n('%1$d nouvelle publiée %2$s', '%1$d nouvelles publiées %2$s', $n, 'rsn-veille'), $n, $periode);
    }

    /**
     * Tableau d'un onglet, comme celui de la file d'approbation : date de publication, nouvelle (titre et extrait),
     * source, repères de l'onglet (pertinence et « Déjà paru », ou date à laquelle elle a été gardée ou écartée)
     * et actions. Sans ligne, le tableau est caché et le message « aucune nouvelle » paraît (le script fait de
     * même quand la dernière ligne part).
     *
     * @param array<int,array<string,mixed>> $nouvelles Dans l'ordre d'affichage.
     */
    private static function tableau_html(string $onglet, array $nouvelles): string {
        [$section, $reperes, $vide, $intro] = match ($onglet) {
            'gardees' => ['gardees', __('Gardée le', 'rsn-veille'), __("Aucune nouvelle gardée pour l'instant.", 'rsn-veille'), ''],
            'ecartees' => ['ecartees', __('Écartée le', 'rsn-veille'), __('Aucune nouvelle écartée.', 'rsn-veille'),
                __("Une nouvelle écartée ne reparaît plus dans « À trier ». Remettez-la dans le radar pour la trier de nouveau, ou gardez-la directement pour l'infolettre.", 'rsn-veille')],
            default => ['radar', __('Repères', 'rsn-veille'), __('Aucune nouvelle à trier pour le moment.', 'rsn-veille'), ''],
        };
        $lignes = '';
        foreach ($nouvelles as $nouvelle) {
            $lignes .= is_array($nouvelle) ? self::ligne_html($nouvelle, $onglet) : '';
        }
        $entete = '';
        foreach ([__('Publiée le', 'rsn-veille'), __('Nouvelle', 'rsn-veille'), __('Source', 'rsn-veille'), $reperes, __('Actions', 'rsn-veille')] as $colonne) {
            $entete .= '<th scope="col">' . esc_html($colonne) . '</th>';
        }
        return '<div data-section="' . esc_attr($section) . '">'
            . ($intro !== '' ? '<p class="description">' . esc_html($intro) . '</p>' : '')
            . '<p class="rsn-veille-vide"' . ($lignes !== '' ? ' hidden' : '') . '>' . esc_html($vide) . '</p>'
            . '<table class="wp-list-table widefat striped rsn-veille-radar-tableau"' . ($lignes === '' ? ' hidden' : '') . '>'
            . '<thead><tr>' . $entete . '</tr></thead><tbody>' . $lignes . '</tbody></table>'
            . '</div>';
    }

    /**
     * Une ligne du tableau. Vide si l'adresse n'est pas une adresse http(s) valide (aucune action ne pourrait la
     * traiter). Une nouvelle écartée sans détails (écartée avant la 0.8.0 et sortie des flux) a son adresse pour
     * titre et ne peut pas être gardée.
     *
     * @param array<string,mixed> $n
     */
    private static function ligne_html(array $n, string $onglet): string {
        $url = (string) ($n['url'] ?? '');
        if (!self::url_valide($url)) {
            return '';
        }
        $titre = trim((string) ($n['titre'] ?? ''));
        $affiche = $titre !== '' ? $titre : ($onglet === 'ecartees' ? $url : __('(sans titre)', 'rsn-veille'));
        $source = self::source_de($n);
        $resume = Fiche::court((string) ($n['resume'] ?? ''), self::LONGUEUR_EXTRAIT);

        // Encadré d'une infolettre qui revient d'un numéro à l'autre : nombre d'autres numéros où il a paru.
        $deja_paru = 0;
        if ($onglet === 'gardees') {
            $reperes = esc_html(Dates::date_heure((string) ($n['gardee_le'] ?? '')));
            $boutons = self::bouton_html('retirer', __('Retirer', 'rsn-veille'), false);
        } elseif ($onglet === 'ecartees') {
            $reperes = esc_html(Dates::date_heure((string) ($n['ignoree_le'] ?? '')));
            $boutons = self::bouton_html('retablir', __('Remettre dans le radar', 'rsn-veille'), true)
                . ($titre !== '' ? ' ' . self::bouton_html('garder', __("Garder pour l'infolettre", 'rsn-veille'), false) : '');
        } else {
            $deja_paru = is_numeric($n['deja_paru'] ?? null) ? max(0, (int) $n['deja_paru']) : 0;
            $reperes = self::pertinence_html((string) ($n['statut_radar'] ?? ''), (string) ($n['domaine'] ?? ''));
            if ($deja_paru > 0) {
                /* translators: %d : nombre d'autres numéros de l'infolettre où l'encadré a déjà paru. */
                $reperes .= ' <span class="rsn-veille-deja-paru" title="' . esc_attr__("Cet encadré revient d'un numéro à l'autre de l'infolettre : rien de neuf, sans doute.", 'rsn-veille') . '">'
                    . esc_html(sprintf(_n('Déjà paru dans %d autre numéro', 'Déjà paru dans %d autres numéros', $deja_paru, 'rsn-veille'), $deja_paru)) . '</span>';
            }
            $boutons = self::bouton_html('garder', __("Garder pour l'infolettre", 'rsn-veille'), true) . ' ' . self::bouton_html('ignorer', __('Écarter', 'rsn-veille'), false);
        }

        return '<tr class="rsn-veille-nouvelle" data-url="' . esc_attr($url) . '"' . ($deja_paru > 0 ? ' data-deja-paru="' . $deja_paru . '"' : '') . '>'
            . '<td class="rsn-veille-nouvelle-date">' . esc_html(Dates::date_longue((string) ($n['date'] ?? ''))) . '</td>'
            . '<td class="rsn-veille-nouvelle-titre"><strong><a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($affiche) . '</a></strong>'
            . ($resume !== '' ? '<p class="rsn-veille-extrait">' . esc_html($resume) . '</p>' : '') . '</td>'
            . '<td class="rsn-veille-nouvelle-source">' . esc_html($source !== '' ? $source : __('Source inconnue', 'rsn-veille')) . '</td>'
            . '<td class="rsn-veille-nouvelle-reperes">' . $reperes . '</td>'
            . '<td class="rsn-veille-actions">' . $boutons . '</td>'
            . '</tr>';
    }

    /**
     * Ce que touche la nouvelle, d'après les mots-clés des Réglages : « Santé + numérique » (repérée : a priori pour le
     * RSN), sinon « Santé seulement » ou « Numérique seulement » (à juger). Sans domaine connu (lecture gardée en cache
     * par une version précédente) : « Un seul des deux ».
     */
    private static function pertinence_html(string $statut, string $domaine): string {
        if ($statut === 'reperee') {
            return '<span class="rsn-veille-radar-statut" data-statut="reperee" title="' . esc_attr__('Le texte touche la santé et le numérique : a priori dans le mille.', 'rsn-veille') . '">'
                . esc_html__('Santé + numérique', 'rsn-veille') . '</span>';
        }
        $libelle = match ($domaine) {
            'sante' => __('Santé seulement', 'rsn-veille'),
            'numerique' => __('Numérique seulement', 'rsn-veille'),
            default => __('Un seul des deux', 'rsn-veille'),
        };
        return '<span class="rsn-veille-radar-statut" data-statut="a_trier" title="' . esc_attr__("Le texte ne touche qu'un des deux, santé ou numérique : à vous de juger.", 'rsn-veille') . '">'
            . esc_html($libelle) . '</span>';
    }

    private static function bouton_html(string $acte, string $libelle, bool $principal): string {
        return '<button type="button" class="button' . ($principal ? ' button-primary' : '') . ' rsn-veille-radar-action" data-act="' . esc_attr($acte) . '">'
            . esc_html($libelle) . '</button>';
    }

    /**
     * Alertes de gabarit (une infolettre découpée ne donne plus ses encadrés), bien visibles en haut de la page.
     *
     * @param array<int,mixed> $alertes
     */
    private static function alertes_html(array $alertes): string {
        $items = '';
        foreach (array_filter($alertes, 'is_string') as $alerte) {
            $items .= '<li>' . esc_html($alerte) . '</li>';
        }
        if ($items === '') {
            return '';
        }
        return '<div class="notice notice-warning inline rsn-veille-alerte-gabarit"><p><strong>' . esc_html__('Gabarit à vérifier', 'rsn-veille') . '</strong></p><ul>' . $items . '</ul>'
            . '<p class="description">' . esc_html__("Ouvrez le dernier numéro pour voir s'il est présenté autrement. Si oui, le découpage est à adapter : en attendant, le numéro reste lisible en une seule nouvelle.", 'rsn-veille') . '</p></div>';
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

<?php
declare(strict_types=1);

namespace RSN\Veille\Admin;

use RSN\Veille\Collecte;
use RSN\Veille\Entrepot;
use RSN\Veille\EntrepotWordPress;
use RSN\Veille\Fiche;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * File d'approbation : onglets À approuver, Publiées, Écartées, À vérifier ; filtres par type et par
 * source ; boutons Publier, Écarter, Retirer (action AJAX `rsn_veille_changer_statut`).
 *
 * Le HTML est produit par des méthodes pures (testées sans WordPress réel) ; `afficher` et
 * `ajax_changer_statut` lisent la requête et appellent WordPress.
 */
final class PageFile {
    public const NONCE = 'rsn_veille_file';
    public const ACTION_AJAX = 'rsn_veille_changer_statut';

    /** Onglets, dans l'ordre d'affichage. `a_verifier` : fiches publiées signalées absentes de leur source. */
    public const ONGLETS = ['a_approuver', 'publie', 'ecarte', 'a_verifier'];

    /** Statuts qu'un bouton peut donner. */
    private const STATUTS = ['publie', 'a_approuver', 'ecarte'];

    /** Types filtrables. */
    private const TYPES = ['evenement', 'appel'];

    /** Longueur de l'extrait affiché. */
    private const LONGUEUR_EXTRAIT = 180;

    // --- WordPress : page et action AJAX ---

    /** Affiche la page (rappel du menu). */
    public static function afficher(): void {
        if (!current_user_can(Menu::CAPACITE)) {
            wp_die(esc_html__("Vous n'avez pas accès à cette page.", 'rsn-veille'), '', 403);
        }
        $onglet = self::parametre('statut');
        $type = self::parametre('type');
        $source = isset($_GET['source']) && is_string($_GET['source']) ? sanitize_text_field(wp_unslash($_GET['source'])) : '';
        $fiches = (new EntrepotWordPress())->lister('');
        // Sortie entièrement échappée par les méthodes de rendu.
        echo self::page_html(
            $fiches,
            in_array($onglet, self::ONGLETS, true) ? $onglet : 'a_approuver',
            in_array($type, self::TYPES, true) ? $type : '',
            $source,
            admin_url('admin.php?page=' . Menu::SLUG),
            ['url_admin' => admin_url()]
        );
    }

    /**
     * Action AJAX : jeton `rsn_veille_file` (champ `nonce`) et capacité `rsn_veille_gerer` exigés.
     * Réponse `{success, data: {ok, statut, compteurs}}`, ou `{success: false, data: {ok, message}}`
     * en 403 (jeton ou droits) ou 400 (statut ou fiche invalides).
     */
    public static function ajax_changer_statut(): void {
        if (check_ajax_referer(self::NONCE, 'nonce', false) === false) {
            wp_send_json_error(['ok' => false, 'message' => __('Jeton de sécurité expiré : rechargez la page.', 'rsn-veille')], 403);
            return;
        }
        if (!current_user_can(Menu::CAPACITE)) {
            wp_send_json_error(['ok' => false, 'message' => __("Vous n'avez pas le droit de modifier la veille.", 'rsn-veille')], 403);
            return;
        }
        $id = isset($_POST['id']) && is_scalar($_POST['id']) ? absint(wp_unslash((string) $_POST['id'])) : 0;
        $statut = isset($_POST['statut']) && is_string($_POST['statut']) ? sanitize_key(wp_unslash($_POST['statut'])) : '';
        $resultat = self::changer_statut(new EntrepotWordPress(), $id, $statut);
        if ($resultat['ok']) {
            wp_send_json_success($resultat);
            return;
        }
        wp_send_json_error($resultat, 400);
    }

    // --- Logique ---

    /**
     * Change le statut d'une fiche à la main (`manuel = true`). Publier remet aussi les absences à zéro
     * et lève le drapeau « absent de la source ». Purge ensuite les caches de la page Veille.
     *
     * @return array{ok: bool, statut?: string, compteurs?: array<string,int>, message?: string}
     */
    public static function changer_statut(Entrepot $e, int $id, string $statut): array {
        if (!in_array($statut, self::STATUTS, true)) {
            return ['ok' => false, 'message' => __('Statut inconnu.', 'rsn-veille')];
        }
        if ($id <= 0) {
            return ['ok' => false, 'message' => __('Fiche introuvable.', 'rsn-veille')];
        }
        $champs = ['statut' => $statut, 'manuel' => true];
        if ($statut === 'publie') {
            $champs += ['absences' => 0, 'absent_depuis' => null];
        }
        try {
            $e->mettre_a_jour($id, $champs);
        } catch (\InvalidArgumentException $erreur) {
            // L'entrepôt refuse un identifiant qui n'est pas une fiche de veille.
            return ['ok' => false, 'message' => __('Fiche introuvable.', 'rsn-veille')];
        } catch (\RuntimeException $erreur) {
            return ['ok' => false, 'message' => __("Le changement de statut n'a pas pu être enregistré.", 'rsn-veille')];
        }
        $fiches = $e->lister('');
        if ($statut === 'ecarte') {
            foreach ($fiches as $fiche) {
                if ($fiche->id === $id && $fiche->est_proposition()) {
                    self::retirer_image_proposee($e, $id);
                    break;
                }
            }
        }
        Collecte::purger_caches();
        return ['ok' => true, 'statut' => $statut, 'compteurs' => self::compteurs($fiches)];
    }

    /**
     * Proposition du public écartée : son image (téléversée par un anonyme, publique à son adresse) est
     * supprimée de la médiathèque, fichier compris, et la fiche n'a plus d'image.
     */
    private static function retirer_image_proposee(Entrepot $e, int $id): void {
        $piece = (int) get_post_thumbnail_id($id);
        if ($piece <= 0) {
            return;
        }
        wp_delete_attachment($piece, true);
        try {
            $e->mettre_a_jour($id, ['image_url' => '']);
        } catch (\RuntimeException $erreur) {
            // L'image est supprimée ; l'adresse restée dans la fiche ne mène plus à rien.
        }
    }

    /**
     * Fiches d'un onglet, d'un type et d'une source (vides : tous), triées par date de début croissante
     * (décroissante pour les écartées). Sans début, la date limite sert de date ; sans date, en dernier.
     *
     * @param Fiche[] $fiches
     * @return Fiche[]
     */
    public static function filtrer(array $fiches, string $statut, string $type, string $source): array {
        $retenues = array_values(array_filter($fiches, static fn(Fiche $f): bool => self::dans_onglet($f, $statut)
            && ($type === '' || $f->type === $type)
            && ($source === '' || $f->source === $source)));
        $sens = $statut === 'ecarte' ? -1 : 1;
        usort($retenues, static function (Fiche $a, Fiche $b) use ($sens): int {
            $da = $a->debut ?? $a->echeance ?? '';
            $db = $b->debut ?? $b->echeance ?? '';
            if ($da === '' || $db === '') {
                return ($da === '') <=> ($db === '') ?: (int) $a->id <=> (int) $b->id;
            }
            return $sens * strcmp($da, $db) ?: (int) $a->id <=> (int) $b->id;
        });
        return $retenues;
    }

    /**
     * Nombre de fiches par onglet.
     *
     * @param Fiche[] $fiches
     * @return array<string,int>
     */
    public static function compteurs(array $fiches): array {
        $compteurs = array_fill_keys(self::ONGLETS, 0);
        foreach ($fiches as $fiche) {
            foreach (self::ONGLETS as $onglet) {
                if (self::dans_onglet($fiche, $onglet)) {
                    $compteurs[$onglet]++;
                }
            }
        }
        return $compteurs;
    }

    /**
     * Sources distinctes des fiches, triées.
     *
     * @param Fiche[] $fiches
     * @return string[]
     */
    public static function sources_distinctes(array $fiches): array {
        $sources = array_unique(array_filter(array_map(static fn(Fiche $f): string => $f->source, $fiches), static fn(string $s): bool => $s !== ''));
        sort($sources, SORT_NATURAL | SORT_FLAG_CASE);
        return array_values($sources);
    }

    // --- Rendu ---

    /**
     * Page complète : titre, onglets, filtres, zone de message et tableau.
     *
     * @param Fiche[] $fiches Toutes les fiches (les compteurs portent sur toutes).
     * @param array{url_admin?: string} $options
     */
    public static function page_html(array $fiches, string $onglet, string $type, string $source, string $url_page, array $options): string {
        return '<div class="wrap rsn-veille-admin">'
            . '<h1>' . esc_html__("File d'approbation", 'rsn-veille') . '</h1>'
            . self::onglets_html($onglet, self::compteurs($fiches), $type, $source, $url_page)
            . self::filtres_html($onglet, $type, $source, self::sources_distinctes($fiches))
            . '<div class="rsn-veille-message" role="status" aria-live="polite"></div>'
            . self::tableau_html(self::filtrer($fiches, $onglet, $type, $source), $onglet, $options)
            . '</div>';
    }

    /**
     * Une ligne du tableau : titre (lien Modifier, lien d'information, drapeau d'absence), type, dates,
     * organisateur, source et niveau, extrait, boutons selon le statut.
     *
     * @param array{url_admin?: string} $options `url_admin` : adresse de wp-admin, terminée par « / ».
     */
    public static function ligne_html(Fiche $f, array $options): string {
        $id = (int) $f->id;
        return '<tr id="rsn-veille-fiche-' . $id . '" data-id="' . $id . '" data-statut="' . esc_attr($f->statut) . '">'
            . '<td class="rsn-veille-titre">' . self::titre_html($f, (string) ($options['url_admin'] ?? '')) . '</td>'
            . '<td>' . esc_html(self::libelle_type($f->type)) . '</td>'
            . '<td>' . self::dates_html($f) . '</td>'
            . '<td>' . esc_html($f->organisateur) . '</td>'
            . '<td>' . self::source_html($f) . '</td>'
            . '<td class="rsn-veille-extrait">' . esc_html(Fiche::court($f->resume, self::LONGUEUR_EXTRAIT)) . '</td>'
            . '<td class="rsn-veille-actions">' . self::boutons_html($f) . '</td>'
            . '</tr>';
    }

    /** @param Fiche[] $fiches Fiches déjà filtrées et triées. */
    private static function tableau_html(array $fiches, string $onglet, array $options): string {
        $colonnes = [
            __('Titre', 'rsn-veille'), __('Type', 'rsn-veille'), __('Dates', 'rsn-veille'), __('Organisateur', 'rsn-veille'),
            __('Source', 'rsn-veille'), __('Extrait', 'rsn-veille'), __('Actions', 'rsn-veille'),
        ];
        $entete = '';
        foreach ($colonnes as $colonne) {
            $entete .= '<th scope="col">' . esc_html($colonne) . '</th>';
        }
        $lignes = '';
        foreach ($fiches as $fiche) {
            $lignes .= self::ligne_html($fiche, $options);
        }
        if ($lignes === '') {
            $lignes = '<tr class="rsn-veille-vide"><td colspan="' . count($colonnes) . '">' . esc_html__('Aucune fiche.', 'rsn-veille') . '</td></tr>';
        }
        return '<table class="wp-list-table widefat striped rsn-veille-file" data-onglet="' . esc_attr($onglet) . '">'
            . '<thead><tr>' . $entete . '</tr></thead><tbody>' . $lignes . '</tbody></table>';
    }

    /** @param array<string,int> $compteurs */
    private static function onglets_html(string $actif, array $compteurs, string $type, string $source, string $url_page): string {
        $libelles = [
            'a_approuver' => __('À approuver', 'rsn-veille'),
            'publie' => __('Publiées', 'rsn-veille'),
            'ecarte' => __('Écartées', 'rsn-veille'),
            'a_verifier' => __('À vérifier', 'rsn-veille'),
        ];
        $html = '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__('Statut des fiches', 'rsn-veille') . '">';
        foreach (self::ONGLETS as $onglet) {
            $url = $url_page . '&' . http_build_query(array_filter(['statut' => $onglet, 'type' => $type, 'source' => $source], static fn(string $v): bool => $v !== ''), '', '&', PHP_QUERY_RFC3986);
            $classe = 'nav-tab' . ($onglet === $actif ? ' nav-tab-active' : '');
            $courant = $onglet === $actif ? ' aria-current="page"' : '';
            $html .= '<a class="' . $classe . '"' . $courant . ' href="' . esc_url($url) . '">' . esc_html($libelles[$onglet])
                . ' <span class="count" data-compteur="' . esc_attr($onglet) . '">(' . (int) ($compteurs[$onglet] ?? 0) . ')</span></a>';
        }
        return $html . '</nav>';
    }

    /** @param string[] $sources */
    private static function filtres_html(string $onglet, string $type, string $source, array $sources): string {
        $types = ['' => __('Tous les types', 'rsn-veille'), 'evenement' => __('Événements', 'rsn-veille'), 'appel' => __('Appels', 'rsn-veille')];
        $choix_sources = ['' => __('Toutes les sources', 'rsn-veille')];
        foreach ($sources as $nom) {
            $choix_sources[$nom] = $nom;
        }
        return '<form class="rsn-veille-filtres" method="get" action="">'
            . '<input type="hidden" name="page" value="' . esc_attr(Menu::SLUG) . '">'
            . '<input type="hidden" name="statut" value="' . esc_attr($onglet) . '">'
            . '<label class="screen-reader-text" for="rsn-veille-type">' . esc_html__('Type', 'rsn-veille') . '</label>'
            . self::liste_html('rsn-veille-type', 'type', $types, $type)
            . '<label class="screen-reader-text" for="rsn-veille-source">' . esc_html__('Source', 'rsn-veille') . '</label>'
            . self::liste_html('rsn-veille-source', 'source', $choix_sources, $source)
            . '<button type="submit" class="button">' . esc_html__('Filtrer', 'rsn-veille') . '</button>'
            . '</form>';
    }

    /** @param array<string,string> $choix valeur => libellé */
    private static function liste_html(string $id, string $nom, array $choix, string $choisi): string {
        $html = '<select id="' . esc_attr($id) . '" name="' . esc_attr($nom) . '">';
        foreach ($choix as $valeur => $libelle) {
            $valeur = (string) $valeur;
            $html .= '<option value="' . esc_attr($valeur) . '"' . ($valeur === $choisi ? ' selected' : '') . '>' . esc_html($libelle) . '</option>';
        }
        return $html . '</select>';
    }

    private static function titre_html(Fiche $f, string $url_admin): string {
        $titre = $f->titre !== '' ? $f->titre : __('(sans titre)', 'rsn-veille');
        $liens = [];
        if ($url_admin !== '') {
            $liens[] = '<a href="' . esc_url($url_admin . 'post.php?post=' . (int) $f->id . '&action=edit') . '">' . esc_html__('Modifier', 'rsn-veille') . '</a>';
        }
        $info = esc_url($f->url);
        if ($info !== '') {
            $liens[] = '<a href="' . $info . '" target="_blank" rel="noopener noreferrer">' . esc_html__("Page d'origine", 'rsn-veille') . '</a>';
        }
        $html = '<strong>' . esc_html($titre) . '</strong>';
        if ($f->absent_depuis !== null && $f->absent_depuis !== '') {
            $depuis = Dates::date_longue($f->absent_depuis);
            /* translators: %s : date à laquelle la fiche n'a plus été trouvée à sa source. */
            $html .= '<p class="rsn-veille-absent">' . esc_html(sprintf(__('Absent de la source depuis le %s', 'rsn-veille'), $depuis !== '' ? $depuis : $f->absent_depuis)) . '</p>';
        }
        if ($liens !== []) {
            $html .= '<div class="rsn-veille-liens">' . implode(' | ', $liens) . '</div>';
        }
        return $html;
    }

    private static function dates_html(Fiche $f): string {
        $html = esc_html(Dates::periode($f->debut, $f->fin, $f->heure));
        $limite = Dates::date_longue($f->echeance);
        if ($limite !== '') {
            /* translators: %s : date limite d'un appel. */
            $html .= ($html !== '' ? '<br>' : '') . esc_html(sprintf(__('Date limite : %s', 'rsn-veille'), $limite));
        }
        return $html;
    }

    private static function source_html(Fiche $f): string {
        $niveaux = [
            'A' => __('Flux structuré avec dates', 'rsn-veille'),
            'B' => __('Flux RSS', 'rsn-veille'),
            'C' => __('Page web structurée', 'rsn-veille'),
            'M' => __('Saisie manuelle', 'rsn-veille'),
        ];
        $html = esc_html($f->source);
        if ($f->niveau !== '') {
            $titre = $niveaux[$f->niveau] ?? '';
            $html .= ' <span class="rsn-veille-niveau">' . esc_html__('niveau', 'rsn-veille') . ' <abbr title="' . esc_attr($titre) . '">' . esc_html($f->niveau) . '</abbr></span>';
        }
        return $html;
    }

    /** Boutons selon le statut ; une fiche absente de sa source peut aussi être gardée (drapeau levé). */
    private static function boutons_html(Fiche $f): string {
        $boutons = match ($f->statut) {
            'a_approuver' => [['publie', __('Publier', 'rsn-veille'), true], ['ecarte', __('Écarter', 'rsn-veille'), false]],
            'publie' => [['ecarte', __('Retirer', 'rsn-veille'), false]],
            default => [['publie', __('Publier', 'rsn-veille'), true]],
        };
        if ($f->statut === 'publie' && $f->absent_depuis !== null && $f->absent_depuis !== '') {
            array_unshift($boutons, ['publie', __('Garder publiée', 'rsn-veille'), false]);
        }
        $html = '';
        foreach ($boutons as [$statut, $libelle, $principal]) {
            $html .= '<button type="button" class="button' . ($principal ? ' button-primary' : '') . ' rsn-veille-action" data-statut="' . esc_attr($statut) . '">'
                . esc_html($libelle) . '</button> ';
        }
        return rtrim($html);
    }

    private static function libelle_type(string $type): string {
        return match ($type) {
            'evenement' => __('Événement', 'rsn-veille'),
            'appel' => __('Appel', 'rsn-veille'),
            'nouvelle' => __('Nouvelle', 'rsn-veille'),
            default => $type,
        };
    }

    private static function dans_onglet(Fiche $f, string $onglet): bool {
        if ($onglet === 'a_verifier') {
            return $f->statut === 'publie' && $f->absent_depuis !== null && $f->absent_depuis !== '';
        }
        return $f->statut === $onglet;
    }

    /** Paramètre d'adresse réduit à une clé (`sanitize_key`), vide s'il manque. */
    private static function parametre(string $nom): string {
        return isset($_GET[$nom]) && is_string($_GET[$nom]) ? sanitize_key(wp_unslash($_GET[$nom])) : '';
    }
}

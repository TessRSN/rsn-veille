<?php
declare(strict_types=1);

namespace RSN\Veille\Admin;

use RSN\Veille\EntrepotWordPress;
use RSN\Veille\Infolettre;
use RSN\Veille\Public\Donnees;
use RSN\Veille\Public\EvenementsRsn;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Page Infolettre : trois blocs HTML (Agenda, Appels, Nouvelles gardées) à coller dans Mailchimp.
 *
 * Chaque bloc a un aperçu (cadre isolé, sans script), « Copier » (collage enrichi : HTML et texte) et
 * « Copier le code HTML ». Les événements et les appels viennent de `Donnees::construire` (les fiches
 * publiées et les événements du RSN, dédoublonnés comme sur la page publique) ; les programmes ouverts du
 * RSN, des articles de la catégorie 149 ; les nouvelles, de l'option du radar.
 *
 * Le HTML des blocs est produit par `Infolettre` (testée sans WordPress) ; `page_html` assemble la page à
 * partir de données déjà lues. `afficher`, `traiter_vider` et `programmes_rsn` lisent WordPress.
 */
final class PageInfolettre {
    public const NONCE = 'rsn_veille_infolettre';
    public const ACTION_VIDER = 'rsn_veille_infolettre_vider';

    /** Nombres d'événements proposés pour l'agenda. */
    public const NOMBRES = [6, 8, 10, 12];

    /** Catégorie des articles de programmes du RSN (site rsn.quebec). */
    public const CATEGORIE_PROGRAMMES = 149;

    /** Méta de l'article qui porte la période d'un programme (« date limite : 15 novembre 2026 »). */
    public const META_PERIODE = 'rsn_appel_periode';

    /** Nombre d'articles de programmes lus au plus. */
    private const MAX_PROGRAMMES = 50;

    // --- WordPress ---

    /** Accroche le vidage des nouvelles gardées (admin-post.php, connectés seulement). */
    public static function accrocher(): void {
        add_action('admin_post_' . self::ACTION_VIDER, [self::class, 'traiter_vider']);
    }

    /** Affiche la page (rappel du menu). */
    public static function afficher(): void {
        if (!current_user_can(Menu::CAPACITE)) {
            wp_die(esc_html__("Vous n'avez pas accès à cette page.", 'rsn-veille'), '', 403);
        }
        // Réglages demandés par l'adresse (lecture seule, aucune écriture).
        $nombre = self::nombre_demande(isset($_GET['nombre']) ? wp_unslash($_GET['nombre']) : null);
        $horizon = self::horizon_demande(isset($_GET['jusqu_au']) ? wp_unslash($_GET['jusqu_au']) : null);
        $aujourdhui = \rsn_veille_aujourdhui();
        // Même source que la page publique : fiches publiées et événements du RSN dédoublonnés, sans les champs d'équipe.
        $donnees = Donnees::construire($aujourdhui, new EntrepotWordPress(), EvenementsRsn::lire(), false);
        // Sortie entièrement échappée par les méthodes de rendu.
        echo self::page_html($donnees['items'], PageRadar::lire_gardees(), self::programmes_rsn(), [
            'aujourdhui' => $aujourdhui,
            'nombre' => $nombre,
            'jusqu_au' => $horizon,
            'url_page' => admin_url('admin.php'),
            'url_action' => admin_url('admin-post.php'),
            'nonce' => wp_create_nonce(self::NONCE),
            'vide' => isset($_GET['vide']) && $_GET['vide'] === '1',
        ]);
    }

    /** admin-post.php : « Vider les nouvelles gardées » (capacité et jeton exigés), puis retour à la page. */
    public static function traiter_vider(): void {
        if (!current_user_can(Menu::CAPACITE)) {
            wp_die(esc_html__("Vous n'avez pas le droit de modifier la veille.", 'rsn-veille'), '', 403);
        }
        check_admin_referer(self::NONCE);
        self::vider_gardees();
        wp_safe_redirect(admin_url('admin.php?page=' . Menu::SLUG_INFOLETTRE . '&vide=1'));
        exit;
    }

    /** Vide l'option des nouvelles gardées (la même que celle du radar). */
    public static function vider_gardees(): void {
        update_option(PageRadar::OPTION_GARDEES, [], false);
    }

    /**
     * Programmes du RSN ouverts : articles de la catégorie 149 pour lesquels la fonction `rsn_appel_etat`
     * du site (extrait 45) répond « ouvert », convertis en appels. Sans cette fonction, liste vide.
     * Non testable sans WordPress : vérification dans `tests/manuel/verifier-admin.md`.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function programmes_rsn(): array {
        if (!function_exists('rsn_appel_etat') || !function_exists('get_posts')) {
            return [];
        }
        $articles = get_posts([
            'cat' => self::CATEGORIE_PROGRAMMES,
            'post_type' => 'post',
            'post_status' => 'publish',
            'numberposts' => self::MAX_PROGRAMMES,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
        $aujourdhui = \rsn_veille_aujourdhui();
        $programmes = [];
        foreach (is_array($articles) ? $articles : [] as $article) {
            if (!$article instanceof \WP_Post || rsn_appel_etat($article) !== 'ouvert') {
                continue;
            }
            $extrait = (string) get_the_excerpt($article);
            if ($extrait === '') {
                $extrait = (string) $article->post_content;
            }
            $periode = get_post_meta($article->ID, self::META_PERIODE, true);
            $programmes[] = Infolettre::appel_programme(
                html_entity_decode(wp_strip_all_tags((string) get_the_title($article)), ENT_QUOTES, 'UTF-8'),
                (string) get_permalink($article),
                $extrait,
                is_string($periode) ? $periode : '',
                (string) $article->post_date,
                $aujourdhui
            );
        }
        return $programmes;
    }

    // --- Réglages demandés ---

    /** Nombre d'événements de l'agenda : 6, 8, 10 ou 12 ; 8 sinon. */
    public static function nombre_demande(mixed $brut): int {
        $nombre = is_scalar($brut) && preg_match('/^\d+$/', (string) $brut) === 1 ? (int) $brut : 0;
        return in_array($nombre, self::NOMBRES, true) ? $nombre : Infolettre::NOMBRE_PAR_DEFAUT;
    }

    /** Horizon de l'agenda : une date `Y-m-d` valide, sinon aucun. */
    public static function horizon_demande(mixed $brut): ?string {
        if (!is_string($brut) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $brut, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        return $brut;
    }

    // --- Rendu ---

    /**
     * Page complète.
     *
     * @param array<int,mixed> $items Fiches de `Donnees` (événements et appels publiés).
     * @param array<int,mixed> $gardees Nouvelles gardées.
     * @param array<int,mixed> $programmes Programmes du RSN ouverts, au format appel.
     * @param array{aujourdhui?: string, nombre?: int, jusqu_au?: ?string, url_page?: string, url_action?: string, nonce?: string, vide?: bool} $options
     */
    public static function page_html(array $items, array $gardees, array $programmes, array $options): string {
        $aujourdhui = (string) ($options['aujourdhui'] ?? \rsn_veille_aujourdhui());
        $nombre = self::nombre_demande($options['nombre'] ?? null);
        $horizon = self::horizon_demande($options['jusqu_au'] ?? null);
        $gardees = array_values(array_filter($gardees, static fn($g): bool => is_array($g)));
        $appels = array_values(array_filter($items, static fn($i): bool => is_array($i) && ($i['type'] ?? '') === 'appel'));
        $evenements = array_values(array_filter($items, static fn($i): bool => is_array($i) && ($i['type'] ?? '') === 'evenement'));

        $nb_evenements = count(Infolettre::evenements_retenus($evenements, $nombre, $horizon, $aujourdhui));
        $nb_appels = count(Infolettre::appels_retenus($appels, $programmes, $aujourdhui));
        $nb_nouvelles = count(Infolettre::nouvelles_retenues($gardees));

        $avis = !empty($options['vide'])
            ? '<div class="notice notice-success inline"><p>' . esc_html__('Nouvelles gardées vidées.', 'rsn-veille') . '</p></div>'
            : '';

        return '<div class="wrap rsn-veille-admin rsn-veille-infolettre">'
            . '<h1>' . esc_html__('Infolettre', 'rsn-veille') . '</h1>'
            . '<p class="description">' . esc_html__("Trois blocs prêts pour Mailchimp. « Copier » garde la mise en forme : collez-la dans l'éditeur de l'infolettre. « Copier le code HTML » sert pour un bloc Code.", 'rsn-veille') . '</p>'
            . $avis
            . '<div class="rsn-veille-message" role="status" aria-live="polite"></div>'
            . self::section_html(
                'agenda',
                __('Agenda', 'rsn-veille'),
                self::libelle_evenements($nb_evenements),
                Infolettre::bloc_agenda($evenements, $nombre, $horizon, $aujourdhui),
                $nb_evenements,
                self::reglages_html($nombre, $horizon, $options)
            )
            . self::section_html(
                'appels',
                __('Appels', 'rsn-veille'),
                self::libelle_appels($nb_appels),
                Infolettre::bloc_appels($appels, $programmes, $aujourdhui),
                $nb_appels,
                ''
            )
            . self::section_html(
                'nouvelles',
                __('Nouvelles gardées', 'rsn-veille'),
                self::libelle_nouvelles($nb_nouvelles),
                Infolettre::bloc_nouvelles($gardees, $aujourdhui),
                $nb_nouvelles,
                self::vider_html(count($gardees), $options)
            )
            . '</div>';
    }

    /**
     * Un bloc : titre, compteur, réglages éventuels, boutons, aperçu et codes cachés (HTML et texte).
     * Sans élément, les boutons de copie sont désactivés (le bloc ne contient que son message).
     */
    private static function section_html(string $cle, string $titre, string $compteur, string $html, int $nb, string $complement): string {
        $desactive = $nb === 0 ? ' disabled' : '';
        $apercu = '<!doctype html><html><head><meta charset="utf-8"><base target="_blank"></head>'
            . '<body style="margin:16px;background:#fff">' . $html . '</body></html>';
        return '<section class="rsn-veille-bloc" data-bloc="' . esc_attr($cle) . '">'
            . '<h2>' . esc_html($titre) . ' <span class="rsn-veille-compteur">' . esc_html($compteur) . '</span></h2>'
            . $complement
            . '<div class="rsn-veille-bloc-actions">'
            . '<button type="button" class="button button-primary rsn-veille-copier" data-mode="riche"' . $desactive . '>' . esc_html__('Copier', 'rsn-veille') . '</button> '
            . '<button type="button" class="button rsn-veille-copier" data-mode="code"' . $desactive . '>' . esc_html__('Copier le code HTML', 'rsn-veille') . '</button>'
            . '</div>'
            // Cadre isolé : aucun script (pas d'allow-scripts), même origine seulement pour que « Copier » puisse sélectionner le rendu.
            // `htmlspecialchars` (et non esc_attr) : esc_attr ne ré-encode pas les entités, ce qui rendrait « &lt;b&gt; » en vraie balise.
            . '<iframe class="rsn-veille-apercu" title="' . esc_attr(sprintf(
                /* translators: %s : nom du bloc (Agenda, Appels, Nouvelles gardées). */
                __('Aperçu du bloc %s', 'rsn-veille'),
                $titre
            )) . '" sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox" srcdoc="'
            . htmlspecialchars($apercu, ENT_QUOTES, 'UTF-8') . '"></iframe>'
            . '<textarea hidden readonly data-role="html">' . esc_textarea($html) . '</textarea>'
            . '<textarea hidden readonly data-role="texte">' . esc_textarea(Infolettre::texte_brut($html)) . '</textarea>'
            . '</section>';
    }

    /** Réglages de l'agenda : formulaire GET (nombre d'événements ou horizon). */
    private static function reglages_html(int $nombre, ?string $horizon, array $options): string {
        $choix = '';
        foreach (self::NOMBRES as $n) {
            $choix .= '<option value="' . $n . '"' . ($n === $nombre ? ' selected' : '') . '>' . $n . '</option>';
        }
        return '<form method="get" action="' . esc_url((string) ($options['url_page'] ?? '')) . '" class="rsn-veille-reglages-bloc">'
            . '<input type="hidden" name="page" value="' . esc_attr(Menu::SLUG_INFOLETTRE) . '">'
            . '<label for="rsn-veille-nombre">' . esc_html__("Nombre d'événements", 'rsn-veille') . '</label> '
            . '<select name="nombre" id="rsn-veille-nombre">' . $choix . '</select> '
            . '<label for="rsn-veille-jusqu-au">' . esc_html__("ou jusqu'au", 'rsn-veille') . '</label> '
            . '<input type="date" name="jusqu_au" id="rsn-veille-jusqu-au" value="' . esc_attr((string) $horizon) . '"> '
            . '<button type="submit" class="button">' . esc_html__('Appliquer', 'rsn-veille') . '</button> '
            . '<span class="description">' . esc_html__("Une date remplace le nombre d'événements.", 'rsn-veille') . '</span>'
            . '</form>';
    }

    /** Bouton « Vider les nouvelles gardées » (POST, jeton) ; désactivé quand rien n'est gardé. */
    private static function vider_html(int $nb_gardees, array $options): string {
        return '<form method="post" action="' . esc_url((string) ($options['url_action'] ?? '')) . '" class="rsn-veille-vider">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_VIDER) . '">'
            . '<input type="hidden" name="_wpnonce" value="' . esc_attr((string) ($options['nonce'] ?? '')) . '">'
            . '<button type="submit" class="button"' . ($nb_gardees === 0 ? ' disabled' : '') . '>' . esc_html__('Vider les nouvelles gardées', 'rsn-veille') . '</button>'
            . '</form>';
    }

    private static function libelle_evenements(int $n): string {
        /* translators: %d : nombre d'événements dans le bloc Agenda. */
        return sprintf(_n('%d événement', '%d événements', $n, 'rsn-veille'), $n);
    }

    private static function libelle_appels(int $n): string {
        /* translators: %d : nombre d'appels dans le bloc Appels. */
        return sprintf(_n('%d appel', '%d appels', $n, 'rsn-veille'), $n);
    }

    private static function libelle_nouvelles(int $n): string {
        /* translators: %d : nombre de nouvelles dans le bloc Nouvelles gardées. */
        return sprintf(_n('%d nouvelle', '%d nouvelles', $n, 'rsn-veille'), $n);
    }
}

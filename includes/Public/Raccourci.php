<?php
declare(strict_types=1);

namespace RSN\Veille\Public;

use RSN\Veille\Collecte;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Raccourci `[rsn_veille section="tout|agenda|appels"]` : la page publique de la veille, dans le style
 * de la maquette validée (`docs/maquette/gabarit-v6.1.html`), données JSON incorporées dans la page.
 */
final class Raccourci {
    public const NOM = 'rsn_veille';

    /** Nom des ressources CSS et JS de la page publique. */
    private const RESSOURCE = 'rsn-veille';
    private const POLICES = 'rsn-veille-polices';
    private const URL_POLICES = 'https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Plus+Jakarta+Sans:wght@400;500;600&display=swap';

    private const SECTIONS = ['tout', 'agenda', 'appels'];

    /** Drapeaux JSON : `<`, `>` et `&` en <… (aucun `</script>` possible), Unicode et barres obliques lisibles. */
    private const DRAPEAUX_JSON = JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    private const HEX = '<svg class="hex" viewBox="0 0 24 26" aria-hidden="true"><path d="M12 2 21.5 7.5v11L12 24 2.5 18.5v-11Z" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linejoin="round"/></svg>';

    /** Vrai une fois la page rendue : ses identifiants (#rsn-veille-donnees…) sont uniques dans la page. */
    private static bool $rendu = false;

    /** Accroche le raccourci, les ressources et l'invalidation des caches publics. */
    public static function accrocher(): void {
        add_action('init', [self::class, 'enregistrer']);
        add_action('wp_enqueue_scripts', [self::class, 'charger_si_present']);
        Donnees::accrocher();
    }

    public static function enregistrer(): void {
        add_shortcode(self::NOM, [self::class, 'rendre']);
    }

    /**
     * Ressources en tête de page quand la page affichée contient le raccourci (contenu de l'article, ou
     * page Veille des réglages pour une page Elementor). Sinon, `rendre` les met en file au rendu.
     */
    public static function charger_si_present(): void {
        $article = get_queried_object();
        $present = false;
        if (is_singular() && $article instanceof \WP_Post) {
            $id_page = (int) (Collecte::reglages()['page_veille_id'] ?? 0);
            $present = has_shortcode((string) $article->post_content, self::NOM) || ($id_page > 0 && $article->ID === $id_page);
        }
        if ((bool) apply_filters('rsn_veille_charger_ressources', $present)) {
            self::mettre_en_file();
        }
    }

    /** CSS et JS de la page publique, versionnés ; polices Google seulement si le filtre le demande. */
    public static function mettre_en_file(): void {
        // Le site charge déjà DM Serif Display et Plus Jakarta Sans : `rsn_veille_charger_polices` à vrai pour un autre thème.
        if ((bool) apply_filters('rsn_veille_charger_polices', false)) {
            wp_enqueue_style(self::POLICES, self::URL_POLICES, [], null);
        }
        wp_enqueue_style(self::RESSOURCE, RSN_VEILLE_URL . 'assets/veille.css', [], RSN_VEILLE_VERSION);
        wp_enqueue_script(self::RESSOURCE, RSN_VEILLE_URL . 'assets/veille.js', [], RSN_VEILLE_VERSION, true);
    }

    /**
     * Rendu du raccourci.
     *
     * @param array<string,mixed>|string $attributs
     */
    public static function rendre(mixed $attributs = []): string {
        if (self::$rendu) {
            // Une seule page de veille par page : les identifiants du gabarit seraient en double.
            return '';
        }
        self::$rendu = true;
        $attributs = shortcode_atts(['section' => 'tout'], is_array($attributs) ? $attributs : [], self::NOM);
        $section = strtolower(trim((string) $attributs['section']));
        if (!in_array($section, self::SECTIONS, true)) {
            $section = 'tout';
        }
        self::mettre_en_file();

        $json_donnees = self::json(Donnees::en_cache());
        $json_textes = self::json(self::textes());
        $hex = self::HEX;
        ob_start();
        include __DIR__ . '/gabarit-veille.php';
        return (string) ob_get_clean();
    }

    /** JSON sûr dans un `<script type="application/json">`. */
    public static function json(mixed $valeur): string {
        $json = wp_json_encode($valeur, self::DRAPEAUX_JSON);
        return is_string($json) ? $json : '{}';
    }

    /** Pour les tests : permet un nouveau rendu dans la même requête. */
    public static function reinitialiser(): void {
        self::$rendu = false;
    }

    /**
     * Textes du JS, traduisibles. Pluriels : [singulier, pluriel] ; « %d » reçoit le nombre, « %s » les valeurs.
     *
     * @return array<string,mixed>
     */
    public static function textes(): array {
        return [
            'mois' => [_x('janv.', 'mois abrégé', 'rsn-veille'), _x('févr.', 'mois abrégé', 'rsn-veille'), _x('mars', 'mois abrégé', 'rsn-veille'), _x('avr.', 'mois abrégé', 'rsn-veille'), _x('mai', 'mois abrégé', 'rsn-veille'), _x('juin', 'mois abrégé', 'rsn-veille'),
                _x('juil.', 'mois abrégé', 'rsn-veille'), _x('août', 'mois abrégé', 'rsn-veille'), _x('sept.', 'mois abrégé', 'rsn-veille'), _x('oct.', 'mois abrégé', 'rsn-veille'), _x('nov.', 'mois abrégé', 'rsn-veille'), _x('déc.', 'mois abrégé', 'rsn-veille')],
            'mois_longs' => [__('janvier', 'rsn-veille'), __('février', 'rsn-veille'), __('mars', 'rsn-veille'), __('avril', 'rsn-veille'), __('mai', 'rsn-veille'), __('juin', 'rsn-veille'),
                __('juillet', 'rsn-veille'), __('août', 'rsn-veille'), __('septembre', 'rsn-veille'), __('octobre', 'rsn-veille'), __('novembre', 'rsn-veille'), __('décembre', 'rsn-veille')],
            // Initiales des jours, du lundi au dimanche (contexte : le jour entier).
            'jours_courts' => [_x('L', 'lundi', 'rsn-veille'), _x('M', 'mardi', 'rsn-veille'), _x('M', 'mercredi', 'rsn-veille'), _x('J', 'jeudi', 'rsn-veille'), _x('V', 'vendredi', 'rsn-veille'), _x('S', 'samedi', 'rsn-veille'), _x('D', 'dimanche', 'rsn-veille')],
            /* translators: premier jour du mois, dans une date (« 1er novembre »). */
            'premier_du_mois' => __('1er', 'rsn-veille'),
            /* translators: 1 : jour de début, 2 : jour de fin, 3 : mois, 4 : année. */
            'du_au_meme_mois' => __('Du %s au %s %s %s', 'rsn-veille'),
            /* translators: 1 : date de début, 2 : date de fin. */
            'du_au' => __('Du %s au %s', 'rsn-veille'),
            'en_anglais' => __('En anglais', 'rsn-veille'),
            'plus_info' => __("Plus d'information", 'rsn-veille'),
            'date_limite' => __('date limite', 'rsn-veille'),
            'publie' => __('publié', 'rsn-veille'),
            /* translators: %s : date de publication. */
            'publie_le' => __('publié le %s', 'rsn-veille'),
            'appel' => __('Appel', 'rsn-veille'),
            'consulter_appel' => __("Consulter l'appel", 'rsn-veille'),
            'nb_evenements' => [__('%d événement', 'rsn-veille'), __('%d événements', 'rsn-veille')],
            'nb_a_venir' => [__('%d événement à venir', 'rsn-veille'), __('%d événements à venir', 'rsn-veille')],
            'nb_passes' => [__('%d événement passé', 'rsn-veille'), __('%d événements passés', 'rsn-veille')],
            /* translators: %d : nombre, %s : mois et année du plus ancien. */
            'nb_passes_depuis' => [__('%d événement passé depuis %s', 'rsn-veille'), __('%d événements passés depuis %s', 'rsn-veille')],
            'nb_ce_mois' => [__('%d événement ce mois-ci', 'rsn-veille'), __('%d événements ce mois-ci', 'rsn-veille')],
            'nb_appels' => [__('%d appel', 'rsn-veille'), __('%d appels', 'rsn-veille')],
            'aucun_evenement_filtres' => __('Aucun événement ne correspond à ces filtres.', 'rsn-veille'),
            'aucun_ce_jour' => __('Aucun événement ce jour-là.', 'rsn-veille'),
            'aucun_appel' => __('Aucun appel en cours.', 'rsn-veille'),
            'cliquer_jour' => __('Cliquer sur un jour pour voir ses événements', 'rsn-veille'),
            'tous_les_mois' => __('Tous les mois à venir', 'rsn-veille'),
            'plus_tard' => __('Plus tard', 'rsn-veille'),
            'evenements_passes' => __('Événements passés', 'rsn-veille'),
            'plus' => __('Plus…', 'rsn-veille'),
            'moins' => __('Moins', 'rsn-veille'),
            'tous' => __('Tous', 'rsn-veille'),
            'qui' => [
                'rsn' => __('RSN', 'rsn-veille'),
                'partenaires' => __('Partenaires', 'rsn-veille'),
                'ecosysteme' => __('Écosystème', 'rsn-veille'),
            ],
            'formats' => [
                'Présentiel' => __('Présentiel', 'rsn-veille'),
                'Virtuel' => __('Virtuel', 'rsn-veille'),
                'Hybride' => __('Hybride', 'rsn-veille'),
            ],
            'regions' => [
                'Québec' => __('Québec', 'rsn-veille'),
                'En ligne' => __('En ligne', 'rsn-veille'),
                'Hors Québec' => __('Hors Québec', 'rsn-veille'),
            ],
            /* translators: %s : date de la collecte. */
            'collecte_du' => __('collecte du %s', 'rsn-veille'),
            /* translators: 1 : date de la collecte, 2 : heure (« 3 h 05 »). */
            'collecte_du_a' => __('collecte du %s à %s', 'rsn-veille'),
            'envoi_non_branche' => __('Envoi non encore branché.', 'rsn-veille'),
        ];
    }
}

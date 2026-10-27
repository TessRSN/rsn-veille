<?php
declare(strict_types=1);

namespace RSN\Veille\Public;

use RSN\Veille\Collecte;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Raccourci `[rsn_veille section="tout|agenda|appels" une="1|0"]` : la page publique de la veille, dans le style
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

    /** Objet JS de la configuration du formulaire (`window.rsnVeillePublic`). */
    public const OBJET_JS = 'rsnVeillePublic';

    /** Rendus dans la requête : le deuxième raccourci de la page reçoit #rsn-veille-donnees-2, etc. */
    private static int $rendus = 0;

    /** Configuration du formulaire déjà passée au JS dans cette requête. */
    private static bool $configure = false;

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
     * page Veille des réglages et ses traductions pour une page Elementor). Sinon, `rendre` les met en file au rendu.
     */
    public static function charger_si_present(): void {
        $article = get_queried_object();
        $present = false;
        if (is_singular() && $article instanceof \WP_Post) {
            // Page Veille des réglages ou l'une de ses traductions WPML (page Elementor : raccourci hors du contenu).
            $present = has_shortcode((string) $article->post_content, self::NOM) || in_array($article->ID, Collecte::pages_veille(), true);
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
        if (!self::$configure) {
            self::$configure = true;
            // LiteSpeed Cache (ESI actif) : le jeton est servi hors du cache de la page. Sinon, le JS en
            // redemande un (action rsn_veille_jeton) quand celui de la page en cache a expiré.
            do_action('litespeed_nonce', Formulaire::NONCE);
            wp_localize_script(self::RESSOURCE, self::OBJET_JS, [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'action' => Formulaire::ACTION,
                'action_jeton' => Formulaire::ACTION_JETON,
                'nonce' => wp_create_nonce(Formulaire::NONCE),
            ]);
        }
    }

    /**
     * Rendu du raccourci.
     *
     * @param array<string,mixed>|string $attributs
     */
    public static function rendre(mixed $attributs = []): string {
        self::$rendus++;
        $suffixe = self::$rendus > 1 ? '-' . self::$rendus : '';
        $attributs = shortcode_atts(['section' => 'tout', 'une' => '1'], is_array($attributs) ? $attributs : [], self::NOM);
        $section = strtolower(trim((string) $attributs['section']));
        if (!in_array($section, self::SECTIONS, true)) {
            $section = 'tout';
        }
        // Bande « À la une » : présente sauf si l'attribut vaut « 0 » (et seulement dans la section « tout »).
        $une = trim((string) $attributs['une']) === '0' ? '0' : '1';
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

    /** Pour les tests : remet le compteur de rendus et la configuration du JS à zéro. */
    public static function reinitialiser(): void {
        self::$rendus = 0;
        self::$configure = false;
    }

    /**
     * Textes du JS, traduisibles. Pluriels : [singulier, pluriel] ; « %d » reçoit le nombre, « %1$s », « %2$s »… (ou « %s »)
     * les valeurs.
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
            'du_au_meme_mois' => __('Du %1$s au %2$s %3$s %4$s', 'rsn-veille'),
            /* translators: 1 : date de début, 2 : date de fin. */
            'du_au' => __('Du %1$s au %2$s', 'rsn-veille'),
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
            'collecte_du_a' => __('collecte du %1$s à %2$s', 'rsn-veille'),
            // Formulaire « Proposer un événement ».
            'envoi_en_cours' => __('Envoi…', 'rsn-veille'),
            'merci_proposition' => __("Merci ! Votre proposition est dans la file d'approbation de l'équipe du RSN.", 'rsn-veille'),
            'corriger_champs' => __('Vérifiez les champs signalés :', 'rsn-veille'),
            'erreur_envoi' => __("L'envoi a échoué. Réessayez plus tard.", 'rsn-veille'),
            'jeton_expire' => __('Jeton de sécurité expiré : rechargez la page.', 'rsn-veille'),
            'image_trop_lourde' => __('Image trop lourde (3 Mo au plus).', 'rsn-veille'),
            // Bande « À la une », dates limites, filtre « Afficher ».
            'a_la_une' => __('À la une du RSN', 'rsn-veille'),
            'tout_le_rsn' => __('Tout ce que le RSN organise et finance →', 'rsn-veille'),
            'evenement_rsn' => __('Événement du RSN', 'rsn-veille'),
            'nouveau' => __('Nouveau', 'rsn-veille'),
            'ferme_dans' => [__('Ferme dans %d jour', 'rsn-veille'), __('Ferme dans %d jours', 'rsn-veille')],
            'ferme_demain' => __('Ferme demain', 'rsn-veille'),
            'ferme_aujourdhui' => __("Ferme aujourd'hui", 'rsn-veille'),
            /* translators: %s : date limite d'un appel. */
            'date_limite_le' => __('Date limite : %s', 'rsn-veille'),
            'dates_limites' => __('Dates limites', 'rsn-veille'),
            'afficher' => __('Afficher', 'rsn-veille'),
            'evenements' => __('Événements', 'rsn-veille'),
            'appels' => __('Appels', 'rsn-veille'),
            'evenement' => __('Événement', 'rsn-veille'),
            /* translators: %s : nom de l'organisme partenaire. */
            'partenaire' => __('Partenaire · %s', 'rsn-veille'),
            /* translators: 1 : nombre d'événements (« 12 événements »), 2 : nombre d'appels (« 3 appels »). */
            'nb_evenements_et_appels' => __('%1$s et %2$s à venir', 'rsn-veille'),
            'nb_elements' => [__('%d élément', 'rsn-veille'), __('%d éléments', 'rsn-veille')],
            'date_limite_puce' => __('Date limite · ', 'rsn-veille'),
            'legende_date_limite' => __("Date limite d'un appel", 'rsn-veille'),
            's_abonner' => __("S'abonner au calendrier", 'rsn-veille'),
            'aucun_element_filtres' => __('Aucun événement ni appel ne correspond à ces filtres.', 'rsn-veille'),
        ];
    }
}

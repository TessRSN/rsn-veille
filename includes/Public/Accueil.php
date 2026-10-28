<?php
declare(strict_types=1);

namespace RSN\Veille\Public;

use RSN\Veille\Collecte;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Code court `[rsn_veille_accueil]` : la bande « Prochains événements » de l'accueil (calendrier du mois, événements
 * du RSN, quatre événements de l'écosystème), d'après la variante A de `docs/maquette/accueil-v1.html`. Le serveur
 * choisit les événements (`selection`, pure) et rend un balisage vide avec les données et les textes en JSON ;
 * `assets/accueil.js` dessine la bande et `assets/accueil.css` la met en forme. Langue de la page : WPML.
 */
final class Accueil {
    public const NOM = 'rsn_veille_accueil';

    /** Nom des ressources CSS et JS de la bande. */
    private const RESSOURCE = 'rsn-veille-accueil';

    /** Au-delà de ce nombre de jours entre le début et la fin, un événement est une « série » (cours, webinaires). */
    private const JOURS_SERIE_LONGUE = 7;

    /** Champs gardés pour le navigateur, dans cet ordre. */
    private const CHAMPS = ['id', 'titre', 'debut', 'fin', 'organisateur', 'organise_par', 'format', 'url', 'ville', 'langue', 'image_url'];

    /** Clés de dates et de formats reprises de `Raccourci::textes()` (pas de duplication). */
    private const CLES_DE_RACCOURCI = ['mois', 'mois_longs', 'jours_courts', 'premier_du_mois', 'date_longue', 'du_au_meme_mois', 'du_au', 'formats', 'en_anglais', 'en_francais'];

    /** Accroche le code court et le chargement des ressources. */
    public static function accrocher(): void {
        add_action('init', [self::class, 'enregistrer']);
        add_action('wp_enqueue_scripts', [self::class, 'charger_si_present']);
    }

    public static function enregistrer(): void {
        add_shortcode(self::NOM, [self::class, 'rendre']);
    }

    /**
     * Ressources en tête de page sur la page d'accueil (la traduction WPML de l'accueil est aussi `is_front_page()`)
     * et sur toute page dont le contenu contient le code court. Sinon, `rendre` les met en file au rendu.
     * Le filtre `rsn_veille_charger_accueil` force ou empêche le chargement.
     */
    public static function charger_si_present(): void {
        if ((bool) apply_filters('rsn_veille_charger_accueil', is_front_page() || self::contenu_demande_a_le_code_court())) {
            self::mettre_en_file();
        }
    }

    private static function contenu_demande_a_le_code_court(): bool {
        $article = get_queried_object();
        return is_singular() && $article instanceof \WP_Post && has_shortcode((string) $article->post_content, self::NOM);
    }

    /** CSS et JS de la bande, versionnés, script en pied de page (déjà en file : sans effet). */
    public static function mettre_en_file(): void {
        wp_enqueue_style(self::RESSOURCE, RSN_VEILLE_URL . 'assets/accueil.css', [], RSN_VEILLE_VERSION);
        wp_enqueue_script(self::RESSOURCE, RSN_VEILLE_URL . 'assets/accueil.js', [], RSN_VEILLE_VERSION, true);
    }

    /**
     * Rendu du code court : trois colonnes vides que le script remplit, les données et les textes en JSON incorporé,
     * un lien vers l'agenda sans JavaScript.
     *
     * @param array<string,mixed>|string $attributs Aucun attribut.
     */
    public static function rendre(mixed $attributs = []): string {
        self::mettre_en_file();

        $langue = Donnees::langue_courante();
        $aujourdhui = \rsn_veille_aujourdhui();
        $agenda = self::url_agenda();
        $donnees = self::selection(self::evenements(Donnees::en_cache()), $aujourdhui) + ['agenda' => $agenda, 'langue' => $langue];
        $json_donnees = Raccourci::json($donnees);
        $json_textes = Raccourci::json(self::textes());

        $au_rsn = esc_html(__('Au RSN', 'rsn-veille'));
        $dans_ecosysteme = esc_html(__("Dans l'écosystème", 'rsn-veille'));
        $tous = esc_html(__('Tous', 'rsn-veille'));
        $voir_agenda = esc_html(__("Voir l'agenda de l'écosystème", 'rsn-veille'));
        $url_agenda = esc_url($agenda);

        return '<div class="rsn-veille-accueil" lang="' . esc_attr($langue) . '" data-aujourdhui="' . esc_attr($aujourdhui) . '">'
            . '<div class="rsna-cal"></div>'
            . '<div class="rsna-rsn"><p class="rsna-sous">' . $au_rsn . '</p><div data-rsn></div></div>'
            . '<div class="rsna-eco"><p class="rsna-sous"><span data-sous>' . $dans_ecosysteme . '</span><button type="button" data-tous hidden>' . $tous . '</button></p><div data-liste></div></div>'
            . '<noscript><p><a href="' . $url_agenda . '">' . $voir_agenda . '</a></p></noscript>'
            . '<script type="application/json" data-rsn-accueil-donnees>' . $json_donnees . '</script>'
            . '<script type="application/json" data-rsn-accueil-textes>' . $json_textes . '</script>'
            . '</div>';
    }

    /**
     * Adresse de la page Veille dans la langue de la page : la page des réglages, ou sa traduction WPML dans la
     * langue courante ; l'accueil du site si aucune page n'est choisie.
     */
    private static function url_agenda(): string {
        $pages = Collecte::pages_veille();
        if ($pages === []) {
            return home_url('/');
        }
        $traduite = apply_filters('wpml_object_id', $pages[0], 'page', true);
        $id = is_numeric($traduite) && (int) $traduite > 0 ? (int) $traduite : $pages[0];
        $url = get_permalink($id);
        return is_string($url) && $url !== '' ? $url : home_url('/');
    }

    /**
     * Éléments du JSON public, sans les valeurs qui ne sont pas des tableaux.
     *
     * @param array<string,mixed> $donnees
     * @return array<int,array<string,mixed>>
     */
    private static function evenements(array $donnees): array {
        $items = $donnees['items'] ?? [];
        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }

    /**
     * Choix des événements de la bande (pure).
     *
     * - `rsn` : tous les événements du RSN à venir, du plus proche au plus lointain ;
     * - `eco` : les autres, le plus proche de chaque organisateur, `$nb_eco` au plus ;
     * - `calendrier` : tous les événements (RSN compris) dont la fin est au plus tôt le premier jour du mois courant.
     *
     * « À venir » : fin (ou début) au plus tôt aujourd'hui. Une série de plus de sept jours déjà commencée est
     * écartée de `rsn` et `eco` (elle reste au calendrier). Les appels et les fiches sans début sont ignorés.
     * Un événement sans organisateur n'est regroupé avec aucun autre.
     *
     * Cette fonction est la référence, testée. `assets/accueil.js` en reprend les règles (même sélection de `rsn` et
     * `eco`) à partir de `calendrier` et de la date du navigateur, car le rendu peut être servi par un cache un jour plus
     * tard : en changer une, c'est changer l'autre.
     *
     * @param array<int,array<string,mixed>> $items Éléments de `Donnees` (événements et appels).
     * @param string $aujourdhui Date du jour, Y-m-d.
     * @return array{rsn:array<int,array<string,mixed>>,eco:array<int,array<string,mixed>>,calendrier:array<int,array<string,mixed>>}
     */
    public static function selection(array $items, string $aujourdhui, int $nb_eco = 4): array {
        $premier_du_mois = substr($aujourdhui, 0, 7) . '-01';
        $evenements = [];
        foreach ($items as $item) {
            if (($item['type'] ?? '') !== 'evenement' || (string) ($item['debut'] ?? '') === '') {
                continue;
            }
            $evenements[] = $item;
        }
        usort($evenements, static function (array $a, array $b): int {
            return [(string) $a['debut'], mb_strtolower((string) ($a['titre'] ?? ''))] <=> [(string) $b['debut'], mb_strtolower((string) ($b['titre'] ?? ''))];
        });

        $rsn = [];
        $eco = [];
        $calendrier = [];
        $organisateurs = [];
        foreach ($evenements as $evenement) {
            $fin = self::fin($evenement);
            if ($fin >= $premier_du_mois) {
                $calendrier[] = self::reduire($evenement);
            }
            if ($fin < $aujourdhui || (self::est_serie_longue($evenement) && (string) $evenement['debut'] < $aujourdhui)) {
                continue;
            }
            if (($evenement['organise_par'] ?? null) === 'rsn') {
                $rsn[] = self::reduire($evenement);
                continue;
            }
            if (count($eco) >= $nb_eco) {
                continue;
            }
            $organisateur = trim((string) ($evenement['organisateur'] ?? ''));
            if ($organisateur !== '') {
                if (isset($organisateurs[$organisateur])) {
                    continue;
                }
                $organisateurs[$organisateur] = true;
            }
            $eco[] = self::reduire($evenement);
        }
        return ['rsn' => $rsn, 'eco' => $eco, 'calendrier' => $calendrier];
    }

    /** Dernier jour de l'événement : sa fin si elle suit le début, sinon son début. */
    private static function fin(array $evenement): string {
        $debut = (string) $evenement['debut'];
        $fin = (string) ($evenement['fin'] ?? '');
        return $fin > $debut ? $fin : $debut;
    }

    private static function est_serie_longue(array $evenement): bool {
        $debut = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $evenement['debut']);
        $fin = \DateTimeImmutable::createFromFormat('!Y-m-d', self::fin($evenement));
        return $debut !== false && $fin !== false && (int) $debut->diff($fin)->days > self::JOURS_SERIE_LONGUE;
    }

    /**
     * @param array<string,mixed> $evenement
     * @return array<string,mixed>
     */
    private static function reduire(array $evenement): array {
        $reduit = [];
        foreach (self::CHAMPS as $champ) {
            $reduit[$champ] = $evenement[$champ] ?? null;
        }
        return $reduit;
    }

    /**
     * Textes du JS, traduisibles : les clés de dates de `Raccourci::textes()` et celles de la bande. Pluriels :
     * [singulier, pluriel] (« %d » reçoit le nombre) ; « %s » ou « %1$s »… reçoivent les valeurs.
     *
     * @return array<string,mixed>
     */
    public static function textes(): array {
        $dates = array_intersect_key(Raccourci::textes(), array_flip(self::CLES_DE_RACCOURCI));
        return $dates + [
            'au_rsn' => __('Au RSN', 'rsn-veille'),
            'dans_ecosysteme' => __("Dans l'écosystème", 'rsn-veille'),
            /* translators: %s : date du jour choisi dans le calendrier (« 4 novembre »). */
            'le_jour' => __('Le %s', 'rsn-veille'),
            'tous' => __('Tous', 'rsn-veille'),
            'aucun_jour' => __('Aucun événement ce jour-là.', 'rsn-veille'),
            'aucun_eco' => __("Aucun événement de l'écosystème à venir.", 'rsn-veille'),
            'aucun_rsn' => __("Pas d'événement du RSN à venir pour le moment. Les prochains seront annoncés ici.", 'rsn-veille'),
            'autres_rsn' => [__('+ %d autre événement du RSN →', 'rsn-veille'), __('+ %d autres événements du RSN →', 'rsn-veille')],
            'mois_precedent' => __('Mois précédent', 'rsn-veille'),
            'mois_suivant' => __('Mois suivant', 'rsn-veille'),
            'legende_rsn' => __('RSN', 'rsn-veille'),
            'legende_ecosysteme' => __('Écosystème', 'rsn-veille'),
            'rsn' => __('RSN', 'rsn-veille'),
        ];
    }
}

<?php
declare(strict_types=1);

namespace RSN\Veille;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Type de contenu `rsn_veille`, taxonomie `rsn_theme` et métadonnées des fiches.
 *
 * Le titre, le résumé et le statut sont portés par le post (`post_title`, `post_excerpt`,
 * `post_status`), les thèmes par la taxonomie, tout le reste par des métadonnées `rsn_veille_*`.
 */
final class TypeContenu {
    public const TYPE = 'rsn_veille';
    public const TAXONOMIE = 'rsn_theme';
    public const PREFIXE = 'rsn_veille_';

    /** Méta non unique : une ligne par clé de source, pour une recherche exacte (les tableaux sérialisés se cherchent mal). */
    public const META_CLE = 'rsn_veille_cle';

    /** Méta de la clé de dédoublonnage (`Fiche::cle_dedoublonnage()`), recalculée quand le titre ou le début changent. */
    public const META_DEDOUBLONNAGE = 'rsn_veille_cle_dedoublonnage';

    /** Statut d'une fiche => statut WordPress. */
    public const STATUTS = ['publie' => 'publish', 'a_approuver' => 'pending', 'ecarte' => 'draft'];

    /**
     * Champs stockés en métadonnées (préfixe `rsn_veille_`) et leur type WordPress.
     * `courriel_proposant` n'est pas une propriété de `Fiche` : il reste privé à l'équipe.
     */
    public const METAS = [
        'type' => 'string',
        'debut' => 'string',
        'fin' => 'string',
        'heure' => 'string',
        'organisateur' => 'string',
        'categorie_rsn' => 'string',
        'lieu' => 'string',
        'ville' => 'string',
        'region' => 'string',
        'format' => 'string',
        'prix' => 'string',
        'langue' => 'string',
        'url' => 'string',
        'image_url' => 'string',
        'source' => 'string',
        'niveau' => 'string',
        'cle_source' => 'string',
        'cles_source' => 'array',
        'titres_source' => 'object',
        'categorie' => 'string',
        'echeance' => 'string',
        'titre_en' => 'string',
        'resume_en' => 'string',
        'url_en' => 'string',
        'absent_depuis' => 'string',
        'absences' => 'integer',
        'manuel' => 'boolean',
        'cree_le' => 'string',
        'courriel_proposant' => 'string',
    ];

    /** Enregistre le type de contenu, la taxonomie et les métadonnées (sur `init`). */
    public static function enregistrer(): void {
        register_post_type(self::TYPE, [
            'labels' => [
                'name' => __('Fiches de veille', 'rsn-veille'),
                'singular_name' => __('Fiche de veille', 'rsn-veille'),
                'add_new' => __('Ajouter', 'rsn-veille'),
                'add_new_item' => __('Ajouter une fiche de veille', 'rsn-veille'),
                'edit_item' => __('Modifier la fiche de veille', 'rsn-veille'),
                'new_item' => __('Nouvelle fiche de veille', 'rsn-veille'),
                'view_item' => __('Voir la fiche de veille', 'rsn-veille'),
                'search_items' => __('Rechercher des fiches de veille', 'rsn-veille'),
                'not_found' => __('Aucune fiche de veille', 'rsn-veille'),
                'not_found_in_trash' => __('Aucune fiche de veille dans la corbeille', 'rsn-veille'),
                'all_items' => __('Toutes les fiches de veille', 'rsn-veille'),
            ],
            'public' => false,
            'publicly_queryable' => false,
            'exclude_from_search' => true,
            'show_ui' => true,
            'show_in_menu' => false,
            'show_in_nav_menus' => false,
            'show_in_admin_bar' => false,
            'show_in_rest' => false,
            'has_archive' => false,
            'rewrite' => false,
            'query_var' => false,
            'delete_with_user' => false,
            'supports' => ['title', 'editor', 'excerpt', 'thumbnail'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'taxonomies' => [self::TAXONOMIE],
        ]);

        register_taxonomy(self::TAXONOMIE, [self::TYPE], [
            'labels' => [
                'name' => __('Thèmes', 'rsn-veille'),
                'singular_name' => __('Thème', 'rsn-veille'),
                'search_items' => __('Rechercher des thèmes', 'rsn-veille'),
                'all_items' => __('Tous les thèmes', 'rsn-veille'),
                'edit_item' => __('Modifier le thème', 'rsn-veille'),
                'update_item' => __('Mettre à jour le thème', 'rsn-veille'),
                'add_new_item' => __('Ajouter un thème', 'rsn-veille'),
                'new_item_name' => __('Nom du nouveau thème', 'rsn-veille'),
                'separate_items_with_commas' => __('Séparer les thèmes par des virgules', 'rsn-veille'),
                'choose_from_most_used' => __('Choisir parmi les thèmes les plus utilisés', 'rsn-veille'),
                'not_found' => __('Aucun thème', 'rsn-veille'),
            ],
            'hierarchical' => false,
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'show_in_nav_menus' => false,
            'show_tagcloud' => false,
            'show_admin_column' => false,
            'show_in_rest' => false,
            'rewrite' => false,
            'query_var' => false,
            // Garde l'ordre des thèmes (priorité d'affichage) dans term_order.
            'sort' => true,
        ]);

        foreach (self::METAS as $nom => $type) {
            register_post_meta(self::TYPE, self::PREFIXE . $nom, [
                'type' => $type,
                'single' => true,
                'show_in_rest' => false,
                'sanitize_callback' => [self::class, 'nettoyer_meta_enregistree'],
                'auth_callback' => [self::class, 'peut_modifier'],
            ]);
        }
        register_post_meta(self::TYPE, self::META_DEDOUBLONNAGE, [
            'type' => 'string',
            'single' => true,
            'show_in_rest' => false,
            'auth_callback' => [self::class, 'peut_modifier'],
        ]);
        register_post_meta(self::TYPE, self::META_CLE, [
            'type' => 'string',
            'single' => false,
            'show_in_rest' => false,
            'auth_callback' => [self::class, 'peut_modifier'],
        ]);

        add_filter('wp_insert_post_data', [self::class, 'jamais_planifiee']);
    }

    /** Statut WordPress d'un statut de fiche. */
    public static function statut_wp(string $statut): string {
        if (!isset(self::STATUTS[$statut])) {
            throw new \InvalidArgumentException('Statut de fiche inconnu : ' . $statut);
        }
        return self::STATUTS[$statut];
    }

    /** Statut de fiche d'un statut WordPress (une fiche planifiée compte comme publiée, le reste comme écarté). */
    public static function statut_fiche(string $statut_wp): string {
        $statut = array_search($statut_wp, self::STATUTS, true);
        if ($statut !== false) {
            return $statut;
        }
        return $statut_wp === 'future' ? 'publie' : 'ecarte';
    }

    /**
     * Valeur nettoyée d'un champ avant écriture. Les clés de source et les titres vus à la source
     * (`titres_source`) ne sont que contrôlés en UTF-8 : ils sont comparés exactement aux valeurs des
     * collectes suivantes. `sanitize_text_field` (qui retire les `%xx` et change un `<` isolé en
     * `&lt;`) créerait des doublons de clés et réécrirait `titres_source` à chaque collecte.
     */
    public static function nettoyer_meta(string $nom, mixed $valeur): mixed {
        switch ($nom) {
            case 'url':
            case 'url_en':
            case 'image_url':
                return esc_url_raw(self::chaine($valeur));
            case 'absences':
                return (int) $valeur;
            case 'manuel':
                return $valeur ? '1' : '0';
            case 'courriel_proposant':
                return sanitize_email(self::chaine($valeur));
            case 'cle_source':
                return self::brut($valeur);
            case 'cles_source':
                $cles = array_map(static fn($cle): string => self::brut($cle), is_array($valeur) ? $valeur : []);
                return array_values(array_unique(array_filter($cles, static fn(string $c): bool => $c !== '')));
            case 'titres_source':
                $titres = [];
                foreach (is_array($valeur) ? $valeur : [] as $cle => $titre) {
                    $titres[self::brut($cle)] = self::brut($titre);
                }
                return $titres;
            default:
                return sanitize_text_field(self::chaine($valeur));
        }
    }

    /** Rappel `sanitize_callback` de `register_post_meta` : nettoie selon le nom de la méta. */
    public static function nettoyer_meta_enregistree(mixed $valeur, string $cle_meta = ''): mixed {
        return self::nettoyer_meta(substr($cle_meta, strlen(self::PREFIXE)), $valeur);
    }

    /** Rappel `auth_callback` : modifier une méta de fiche exige de pouvoir modifier la fiche. */
    public static function peut_modifier(mixed $autorise, string $cle_meta = '', mixed $id_objet = 0, mixed $id_utilisateur = 0): bool {
        return user_can((int) $id_utilisateur, 'edit_post', (int) $id_objet);
    }

    /**
     * Filtre `wp_insert_post_data` : une fiche n'est jamais planifiée. Sa date de publication est la
     * date de début de l'événement (pour les tris), souvent future, et WordPress transformerait sinon
     * « publish » en « future ».
     *
     * @param array<string,mixed> $donnees
     * @return array<string,mixed>
     */
    public static function jamais_planifiee(array $donnees): array {
        if (($donnees['post_type'] ?? '') === self::TYPE && ($donnees['post_status'] ?? '') === 'future') {
            $donnees['post_status'] = 'publish';
        }
        return $donnees;
    }

    /** Chaîne gardée telle quelle si elle est en UTF-8 valide, vide sinon. */
    private static function brut(mixed $valeur): string {
        return wp_check_invalid_utf8(self::chaine($valeur));
    }

    /** Valeur scalaire en chaîne, chaîne vide sinon. */
    private static function chaine(mixed $valeur): string {
        return is_scalar($valeur) ? (string) $valeur : '';
    }
}

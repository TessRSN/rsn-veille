<?php
declare(strict_types=1);

namespace RSN\Veille\Public;

use RSN\Veille\Fiche;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Événements du RSN lus directement dans The Events Calendar (TEC) du site, au format fiche.
 *
 * Sans TEC (fonction `tribe_get_events` absente), rien : la page montre alors seulement les fiches
 * collectées (dont celles de la source « RSN : événements », lue par l'API publique du site).
 * Non testable sans WordPress : vérification dans `tests/manuel/verifier-public.md`.
 */
final class EvenementsRsn {
    /** Taxonomie des catégories d'événements de TEC. */
    private const TAXONOMIE = 'tribe_events_cat';

    /** Catégories du site reconnues (« Qui organise »), par ordre de préférence quand un événement en a plusieurs. */
    private const ORDRE = ['rsn', 'partenaires'];

    /**
     * Événements publiés depuis $depuis (passés compris), au format fiche (`source = 'RSN'`).
     *
     * WPML duplique chaque événement en un article par langue : un seul est gardé par groupe de traduction,
     * dans $langue (défaut : langue courante), sinon en français, sinon le premier. Chaque fiche porte aussi
     * `tec_trid` (groupe de traduction, ou null) et `titre_fr` (titre de l'article français du groupe, sinon
     * celui de l'article gardé).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function lire(string $depuis = '2025-09-01', ?string $langue = null): array {
        if (!function_exists('tribe_get_events')) {
            return [];
        }
        $articles = tribe_get_events([
            'eventDisplay' => 'custom',
            'start_date' => $depuis . ' 00:00:00',
            'posts_per_page' => -1,
            'post_status' => 'publish',
        ]);
        if (!is_array($articles)) {
            return [];
        }
        $langue = $langue === 'en' ? 'en' : ($langue === 'fr' ? 'fr' : Donnees::langue_courante());
        $articles = array_values(array_filter($articles, static fn ($article): bool => $article instanceof \WP_Post));

        $trid = static function (int $id): ?int {
            $valeur = apply_filters('wpml_element_trid', null, $id, 'post_tribe_events');
            return (is_int($valeur) || (is_string($valeur) && ctype_digit($valeur))) && (int) $valeur > 0 ? (int) $valeur : null;
        };
        $langue_de = static function (int $id): ?string {
            $details = apply_filters('wpml_post_language_details', null, $id);
            $code = is_array($details) ? ($details['language_code'] ?? null) : null;
            return is_string($code) && $code !== '' ? $code : null;
        };

        $evenements = [];
        foreach (self::choisir_par_groupe($articles, $langue, $trid, $langue_de) as $groupe) {
            $titre_fr = Fiche::nettoyer_texte(get_the_title($groupe['francais'] ?? $groupe['garde']));
            $evenement = self::vers_fiche($groupe['garde'], $groupe['trid'], $titre_fr, $langue_de((int) $groupe['garde']->ID));
            if ($evenement !== null) {
                $evenements[] = $evenement;
            }
        }
        return $evenements;
    }

    /**
     * Regroupe les articles par groupe de traduction (clé `trid`, ou `id:<ID>` sans groupe) et garde, par groupe,
     * l'article en $langue, sinon en français, sinon le premier. L'ordre de sortie est celui de la première
     * apparition de chaque groupe.
     *
     * @param array<int,object> $articles Objets portant `ID` (WP_Post).
     * @param callable(int):?int $trid Groupe de traduction d'un article, null sans WPML.
     * @param callable(int):?string $langue_de Code de langue d'un article, null si inconnu.
     * @return array<int,array{garde: object, francais: ?object, trid: ?int}>
     */
    public static function choisir_par_groupe(array $articles, string $langue, callable $trid, callable $langue_de): array {
        /** @var array<string,array{trid: ?int, membres: array<int,array{0: object, 1: ?string}>}> $groupes */
        $groupes = [];
        foreach ($articles as $article) {
            $id = (int) $article->ID;
            $groupe = $trid($id);
            $cle = $groupe !== null ? (string) $groupe : 'id:' . $id;
            $groupes[$cle] ??= ['trid' => $groupe, 'membres' => []];
            $groupes[$cle]['membres'][] = [$article, $langue_de($id)];
        }
        $choix = [];
        foreach ($groupes as $groupe) {
            $garde = null;
            $francais = null;
            foreach ($groupe['membres'] as [$article, $code]) {
                if ($code === 'fr') {
                    $francais ??= $article;
                }
            }
            foreach ($groupe['membres'] as [$article, $code]) {
                if ($code === $langue) {
                    $garde = $article;
                    break;
                }
            }
            $choix[] = ['garde' => $garde ?? $francais ?? $groupe['membres'][0][0], 'francais' => $francais, 'trid' => $groupe['trid']];
        }
        return $choix;
    }

    /** @return array<string,mixed>|null */
    private static function vers_fiche(\WP_Post $article, ?int $trid, string $titre_fr, ?string $langue_article): ?array {
        $debut = (string) tribe_get_start_date($article, false, 'Y-m-d');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $debut) !== 1) {
            return null;
        }
        $fin = function_exists('tribe_get_end_date') ? (string) tribe_get_end_date($article, false, 'Y-m-d') : '';
        $journee = function_exists('tribe_event_is_all_day') && tribe_event_is_all_day($article->ID);
        $heure = $journee ? '' : (string) tribe_get_start_date($article, false, 'H:i');

        $lieu = function_exists('tribe_get_venue') ? Fiche::nettoyer_texte((string) tribe_get_venue($article->ID)) : '';
        $ville = function_exists('tribe_get_city') ? Fiche::nettoyer_texte((string) tribe_get_city($article->ID)) : '';
        $organisateur = function_exists('tribe_get_organizer') ? Fiche::nettoyer_texte((string) tribe_get_organizer($article->ID)) : '';
        $titre = Fiche::nettoyer_texte(get_the_title($article));
        $extrait = $article->post_excerpt !== '' ? $article->post_excerpt : $article->post_content;
        $resume = Fiche::court(Fiche::nettoyer_texte(strip_shortcodes($extrait)));

        // Événement virtuel (extension Virtual Events de TEC) sinon format deviné du lieu et du titre.
        $virtuel = (bool) get_post_meta($article->ID, '_tribe_events_is_virtual', true);
        $format = Fiche::deviner_format($lieu . ' ' . $ville . ' ' . $titre, $virtuel);
        if ($virtuel && $lieu !== '') {
            $format = 'Hybride';
        }
        $image = get_the_post_thumbnail_url($article, 'medium_large');
        [$categorie, $organise_par] = self::categorie($article);
        $prix = function_exists('tribe_get_cost') ? Fiche::nettoyer_texte((string) tribe_get_cost($article->ID, true)) : '';

        return [
            'type' => 'evenement',
            'titre' => $titre,
            'debut' => $debut,
            'fin' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $fin) === 1 && $fin >= $debut ? $fin : $debut,
            'heure' => preg_match('/^\d{2}:\d{2}$/', $heure) === 1 ? $heure : '',
            'organisateur' => $organisateur !== '' ? $organisateur : 'RSN',
            'categorie_rsn' => $categorie,
            'organise_par' => $organise_par,
            'lieu' => $lieu,
            'ville' => $ville,
            'region' => Fiche::deviner_region($ville, $lieu, $format),
            'format' => $format,
            'prix' => $prix,
            'langue' => $langue_article === 'en' ? 'en' : 'fr',
            'themes' => [],
            'resume' => $resume,
            'url' => (string) get_permalink($article),
            'image_url' => is_string($image) ? $image : '',
            'source' => 'RSN',
            'niveau' => 'A',
            'statut' => 'publie',
            'cle_source' => 'rsn-tec:' . $article->ID,
            'tec_trid' => $trid,
            'titre_fr' => $titre_fr !== '' ? $titre_fr : $titre,
        ];
    }

    /**
     * Catégorie de l'événement et « Qui organise », reconnus par le slug du terme (`evenements-rsn`,
     * `evenements-partenaires`, traductions WPML suffixées comprises) ou par son nom : RSN d'abord, puis
     * partenaires, sinon le premier terme (écosystème). Sans catégorie : « Événements RSN ».
     *
     * @return array{0: string, 1: string}
     */
    private static function categorie(\WP_Post $article): array {
        $termes = get_the_terms($article, self::TAXONOMIE);
        $termes = is_array($termes) ? array_values($termes) : [];
        if ($termes === []) {
            return ['Événements RSN', 'rsn'];
        }
        $reconnus = [];
        foreach ($termes as $terme) {
            $par_slug = Donnees::organise_par((string) ($terme->slug ?? ''));
            $par_nom = Donnees::organise_par((string) ($terme->name ?? ''));
            $qui = $par_slug !== 'ecosysteme' ? $par_slug : $par_nom;
            $reconnus[$qui] ??= Fiche::nettoyer_texte((string) ($terme->name ?? ''));
        }
        foreach (self::ORDRE as $qui) {
            if (isset($reconnus[$qui])) {
                return [$reconnus[$qui], $qui];
            }
        }
        return [Fiche::nettoyer_texte((string) ($termes[0]->name ?? '')), 'ecosysteme'];
    }
}

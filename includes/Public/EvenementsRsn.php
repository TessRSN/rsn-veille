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

    /** Catégories du site reconnues, par ordre de préférence quand un événement en a plusieurs. */
    private const CATEGORIES = ['Événements RSN', 'Événements partenaires', 'Écosystème numérique'];

    /**
     * Événements publiés depuis $depuis (passés compris), au format fiche (`source = 'RSN'`).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function lire(string $depuis = '2025-09-01'): array {
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
        $evenements = [];
        foreach ($articles as $article) {
            if ($article instanceof \WP_Post) {
                $evenement = self::vers_fiche($article);
                if ($evenement !== null) {
                    $evenements[] = $evenement;
                }
            }
        }
        return $evenements;
    }

    /** @return array<string,mixed>|null */
    private static function vers_fiche(\WP_Post $article): ?array {
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
        $prix = function_exists('tribe_get_cost') ? Fiche::nettoyer_texte((string) tribe_get_cost($article->ID, true)) : '';

        return [
            'type' => 'evenement',
            'titre' => $titre,
            'debut' => $debut,
            'fin' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $fin) === 1 && $fin >= $debut ? $fin : $debut,
            'heure' => preg_match('/^\d{2}:\d{2}$/', $heure) === 1 ? $heure : '',
            'organisateur' => $organisateur !== '' ? $organisateur : 'RSN',
            'categorie_rsn' => self::categorie($article),
            'lieu' => $lieu,
            'ville' => $ville,
            'region' => Fiche::deviner_region($ville, $lieu, $format),
            'format' => $format,
            'prix' => $prix,
            'langue' => 'fr',
            'themes' => [],
            'resume' => $resume,
            'url' => (string) get_permalink($article),
            'image_url' => is_string($image) ? $image : '',
            'source' => 'RSN',
            'niveau' => 'A',
            'statut' => 'publie',
            'cle_source' => 'rsn-tec:' . $article->ID,
        ];
    }

    /** Catégorie RSN reconnue parmi celles de l'événement, sinon la première, sinon « Événements RSN ». */
    private static function categorie(\WP_Post $article): string {
        $termes = get_the_terms($article, self::TAXONOMIE);
        $noms = is_array($termes) ? array_map(static fn($t): string => Fiche::nettoyer_texte((string) $t->name), $termes) : [];
        foreach (self::CATEGORIES as $categorie) {
            if (in_array($categorie, $noms, true)) {
                return $categorie;
            }
        }
        return $noms[0] ?? 'Événements RSN';
    }
}

<?php
declare(strict_types=1);

namespace RSN\Veille\Admin;

use RSN\Veille\Collecte;
use RSN\Veille\Journal;
use RSN\Veille\Sources;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Journal des collectes : date de la dernière collecte terminée, tableau de la dernière entrée de
 * chaque source, puis le déroulé (60 entrées au plus) de chaque source, replié.
 */
final class PageJournal {
    /** Compteurs affichés, dans l'ordre des colonnes. */
    private const COMPTEURS = ['lus', 'crees', 'fusionnes', 'mis_a_jour', 'inchanges', 'ecartes', 'ignores'];

    /** Affiche la page (rappel du menu). */
    public static function afficher(): void {
        if (!current_user_can(Menu::CAPACITE)) {
            wp_die(esc_html__("Vous n'avez pas accès à cette page.", 'rsn-veille'), '', 403);
        }
        $derniere = get_option(Collecte::OPTION_DERNIERE, 0);
        $lisible = is_numeric($derniere) && (int) $derniere > 0 ? Dates::date_heure(wp_date('Y-m-d H:i', (int) $derniere)) : '';
        // Sortie entièrement échappée par les méthodes de rendu.
        $journal = Journal::tout();
        // Sources actives, puis les sources désactivées qui ont un journal (sous leur nom).
        $sources = Collecte::sources() + array_intersect_key(Sources::par_defaut(), $journal);
        echo self::page_html(self::resume($journal, $sources), $lisible);
    }

    /**
     * Une ligne par source du registre (dans son ordre), puis par source qui n'y est plus mais a un
     * journal. Les flux de nouvelles, que la collecte de nuit ne lit pas, n'apparaissent que s'ils ont
     * un journal.
     *
     * @param array<string,mixed> $journal `Journal::tout()`
     * @param array<string,array<string,mixed>> $sources
     * @return array<int,array{id: string, nom: string, entree: ?array, historique: array<int,array>}>
     */
    public static function resume(array $journal, array $sources): array {
        $lignes = [];
        foreach ($sources as $id => $source) {
            $id = (string) $id;
            if (!is_array($source) || (!empty($source['categorie_nouvelles']) && empty($journal[$id]))) {
                continue;
            }
            $lignes[$id] = ['id' => $id, 'nom' => (string) ($source['nom'] ?? $id), 'entree' => null, 'historique' => []];
        }
        foreach ($journal as $id => $entrees) {
            $id = (string) $id;
            $lignes[$id] ??= ['id' => $id, 'nom' => $id, 'entree' => null, 'historique' => []];
            $entrees = is_array($entrees) ? array_values(array_filter($entrees, 'is_array')) : [];
            $lignes[$id]['historique'] = array_slice($entrees, 0, Journal::MAX);
            $lignes[$id]['entree'] = $entrees[0] ?? null;
        }
        return array_values($lignes);
    }

    /**
     * Ligne d'une source : nom, puis date, compteurs, erreurs (repliées) et durée de son entrée, ou
     * « jamais collectée ».
     *
     * @param array<string,mixed>|null $entree
     */
    public static function ligne_html(string $id_source, string $nom, ?array $entree): string {
        $tete = '<th scope="row">' . esc_html($nom) . ' <code>' . esc_html($id_source) . '</code></th>';
        if ($entree === null) {
            return '<tr>' . $tete . '<td colspan="' . (count(self::COMPTEURS) + 3) . '"><em>'
                . esc_html__('jamais collectée', 'rsn-veille') . '</em></td></tr>';
        }
        return '<tr>' . $tete . self::cellules_html($entree) . '</tr>';
    }

    /**
     * @param array<int,array{id: string, nom: string, entree: ?array, historique: array<int,array>}> $lignes `resume()`
     * @param string $derniere Date lisible de la dernière collecte terminée, vide si aucune.
     */
    public static function page_html(array $lignes, string $derniere): string {
        $texte = $derniere !== ''
            /* translators: %s : date et heure de la dernière collecte terminée. */
            ? sprintf(__('Dernière collecte terminée : %s', 'rsn-veille'), $derniere)
            : __('Aucune collecte terminée pour l’instant.', 'rsn-veille');
        $corps = '';
        foreach ($lignes as $ligne) {
            $corps .= self::ligne_html($ligne['id'], $ligne['nom'], $ligne['entree']);
        }
        return '<div class="wrap rsn-veille-admin">'
            . '<h1>' . esc_html__('Journal des collectes', 'rsn-veille') . '</h1>'
            . '<p class="rsn-veille-derniere">' . esc_html($texte) . '</p>'
            . '<h2>' . esc_html__('Dernière collecte par source', 'rsn-veille') . '</h2>'
            . self::tableau_html(__('Source', 'rsn-veille'), $corps)
            . self::deroule_html($lignes)
            . '</div>';
    }

    /** Déroulé des entrées de chaque source collectée, replié. */
    private static function deroule_html(array $lignes): string {
        $html = '';
        foreach ($lignes as $ligne) {
            if ($ligne['historique'] === []) {
                continue;
            }
            $corps = '';
            foreach ($ligne['historique'] as $entree) {
                $corps .= '<tr>' . self::cellules_html($entree) . '</tr>';
            }
            /* translators: 1 : nom de la source ; 2 : nombre d'entrées. */
            $resume = sprintf(_n('%1$s (%2$d entrée)', '%1$s (%2$d entrées)', count($ligne['historique']), 'rsn-veille'), $ligne['nom'], count($ligne['historique']));
            $html .= '<details class="rsn-veille-deroule"><summary>' . esc_html($resume) . '</summary>' . self::tableau_html('', $corps, false) . '</details>';
        }
        return $html === '' ? '' : '<h2>' . esc_html__('Déroulé par source', 'rsn-veille') . '</h2>' . $html;
    }

    /** Tableau du journal ; $avec_source : première colonne pour le nom de la source. */
    private static function tableau_html(string $premiere, string $corps, bool $avec_source = true): string {
        $colonnes = [
            __('Date', 'rsn-veille'), __('Lus', 'rsn-veille'), __('Créés', 'rsn-veille'), __('Fusionnés', 'rsn-veille'),
            __('Mis à jour', 'rsn-veille'), __('Inchangés', 'rsn-veille'), __('Écartés', 'rsn-veille'), __('Ignorés', 'rsn-veille'),
            __('Erreurs', 'rsn-veille'), __('Durée', 'rsn-veille'),
        ];
        if ($avec_source) {
            array_unshift($colonnes, $premiere);
        }
        $entete = '';
        foreach ($colonnes as $colonne) {
            $entete .= '<th scope="col">' . esc_html($colonne) . '</th>';
        }
        return '<table class="wp-list-table widefat striped rsn-veille-journal"><thead><tr>' . $entete . '</tr></thead><tbody>' . $corps . '</tbody></table>';
    }

    /** Cellules d'une entrée : date, compteurs, erreurs, durée. Valeurs illisibles tolérées. */
    private static function cellules_html(array $entree): string {
        $date = is_string($entree['date'] ?? null) ? $entree['date'] : '';
        $lisible = Dates::date_heure($date);
        $html = '<td>' . esc_html($lisible !== '' ? $lisible : $date) . '</td>';
        foreach (self::COMPTEURS as $compteur) {
            $valeur = $entree[$compteur] ?? 0;
            $html .= '<td class="num">' . (is_numeric($valeur) ? (int) $valeur : 0) . '</td>';
        }
        $erreurs = is_array($entree['erreurs'] ?? null) ? array_filter($entree['erreurs'], 'is_scalar') : [];
        $duree = $entree['duree_ms'] ?? 0;
        return $html . '<td>' . self::anglais_html($entree) . self::erreurs_html($erreurs) . '</td>'
            . '<td>' . esc_html(self::duree(is_numeric($duree) ? (int) $duree : 0)) . '</td>';
    }

    /**
     * Appariement de la version anglaise (Mila, IVADO), au-dessus des erreurs : « 3 appariées en anglais,
     * 1 orpheline ». Vide si l'entrée n'en consigne pas (source sans version anglaise, entrée ancienne).
     */
    private static function anglais_html(array $entree): string {
        if (!array_key_exists('appariees_en', $entree) && !array_key_exists('orphelines_en', $entree)) {
            return '';
        }
        $appariees = is_numeric($entree['appariees_en'] ?? null) ? (int) $entree['appariees_en'] : 0;
        $orphelines = is_numeric($entree['orphelines_en'] ?? null) ? (int) $entree['orphelines_en'] : 0;
        /* translators: %d : nombre d'événements appariés à leur version anglaise. */
        $texte = sprintf(_n('%d appariée en anglais', '%d appariées en anglais', $appariees, 'rsn-veille'), $appariees);
        /* translators: %d : nombre d'événements anglais sans équivalent français. */
        $texte .= ', ' . sprintf(_n('%d orpheline', '%d orphelines', $orphelines, 'rsn-veille'), $orphelines);
        return '<div class="rsn-veille-anglais">' . esc_html($texte) . '</div>';
    }

    /** @param array<int|string,scalar> $erreurs Liste repliée sous « N erreurs », ou « Aucune ». */
    private static function erreurs_html(array $erreurs): string {
        if ($erreurs === []) {
            return esc_html__('Aucune', 'rsn-veille');
        }
        $liste = '';
        foreach ($erreurs as $erreur) {
            $liste .= '<li>' . esc_html((string) $erreur) . '</li>';
        }
        /* translators: %d : nombre d'erreurs. */
        $resume = sprintf(_n('%d erreur', '%d erreurs', count($erreurs), 'rsn-veille'), count($erreurs));
        return '<details><summary>' . esc_html($resume) . '</summary><ul>' . $liste . '</ul></details>';
    }

    /** « 1,5 s », « 2 min 05 s ». */
    private static function duree(int $millisecondes): string {
        $secondes = max(0, $millisecondes) / 1000;
        if ($secondes < 60) {
            /* translators: %s : durée en secondes, une décimale. */
            return sprintf(__('%s s', 'rsn-veille'), str_replace('.', ',', (string) round($secondes, 1)));
        }
        $entieres = (int) round($secondes);
        /* translators: 1 : minutes ; 2 : secondes. */
        return sprintf(__('%1$d min %2$02d s', 'rsn-veille'), intdiv($entieres, 60), $entieres % 60);
    }
}

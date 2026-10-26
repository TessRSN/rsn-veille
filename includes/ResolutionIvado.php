<?php
declare(strict_types=1);

namespace RSN\Veille;

use RSN\Veille\Lecteurs\LecteurIvado;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Complète les fiches incomplètes d'IVADO (titre vide, URL « ?p=id ») en téléchargeant leur page.
 *
 * Une fiche déjà enregistrée sous la même clé de source n'est pas redemandée : elle reprend le titre
 * vu à la source et l'URL de la fiche enregistrée (sinon l'URL courte écraserait l'URL finale). Les
 * autres sont résolues dans l'ordre du calendrier, au plus `$max` par collecte ; le reste attend les
 * collectes suivantes. Une page en erreur ou sans titre écarte la fiche, avec une erreur.
 */
final class ResolutionIvado {
    /** Résolutions au plus par collecte. */
    public const MAX = 25;

    /**
     * @param Fiche[] $fiches Fiches rendues par LecteurIvado.
     * @param array $source Entrée du registre, avec `flux_evenements` (contenu XML) si le flux a été téléchargé.
     * @param callable(string, array): array $obtenir Client HTTP au format de `Http::obtenir`.
     * @return array{fiches: Fiche[], erreurs: string[], en_attente: int}
     */
    public static function resoudre(array $fiches, array $source, Depot $depot, callable $obtenir, int $max = self::MAX): array {
        $resumes = null;
        $gardees = [];
        $erreurs = [];
        $en_attente = 0;
        $resolutions = 0;
        foreach ($fiches as $fiche) {
            if ($fiche->titre !== '') {
                $gardees[] = $fiche;
                continue;
            }
            $connue = $depot->fiche_de_cle($fiche);
            if ($connue !== null) {
                $fiche->titre = $connue->titres_source[$fiche->cle_source] ?? $connue->titre;
                if ($connue->url !== '') {
                    $fiche->url = $connue->url;
                }
                $gardees[] = $fiche;
                continue;
            }
            if ($resolutions >= $max) {
                $en_attente++;
                continue;
            }
            $resolutions++;
            try {
                $page = $obtenir($fiche->url, ['accept' => 'html']);
                if (($page['erreur'] ?? null) !== null || !Http::est_html($page)) {
                    /* translators: 1 : adresse de la page, 2 : erreur. */
                    $erreurs[] = sprintf(__('Page IVADO %1$s illisible : %2$s', 'rsn-veille'), $fiche->url, $page['erreur'] ?? __('contenu inattendu', 'rsn-veille'));
                    continue;
                }
                $resumes ??= LecteurIvado::resumes_par_url($source);
                $url_courte = $fiche->url;
                LecteurIvado::completer($fiche, (string) $page['corps'], (string) (($page['url_finale'] ?? '') ?: $url_courte), $resumes);
                if ($fiche->titre === '') {
                    /* translators: %s : adresse de la page. */
                    $erreurs[] = sprintf(__('Page IVADO %s sans titre : fiche ignorée', 'rsn-veille'), $url_courte);
                    continue;
                }
                $gardees[] = $fiche;
            } catch (\Throwable $e) {
                /* translators: 1 : adresse de la page, 2 : message de l'exception. */
                $erreurs[] = sprintf(__('Page IVADO %1$s : %2$s', 'rsn-veille'), $fiche->url, $e->getMessage());
            }
        }
        return ['fiches' => $gardees, 'erreurs' => $erreurs, 'en_attente' => $en_attente];
    }
}

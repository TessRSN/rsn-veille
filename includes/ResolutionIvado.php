<?php
declare(strict_types=1);

namespace RSN\Veille;

use RSN\Veille\Lecteurs\LecteurIvado;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Complète les fiches incomplètes d'IVADO (titre vide, URL « ?p=id ») en téléchargeant leur page, et
 * fait intégrer chaque fiche dès qu'elle est prête : une exécution coupée par `max_execution_time`
 * garde ce qui est déjà résolu.
 *
 * Une fiche déjà enregistrée sous la même clé de source n'est pas redemandée : elle reprend le titre
 * vu à la source et l'URL de la fiche enregistrée (sinon l'URL courte écraserait l'URL finale), et
 * elle est intégrée tout de suite. Les autres sont résolues dans l'ordre du calendrier, au plus `$max`
 * par collecte et tant que le budget de temps n'est pas épuisé ; le reste attend les collectes
 * suivantes. Une page en erreur ou sans titre écarte la fiche, avec une erreur.
 */
final class ResolutionIvado {
    /** Résolutions au plus par collecte. */
    public const MAX = 25;

    /** Temps donné aux résolutions, en secondes (une page peut prendre jusqu'à 15 s). */
    public const BUDGET = 20.0;

    /**
     * @param Fiche[] $fiches Fiches rendues par LecteurIvado.
     * @param array $source Entrée du registre, avec `flux_evenements` (contenu XML) si le flux a été téléchargé.
     * @param callable(string, array): array $obtenir Client HTTP au format de `Http::obtenir`.
     * @param callable(Fiche): void $integrer Intègre une fiche prête (ses exceptions ne sont pas interceptées).
     * @param (callable(): float)|null $horloge Secondes écoulées (microtime par défaut).
     * @return array{resolues: int, erreurs: string[], en_attente: int}
     */
    public static function resoudre(array $fiches, array $source, Depot $depot, callable $obtenir, callable $integrer, int $max = self::MAX, float $budget = self::BUDGET, ?callable $horloge = null): array {
        $horloge ??= static fn(): float => microtime(true);
        $debut = $horloge();
        $resumes = null;
        $erreurs = [];
        $en_attente = 0;
        $tentatives = 0;
        $resolues = 0;
        foreach ($fiches as $fiche) {
            if ($fiche->titre !== '') {
                $integrer($fiche);
                continue;
            }
            $connue = $depot->fiche_de_cle($fiche);
            if ($connue !== null) {
                $fiche->titre = $connue->titres_source[$fiche->cle_source] ?? $connue->titre;
                if ($connue->url !== '') {
                    $fiche->url = $connue->url;
                }
                $integrer($fiche);
                continue;
            }
            if ($tentatives >= $max || $horloge() - $debut >= $budget) {
                $en_attente++;
                continue;
            }
            $tentatives++;
            $resumes ??= LecteurIvado::resumes_par_url($source);
            if (self::completer($fiche, $obtenir, $resumes, $erreurs)) {
                $resolues++;
                $integrer($fiche);
            }
        }
        return ['resolues' => $resolues, 'erreurs' => $erreurs, 'en_attente' => $en_attente];
    }

    /**
     * Télécharge la page de la fiche et la complète. Faux (avec une erreur) si la page est en erreur,
     * n'est pas du HTML, n'a pas de titre ou lève une exception.
     *
     * @param array<string,string> $resumes
     * @param string[] $erreurs
     */
    private static function completer(Fiche $fiche, callable $obtenir, array $resumes, array &$erreurs): bool {
        $url_courte = $fiche->url;
        try {
            $page = $obtenir($url_courte, ['accept' => 'html']);
            if (($page['erreur'] ?? null) !== null || !Http::est_html($page)) {
                /* translators: 1 : adresse de la page, 2 : erreur. */
                $erreurs[] = sprintf(__('Page IVADO %1$s illisible : %2$s', 'rsn-veille'), $url_courte, $page['erreur'] ?? __('contenu inattendu', 'rsn-veille'));
                return false;
            }
            LecteurIvado::completer($fiche, (string) $page['corps'], (string) (($page['url_finale'] ?? '') ?: $url_courte), $resumes);
        } catch (\Throwable $e) {
            /* translators: 1 : adresse de la page, 2 : message de l'exception. */
            $erreurs[] = sprintf(__('Page IVADO %1$s : %2$s', 'rsn-veille'), $url_courte, $e->getMessage());
            return false;
        }
        if ($fiche->titre === '') {
            /* translators: %s : adresse de la page. */
            $erreurs[] = sprintf(__('Page IVADO %s sans titre : fiche ignorée', 'rsn-veille'), $url_courte);
            return false;
        }
        return true;
    }
}

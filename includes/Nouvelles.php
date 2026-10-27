<?php
declare(strict_types=1);

namespace RSN\Veille;

use RSN\Veille\Lecteurs\LecteurRss;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Radar des nouvelles : lit les flux des sources `categorie_nouvelles`, garde ce qui touche la santé
 * ou le numérique et présente le tout du plus récent au plus ancien (devis § 3.3). Les nouvelles ne
 * deviennent jamais des fiches : l'équipe choisit celles qu'elle garde pour l'infolettre.
 *
 * Classe pure : le réseau est injecté (`$obtenir`), rien n'est lu ni écrit dans WordPress.
 */
final class Nouvelles {
    /** Âge maximal d'une nouvelle, en jours. */
    public const RETENTION_JOURS = 60;

    /** Éléments lus par source quand elle n'indique pas de `limite`. */
    private const LIMITE_PAR_DEFAUT = 8;

    /**
     * @param array<string,array<string,mixed>> $sources_nouvelles Registre de sources (seules les actives `categorie_nouvelles` sont lues).
     * @param callable(string): array $obtenir Renvoie `['corps' => …, 'type' => …, 'code' => …, 'erreur' => …]` (comme `Http::obtenir`).
     * @param string $aujourdhui Date du jour, `Y-m-d`.
     * @return array{nouvelles: array<int,array{url: string, titre: string, date: string, source: string, organisateur: string, resume: string, statut_radar: string}>, erreurs: string[], genere_le: string}
     */
    public static function lire(array $sources_nouvelles, callable $obtenir, Pertinence $p, string $aujourdhui): array {
        $limite_date = self::date_limite($aujourdhui);
        $lecteur = new LecteurRss();
        $nouvelles = [];
        $vues = [];
        $erreurs = [];

        foreach ($sources_nouvelles as $source) {
            if (!is_array($source) || empty($source['categorie_nouvelles']) || !($source['actif'] ?? true)) {
                continue;
            }
            $nom = (string) ($source['nom'] ?? $source['id'] ?? '');
            $reponse = $obtenir((string) ($source['url'] ?? ''));
            if (($reponse['erreur'] ?? null) !== null) {
                $erreurs[] = $nom . ' : ' . (string) $reponse['erreur'];
                continue;
            }
            if (!Http::est_flux($reponse)) {
                $erreurs[] = $nom . ' : ' . __("La réponse n'est pas un flux RSS ou Atom.", 'rsn-veille');
                continue;
            }

            $resultat = $lecteur->lire((string) $reponse['corps'], array_merge(['limite' => self::LIMITE_PAR_DEFAUT], $source, ['mode' => 'nouvelles']));
            if ($resultat->fiches === [] && $resultat->erreurs !== []) {
                $erreurs[] = $nom . ' : ' . $resultat->erreurs[0];
                continue;
            }

            foreach ($resultat->fiches as $fiche) {
                $url = (string) $fiche->url;
                if (!self::est_adresse_web($url) || isset($vues[$url])) {
                    continue;
                }
                $date = (string) ($fiche->debut ?? '');
                if ($date !== '' && $limite_date !== '' && $date < $limite_date) {
                    continue;
                }
                $statut = $p->statut((string) ($source['type_source'] ?? ''), $fiche->titre . ' ' . $fiche->resume);
                if ($statut !== 'publie' && $statut !== 'a_approuver') {
                    continue;
                }
                $vues[$url] = true;
                $nouvelles[] = [
                    'url' => $url,
                    'titre' => $fiche->titre,
                    'date' => $date,
                    'source' => $nom,
                    'organisateur' => (string) ($source['organisateur'] ?? ''),
                    'resume' => $fiche->resume,
                    'statut_radar' => $statut === 'publie' ? 'reperee' : 'a_trier',
                ];
            }
        }

        // Plus récentes d'abord ; sans date, à la fin ; à date égale, l'ordre des sources (tri stable).
        usort($nouvelles, static function (array $a, array $b): int {
            if ($a['date'] === '' || $b['date'] === '') {
                return ($a['date'] === '') <=> ($b['date'] === '');
            }
            return strcmp($b['date'], $a['date']);
        });

        return ['nouvelles' => $nouvelles, 'erreurs' => $erreurs, 'genere_le' => $aujourdhui];
    }

    /** Plus ancienne date gardée (`Y-m-d`), ou vide si la date du jour est illisible (aucun filtre). */
    private static function date_limite(string $aujourdhui): string {
        $jour = \DateTimeImmutable::createFromFormat('!Y-m-d', $aujourdhui);
        if ($jour === false || $jour->format('Y-m-d') !== $aujourdhui) {
            return '';
        }
        return $jour->modify('-' . self::RETENTION_JOURS . ' days')->format('Y-m-d');
    }

    private static function est_adresse_web(string $url): bool {
        return preg_match('#^https?://[^\s]+$#i', $url) === 1;
    }
}

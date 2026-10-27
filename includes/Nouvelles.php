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
     * Lit toutes les sources de nouvelles, puis assemble le résultat.
     *
     * @param array<string,array<string,mixed>> $sources_nouvelles Registre de sources (seules les actives `categorie_nouvelles` sont lues).
     * @param callable(string): array $obtenir Renvoie `['corps' => …, 'type' => …, 'code' => …, 'erreur' => …]` (comme `Http::obtenir`).
     * @param string $aujourdhui Date du jour, `Y-m-d`.
     * @return array{nouvelles: array<int,array{url: string, titre: string, date: string, source: string, organisateur: string, resume: string, statut_radar: string}>, erreurs: string[], genere_le: string}
     */
    public static function lire(array $sources_nouvelles, callable $obtenir, Pertinence $p, string $aujourdhui): array {
        $parties = [];
        foreach ($sources_nouvelles as $source) {
            if (is_array($source)) {
                $parties[] = self::lire_source($source, $obtenir, $p, $aujourdhui);
            }
        }
        return self::assembler($parties, $aujourdhui);
    }

    /**
     * Lit une source : ses nouvelles retenues (dans l'ordre du flux, sans tri) et ses erreurs. Une
     * source inactive ou hors radar ne donne rien et ne fait aucune requête.
     *
     * @param array<string,mixed> $source
     * @param callable(string): array $obtenir
     * @return array{nouvelles: array<int,array<string,string>>, erreurs: string[]}
     */
    public static function lire_source(array $source, callable $obtenir, Pertinence $p, string $aujourdhui): array {
        $partie = ['nouvelles' => [], 'erreurs' => []];
        if (empty($source['categorie_nouvelles']) || !($source['actif'] ?? true)) {
            return $partie;
        }
        $limite_date = self::date_limite($aujourdhui);
        $nom = (string) ($source['nom'] ?? $source['id'] ?? '');
        $reponse = $obtenir((string) ($source['url'] ?? ''));
        if (($reponse['erreur'] ?? null) !== null) {
            $partie['erreurs'][] = $nom . ' : ' . (string) $reponse['erreur'];
            return $partie;
        }
        if (!Http::est_flux($reponse)) {
            $partie['erreurs'][] = $nom . ' : ' . __("La réponse n'est pas un flux RSS ou Atom.", 'rsn-veille');
            return $partie;
        }

        $resultat = (new LecteurRss())->lire((string) $reponse['corps'], array_merge(['limite' => self::LIMITE_PAR_DEFAUT], $source, ['mode' => 'nouvelles']));
        if ($resultat->fiches === [] && $resultat->erreurs !== []) {
            $partie['erreurs'][] = $nom . ' : ' . $resultat->erreurs[0];
            return $partie;
        }

        $vues = [];
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
            $partie['nouvelles'][] = [
                'url' => $url,
                'titre' => $fiche->titre,
                'date' => $date,
                'source' => $nom,
                'organisateur' => (string) ($source['organisateur'] ?? ''),
                'resume' => $fiche->resume,
                'statut_radar' => $statut === 'publie' ? 'reperee' : 'a_trier',
            ];
        }
        return $partie;
    }

    /**
     * Réunit les lectures de plusieurs sources : une adresse déjà vue (source précédente) est ignorée,
     * puis tri des plus récentes aux plus anciennes (sans date, à la fin ; à date égale, l'ordre des sources).
     *
     * @param array<int|string,array{nouvelles?: array<int,array<string,string>>, erreurs?: string[]}> $parties Résultats de `lire_source`.
     * @return array{nouvelles: array<int,array<string,string>>, erreurs: string[], genere_le: string}
     */
    public static function assembler(array $parties, string $aujourdhui): array {
        $nouvelles = [];
        $erreurs = [];
        $vues = [];
        foreach ($parties as $partie) {
            foreach ($partie['erreurs'] ?? [] as $erreur) {
                $erreurs[] = $erreur;
            }
            foreach ($partie['nouvelles'] ?? [] as $nouvelle) {
                if (!isset($vues[$nouvelle['url']])) {
                    $vues[$nouvelle['url']] = true;
                    $nouvelles[] = $nouvelle;
                }
            }
        }
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

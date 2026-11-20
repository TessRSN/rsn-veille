<?php
declare(strict_types=1);

namespace RSN\Veille;

use RSN\Veille\Lecteurs\DecoupageMailchimp;
use RSN\Veille\Lecteurs\LecteurRss;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Radar des nouvelles : lit les flux des sources `categorie_nouvelles`, garde ce qui touche la santé
 * ou le numérique et présente le tout du plus récent au plus ancien (devis § 3.3). Les nouvelles ne
 * deviennent jamais des fiches : l'équipe choisit celles qu'elle garde pour l'infolettre.
 *
 * Une source `decoupage = mailchimp` est une infolettre Mailchimp (flux d'archive) : chaque numéro est découpé, et
 * chacun de ses encadrés devient une nouvelle (`lire_infolettre`).
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
     * @return array{nouvelles: array<int,array<string,string>>, erreurs: string[], alertes?: string[]} `alertes` (gabarit
     *         d'une infolettre à vérifier) n'est là que s'il y en a.
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
        if (($source['decoupage'] ?? '') === 'mailchimp') {
            return self::lire_infolettre($source, (string) $reponse['corps'], $p, $limite_date, $nom);
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
     * Infolettre Mailchimp : chaque encadré des numéros du flux devient une nouvelle, datée du dernier numéro où il
     * paraît. Un encadré qui revient d'un numéro à l'autre (« Boîte à outils ») porte `deja_paru`, le nombre d'autres
     * numéros où il a paru ; deux envois du même numéro (mêmes encadrés) comptent pour un seul. Un numéro sans encadré
     * (envoi spécial, autre gabarit) reste une seule nouvelle. Si le dernier numéro rompt avec l'habitude des
     * précédents, ou si aucun numéro n'a d'encadré, une alerte demande de vérifier le gabarit.
     *
     * @param array<string,mixed> $source
     * @return array{nouvelles: array<int,array<string,string>>, erreurs: string[], alertes?: string[]}
     */
    private static function lire_infolettre(array $source, string $corps, Pertinence $p, string $limite_date, string $nom): array {
        $partie = ['nouvelles' => [], 'erreurs' => []];
        $numeros = DecoupageMailchimp::numeros($corps);
        if ($numeros === []) {
            $partie['erreurs'][] = $nom . ' : Flux vide ou invalide';
            return $partie;
        }
        $cartes = [];
        $comptes = [];
        foreach ($numeros as $i => $numero) {
            $cartes[$i] = DecoupageMailchimp::cartes($numero['html']);
            $comptes[] = ['titre' => $numero['titre'], 'n' => count($cartes[$i])];
        }
        $editions = self::editions(array_map(static fn(array $liste): array => array_column($liste, 'url'), $cartes));
        $parus = [];
        foreach ($cartes as $i => $liste) {
            foreach ($liste as $carte) {
                $parus[$carte['url']][$editions[$i]] = true;
            }
        }

        $vues = [];
        foreach ($numeros as $i => $numero) {
            if ($numero['date'] !== '' && $limite_date !== '' && $numero['date'] < $limite_date) {
                continue;
            }
            $elements = $cartes[$i] !== [] ? $cartes[$i] : [[
                'titre' => $numero['titre'],
                'url' => $numero['url'],
                'resume' => Fiche::court(DecoupageMailchimp::texte($numero['html'])),
            ]];
            foreach ($elements as $element) {
                $url = $element['url'];
                if (!self::est_adresse_web($url) || isset($vues[$url])) {
                    continue;
                }
                $statut = $p->statut((string) ($source['type_source'] ?? ''), $element['titre'] . ' ' . $element['resume']);
                if ($statut !== 'publie' && $statut !== 'a_approuver') {
                    continue;
                }
                $vues[$url] = true;
                $nouvelle = [
                    'url' => $url,
                    'titre' => $element['titre'],
                    'date' => $numero['date'],
                    'source' => $nom,
                    'organisateur' => (string) ($source['organisateur'] ?? ''),
                    'resume' => $element['resume'],
                    'statut_radar' => $statut === 'publie' ? 'reperee' : 'a_trier',
                ];
                $autres = count($parus[$url] ?? []) - 1;
                if ($autres > 0) {
                    $nouvelle['deja_paru'] = (string) $autres;
                }
                $partie['nouvelles'][] = $nouvelle;
            }
        }

        $alerte = self::alerte_gabarit($nom, $comptes);
        if ($alerte !== '') {
            $partie['alertes'] = [$alerte];
        }
        return $partie;
    }

    /**
     * Numéro d'édition de chaque envoi : deux envois dont les encadrés sont presque les mêmes (au moins 80 % d'adresses
     * communes sur l'ensemble des deux) sont le même numéro, envoyé deux fois. Un envoi sans encadré est seul.
     *
     * @param array<int,string[]> $adresses Adresses des encadrés de chaque envoi.
     * @return array<int,int>
     */
    private static function editions(array $adresses): array {
        $editions = [];
        $modeles = [];
        foreach ($adresses as $i => $liste) {
            $liste = array_unique($liste);
            $editions[$i] = count($modeles);
            foreach ($modeles as $numero => $modele) {
                $communes = count(array_intersect($liste, $modele));
                $toutes = count($liste) + count($modele) - $communes;
                if ($liste !== [] && $communes * 5 >= $toutes * 4) {
                    $editions[$i] = $numero;
                    break;
                }
            }
            if ($editions[$i] === count($modeles)) {
                $modeles[] = $liste;
            }
        }
        return $editions;
    }

    /**
     * Phrase d'alerte si le gabarit de l'infolettre semble avoir changé, sinon vide.
     *
     * @param array<int,array{titre: string, n: int}> $comptes Titre et nombre d'encadrés de chaque numéro, du plus récent au plus ancien.
     */
    private static function alerte_gabarit(string $nom, array $comptes): string {
        if (array_sum(array_column($comptes, 'n')) === 0) {
            /* translators: 1 : nom de la source ; 2 : nombre de numéros lus. */
            return sprintf(_n(
                '%1$s : aucun encadré reconnu dans le numéro du flux. Le gabarit de l’infolettre a peut-être changé : le numéro reste une seule nouvelle.',
                '%1$s : aucun encadré reconnu dans les %2$d numéros du flux. Le gabarit de l’infolettre a peut-être changé : chaque numéro reste une seule nouvelle.',
                count($comptes),
                'rsn-veille'
            ), $nom, count($comptes));
        }
        $rupture = DecoupageMailchimp::rupture($comptes);
        if ($rupture === null) {
            return '';
        }
        if ($rupture['n'] === 0) {
            /* translators: 1 : nom de la source ; 2 : titre du numéro ; 3 : nombre habituel d'encadrés. */
            return sprintf(__('%1$s : le numéro « %2$s » n’a donné aucun encadré, alors que les précédents en donnent environ %3$d. Le gabarit de l’infolettre a peut-être changé.', 'rsn-veille'), $nom, $rupture['titre'], $rupture['habitude']);
        }
        /* translators: 1 : nom de la source ; 2 : titre du numéro ; 3 : nombre d'encadrés du numéro ; 4 : nombre habituel. */
        return sprintf(_n(
            '%1$s : le numéro « %2$s » n’a donné que %3$d encadré, alors que les précédents en donnent environ %4$d. Le gabarit de l’infolettre a peut-être changé.',
            '%1$s : le numéro « %2$s » n’a donné que %3$d encadrés, alors que les précédents en donnent environ %4$d. Le gabarit de l’infolettre a peut-être changé.',
            $rupture['n'],
            'rsn-veille'
        ), $nom, $rupture['titre'], $rupture['n'], $rupture['habitude']);
    }

    /**
     * Réunit les lectures de plusieurs sources : une adresse déjà vue (source précédente) est ignorée,
     * puis tri des plus récentes aux plus anciennes (sans date, à la fin ; à date égale, l'ordre des sources).
     *
     * @param array<int|string,array{nouvelles?: array<int,array<string,string>>, erreurs?: string[]}> $parties Résultats de `lire_source`.
     * @return array{nouvelles: array<int,array<string,string>>, erreurs: string[], genere_le: string, alertes?: string[]}
     */
    public static function assembler(array $parties, string $aujourdhui): array {
        $nouvelles = [];
        $erreurs = [];
        $alertes = [];
        $vues = [];
        foreach ($parties as $partie) {
            foreach ($partie['alertes'] ?? [] as $alerte) {
                $alertes[] = $alerte;
            }
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
        return ['nouvelles' => $nouvelles, 'erreurs' => $erreurs, 'genere_le' => $aujourdhui] + ($alertes !== [] ? ['alertes' => $alertes] : []);
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

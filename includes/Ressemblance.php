<?php
declare(strict_types=1);

namespace RSN\Veille;

use RSN\Veille\Lecteurs\DecoupageMailchimp;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Deux fiches annoncent-elles sans doute la même chose ? Le dédoublonnage de la collecte (`Depot`) ne fusionne que ce
 * qui est sûr (même jour et même début de titre, ou même adresse). Ici, on repère ce qui est seulement probable, pour le
 * signaler à l'équipe dans la file d'approbation : le même événement sous un titre un peu différent (« 20e Forum… » et
 * « Forum… »), une proposition du public déjà collectée ailleurs, la préannonce et le lancement d'un appel.
 *
 * Classe pure : rien n'est lu ni écrit, rien n'est fusionné.
 */
final class Ressemblance {
    /** Mots sans poids dans un titre. */
    private const MOTS_VIDES = [
        'de', 'du', 'des', 'la', 'le', 'les', 'un', 'une', 'et', 'en', 'au', 'aux', 'sur', 'pour', 'par', 'dans', 'avec',
        'the', 'of', 'and', 'for', 'on', 'in', 'to', 'an',
    ];

    /** Hôtes des numéros d'infolettres (Mailchimp) : une adresse commune à tout ce qu'un numéro annonce. */
    private const INFOLETTRES = '~(?:^|\.)(?:mailchi\.mp|campaign-archive\.com|eepurl\.com|list-manage\.com)$~i';

    /** Part des mots du titre le plus court qui doivent se retrouver dans l'autre. */
    private const PART_COMMUNE = 0.8;

    /** Mots communs exigés quand les deux fiches nomment des organisateurs sans rapport (événement conjoint). */
    private const COMMUNS_AUTRE_ORGANISATEUR = 4;

    /**
     * Fiche ressemblante de chaque fiche en attente, par identifiant : une fiche publiée d'abord (elle est déjà dans
     * l'agenda), sinon une autre fiche en attente. Les fiches sans ressemblance n'y sont pas. Un appel publié mais échu
     * (`Fiche::appel_en_cours`) n'est jamais proposé : l'édition de l'an passé n'est pas « déjà dans l'agenda ».
     *
     * @param Fiche[] $en_attente
     * @param Fiche[] $publiees
     * @param string $aujourdhui `Y-m-d` ; vide : les appels échus ne sont pas écartés.
     * @return array<int,Fiche>
     */
    public static function pour_la_file(array $en_attente, array $publiees, string $aujourdhui = ''): array {
        if ($aujourdhui !== '') {
            $publiees = array_filter($publiees, static fn(Fiche $f): bool => $f->type !== 'appel' || $f->appel_en_cours($aujourdhui));
        }
        // Chaque fiche est préparée une seule fois (mots du titre, adresse) : la comparaison de deux fiches ne coûte presque rien.
        $candidates = [];
        foreach (array_merge(array_values($publiees), array_values($en_attente)) as $fiche) {
            $candidates[] = [$fiche, self::profil($fiche)];
        }
        // Une adresse portée par trois fiches ou plus est une page générale : elle ne désigne pas un événement.
        $usages = array_count_values(array_filter(array_map(static fn(array $c): string => $c[1]['adresse'], $candidates)));
        $generales = array_filter($usages, static fn(int $n): bool => $n > 2);
        $trouvees = [];
        foreach ($en_attente as $fiche) {
            if ($fiche->id === null) {
                continue;
            }
            $profil = self::profil($fiche);
            foreach ($candidates as [$candidate, $autre]) {
                if (self::profils_pareils($profil, $autre, $generales)) {
                    $trouvees[(int) $fiche->id] = $candidate;
                    break;
                }
            }
        }
        return $trouvees;
    }

    /**
     * Même type, et : pour deux événements, des dates qui se recoupent avec un titre proche ou la même adresse ; pour
     * deux appels sans dates limites contraires, un titre proche ou la même adresse. Deux organisateurs nommés et sans
     * rapport demandent un titre long en commun (événement conjoint), sinon ce sont deux « assemblées générales ».
     *
     * @param string[] $adresses_generales Adresses (ramenées à l'essentiel) qui ne désignent pas une seule fiche.
     */
    public static function memes(Fiche $a, Fiche $b, array $adresses_generales = []): bool {
        return self::profils_pareils(self::profil($a), self::profil($b), array_flip($adresses_generales));
    }

    /**
     * Deux titres disent-ils la même chose ? Oui si au moins 80 % des mots du plus court (sans mots vides, numéros
     * d'édition ni années) sont dans l'autre. Un titre d'un seul mot ne prouve rien ; un titre de deux mots, seulement
     * si l'autre a les deux mêmes et rien d'autre (« Webinaire IA » n'est pas « Webinaire : l'IA générative en soins »).
     */
    public static function titres_proches(string $a, string $b): bool {
        return self::mots_proches(array_flip(self::mots($a)), array_flip(self::mots($b)));
    }

    /**
     * Ce qui sert à comparer une fiche, calculé une fois.
     *
     * @return array{id: ?int, type: string, debut: string, fin: string, echeance: string, adresse: string, mots: array<string,int>, organisateur: array<string,int>}
     */
    private static function profil(Fiche $f): array {
        $debut = (string) ($f->debut ?? '');
        return [
            'id' => $f->id,
            'type' => $f->type,
            'debut' => $debut,
            'fin' => max($debut, (string) ($f->fin ?? '')),
            'echeance' => (string) ($f->echeance ?? ''),
            'adresse' => self::adresse($f->url),
            'mots' => array_flip(self::mots($f->titre)),
            'organisateur' => array_flip(self::mots($f->organisateur)),
        ];
    }

    /** @param array<string,mixed> $generales Adresses générales, en clés. */
    private static function profils_pareils(array $a, array $b, array $generales): bool {
        if ($a['type'] !== $b['type'] || ($a['id'] !== null && $a['id'] === $b['id'])) {
            return false;
        }
        if ($a['type'] === 'appel') {
            // Deux dates limites différentes : deux concours, ou deux éditions du même.
            if ($a['echeance'] !== '' && $b['echeance'] !== '' && $a['echeance'] !== $b['echeance']) {
                return false;
            }
        } elseif ($a['debut'] === '' || $b['debut'] === '' || $a['debut'] > $b['fin'] || $b['debut'] > $a['fin']) {
            return false;
        }
        if ($a['adresse'] !== '' && $a['adresse'] === $b['adresse'] && !isset($generales[$a['adresse']])) {
            return true;
        }
        $autre_organisateur = $a['organisateur'] !== [] && $b['organisateur'] !== [] && array_intersect_key($a['organisateur'], $b['organisateur']) === [];
        return self::mots_proches($a['mots'], $b['mots'], $autre_organisateur ? self::COMMUNS_AUTRE_ORGANISATEUR : 0);
    }

    /**
     * @param array<string,int> $a Mots d'un titre, en clés.
     * @param array<string,int> $b
     * @param int $communs_exiges Nombre minimal de mots communs, en plus de la règle des 80 %.
     */
    private static function mots_proches(array $a, array $b, int $communs_exiges = 0): bool {
        $court = min(count($a), count($b));
        if ($court < 2) {
            return false;
        }
        $communs = count(array_intersect_key($a, $b));
        if ($communs < $communs_exiges) {
            return false;
        }
        if ($court === 2) {
            return $communs === 2 && count($a) === 2 && count($b) === 2;
        }
        return $communs >= self::PART_COMMUNE * $court;
    }

    /** @return string[] Mots distincts qui comptent : minuscules, sans accents, sans mots vides ni nombres. */
    private static function mots(string $titre): array {
        $mots = [];
        foreach (explode(' ', Fiche::plier($titre)) as $mot) {
            // Lettres élidées (« l », « d »), numéros d'édition (« 20e », « 2e », « 3rd ») et années.
            if (strlen($mot) < 2 || in_array($mot, self::MOTS_VIDES, true) || preg_match('/^\d+(?:e|er|eme|ieme|th|st|nd|rd)?$/', $mot) === 1) {
                continue;
            }
            $mots[$mot] = true;
        }
        return array_keys($mots);
    }

    /**
     * Adresse ramenée à l'essentiel pour la comparaison : sans protocole, « www. », suivi publicitaire ni barre finale.
     * Vide si elle ne désigne pas une page précise (numéro d'infolettre, page d'accueil).
     */
    private static function adresse(string $url): string {
        $url = DecoupageMailchimp::nettoyer_url($url);
        // Le numéro d'une infolettre annonce plusieurs événements : son adresse n'en désigne aucun.
        if ($url === '' || preg_match(self::INFOLETTRES, (string) parse_url($url, PHP_URL_HOST)) === 1) {
            return '';
        }
        $url = preg_replace('~^https?://(?:www\.)?~i', '', $url) ?? '';
        $url = rtrim(strtolower(preg_replace('~#.*$~', '', $url) ?? ''), '/');
        // La page d'accueil d'un site ne désigne aucun événement non plus.
        return strpbrk($url, '/?') === false ? '' : $url;
    }
}

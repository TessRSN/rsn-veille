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

    /**
     * Fiche ressemblante de chaque fiche en attente, par identifiant : une fiche publiée d'abord (elle est déjà dans
     * l'agenda), sinon une autre fiche en attente. Les fiches sans ressemblance n'y sont pas.
     *
     * @param Fiche[] $en_attente
     * @param Fiche[] $publiees
     * @return array<int,Fiche>
     */
    public static function pour_la_file(array $en_attente, array $publiees): array {
        // Une adresse portée par trois fiches ou plus est une page générale : elle ne désigne pas un événement.
        $usages = array_count_values(array_filter(array_map(static fn(Fiche $f): string => self::adresse($f->url), array_merge($en_attente, $publiees))));
        $generales = array_keys(array_filter($usages, static fn(int $n): bool => $n > 2));
        $trouvees = [];
        foreach ($en_attente as $fiche) {
            if ($fiche->id === null) {
                continue;
            }
            foreach ([$publiees, $en_attente] as $candidates) {
                foreach ($candidates as $candidate) {
                    if (self::memes($fiche, $candidate, $generales)) {
                        $trouvees[(int) $fiche->id] = $candidate;
                        continue 3;
                    }
                }
            }
        }
        return $trouvees;
    }

    /**
     * Même type, et : pour deux événements, des dates qui se recoupent avec un titre proche ou la même adresse ; pour
     * deux appels, un titre proche sans dates limites contraires, ou la même adresse.
     *
     * @param string[] $adresses_generales Adresses (ramenées à l'essentiel) qui ne désignent pas une seule fiche.
     */
    public static function memes(Fiche $a, Fiche $b, array $adresses_generales = []): bool {
        if ($a->type !== $b->type || ($a->id !== null && $a->id === $b->id)) {
            return false;
        }
        $adresse = self::adresse($a->url);
        $meme_adresse = $adresse !== '' && $adresse === self::adresse($b->url) && !in_array($adresse, $adresses_generales, true);
        if ($a->type === 'appel') {
            $limites = array_filter([$a->echeance, $b->echeance]);
            $limites_contraires = count($limites) === 2 && $a->echeance !== $b->echeance;
            return $meme_adresse || (!$limites_contraires && self::titres_proches($a->titre, $b->titre));
        }
        $debut_a = (string) ($a->debut ?? '');
        $debut_b = (string) ($b->debut ?? '');
        if ($debut_a === '' || $debut_b === '') {
            return false;
        }
        $fin_a = max($debut_a, (string) ($a->fin ?? ''));
        $fin_b = max($debut_b, (string) ($b->fin ?? ''));
        if ($debut_a > $fin_b || $debut_b > $fin_a) {
            return false;
        }
        return $meme_adresse || self::titres_proches($a->titre, $b->titre);
    }

    /**
     * Deux titres disent-ils la même chose ? Oui si au moins 80 % des mots du plus court (sans mots vides, numéros
     * d'édition ni années) sont dans l'autre. Il faut au moins deux mots de part et d'autre : un seul mot (« Webinaire »)
     * ne prouve rien.
     */
    public static function titres_proches(string $a, string $b): bool {
        $mots_a = self::mots($a);
        $mots_b = self::mots($b);
        $court = min(count($mots_a), count($mots_b));
        if ($court < 2) {
            return false;
        }
        return count(array_intersect($mots_a, $mots_b)) >= self::PART_COMMUNE * $court;
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

    /** Adresse ramenée à l'essentiel pour la comparaison : sans protocole, « www. », suivi publicitaire ni barre finale. */
    private static function adresse(string $url): string {
        $url = DecoupageMailchimp::nettoyer_url($url);
        // Le numéro d'une infolettre annonce plusieurs événements : son adresse n'en désigne aucun.
        if ($url === '' || preg_match(self::INFOLETTRES, (string) parse_url($url, PHP_URL_HOST)) === 1) {
            return '';
        }
        $url = preg_replace('~^https?://(?:www\.)?~i', '', $url) ?? '';
        $url = preg_replace('~#.*$~', '', $url) ?? '';
        return rtrim(strtolower($url), '/');
    }
}

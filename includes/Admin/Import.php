<?php
declare(strict_types=1);

namespace RSN\Veille\Admin;

use RSN\Veille\Depot;
use RSN\Veille\Fiche;
use RSN\Veille\Pertinence;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Import des événements recopiés des infolettres (fichier JSON, format de
 * `docs/maquette/evenements-infolettres-2025-2026.json`) : chaque entrée devient une fiche d'événement
 * de niveau M, source `infolettre`, publiée, intégrée par le dépôt (les événements déjà connus, par
 * exemple ceux du calendrier du RSN, sont fusionnés au lieu d'être recréés).
 *
 * Classe pure : seulement `__()` pour les messages ; l'écriture passe par le dépôt reçu.
 */
final class Import {
    /** Préfixe des clés de source des fiches importées. */
    public const SOURCE = 'infolettre';

    private const FORMATS = ['Présentiel', 'Virtuel', 'Hybride'];
    private const REGIONS = ['Québec', 'Hors Québec', 'En ligne'];
    private const LANGUES = ['fr', 'en'];

    /**
     * Importe le fichier. `lus` compte toutes les entrées, valides ou non ; `erreurs` donne un message
     * par entrée refusée (titre, début ou organisateur manquant, date ou heure illisible) ou par
     * écriture impossible. $aujourdhui n'écarte rien : les événements passés de l'infolettre sont
     * gardés comme archives.
     *
     * @return array{lus: int, crees: int, fusionnes: int, mis_a_jour: int, inchanges: int, erreurs: string[]}
     */
    public static function importer(string $json, Depot $d, Pertinence $p, string $aujourdhui): array {
        $resultat = ['lus' => 0, 'crees' => 0, 'fusionnes' => 0, 'mis_a_jour' => 0, 'inchanges' => 0, 'erreurs' => []];
        $entrees = json_decode(self::sans_bom($json), true);
        // Une liste (clés 0, 1, 2…), pas un objet seul.
        if (!is_array($entrees) || array_keys($entrees) !== array_keys(array_values($entrees))) {
            $resultat['erreurs'][] = __("Fichier illisible : une liste d'événements au format JSON est attendue.", 'rsn-veille');
            return $resultat;
        }
        $compteurs = ['cree' => 'crees', 'fusionne' => 'fusionnes', 'mis_a_jour' => 'mis_a_jour', 'inchange' => 'inchanges'];
        foreach ($entrees as $i => $entree) {
            $resultat['lus']++;
            $numero = $i + 1;
            $fiche = is_array($entree) ? self::fiche($entree, $p) : null;
            if (!$fiche instanceof Fiche) {
                $resultat['erreurs'][] = self::erreur($numero, is_string($fiche) ? $fiche : __("ce n'est pas un objet JSON", 'rsn-veille'));
                continue;
            }
            try {
                // L'adresse d'une entrée est celle du numéro de l'infolettre, commune à plusieurs
                // événements : elle ne sert pas à repérer les doublons.
                $resultat[$compteurs[$d->integrer($fiche, false)]]++;
            } catch (\Throwable $e) {
                /* translators: %s : message de l'erreur. */
                $resultat['erreurs'][] = self::erreur($numero, sprintf(__('écriture impossible (%s)', 'rsn-veille'), $e->getMessage()));
            }
        }
        return $resultat;
    }

    /**
     * Fiche d'une entrée, ou le motif de son refus.
     *
     * @param array<string,mixed> $e
     */
    public static function fiche(array $e, Pertinence $p): Fiche|string {
        $titre = self::texte($e, 'titre');
        $debut = self::texte($e, 'debut');
        $organisateur = self::texte($e, 'organisateur');
        if ($titre === '' || $debut === '' || $organisateur === '') {
            return __('titre, début ou organisateur manquant', 'rsn-veille');
        }
        if (!self::date_valide($debut)) {
            return __('date de début illisible (format AAAA-MM-JJ attendu)', 'rsn-veille');
        }
        $fin = self::texte($e, 'fin');
        if ($fin !== '' && (!self::date_valide($fin) || $fin < $debut)) {
            return __('date de fin illisible ou antérieure au début', 'rsn-veille');
        }
        $heure = self::texte($e, 'heure');
        if ($heure !== '') {
            if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $heure, $m) !== 1) {
                return __('heure illisible (format HH:MM attendu)', 'rsn-veille');
            }
            $heure = sprintf('%02d:%s', (int) $m[1], $m[2]);
        }

        $f = new Fiche();
        $f->type = 'evenement';
        $f->titre = $titre;
        $f->debut = $debut;
        $f->fin = $fin !== '' ? $fin : null;
        $f->heure = $heure;
        $f->organisateur = $organisateur;
        $f->categorie_rsn = self::texte($e, 'categorie_rsn');
        $f->lieu = self::texte($e, 'lieu');
        $f->ville = self::texte($e, 'ville');
        $f->prix = self::texte($e, 'prix');
        $f->resume = self::texte($e, 'resume');
        $f->url = self::url(self::texte($e, 'url'));
        $langue = self::texte($e, 'langue');
        $f->langue = in_array($langue, self::LANGUES, true) ? $langue : 'fr';

        $format = self::texte($e, 'format');
        $f->format = in_array($format, self::FORMATS, true) ? $format : Fiche::deviner_format($f->titre . ' ' . $f->resume . ' ' . $f->lieu);
        $region = self::texte($e, 'region');
        $f->region = in_array($region, self::REGIONS, true) ? $region : Fiche::deviner_region($f->ville, $f->lieu, $f->format);

        $etiquettes = [];
        foreach (is_array($e['etiquettes'] ?? null) ? $e['etiquettes'] : [] as $etiquette) {
            $etiquette = is_scalar($etiquette) ? Fiche::nettoyer_texte((string) $etiquette) : '';
            if ($etiquette !== '') {
                $etiquettes[] = $etiquette;
            }
        }
        $f->themes = $etiquettes !== [] ? array_values(array_unique($etiquettes)) : $p->themes($f->titre . ' ' . $f->resume);

        $f->niveau = 'M';
        $f->source = self::SOURCE;
        $f->statut = 'publie';
        $f->cle_source = self::SOURCE . ':' . md5($debut . $titre);
        $f->cles_source = [$f->cle_source];
        return $f;
    }

    /** Champ texte de l'entrée, nettoyé (balises retirées, espaces réduites) ; vide s'il manque. */
    private static function texte(array $e, string $champ): string {
        $valeur = $e[$champ] ?? '';
        return is_scalar($valeur) ? Fiche::nettoyer_texte((string) $valeur) : '';
    }

    private static function date_valide(string $date): bool {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** Adresse http(s) valide, sinon vide. */
    private static function url(string $url): string {
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return '';
        }
        $schema = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($schema, ['http', 'https'], true) ? $url : '';
    }

    private static function erreur(int $numero, string $motif): string {
        /* translators: 1 : numéro de l'entrée dans le fichier ; 2 : motif du refus. */
        return sprintf(__('Entrée %1$d : %2$s', 'rsn-veille'), $numero, $motif);
    }

    private static function sans_bom(string $texte): string {
        return str_starts_with($texte, "\xEF\xBB\xBF") ? substr($texte, 3) : $texte;
    }
}

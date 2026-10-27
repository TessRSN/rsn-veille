<?php
declare(strict_types=1);

namespace RSN\Veille\Lecteurs;

use RSN\Veille\Fiche;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lecteur du calendrier d'IVADO. La page ne donne que des identifiants et des dates (éléments
 * « calendarEvents ») : les fiches sont incomplètes (titre vide, URL courte « ?p=id ») et la collecte
 * les complète avec `completer()` après avoir résolu l'URL.
 */
final class LecteurIvado extends LecteurHtml {
    protected function extraire(\DOMXPath $xpath, array $source, Resultat $resultat): void {
        $requete = '//*[' . self::classe('calendarEvents') . '][@data-event-id][@data-event-start][@data-event-end]';
        // Une série de séances partage un identifiant : début minimal, fin maximale.
        $evenements = [];
        foreach ($xpath->query($requete) as $element) {
            $id = $this->attribut($element, 'data-event-id');
            $debut = $this->attribut($element, 'data-event-start');
            $fin = $this->attribut($element, 'data-event-end');
            if ($id === '' || preg_match('/^\d{4}-\d{2}-\d{2}/', $debut) !== 1) {
                continue;
            }
            $fin = preg_match('/^\d{4}-\d{2}-\d{2}/', $fin) === 1 ? max($fin, $debut) : $debut;
            $evenements[$id] = isset($evenements[$id])
                ? [min($evenements[$id][0], $debut), max($evenements[$id][1], $fin)]
                : [$debut, $fin];
        }

        $vus = [];
        $position = 0;
        foreach ($evenements as $id => [$debut, $fin]) {
            $fiche = $this->identite($source, (string) $id);
            $fiche->url = 'https://ivado.ca/?p=' . $id;
            $this->dates($fiche, [$debut, $fin]);
            $this->classer($fiche);
            $this->ajouter($resultat, $fiche, $vus, $position++);
        }
    }

    /**
     * Résumés du flux des événements, par URL (sans barre oblique finale). Vide si le flux est absent ou invalide.
     *
     * @return array<string,string>
     */
    public static function resumes_par_url(array $source): array {
        $flux = (string) ($source['flux_evenements'] ?? '');
        if ($flux === '') {
            return [];
        }
        // Le mode « nouvelles » ne filtre pas les dates : une grande limite garde toutes les entrées.
        $resultat = (new LecteurRss())->lire($flux, ['id' => 'ivado', 'mode' => 'nouvelles', 'limite' => PHP_INT_MAX]);
        $resumes = [];
        foreach ($resultat->fiches as $fiche) {
            if ($fiche->resume !== '') {
                $resumes[rtrim($fiche->url, '/')] = $fiche->resume;
            }
        }
        return $resumes;
    }

    /**
     * Complète une fiche IVADO avec la page résolue : titre (sans le suffixe « | IVADO »), URL finale
     * et, si le flux en donne un pour cette URL, résumé. Format et région sont alors refaits avec le titre.
     *
     * @param array<string,string> $resumes_par_url Résultat de resumes_par_url().
     */
    public static function completer(Fiche $fiche, string $html_page, string $url_finale, array $resumes_par_url = []): void {
        $lecteur = new self();
        $document = $lecteur->document($html_page);
        $titre = $document === null ? '' : $lecteur->texte($document->getElementsByTagName('title')->item(0));
        $titre = trim(preg_replace('/\s*\|\s*IVADO\s*$/iu', '', $titre) ?? $titre);
        if ($titre !== '') {
            $fiche->titre = $titre;
        }
        $fiche->url = $url_finale;
        $resume = $resumes_par_url[rtrim($url_finale, '/')] ?? '';
        if ($resume !== '') {
            $fiche->resume = $resume;
        }
        $lecteur->classer($fiche);
    }
}

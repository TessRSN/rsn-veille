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
 * les complète avec `completer()` après avoir résolu l'URL. En anglais, l'adresse courte est « /en/?p=id ».
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

        // Adresse courte de l'événement : IVADO renvoie « ?p=id » sans « /en/ » vers la page française, même pour
        // l'identifiant d'une traduction anglaise ; la version anglaise passe donc par « /en/?p=id ».
        $courte = ($source['langue'] ?? '') === 'en' ? 'https://ivado.ca/en/?p=' : 'https://ivado.ca/?p=';
        $vus = [];
        $position = 0;
        foreach ($evenements as $id => [$debut, $fin]) {
            $fiche = $this->identite($source, (string) $id);
            $fiche->url = $courte . $id;
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
     * Reconnaît une page de redirection (IVADO en publie pour des événements hébergés ailleurs) : « redirect » dans le
     * titre ou le premier titre h1, un `meta refresh`, ou une affectation de `window.location` / `location.href`
     * dans un script. Null si la page n'en est pas une ; sinon l'adresse cible http(s), ou '' si elle est illisible.
     * La cible d'une affectation est un littéral ou une variable affectée par un littéral dans un script ; une affectation
     * d'une variable jamais définie (le suivi commun à toutes les pages d'IVADO) n'est pas une redirection.
     *
     * @return array{cible: string}|null
     */
    public static function redirection(string $html): ?array {
        $lecteur = new self();
        $document = $lecteur->document($html);
        if ($document === null) {
            return null;
        }
        $xpath = $lecteur->xpath($document);
        $est = false;
        foreach (['title', 'h1'] as $balise) {
            $texte = $lecteur->texte($document->getElementsByTagName($balise)->item(0));
            $est = $est || stripos($texte, 'redirect') !== false;
        }

        $cible = '';
        foreach ($xpath->query('//meta[translate(@http-equiv, "REFSH", "refsh")="refresh"]') as $meta) {
            $est = true;
            if ($cible === '' && preg_match('/url\s*=\s*[\'"]?([^\'";\s]+)/i', $lecteur->attribut($meta, 'content'), $m) === 1) {
                $cible = $m[1];
            }
        }

        $scripts = '';
        foreach ($document->getElementsByTagName('script') as $script) {
            $scripts .= $script->textContent . "\n";
        }
        // Toutes les pages d'IVADO portent un suivi qui fait « window.location = lien » seulement si la variable
        // `lien` existe : une affectation compte donc seulement si sa valeur est un littéral ou une variable définie.
        $motif = '/(?:(?<![\w$.])(?:window|document|self|top)\s*\.\s*location|(?<![\w$.])location)(?:\s*\.\s*href)?\s*=(?!=)\s*([^;\n]+)/';
        if (preg_match_all($motif, $scripts, $affectations) > 0) {
            foreach ($affectations[1] as $valeur) {
                $valeur = trim($valeur);
                if (preg_match('/^([\'"])(.*?)\1/', $valeur, $l) === 1
                    || (preg_match('/^([A-Za-z_$][\w$]*)\s*$/', $valeur, $v) === 1
                        && preg_match('/\b' . preg_quote($v[1], '/') . '\s*=\s*([\'"])(.*?)\1/', $scripts, $l) === 1)) {
                    $est = true;
                    $cible = $cible !== '' ? $cible : $l[2];
                    break;
                }
            }
        }

        if (!$est) {
            return null;
        }
        $cible = trim(html_entity_decode($cible, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        return ['cible' => preg_match('~^https?://[^\s]+$~i', $cible) === 1 ? $cible : ''];
    }

    /**
     * Complète une fiche IVADO avec la page résolue : titre (sans le suffixe « | IVADO »), URL finale
     * et, si le flux en donne un pour cette URL, résumé. Format et région sont alors refaits avec le titre.
     * Une page de redirection ne donne pas de résumé et ne refait ni format ni région : l'adresse devient la cible (sinon l'URL résolue) et le titre de la balise <title> n'est pris que si la fiche n'en a pas et qu'il ne contient pas « redirect ».
     *
     * @param array<string,string> $resumes_par_url Résultat de resumes_par_url().
     */
    public static function completer(Fiche $fiche, string $html_page, string $url_finale, array $resumes_par_url = []): void {
        $lecteur = new self();
        $redirection = self::redirection($html_page);
        $document = $lecteur->document($html_page);
        $titre = $document === null ? '' : $lecteur->texte($document->getElementsByTagName('title')->item(0));
        $titre = trim(preg_replace('/\s*\|\s*IVADO\s*$/iu', '', $titre) ?? $titre);
        if ($redirection !== null) {
            // Le flux d'IVADO ne donne pas de titre : sans lui la fiche serait ignorée. Un titre déjà posé est gardé.
            // Le <title> d'une page de passage (« Redirect », « Redirecting… ») n'est pas celui de l'événement.
            if ($fiche->titre === '' && $titre !== '' && stripos($titre, 'redirect') === false) {
                $fiche->titre = $titre;
            }
            $fiche->url = $redirection['cible'] !== '' ? $redirection['cible'] : $url_finale;
            return;
        }
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

<?php
declare(strict_types=1);

namespace RSN\Veille\Lecteurs;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lecteur de la page des événements du Fonds de recherche du Québec : un <article> par événement.
 */
final class LecteurFrqEvenements extends LecteurHtml {
    protected function extraire(\DOMXPath $xpath, array $source, Resultat $resultat): void {
        $vus = [];
        foreach ($xpath->query('//article[' . self::classe('events__list__el') . ']') as $position => $bloc) {
            $lien = $this->premier($xpath, './/h3/a', $bloc);
            $url = $this->attribut($lien, 'href');
            $fiche = $this->evenement($source, $this->chemin($url), [
                'titre' => $this->texte($lien),
                'url' => $url,
                // L'attribut src est un gabarit vide : la vraie image est chargée paresseusement.
                'image_url' => $this->attribut($this->premier($xpath, './/img[@data-lazy-src]', $bloc), 'data-lazy-src'),
                'resume' => $this->texte($this->premier($xpath, './/header/following-sibling::p[1]', $bloc)),
                'datetimes' => $this->datetimes($xpath, $bloc),
            ]);
            $this->ajouter($resultat, $fiche, $vus, $position);
        }
    }
}

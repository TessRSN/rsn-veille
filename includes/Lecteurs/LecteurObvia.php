<?php
declare(strict_types=1);

namespace RSN\Veille\Lecteurs;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lecteur de la page des événements d'Obvia (Drupal) : un <article> par événement.
 */
final class LecteurObvia extends LecteurHtml {
    protected function extraire(\DOMXPath $xpath, array $source, Resultat $resultat): void {
        $vus = [];
        foreach ($xpath->query('//article[' . self::classe('node--type-event') . ']') as $position => $bloc) {
            $lien = $this->premier($xpath, './/h2/a', $bloc);
            $url = $this->attribut($lien, 'href');
            $fiche = $this->evenement($source, $this->chemin($url), [
                'titre' => $this->texte($lien),
                'url' => $url,
                'image_url' => $this->attribut($this->premier($xpath, './/img[@src]', $bloc), 'src'),
                'resume' => $this->texte($this->premier($xpath, './/*[' . self::classe('inner-summary') . ']', $bloc)),
                'datetimes' => $this->datetimes($xpath, $bloc),
            ]);
            if ($fiche !== null) {
                // Les dates d'Obvia sont toutes à midi UTC : c'est un jour, pas une heure.
                $fiche->heure = '';
            }
            $this->ajouter($resultat, $fiche, $vus, $position);
        }
    }
}

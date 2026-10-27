<?php
declare(strict_types=1);

namespace RSN\Veille\Lecteurs;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lecteur de la page des événements de Mila (Drupal) : un <div data-nid> par événement.
 */
final class LecteurMila extends LecteurHtml {
    protected function extraire(\DOMXPath $xpath, array $source, Resultat $resultat): void {
        $vus = [];
        $requete = '//div[@data-nid][./div[' . self::classe('node--type-event') . ']]';
        foreach ($xpath->query($requete) as $position => $bloc) {
            $identifiant = $this->attribut($bloc, 'data-nid');
            if ($identifiant === '') {
                $resultat->erreurs[] = sprintf('Événement %d sans identifiant', $position + 1);
                continue;
            }
            $lien = $this->premier($xpath, './/*[' . self::classe('field-name-node-title') . ']//a', $bloc);
            $lieu = $this->texte($this->premier($xpath, './/*[' . self::classe('field-name-field-text1') . ']', $bloc));
            $fiche = $this->evenement($source, $identifiant, [
                'titre' => $this->texte($lien),
                'url' => $this->attribut($lien, 'href'),
                'image_url' => $this->attribut($this->premier($xpath, './/img[starts-with(@src, "/sites/default/files/")]', $bloc), 'src'),
                'resume' => $this->texte($this->premier($xpath, './/*[' . self::classe('field-name-body') . ']', $bloc)),
                'lieu' => $lieu,
                'ville' => $lieu !== '' ? 'Montréal' : '',
                'datetimes' => $this->datetimes($xpath, $bloc),
            ]);
            $this->ajouter($resultat, $fiche, $vus, $position);
        }
    }
}

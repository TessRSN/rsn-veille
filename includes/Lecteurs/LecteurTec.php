<?php
declare(strict_types=1);

namespace RSN\Veille\Lecteurs;

use RSN\Veille\Fiche;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lecteur de l'API REST de The Events Calendar (JSON, clé « events »).
 */
final class LecteurTec implements Lecteur {
    public function lire(string $contenu, array $source): Resultat {
        $resultat = new Resultat();
        $donnees = json_decode($contenu, true);
        if (!is_array($donnees) || !isset($donnees['events']) || !is_array($donnees['events'])) {
            $resultat->erreurs[] = 'JSON invalide ou sans événements';
            return $resultat;
        }

        $aujourdhui = \rsn_veille_aujourdhui();
        $inclure_passes = !empty($source['inclure_passes']);

        foreach ($donnees['events'] as $position => $evenement) {
            $id = is_array($evenement) ? ($evenement['id'] ?? '') : '';
            $titre = is_array($evenement) && is_string($evenement['title'] ?? null)
                ? Fiche::nettoyer_texte($evenement['title']) : '';
            $debut = is_array($evenement) && is_string($evenement['start_date'] ?? null)
                ? $evenement['start_date'] : '';
            if (!is_scalar($id) || (string) $id === '' || $titre === '' || $debut === '') {
                $resultat->erreurs[] = sprintf('Événement %d sans id, titre ou date de début', $position + 1);
                continue;
            }

            $fiche = $this->fiche($evenement, (string) $id, $titre, $source);
            if (!$inclure_passes && ($fiche->fin ?? $fiche->debut) < $aujourdhui) {
                $resultat->ignores++;
                continue;
            }
            $resultat->fiches[] = $fiche;
        }
        return $resultat;
    }

    private function fiche(array $e, string $id, string $titre, array $source): Fiche {
        $fiche = new Fiche();
        $fiche->type = 'evenement';
        $fiche->titre = $titre;
        $fiche->debut = substr($e['start_date'], 0, 10);
        $fin = is_string($e['end_date'] ?? null) ? substr($e['end_date'], 0, 10) : '';
        $fiche->fin = $fin !== '' ? $fin : $fiche->debut;
        $fiche->heure = empty($e['all_day']) ? (string) substr($e['start_date'], 11, 5) : '';

        $lieu = $this->premier_objet($e['venue'] ?? null);
        $fiche->lieu = $this->texte($lieu['venue'] ?? null);
        $fiche->ville = $this->texte($lieu['city'] ?? null);

        $organisateur = $this->premier_objet($e['organizer'] ?? null);
        $fiche->organisateur = $this->texte($organisateur['organizer'] ?? null);
        if ($fiche->organisateur === '') {
            $fiche->organisateur = $this->texte($source['organisateur'] ?? null) ?: $this->texte($source['nom'] ?? null);
        }

        $fiche->image_url = $this->image($e['image'] ?? null);

        if (($source['nom'] ?? null) === 'RSN') {
            $categorie = $this->premier_objet($e['categories'] ?? null);
            $fiche->categorie_rsn = $this->texte($categorie['name'] ?? null);
        }

        $fiche->prix = $this->texte($e['cost'] ?? null);
        $brut = $this->texte($e['excerpt'] ?? null) ?: $this->texte($e['description'] ?? null);
        $fiche->resume = Fiche::court($brut);
        $fiche->format = Fiche::deviner_format(
            $fiche->titre . ' ' . $fiche->resume . ' ' . $fiche->lieu,
            !empty($e['is_virtual'])
        );
        $fiche->region = Fiche::deviner_region($fiche->ville, $fiche->lieu, $fiche->format);
        $fiche->langue = (string) ($source['langue'] ?? 'fr');
        $fiche->url = is_string($e['url'] ?? null) ? $e['url'] : '';
        $fiche->source = (string) ($source['nom'] ?? '');
        $fiche->niveau = (string) ($source['niveau'] ?? '');
        $fiche->cle_source = ($source['id'] ?? '') . ':' . $id;
        $fiche->cles_source = [$fiche->cle_source];
        return $fiche;
    }

    /** Premier objet d'une valeur qui peut être un objet, une liste d'objets ou vide. */
    private function premier_objet(mixed $valeur): array {
        if (!is_array($valeur) || $valeur === []) {
            return [];
        }
        if (array_key_exists(0, $valeur)) {
            return is_array($valeur[0]) ? $valeur[0] : [];
        }
        return $valeur;
    }

    /** Texte nettoyé d'une valeur quelconque ; chaîne vide si ce n'est pas une chaîne. */
    private function texte(mixed $valeur): string {
        return is_string($valeur) ? Fiche::nettoyer_texte($valeur) : '';
    }

    /** Image à utiliser : medium_large, sinon medium, sinon l'originale (« image » peut valoir false). */
    private function image(mixed $image): string {
        if (!is_array($image)) {
            return '';
        }
        foreach ([$image['sizes']['medium_large']['url'] ?? null, $image['sizes']['medium']['url'] ?? null, $image['url'] ?? null] as $url) {
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }
        return '';
    }
}

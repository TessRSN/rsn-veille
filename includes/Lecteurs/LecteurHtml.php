<?php
declare(strict_types=1);

namespace RSN\Veille\Lecteurs;

use RSN\Veille\Fiche;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Base des lecteurs de pages HTML d'événements : chargement du document, accès aux nœuds
 * et construction commune des fiches. Chaque sous-classe ne connaît que les repères de sa page.
 */
abstract class LecteurHtml implements Lecteur {
    /** Repère les blocs d'événements de la page et les ajoute au résultat. */
    abstract protected function extraire(\DOMXPath $xpath, array $source, Resultat $resultat): void;

    public function lire(string $contenu, array $source): Resultat {
        $resultat = new Resultat();
        $document = $this->document($contenu);
        if ($document === null) {
            $resultat->erreurs[] = 'Contenu HTML vide ou invalide';
            return $resultat;
        }
        $this->extraire($this->xpath($document), $source, $resultat);
        return $resultat;
    }

    /** Document HTML (lu en UTF-8, sans aucun avertissement libxml), ou null si le contenu n'est pas du HTML. */
    protected function document(string $html): ?\DOMDocument {
        // Sans aucune balise, ce n'est pas une page (chaîne vide, JSON, texte brut).
        if (preg_match('/<[a-z!]/i', $html) !== 1) {
            return null;
        }
        $precedent = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        // L'instruction XML en tête force la lecture en UTF-8, car HTML n'annonce pas toujours son encodage.
        $charge = $document->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($precedent);
        return $charge ? $document : null;
    }

    protected function xpath(\DOMDocument $document): \DOMXPath {
        return new \DOMXPath($document);
    }

    /** Condition XPath : l'attribut class contient ce mot entier. */
    protected static function classe(string $mot): string {
        return "contains(concat(' ', normalize-space(@class), ' '), ' " . $mot . " ')";
    }

    /** Premier nœud qui répond à la requête (relative au bloc), ou null. */
    protected function premier(\DOMXPath $xpath, string $requete, ?\DOMNode $bloc = null): ?\DOMNode {
        $trouves = $bloc === null ? $xpath->query($requete) : $xpath->query($requete, $bloc);
        return $trouves !== false && $trouves->length > 0 ? $trouves->item(0) : null;
    }

    /** Texte brut du contenu d'un nœud (HTML interne nettoyé). */
    protected function texte(?\DOMNode $noeud): string {
        if ($noeud === null) {
            return '';
        }
        $html = '';
        foreach ($noeud->childNodes as $enfant) {
            $html .= $noeud->ownerDocument->saveHTML($enfant);
        }
        return Fiche::nettoyer_texte($html);
    }

    protected function attribut(?\DOMNode $noeud, string $nom): string {
        return $noeud instanceof \DOMElement ? trim($noeud->getAttribute($nom)) : '';
    }

    /**
     * URL absolue (résolution simplifiée de la RFC 3986) : une URL avec schéma reste telle quelle ;
     * « //hôte/… » prend le schéma de la base ; « /chemin » part de l'origine de la base ; « ?x » garde le chemin
     * de la base ; sinon le chemin est relatif au répertoire de la base. Les segments « . » et « .. » sont résolus.
     */
    protected function absolue(string $url, string $base): string {
        $url = trim($url);
        if ($url === '' || preg_match('~^[a-z][a-z0-9+.-]*:~i', $url) === 1) {
            return $url;
        }
        $morceaux = parse_url(trim($base));
        if (!is_array($morceaux) || !isset($morceaux['scheme'], $morceaux['host'])) {
            return $url;
        }
        if (str_starts_with($url, '//')) {
            return $morceaux['scheme'] . ':' . $url;
        }
        $origine = $morceaux['scheme'] . '://' . $morceaux['host'] . (isset($morceaux['port']) ? ':' . $morceaux['port'] : '');
        $chemin_base = ($morceaux['path'] ?? '') !== '' ? $morceaux['path'] : '/';
        if ($url[0] === '?') {
            return $origine . $chemin_base . $url;
        }
        // Le chemin sans sa requête ni son ancre, puis ce qui suit (requête, ancre) tel quel.
        $coupure = strcspn($url, '?#');
        $chemin = substr($url, 0, $coupure);
        $suffixe = substr($url, $coupure);
        if ($chemin === '') {
            return $origine . $chemin_base . $suffixe;
        }
        if ($chemin[0] !== '/') {
            $chemin = substr($chemin_base, 0, (int) strrpos($chemin_base, '/') + 1) . $chemin;
        }

        $segments = explode('/', $chemin);
        $dernier = count($segments) - 1;
        $retenus = [];
        foreach ($segments as $position => $segment) {
            if ($position === 0) {
                continue; // Vide : le chemin commence par « / ».
            }
            if ($segment === '.' || $segment === '..') {
                if ($segment === '..') {
                    array_pop($retenus);
                }
                if ($position === $dernier) {
                    $retenus[] = ''; // « a/b/.. » désigne le répertoire « a/ ».
                }
                continue;
            }
            $retenus[] = $segment;
        }
        return $origine . '/' . implode('/', $retenus) . $suffixe;
    }

    /** Valeurs des <time datetime> d'un bloc, dans l'ordre du document. */
    protected function datetimes(\DOMXPath $xpath, \DOMNode $bloc): array {
        $valeurs = [];
        foreach ($xpath->query('.//time[@datetime]', $bloc) as $temps) {
            $valeurs[] = $this->attribut($temps, 'datetime');
        }
        return $valeurs;
    }

    /**
     * Identifiant naturel d'une page : chemin (sans barre oblique finale) et requête de son URL absolue, sans le domaine,
     * pour que la clé ne change pas si la source passe de liens relatifs à des liens absolus.
     */
    protected function chemin(string $url_absolue): string {
        $morceaux = parse_url($url_absolue);
        $chemin = rtrim((string) ($morceaux['path'] ?? ''), '/');
        $requete = isset($morceaux['query']) && $morceaux['query'] !== '' ? '?' . $morceaux['query'] : '';
        $identifiant = $chemin . $requete;
        return $identifiant !== '' ? $identifiant : $url_absolue;
    }

    /** Fiche d'événement avec ce que toutes les sources ont en commun. */
    protected function identite(array $source, string $identifiant): Fiche {
        $fiche = new Fiche();
        $fiche->type = 'evenement';
        $fiche->organisateur = (string) ($source['organisateur'] ?? $source['nom'] ?? '');
        $fiche->source = (string) ($source['nom'] ?? '');
        $fiche->niveau = (string) ($source['niveau'] ?? '');
        $fiche->langue = (string) ($source['langue'] ?? 'fr');
        $fiche->cle_source = ($source['id'] ?? '') . ':' . $identifiant;
        $fiche->cles_source = [$fiche->cle_source];
        return $fiche;
    }

    /**
     * Dates et heure depuis les datetime d'un bloc : début = premier, fin = dernier ;
     * l'heure est celle du premier, si elle existe et n'est pas minuit. Faux si aucune date lisible.
     */
    protected function dates(Fiche $fiche, array $datetimes): bool {
        $datetimes = array_values(array_filter($datetimes, static fn(string $d): bool => preg_match('/^\d{4}-\d{2}-\d{2}/', $d) === 1));
        if ($datetimes === []) {
            return false;
        }
        $premier = $datetimes[0];
        $fiche->debut = substr($premier, 0, 10);
        $fiche->fin = substr($datetimes[count($datetimes) - 1], 0, 10);
        $heure = preg_match('/^\d{4}-\d{2}-\d{2}[T ](\d{2}:\d{2})/', $premier, $m) === 1 ? $m[1] : '';
        $fiche->heure = $heure !== '00:00' ? $heure : '';
        return true;
    }

    /** Format et région devinés depuis le titre, le résumé et le lieu. */
    protected function classer(Fiche $fiche): void {
        $fiche->format = Fiche::deviner_format($fiche->titre . ' ' . $fiche->resume . ' ' . $fiche->lieu);
        $fiche->region = Fiche::deviner_region($fiche->ville, $fiche->lieu, $fiche->format);
    }

    /**
     * Fiche d'un bloc d'événement ordinaire, ou null s'il manque le titre, le lien ou la date.
     * Champs : titre, url, image_url, resume (texte brut), lieu, ville, datetimes.
     */
    protected function evenement(array $source, string $identifiant, array $champs): ?Fiche {
        $base = (string) ($source['base'] ?? '');
        $fiche = $this->identite($source, $identifiant);
        $fiche->titre = (string) ($champs['titre'] ?? '');
        $fiche->url = $this->absolue((string) ($champs['url'] ?? ''), $base);
        if ($fiche->titre === '' || $fiche->url === '' || !$this->dates($fiche, $champs['datetimes'] ?? [])) {
            return null;
        }
        $fiche->image_url = $this->absolue((string) ($champs['image_url'] ?? ''), $base);
        $fiche->resume = Fiche::court((string) ($champs['resume'] ?? ''));
        $fiche->lieu = (string) ($champs['lieu'] ?? '');
        $fiche->ville = (string) ($champs['ville'] ?? '');
        $this->classer($fiche);
        return $fiche;
    }

    /**
     * Ajoute la fiche au résultat : une erreur si le bloc est inutilisable, rien si l'événement
     * a déjà été vu sur la page, « ignoré » s'il est terminé.
     *
     * @param string[] $vus Clés de source déjà ajoutées (par référence).
     */
    protected function ajouter(Resultat $resultat, ?Fiche $fiche, array &$vus, int $position): void {
        if ($fiche === null) {
            $resultat->erreurs[] = sprintf('Événement %d sans titre, lien ou date', $position + 1);
            return;
        }
        if (isset($vus[$fiche->cle_source])) {
            return;
        }
        $vus[$fiche->cle_source] = true;
        if (($fiche->fin ?? $fiche->debut) < \rsn_veille_aujourdhui()) {
            $resultat->ignores++;
            return;
        }
        $resultat->fiches[] = $fiche;
    }
}

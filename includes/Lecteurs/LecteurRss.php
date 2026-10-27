<?php
declare(strict_types=1);

namespace RSN\Veille\Lecteurs;

use RSN\Veille\Fiche;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lecteur de flux RSS 2.0 et Atom, pour les événements, les appels et les nouvelles.
 */
final class LecteurRss implements Lecteur {
    private const NS_ATOM = 'http://www.w3.org/2005/Atom';
    private const NS_DC = 'http://purl.org/dc/elements/1.1/';
    private const NS_CONTENU = 'http://purl.org/rss/1.0/modules/content/';

    public function lire(string $contenu, array $source): Resultat {
        $resultat = new Resultat();
        $items = $this->items($contenu);
        if ($items === []) {
            $resultat->erreurs[] = 'Flux vide ou invalide';
            return $resultat;
        }

        $mode = (string) ($source['mode'] ?? 'nouvelles');
        if (!in_array($mode, ['evenements', 'appels', 'nouvelles'], true)) {
            $resultat->erreurs[] = 'Mode de source inconnu : ' . $mode;
            return $resultat;
        }

        $aujourdhui = \rsn_veille_aujourdhui();
        $retenues = [];
        foreach ($items as $position => $item) {
            $titre = Fiche::nettoyer_texte($this->texte($item, 'title'));
            $lien = $this->lien($item);
            if ($titre === '' || $lien === '') {
                $resultat->erreurs[] = sprintf('Élément %d sans titre ou sans lien', $position + 1);
                continue;
            }
            $date = $this->date($this->premier($item, [['published'], ['pubDate'], ['date', self::NS_DC], ['updated']]));
            $description = $this->premier($item, [['description'], ['encoded', self::NS_CONTENU], ['summary'], ['content']]);
            $categories = $this->categories($item);

            $fiche = new Fiche();
            $fiche->titre = $titre;
            $fiche->url = $lien;
            $fiche->debut = $date['jour'] ?? null;
            $fiche->fin = $fiche->debut;
            $fiche->resume = Fiche::court(Fiche::nettoyer_texte($description));
            $fiche->organisateur = (string) ($source['organisateur'] ?? $source['nom'] ?? '');
            $fiche->source = (string) ($source['nom'] ?? '');
            $fiche->niveau = (string) ($source['niveau'] ?? '');
            $fiche->langue = (string) ($source['langue'] ?? 'fr');
            $identifiant = $this->premier($item, [['guid'], ['id']]);
            $fiche->cle_source = ($source['id'] ?? '') . ':' . ($identifiant !== '' ? $identifiant : $lien);
            $fiche->cles_source = [$fiche->cle_source];

            if ($mode === 'evenements') {
                if ($fiche->debut === null) {
                    $resultat->erreurs[] = sprintf('Événement %d sans date lisible', $position + 1);
                    continue;
                }
                if ($fiche->debut < $aujourdhui) {
                    $resultat->ignores++;
                    continue;
                }
                $fiche->type = 'evenement';
                $fiche->heure = ($date['heure'] ?? '') !== '00:00' ? $date['heure'] : '';
                $fiche->format = Fiche::deviner_format($titre . ' ' . $fiche->resume);
                $fiche->region = Fiche::deviner_region('', '', $fiche->format);
            } elseif ($mode === 'appels') {
                $categorie = $this->categorie_retenue($categories, $titre, $source);
                if ($categorie === null) {
                    continue;
                }
                $fiche->type = 'appel';
                $fiche->categorie = $categorie;
                // Date limite : dans l'extrait, puis dans le contenu complet (content:encoded), puis dans le titre.
                // Une date sans année se lit par rapport au jour de publication, pas à aujourd'hui.
                $reference = $fiche->debut ?? $aujourdhui;
                $fiche->echeance = Echeance::trouver($description, $reference)
                    ?? Echeance::trouver($this->texte($item, 'encoded', self::NS_CONTENU), $reference)
                    ?? Echeance::trouver($titre, $reference);
                $fiche->fin = $fiche->echeance ?? $fiche->debut;
            } else {
                $fiche->type = 'nouvelle';
            }
            $retenues[] = [($date['jour'] ?? '') . ' ' . ($date['heure'] ?? ''), $fiche];
        }

        if ($mode === 'nouvelles') {
            usort($retenues, static fn(array $a, array $b): int => strcmp($b[0], $a[0]));
            $retenues = array_slice($retenues, 0, (int) ($source['limite'] ?? 8));
        }
        $resultat->fiches = array_column($retenues, 1);
        return $resultat;
    }

    /** @return \SimpleXMLElement[] Les <item> (RSS 2.0) ou les <entry> (Atom) ; vide si ce n'est pas un flux. */
    private function items(string $contenu): array {
        $precedent = libxml_use_internal_errors(true);
        // Les flux WordPress commencent parfois par des sauts de ligne ou un BOM avant « <?xml ».
        $contenu = ltrim($contenu, " \t\r\n\0\x0B\xEF\xBB\xBF");
        $xml = simplexml_load_string($contenu, \SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($precedent);
        if (!$xml instanceof \SimpleXMLElement) {
            return [];
        }
        $liste = [];
        foreach ($xml->channel->item ?? [] as $item) {
            $liste[] = $item;
        }
        // Atom : espace de noms par défaut d'abord, puis <atom:feed> préfixé (jamais les deux : mêmes nœuds).
        $entrees = $xml->entry;
        if (count($entrees) === 0) {
            $entrees = $xml->children(self::NS_ATOM)->entry;
        }
        foreach ($entrees as $entree) {
            $liste[] = $entree;
        }
        return $liste;
    }

    /** Enfants d'un nom donné : espace de noms indiqué, sinon espace par défaut puis, s'il est vide, celui d'Atom. */
    private function enfants(\SimpleXMLElement $item, string $nom, ?string $espace = null): array {
        $candidats = $espace === null ? [null, self::NS_ATOM] : [$espace];
        foreach ($candidats as $ns) {
            $parent = $ns === null ? $item : $item->children($ns);
            $trouves = [];
            foreach ($parent->{$nom} as $enfant) {
                $trouves[] = $enfant;
            }
            if ($trouves !== []) {
                return $trouves;
            }
        }
        return [];
    }

    private function texte(\SimpleXMLElement $item, string $nom, ?string $espace = null): string {
        foreach ($this->enfants($item, $nom, $espace) as $enfant) {
            $texte = trim((string) $enfant);
            if ($texte !== '') {
                return $texte;
            }
        }
        return '';
    }

    /** Premier texte non vide parmi des couples [nom, espace de noms optionnel]. */
    private function premier(\SimpleXMLElement $item, array $candidats): string {
        foreach ($candidats as $c) {
            $texte = $this->texte($item, $c[0], $c[1] ?? null);
            if ($texte !== '') {
                return $texte;
            }
        }
        return '';
    }

    /** Lien : texte de <link> (RSS) ou href du <link> sans rel ou rel="alternate" (Atom) ; jamais self ni enclosure. */
    private function lien(\SimpleXMLElement $item): string {
        $liens = $this->enfants($item, 'link');
        foreach ($liens as $lien) {
            $texte = trim((string) $lien);
            if ($texte !== '') {
                return $texte;
            }
        }
        foreach ($liens as $lien) {
            $rel = $this->attribut($lien, 'rel');
            if (($rel === '' || $rel === 'alternate') && $this->attribut($lien, 'href') !== '') {
                return $this->attribut($lien, 'href');
            }
        }
        return '';
    }

    /** Attribut sans espace de noms (lu par attributes() : l'accès direct échoue sur un élément <atom:…>). */
    private function attribut(\SimpleXMLElement $noeud, string $nom): string {
        return trim((string) ($noeud->attributes()[$nom] ?? ''));
    }

    /** @return string[] */
    private function categories(\SimpleXMLElement $item): array {
        $categories = [];
        foreach ($this->enfants($item, 'category') as $c) {
            $valeur = Fiche::nettoyer_texte((string) $c) ?: Fiche::nettoyer_texte($this->attribut($c, 'term'));
            if ($valeur !== '') {
                $categories[] = $valeur;
            }
        }
        return $categories;
    }

    /** Catégorie retenue pour un appel (première des catégories voulues, ou « Appel » si le titre correspond), sinon null. */
    private function categorie_retenue(array $categories, string $titre, array $source): ?string {
        $voulues = array_map([Fiche::class, 'nettoyer_texte'], array_map('strval', (array) ($source['categories'] ?? [])));
        foreach ($categories as $categorie) {
            if (in_array($categorie, $voulues, true)) {
                return $categorie;
            }
        }
        $motif = (string) ($source['motif_titre'] ?? '');
        // Le « @ » masque l'avertissement d'une expression invalide : elle ne retient alors rien.
        if ($motif !== '' && @preg_match('~' . str_replace('~', '\~', $motif) . '~iu', $titre) === 1) {
            return 'Appel';
        }
        return null;
    }

    /**
     * Jour et heure dans le fuseau propre de la date du flux (celui de son décalage ; sans décalage, lue telle quelle) :
     * ['jour' => Y-m-d, 'heure' => H:i], ou null si elle est illisible.
     * Accepte le RFC 2822, l'ISO 8601 et les dates Drupal « jeu, 10/22/2026 - 08:25 ».
     */
    private function date(string $texte): ?array {
        $texte = trim($texte);
        if ($texte === '') {
            return null;
        }
        try {
            if (preg_match('~(\d{1,2})/(\d{1,2})/(\d{4})(?:\s*-\s*(\d{1,2}):(\d{2}))?~', $texte, $m)) {
                if (!checkdate((int) $m[1], (int) $m[2], (int) $m[3])) {
                    return null;
                }
                $date = new \DateTimeImmutable(sprintf('%04d-%02d-%02d %02d:%02d', $m[3], $m[1], $m[2], $m[4] ?? 0, $m[5] ?? 0));
            } else {
                $date = new \DateTimeImmutable($texte);
            }
        } catch (\Exception $e) {
            return null;
        }
        return ['jour' => $date->format('Y-m-d'), 'heure' => $date->format('H:i')];
    }
}

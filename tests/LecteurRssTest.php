<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RSN\Veille\Lecteurs\LecteurRss;

final class LecteurRssTest extends TestCase {
    private function rss(string $items): string {
        return '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel><title>T</title>' . $items . '</channel></rss>';
    }

    public function test_sommeil_evenements(): void {
        $r = (new LecteurRss())->lire(file_get_contents(FIXTURES . '/sommeil.xml'), ['id' => 'sommeil', 'nom' => 'Réseau sommeil', 'mode' => 'evenements', 'niveau' => 'A', 'type_source' => 'sante', 'organisateur' => 'Réseau québécois de recherche sur le sommeil']);
        $this->assertCount(3, $r->fiches);
        $this->assertSame(['2026-10-28', 'Virtuel'], [$r->fiches[0]->debut, $r->fiches[0]->format]);
        $f = $r->fiches[0];
        $this->assertSame(['evenement', '2026-10-28', '17:30', 'En ligne', 'A', 'Réseau sommeil'], [$f->type, $f->fin, $f->heure, $f->region, $f->niveau, $f->source]);
        $this->assertSame('sommeil:https://reseausommeil.ca/?post_type=mec-events&p=6764', $f->cle_source);
        $this->assertSame([$f->cle_source], $f->cles_source);
        $this->assertStringStartsWith('Le Réseau québécois', $f->resume);
    }

    public function test_frq_appels_par_categorie(): void {
        $r = (new LecteurRss())->lire(file_get_contents(FIXTURES . '/frq.xml'), ['id' => 'frq', 'nom' => 'FRQ', 'mode' => 'appels', 'categories' => ['Appel'], 'niveau' => 'B', 'type_source' => 'sante_numerique', 'organisateur' => 'Fonds de recherche du Québec']);
        $this->assertCount(5, $r->fiches);
        $this->assertSame('appel', $r->fiches[0]->type);
        $this->assertSame(['2026-10-15', 'Appel'], [$r->fiches[0]->debut, $r->fiches[0]->categorie]);
    }

    public function test_rsn_echeance_cherchee_aussi_dans_le_contenu_complet(): void {
        // Fixture réelle : la description (extrait) ne donne pas la date limite, le content:encoded oui.
        $r = (new LecteurRss())->lire(file_get_contents(FIXTURES . '/rsn.xml'), ['id' => 'rsn_appels', 'nom' => 'RSN', 'mode' => 'appels', 'categories' => ['Appels de financement'], 'niveau' => 'B']);
        $parTitre = [];
        foreach ($r->fiches as $f) {
            $parTitre[mb_substr($f->titre, 0, 30)] = [$f->debut, $f->echeance, $f->fin];
        }
        $this->assertSame(['2026-10-23', '2026-12-13', '2026-12-13'], $parTitre['Appel à projets de recherche 2']);
        $this->assertSame(['2026-06-22', '2026-08-07', '2026-08-07'], $parTitre['Le RSN lance un programme de f']);
    }

    public function test_echeance_sans_annee_lue_par_rapport_a_la_publication(): void {
        // Publié en mars, « date limite : 15 mai » : mai 2026 (déjà passé), pas mai 2027.
        $xml = $this->rss('<item><title>Appel ancien</title><link>https://x.test/1</link><pubDate>Mon, 09 Mar 2026 10:00:00 -0400</pubDate><category>Appel</category>'
            . '<description>Un concours.</description><content:encoded><![CDATA[<p>Date limite : 15 mai.</p>]]></content:encoded></item>');
        $r = (new LecteurRss())->lire($xml, ['id' => 'x', 'nom' => 'X', 'mode' => 'appels', 'categories' => ['Appel']]);
        $this->assertSame('2026-05-15', $r->fiches[0]->echeance);
    }

    public function test_crm_echeance_extraite(): void {
        $r = (new LecteurRss())->lire(file_get_contents(FIXTURES . '/crm.xml'), ['id' => 'crm', 'nom' => 'CRM', 'mode' => 'appels', 'categories' => ['Appel', 'Concours ouvert'], 'niveau' => 'B', 'type_source' => 'generaliste', 'organisateur' => 'CRM']);
        $stages = array_values(array_filter($r->fiches, fn($f) => str_starts_with($f->titre, 'Stages postdoctoraux')));
        $this->assertSame('2026-11-30', $stages[0]->echeance);
        $this->assertSame('2026-11-30', $stages[0]->fin);
        $this->assertSame('2026-09-10', $stages[0]->debut);
    }

    public function test_inspq_date_drupal(): void {
        $r = (new LecteurRss())->lire(file_get_contents(FIXTURES . '/inspq.xml'), ['id' => 'inspq', 'nom' => 'INSPQ', 'mode' => 'nouvelles', 'niveau' => 'B', 'type_source' => 'sante', 'organisateur' => 'INSPQ']);
        $this->assertMatchesRegularExpression('/^2026-\d\d-\d\d$/', $r->fiches[0]->debut);
        $this->assertSame('2026-10-22', $r->fiches[0]->debut);
        $this->assertCount(8, $r->fiches);
        $this->assertSame('nouvelle', $r->fiches[0]->type);
    }

    public function test_html_a_la_place_du_flux(): void {
        $r = (new LecteurRss())->lire('<!doctype html><html><body>Accueil</body></html>', ['id' => 'x', 'nom' => 'X', 'mode' => 'nouvelles', 'niveau' => 'B', 'type_source' => 'sante']);
        $this->assertSame([], $r->fiches);
        $this->assertSame(['Flux vide ou invalide'], $r->erreurs);
    }

    public function test_xml_sans_item(): void {
        $r = (new LecteurRss())->lire($this->rss(''), ['id' => 'x', 'nom' => 'X', 'mode' => 'nouvelles']);
        $this->assertSame([], $r->fiches);
        $this->assertSame(['Flux vide ou invalide'], $r->erreurs);
    }

    public function test_evenements_passes_ignores(): void {
        $xml = $this->rss('<item><title>Passé</title><link>https://x.test/1</link><pubDate>Mon, 19 Oct 2026 10:00:00 -0400</pubDate></item>'
            . '<item><title>Futur</title><link>https://x.test/2</link><pubDate>Fri, 30 Oct 2026 00:00:00 -0400</pubDate></item>');
        $r = (new LecteurRss())->lire($xml, ['id' => 'x', 'nom' => 'X', 'mode' => 'evenements']);
        $this->assertCount(1, $r->fiches);
        $this->assertSame(1, $r->ignores);
        $this->assertSame(['Futur', ''], [$r->fiches[0]->titre, $r->fiches[0]->heure]);
    }

    public function test_appel_retenu_par_titre_et_echeance_dans_le_titre(): void {
        $xml = $this->rss('<item><title>Appel de projets : échéance 15 novembre 2026</title><link>https://x.test/1</link><pubDate>Mon, 19 Oct 2026 10:00:00 -0400</pubDate><category>Actualité</category></item>'
            . '<item><title>Autre sujet</title><link>https://x.test/2</link><pubDate>Mon, 19 Oct 2026 10:00:00 -0400</pubDate><category>Actualité</category></item>');
        $r = (new LecteurRss())->lire($xml, ['id' => 'x', 'nom' => 'X', 'mode' => 'appels', 'categories' => ['Appel'], 'motif_titre' => '^appel\b']);
        $this->assertCount(1, $r->fiches);
        $this->assertSame(['Appel', '2026-11-15'], [$r->fiches[0]->categorie, $r->fiches[0]->echeance]);
    }

    public function test_nouvelles_limite_et_tri_decroissant(): void {
        $items = '';
        foreach ([3, 9, 5, 7] as $jour) {
            $items .= sprintf('<item><title>N%d</title><link>https://x.test/%d</link><pubDate>%02d Sep 2026 10:00:00 -0400</pubDate></item>', $jour, $jour, $jour);
        }
        $r = (new LecteurRss())->lire($this->rss($items), ['id' => 'x', 'nom' => 'X', 'mode' => 'nouvelles', 'limite' => 2]);
        $this->assertSame(['N9', 'N7'], array_map(fn($f) => $f->titre, $r->fiches));
    }

    public function test_atom(): void {
        $xml = '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><title>A</title>'
            . '<entry><title>Entrée</title><id>urn:uuid:1</id><link rel="self" href="https://x.test/self"/><link rel="alternate" href="https://x.test/e1"/>'
            . '<updated>2026-10-21T08:00:00Z</updated><summary>Résumé &lt;b&gt;court&lt;/b&gt;</summary><category term="Appel"/><category term="Autre"/></entry>'
            . '<entry><title>Seconde</title><id>urn:uuid:2</id><link href="https://x.test/e2"/><updated>2026-10-22T08:00:00Z</updated><category term="Appel"/></entry></feed>';
        $r = (new LecteurRss())->lire($xml, ['id' => 'a', 'nom' => 'A', 'mode' => 'appels', 'categories' => ['Autre']]);
        $this->assertSame([], $r->erreurs);
        $this->assertCount(1, $r->fiches);
        $f = $r->fiches[0];
        $this->assertSame(['https://x.test/e1', '2026-10-21', 'Résumé court', 'a:urn:uuid:1', 'Autre'], [$f->url, $f->debut, $f->resume, $f->cle_source, $f->categorie]);

        $r = (new LecteurRss())->lire($xml, ['id' => 'a', 'nom' => 'A', 'mode' => 'nouvelles']);
        $this->assertSame([], $r->erreurs);
        $this->assertCount(2, $r->fiches);
        $this->assertSame('https://x.test/e2', $r->fiches[0]->url);
    }

    public function test_atom_prefixe(): void {
        $xml = '<?xml version="1.0"?><a:feed xmlns:a="http://www.w3.org/2005/Atom"><a:entry><a:title>P</a:title><a:id>1</a:id><a:link href="https://x.test/p"/><a:updated>2026-10-21T08:00:00Z</a:updated></a:entry></a:feed>';
        $r = (new LecteurRss())->lire($xml, ['id' => 'a', 'nom' => 'A', 'mode' => 'nouvelles']);
        $this->assertSame([], $r->erreurs);
        $this->assertSame(['P', 'https://x.test/p'], [$r->fiches[0]->titre, $r->fiches[0]->url]);
    }

    public function test_atom_deux_categories_exactement(): void {
        $xml = '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><entry><title>T</title><id>1</id><link href="https://x.test/1"/><updated>2026-10-21T08:00:00Z</updated><category term="Appel"/><category term="Concours"/></entry></feed>';
        $r = (new LecteurRss())->lire($xml, ['id' => 'a', 'nom' => 'A', 'mode' => 'appels', 'categories' => ['Concours']]);
        $this->assertSame('Concours', $r->fiches[0]->categorie);
        $m = new ReflectionMethod(LecteurRss::class, 'categories');
        $m->setAccessible(true);
        $this->assertSame(['Appel', 'Concours'], $m->invoke(new LecteurRss(), simplexml_load_string($xml)->entry));
    }

    public function test_contenu_precede_de_sauts_de_ligne_ou_bom(): void {
        $source = ['id' => 'sommeil', 'nom' => 'S', 'mode' => 'evenements'];
        $contenu = file_get_contents(FIXTURES . '/sommeil.xml');
        $this->assertCount(3, (new LecteurRss())->lire("\n\n" . $contenu, $source)->fiches);
        $this->assertCount(3, (new LecteurRss())->lire("\xEF\xBB\xBF" . $contenu, $source)->fiches);
    }

    public function test_lien_atom_jamais_self_ni_enclosure(): void {
        $xml = '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><entry><title>T</title><id>1</id><link rel="enclosure" href="https://x.test/fichier.mp3"/><updated>2026-10-21T08:00:00Z</updated></entry></feed>';
        $r = (new LecteurRss())->lire($xml, ['id' => 'a', 'nom' => 'A', 'mode' => 'nouvelles']);
        $this->assertSame([], $r->fiches);
        $this->assertCount(1, $r->erreurs);
    }

    public function test_fuseau_propre_de_la_date_du_flux(): void {
        $xml = $this->rss('<item><title>Minuit</title><link>https://x.test/1</link><pubDate>Wed, 28 Oct 2026 00:00:00 +0000</pubDate></item>');
        $f = (new LecteurRss())->lire($xml, ['id' => 'x', 'nom' => 'X', 'mode' => 'evenements'])->fiches[0];
        $this->assertSame(['2026-10-28', ''], [$f->debut, $f->heure]);
    }

    public function test_item_sans_titre_ou_sans_lien_en_erreur(): void {
        $xml = $this->rss('<item><title>Sans lien</title></item><item><link>https://x.test/1</link></item><item><title>Bon</title><link>https://x.test/3</link><pubDate>Mon, 19 Oct 2026 10:00:00 -0400</pubDate></item>');
        $r = (new LecteurRss())->lire($xml, ['id' => 'x', 'nom' => 'X', 'mode' => 'nouvelles']);
        $this->assertCount(1, $r->fiches);
        $this->assertCount(2, $r->erreurs);
    }
}

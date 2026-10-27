<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RSN\Veille\Fiche;
use RSN\Veille\Lecteurs\LecteurFrqEvenements;
use RSN\Veille\Lecteurs\LecteurIvado;
use RSN\Veille\Lecteurs\LecteurMila;
use RSN\Veille\Lecteurs\LecteurObvia;

final class LecteursHtmlTest extends TestCase {
    private const OBVIA = ['id' => 'obvia', 'nom' => 'Obvia', 'base' => 'https://www.obvia.ca', 'niveau' => 'B', 'type_source' => 'numerique', 'organisateur' => 'Obvia'];
    private const MILA = ['id' => 'mila', 'nom' => 'Mila', 'base' => 'https://mila.quebec', 'niveau' => 'C', 'type_source' => 'numerique', 'organisateur' => 'Mila'];
    private const FRQ = ['id' => 'frq_ev', 'nom' => 'FRQ', 'base' => 'https://frq.gouv.qc.ca', 'niveau' => 'B', 'type_source' => 'sante_numerique', 'organisateur' => 'Fonds de recherche du Québec'];
    private const IVADO = ['id' => 'ivado', 'nom' => 'IVADO', 'base' => 'https://ivado.ca', 'niveau' => 'C', 'type_source' => 'numerique', 'organisateur' => 'IVADO'];

    private function fixture(string $nom): string {
        return file_get_contents(FIXTURES . '/' . $nom);
    }

    public function test_obvia(): void {
        $r = (new LecteurObvia())->lire($this->fixture('obvia.html'), self::OBVIA);
        $this->assertCount(10, $r->fiches);
        $this->assertSame([], $r->erreurs);
        $this->assertGreaterThan(0, $r->ignores);
        $f = $r->fiches[0];
        $this->assertSame('Présentation de l\'exposition À l’heure de l’IA au Cégep Edouard-Montpetit', $f->titre);
        $this->assertSame(['evenement', '2026-10-07', '2026-10-27'], [$f->type, $f->debut, $f->fin]);
        $this->assertStringStartsWith('https://www.obvia.ca/evenements/', $f->url);
        $this->assertStringStartsWith('https://www.obvia.ca/sites/', $f->image_url);
        $this->assertStringStartsWith('Avec À l’heure de l’IA', $f->resume);
        $this->assertSame(['Obvia', 'Obvia', 'B', 'fr'], [$f->organisateur, $f->source, $f->niveau, $f->langue]);
        $this->assertSame('obvia:/evenements/presentation-de-lexposition-a-lheure-de-lia-au-cegep-edouard-montpetit', $f->cle_source);
        $this->assertSame([$f->cle_source], $f->cles_source);
        // Obvia donne midi UTC pour toutes les dates : ce n'est pas une heure d'événement.
        $this->assertSame('', $f->heure);
        $cles = array_map(fn($x) => $x->cle_source, $r->fiches);
        $this->assertSame($cles, array_values(array_unique($cles)));
        foreach ($r->fiches as $fiche) {
            $this->assertGreaterThanOrEqual('2026-10-25', $fiche->fin);
        }
    }

    public function test_mila(): void {
        $r = (new LecteurMila())->lire($this->fixture('mila.html'), self::MILA);
        $this->assertCount(3, $r->fiches);
        $this->assertSame([], $r->erreurs);
        $this->assertSame(5, $r->ignores);
        $f = array_values(array_filter($r->fiches, fn($x) => str_starts_with($x->titre, 'Communauté de pratique de Mila')))[0];
        $this->assertSame('Communauté de pratique de Mila : L’IA agentique', $f->titre);
        $this->assertSame(['2026-11-10', '15:00'], [$f->debut, $f->heure]);
        $this->assertSame(['Agora de Mila', 'Montréal', 'Québec'], [$f->lieu, $f->ville, $f->region]);
        $this->assertStringStartsWith('https://mila.quebec/fr/evenement/', $f->url);
        $this->assertStringStartsWith('https://mila.quebec/sites/default/files/', $f->image_url);
        $this->assertMatchesRegularExpression('/^mila:\d+$/', $f->cle_source);
        $this->assertNotSame('', $f->resume);
    }

    public function test_frq_evenements(): void {
        $r = (new LecteurFrqEvenements())->lire($this->fixture('frq_ev.html'), self::FRQ);
        $this->assertCount(5, $r->fiches);
        $this->assertSame([], $r->erreurs);
        $f = $r->fiches[0];
        $this->assertSame(['2026-09-04', '2026-12-18', 'Virtuel', 'En ligne'], [$f->debut, $f->fin, $f->format, $f->region]);
        $this->assertStringStartsWith('Partenariat scientifique France-Québec', $f->titre);
        $this->assertStringStartsWith('https://frq.gouv.qc.ca/evenement/', $f->url);
        $this->assertStringStartsWith('https://frq.gouv.qc.ca/app/uploads/', $f->image_url);
        $this->assertStringStartsWith('La présente série de six webinaires', $f->resume);
        $this->assertSame('frq_ev:/evenement/' . basename(rtrim($f->url, '/')), $f->cle_source);
    }

    public function test_ivado_calendrier_puis_completion(): void {
        $r = (new LecteurIvado())->lire($this->fixture('ivado.html'), self::IVADO + ['flux_evenements' => $this->fixture('ivado_ev.xml')]);
        $this->assertGreaterThanOrEqual(5, count($r->fiches));
        $this->assertSame([], $r->erreurs);
        $f = $r->fiches[0];
        $this->assertSame('', $f->titre);
        $this->assertStringStartsWith('https://ivado.ca/?p=', $f->url);
        LecteurIvado::completer($f, '<html><head><title>IA en santé 2026 | IVADO</title></head></html>', 'https://ivado.ca/evenements/ia-en-sante-2026/');
        $this->assertSame(['IA en santé 2026', 'https://ivado.ca/evenements/ia-en-sante-2026/'], [$f->titre, $f->url]);
    }

    public function test_ivado_regroupement_des_seances(): void {
        $r = (new LecteurIvado())->lire($this->fixture('ivado.html'), self::IVADO);
        $par_cle = [];
        foreach ($r->fiches as $f) {
            $par_cle[$f->cle_source] = $f;
        }
        $this->assertCount(count($r->fiches), $par_cle);
        $this->assertGreaterThan(0, $r->ignores);
        // Une fiche à une seule séance avec heure, une série de trois séances (attributs entre guillemets simples).
        $unique = $par_cle['ivado:205515'];
        $this->assertSame(['2026-11-30', '2026-11-30', '08:00', 'https://ivado.ca/?p=205515'], [$unique->debut, $unique->fin, $unique->heure, $unique->url]);
        $serie = $par_cle['ivado:205434'];
        $this->assertSame(['2026-11-06', '2026-11-20', ''], [$serie->debut, $serie->fin, $serie->heure]);
        $this->assertSame(['evenement', 'IVADO', 'IVADO', 'C'], [$serie->type, $serie->organisateur, $serie->source, $serie->niveau]);
        foreach ($r->fiches as $f) {
            $this->assertGreaterThanOrEqual('2026-10-25', $f->fin);
        }
    }

    public function test_ivado_completer_applique_le_resume_du_flux(): void {
        $flux = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel><title>T</title>'
            . '<item><title>IA en santé 2026</title><link>https://ivado.ca/evenements/ia-en-sante-2026/</link><pubDate>Fri, 23 Oct 2026 16:19:49 +0000</pubDate><description><![CDATA[<p>Une journée sur l’IA en santé.</p>]]></description></item>'
            . '<item><title>Autre</title><link>https://ivado.ca/evenements/autre/</link><pubDate>Fri, 23 Oct 2026 16:19:49 +0000</pubDate><description>Autre résumé</description></item>'
            . '</channel></rss>';
        $resumes = LecteurIvado::resumes_par_url(['flux_evenements' => $flux]);
        $this->assertSame('Une journée sur l’IA en santé.', $resumes['https://ivado.ca/evenements/ia-en-sante-2026']);
        $f = new Fiche();
        LecteurIvado::completer($f, '<html><head><title>IA en santé 2026 | IVADO</title></head></html>', 'https://ivado.ca/evenements/ia-en-sante-2026/', $resumes);
        $this->assertSame('Une journée sur l’IA en santé.', $f->resume);
        // Sans résumé correspondant, le résumé de la fiche reste intact.
        $g = new Fiche();
        $g->resume = 'Déjà là';
        LecteurIvado::completer($g, '<title>Inconnu | IVADO</title>', 'https://ivado.ca/evenements/inconnu/', $resumes);
        $this->assertSame('Déjà là', $g->resume);
        $this->assertSame([], LecteurIvado::resumes_par_url(['flux_evenements' => '<html></html>']));
        $this->assertSame([], LecteurIvado::resumes_par_url([]));
    }

    public function test_ivado_redirection_detectee_et_cible_lue(): void {
        $refresh = '<html><head><title>IA en santé 2026 | IVADO</title><meta http-equiv="refresh" content="0; url=https://exemple.test/ia-sante-2026"></head><body><h1>Redirection/Redirecting...</h1></body></html>';
        $this->assertSame(['cible' => 'https://exemple.test/ia-sante-2026'], LecteurIvado::redirection($refresh));
        $litteral = '<html><head><title>IA en santé 2026 | IVADO</title></head><body><h1>Redirecting...</h1><script>window.location = "https://exemple.test/litteral"</script></body></html>';
        $this->assertSame(['cible' => 'https://exemple.test/litteral'], LecteurIvado::redirection($litteral));
        $href = '<html><head><title>X</title><script>location.href = \'https://exemple.test/href\';</script></head><body></body></html>';
        $this->assertSame(['cible' => 'https://exemple.test/href'], LecteurIvado::redirection($href));
        // Le titre seul suffit à reconnaître la redirection ; la cible est alors vide.
        $this->assertSame(['cible' => ''], LecteurIvado::redirection('<html><head><title>Redirecting... | IVADO</title></head><body></body></html>'));
        // Cible qui n'est pas http(s) : ignorée.
        $this->assertSame(['cible' => ''], LecteurIvado::redirection('<html><head><title>X</title></head><body><script>window.location = "javascript:alert(1)"</script></body></html>'));
        // La page réelle : l'adresse est dans une variable affectée à window.location.
        $this->assertSame(['cible' => 'https://event.fourwaves.com/aihealthsymposium'], LecteurIvado::redirection($this->fixture('ivado_redirection.html')));
    }

    public function test_ivado_propriete_location_dun_objet_nest_pas_une_redirection(): void {
        $page = static fn(string $script): string => '<html><head><title>IA en santé 2026 | IVADO</title></head><body><h1>IA en santé 2026</h1><script>' . $script . '</script></body></html>';
        $this->assertNull(LecteurIvado::redirection($page('var widget = {}; widget.location = "Montréal";')));
        $this->assertNull(LecteurIvado::redirection($page('e.location = "";')));
        $this->assertNull(LecteurIvado::redirection($page('var o = {}; o.window.location = "https://exemple.test/x";')));
        $this->assertSame(['cible' => 'https://exemple.test/x'], LecteurIvado::redirection($page("window.location = 'https://exemple.test/x'")));
        $this->assertSame(['cible' => 'https://exemple.test/y'], LecteurIvado::redirection($page('location.href = "https://exemple.test/y"')));
    }

    public function test_ivado_redirection_garde_titre_et_resume_de_la_liste(): void {
        $f = new Fiche();
        $f->titre = 'IA en santé 2026';
        $f->resume = '';
        $resumes = ['https://ivado.ca/evenements/ia-en-sante-2026' => 'Un résumé qui ne doit pas être appliqué.'];
        LecteurIvado::completer($f, $this->fixture('ivado_redirection.html'), 'https://ivado.ca/evenements/ia-en-sante-2026/', $resumes);
        $this->assertSame(['IA en santé 2026', '', 'https://event.fourwaves.com/aihealthsymposium'], [$f->titre, $f->resume, $f->url]);
        // Sans cible lisible, l'adresse IVADO résolue est conservée.
        $g = new Fiche();
        $g->titre = 'Fiche';
        LecteurIvado::completer($g, '<html><head><title>Redirecting... | IVADO</title></head><body></body></html>', 'https://ivado.ca/evenements/x/');
        $this->assertSame(['Fiche', 'https://ivado.ca/evenements/x/'], [$g->titre, $g->url]);
    }

    public function test_ivado_redirection_sans_titre_prend_la_balise_title(): void {
        $f = new Fiche();
        $resumes = ['https://ivado.ca/evenements/ia-en-sante-2026' => 'Un résumé qui ne doit pas être appliqué.'];
        LecteurIvado::completer($f, $this->fixture('ivado_redirection.html'), 'https://ivado.ca/evenements/ia-en-sante-2026/', $resumes);
        $this->assertSame(['IA en santé 2026', '', 'https://event.fourwaves.com/aihealthsymposium'], [$f->titre, $f->resume, $f->url]);
        // Jamais le h1 : sans balise title lisible, la fiche reste sans titre (elle sera ignorée).
        $g = new Fiche();
        LecteurIvado::completer($g, '<html><head></head><body><h1>Redirecting...</h1></body></html>', 'https://ivado.ca/evenements/x/');
        $this->assertSame(['', 'https://ivado.ca/evenements/x/'], [$g->titre, $g->url]);
    }

    public function test_ivado_page_normale_toujours_completee(): void {
        $this->assertNull(LecteurIvado::redirection($this->fixture('ivado.html')));
        $this->assertNull(LecteurIvado::redirection('<html><head><title>IA en santé 2026 | IVADO</title></head><body><h1>IA en santé 2026</h1></body></html>'));
        $f = new Fiche();
        LecteurIvado::completer($f, '<html><head><title>IA en santé 2026 | IVADO</title></head><body><h1>IA en santé 2026</h1></body></html>', 'https://ivado.ca/evenements/ia-en-sante-2026/', ['https://ivado.ca/evenements/ia-en-sante-2026' => 'Résumé du flux.']);
        $this->assertSame(['IA en santé 2026', 'Résumé du flux.', 'https://ivado.ca/evenements/ia-en-sante-2026/'], [$f->titre, $f->resume, $f->url]);
    }

    public function test_html_sans_evenement(): void {
        foreach ([new LecteurObvia(), new LecteurMila(), new LecteurFrqEvenements(), new LecteurIvado()] as $lecteur) {
            $r = $lecteur->lire('<html></html>', self::IVADO + ['base' => 'https://x.test']);
            $this->assertSame([], $r->fiches);
            $this->assertSame([], $r->erreurs);
            $this->assertSame(0, $r->ignores);
        }
    }

    public function test_contenu_qui_nest_pas_du_html(): void {
        foreach (['', '   ', '{"events":[]}'] as $contenu) {
            $r = (new LecteurObvia())->lire($contenu, self::OBVIA);
            $this->assertSame([], $r->fiches);
            $this->assertCount(1, $r->erreurs, var_export($contenu, true));
        }
    }

    public function test_bloc_sans_lien_ou_sans_date_est_une_erreur(): void {
        $html = '<html><body>'
            . '<article class="node node--type-event"><h2><a href="/evenements/a">Sans date</a></h2></article>'
            . '<article class="node node--type-event"><time datetime="2026-11-02T12:00:00Z"></time><h2>Sans lien</h2></article>'
            . '<article class="node node--type-event"><time datetime="2026-11-02T12:00:00Z"></time><h2><a href="/evenements/b">Bon</a></h2></article>'
            . '<article class="node node--type-event"><time datetime="2026-11-02T12:00:00Z"></time><h2><a href="/evenements/b">Bon en double</a></h2></article>'
            . '</body></html>';
        $r = (new LecteurObvia())->lire($html, self::OBVIA);
        $this->assertCount(1, $r->fiches);
        $this->assertSame('Bon', $r->fiches[0]->titre);
        $this->assertCount(2, $r->erreurs);
    }

    public function test_meme_evenement_en_lien_relatif_ou_absolu_est_une_seule_fiche(): void {
        $bloc = static fn(string $href): string => '<article class="node node--type-event"><time datetime="2026-11-02T12:00:00Z"></time><h2><a href="' . $href . '">Bon</a></h2></article>';
        $html = '<html><body>' . $bloc('/evenements/a') . $bloc('https://www.obvia.ca/evenements/a') . $bloc('../evenements/a/') . '</body></html>';
        $r = (new LecteurObvia())->lire($html, self::OBVIA + ['base' => 'https://www.obvia.ca/evenements/liste']);
        $this->assertCount(1, $r->fiches);
        $this->assertSame('obvia:/evenements/a', $r->fiches[0]->cle_source);
        $this->assertSame('https://www.obvia.ca/evenements/a', $r->fiches[0]->url);
    }

    public function test_urls_relatives_resolues(): void {
        $lecteur = new class extends \RSN\Veille\Lecteurs\LecteurHtml {
            protected function extraire(\DOMXPath $xpath, array $source, \RSN\Veille\Lecteurs\Resultat $resultat): void {
            }
            public function resoudre(string $url, string $base): string {
                return $this->absolue($url, $base);
            }
        };
        $cas = [
            ['../evenements/a?x=1', 'https://www.obvia.ca/evenements/liste', 'https://www.obvia.ca/evenements/a?x=1'],
            ['a.jpg?itok=z', 'https://x.test/dir/page', 'https://x.test/dir/a.jpg?itok=z'],
            ['a.jpg', 'https://x.test/dir/', 'https://x.test/dir/a.jpg'],
            ['a.jpg', 'https://x.test', 'https://x.test/a.jpg'],
            ['//cdn.test/i.png', 'https://x.test/dir/page', 'https://cdn.test/i.png'],
            ['/sites/x.jpg', 'https://x.test:8080/dir/page', 'https://x.test:8080/sites/x.jpg'],
            ['?page=2', 'https://x.test/dir/page', 'https://x.test/dir/page?page=2'],
            ['../../../a', 'https://x.test/dir/page', 'https://x.test/a'],
            ['./b/./c', 'https://x.test/dir/page', 'https://x.test/dir/b/c'],
            ['..', 'https://x.test/dir/sous/page', 'https://x.test/dir/'],
            ['https://y.test/a/../b', 'https://x.test/', 'https://y.test/a/../b'],
            ['', 'https://x.test/', ''],
            ['/a', '', '/a'],
        ];
        foreach ($cas as [$url, $base, $attendu]) {
            $this->assertSame($attendu, $lecteur->resoudre($url, $base), $url . ' contre ' . $base);
        }
    }

    public function test_mila_bloc_sans_identifiant_est_rejete(): void {
        $bloc = static fn(string $nid, string $titre): string => '<div data-nid="' . $nid . '" class="entity-wrapper"><div class="node node--type-event">'
            . '<div class="field-name-node-title"><a href="/fr/evenement/' . $titre . '">' . $titre . '</a></div>'
            . '<time datetime="2026-11-04T18:00:00-05:00"></time></div></div>';
        $html = '<html><body>' . $bloc('', 'a') . $bloc('', 'b') . $bloc('77', 'c') . '</body></html>';
        $r = (new LecteurMila())->lire($html, self::MILA);
        $this->assertCount(1, $r->fiches);
        $this->assertSame('mila:77', $r->fiches[0]->cle_source);
        $this->assertCount(2, $r->erreurs);
    }

    public function test_libxml_ne_laisse_aucune_erreur_et_restaure_son_mode(): void {
        libxml_use_internal_errors(false);
        (new LecteurObvia())->lire($this->fixture('obvia.html'), self::OBVIA);
        $this->assertFalse(libxml_use_internal_errors(false));
        $this->assertSame([], libxml_get_errors());
    }
}

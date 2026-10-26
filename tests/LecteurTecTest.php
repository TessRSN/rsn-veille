<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RSN\Veille\Lecteurs\LecteurTec;

final class LecteurTecTest extends TestCase {
    public function test_dhc_18_evenements_a_venir(): void {
        $r = (new LecteurTec())->lire(file_get_contents(FIXTURES . '/dhc.json'), ['id' => 'dhc', 'nom' => 'Digital Health Canada', 'niveau' => 'A', 'type_source' => 'sante_numerique', 'organisateur' => 'Digital Health Canada', 'langue' => 'en']);
        $this->assertCount(18, $r->fiches);
        $f = $r->fiches[0];
        $this->assertSame('Digital Health Canada/RSN Montreal 2026 Breakfast', $f->titre);
        $this->assertSame(['2026-10-27', '2026-10-27', '08:00', 'Plaza Centre-Ville', 'Montreal'], [$f->debut, $f->fin, $f->heure, $f->lieu, $f->ville]);
        $this->assertStringStartsWith('https://digitalhealthcanada.com/wp-content/uploads/', $f->image_url);
        $this->assertSame('dhc:' . json_decode(file_get_contents(FIXTURES . '/dhc.json'))->events[0]->id, $f->cle_source);
    }

    public function test_rsn_passes_inclus_et_categorie(): void {
        $r = (new LecteurTec())->lire(file_get_contents(FIXTURES . '/rsn_ev.json'), ['id' => 'rsn', 'nom' => 'RSN', 'niveau' => 'A', 'type_source' => 'sante_numerique', 'organisateur' => 'RSN', 'inclure_passes' => true]);
        $this->assertCount(16, $r->fiches);
        $this->assertSame('Événements RSN', $r->fiches[0]->categorie_rsn);
    }

    public function test_toute_la_journee_sans_heure(): void {
        $json = '{"events":[{"id":7,"title":"Journée","all_day":true,"start_date":"2026-11-04 00:00:00","end_date":"2026-11-04 23:59:59","url":"https://x.test/7","description":"","venue":[],"categories":[],"cost":""}]}';
        $f = (new LecteurTec())->lire($json, ['id' => 'x', 'nom' => 'X', 'niveau' => 'A', 'type_source' => 'sante'])->fiches[0];
        $this->assertSame(['2026-11-04', '2026-11-04', ''], [$f->debut, $f->fin, $f->heure]);
    }

    public function test_json_invalide(): void {
        $r = (new LecteurTec())->lire('<html>Pas un JSON</html>', ['id' => 'x', 'nom' => 'X', 'niveau' => 'A', 'type_source' => 'sante']);
        $this->assertSame([], $r->fiches);
        $this->assertCount(1, $r->erreurs);
    }

    public function test_passes_ignores_et_evenements_incomplets(): void {
        $json = '{"events":[{"id":1,"title":"Fini","start_date":"2026-10-01 09:00:00","end_date":"2026-10-01 10:00:00","url":"https://x.test/1"},{"title":"Sans id","start_date":"2026-11-04 09:00:00"},{"id":3,"title":"Lieu en liste","start_date":"2026-11-05 09:30:00","end_date":"2026-11-05 11:00:00","url":"https://x.test/3","image":false,"venue":[{"venue":"HEC","city":"Montr\u00e9al"}],"organizer":[]}]}';
        $r = (new LecteurTec())->lire($json, ['id' => 'x', 'nom' => 'X', 'niveau' => 'A', 'type_source' => 'sante', 'organisateur' => 'Org X']);
        $this->assertCount(1, $r->fiches);
        $this->assertSame(1, $r->ignores);
        $this->assertCount(1, $r->erreurs);
        $f = $r->fiches[0];
        $this->assertSame(['HEC', 'Montréal', 'Org X', '', '09:30', 'fr', 'x:3'], [$f->lieu, $f->ville, $f->organisateur, $f->image_url, $f->heure, $f->langue, $f->cle_source]);
        $this->assertSame(['x:3'], $f->cles_source);
    }
}

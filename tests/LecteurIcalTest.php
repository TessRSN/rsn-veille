<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RSN\Veille\Lecteurs\LecteurIcal;

final class LecteurIcalTest extends TestCase {
    private const SOURCE = ['id' => 'ics', 'nom' => 'Source iCal', 'niveau' => 'A', 'type_source' => 'sante_numerique', 'organisateur' => 'Org iCal'];

    protected function tearDown(): void {
        $GLOBALS['rsn_veille_test_aujourdhui'] = '2026-10-25';
    }

    /** Enveloppe un ou plusieurs VEVENT dans un VCALENDAR. */
    private function calendrier(string ...$evenements): string {
        $corps = '';
        foreach ($evenements as $evenement) {
            $corps .= "BEGIN:VEVENT\r\n" . $evenement . "\r\nEND:VEVENT\r\n";
        }
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n" . $corps . "END:VCALENDAR\r\n";
    }

    public function test_dhc_fixture(): void {
        // La fixture versionnée dhc.ics ne contient que 4 VEVENT (l'inventaire des sources en annonce 18
        // pour le flux complet). Tous sont à venir au 2026-10-25, donc aucun n'est ignoré.
        $contenu = file_get_contents(FIXTURES . '/dhc.ics');
        $this->assertSame(4, substr_count($contenu, 'BEGIN:VEVENT'));
        $r = (new LecteurIcal())->lire($contenu, self::SOURCE + ['langue' => 'en']);
        $this->assertSame([], $r->erreurs);
        $this->assertSame(0, $r->ignores);
        $this->assertCount(4, $r->fiches);

        $f = $r->fiches[0];
        $this->assertSame('evenement', $f->type);
        $this->assertSame('Digital Health Canada/RSN Montreal 2026 Breakfast', $f->titre);
        $this->assertSame(['2026-10-27', '2026-10-27', '08:00'], [$f->debut, $f->fin, $f->heure]);
        $this->assertStringStartsWith('Plaza Centre-Ville, 777 Blvd Robert Bourassa, Montreal', $f->lieu);
        $this->assertSame('https://digitalhealthcanada.com/event-calendar/digital-health-canada-mtl26/', $f->url);
        $this->assertSame('ics:10000629-1793088000-1793100600@digitalhealthcanada.com', $f->cle_source);
        $this->assertSame([$f->cle_source], $f->cles_source);
        $this->assertSame(['en', 'Org iCal', 'Source iCal', 'A'], [$f->langue, $f->organisateur, $f->source, $f->niveau]);
        $this->assertSame(['Présentiel', 'Québec'], [$f->format, $f->region]);
        // Échappements décodés, saut de ligne replié, résumé court.
        $this->assertStringStartsWith('Let’s discuss the future of digital health in Canada over breakfast.', $f->resume);
        $this->assertStringNotContainsString('\\', $f->resume);
        $this->assertLessThanOrEqual(261, mb_strlen($f->resume));

        // Deux jours, hors ligne : FHIR North ; les DTSTART du VTIMEZONE ne sont pas des événements.
        $this->assertSame(['FHIR North 2026', '2026-10-27', '2026-10-28', 'Virtuel', 'En ligne'], [$r->fiches[1]->titre, $r->fiches[1]->debut, $r->fiches[1]->fin, $r->fiches[1]->format, $r->fiches[1]->region]);
        // Titre avec virgule échappée.
        $this->assertSame('Connected Content, Connected Care: Building a Unified Digital Health Record', $r->fiches[2]->titre);
        // Événement sans LOCATION.
        $this->assertSame('', $r->fiches[3]->lieu);
    }

    public function test_dhc_fixture_passes_ignores_ou_inclus(): void {
        $contenu = file_get_contents(FIXTURES . '/dhc.ics');
        $GLOBALS['rsn_veille_test_aujourdhui'] = '2026-10-29';
        $r = (new LecteurIcal())->lire($contenu, self::SOURCE);
        $this->assertCount(1, $r->fiches);
        $this->assertSame('Digital Health Canada Office Hours Online', $r->fiches[0]->titre);
        $this->assertSame(3, $r->ignores);
        $r = (new LecteurIcal())->lire($contenu, self::SOURCE + ['inclure_passes' => true]);
        $this->assertCount(4, $r->fiches);
        $this->assertSame(0, $r->ignores);
    }

    public function test_utc_converti_en_heure_de_montreal(): void {
        $r = (new LecteurIcal())->lire($this->calendrier("UID:a1\r\nSUMMARY:Soir\r\nDTSTART:20261104T170000Z\r\nDTEND:20261104T190000Z"), self::SOURCE);
        $f = $r->fiches[0];
        $this->assertSame(['2026-11-04', '2026-11-04', '12:00'], [$f->debut, $f->fin, $f->heure]);
    }

    public function test_utc_en_heure_avancee_et_passage_a_minuit(): void {
        $r = (new LecteurIcal())->lire($this->calendrier("UID:a2\r\nSUMMARY:Été\r\nDTSTART:20260715T030000Z\r\nDTEND:20260715T050000Z"), self::SOURCE + ['inclure_passes' => true]);
        // 03:00 UTC = 23:00 la veille (UTC−4) ; la fin (05:00 UTC) tombe à 01:00 le lendemain.
        $this->assertSame(['2026-07-14', '2026-07-15', '23:00'], [$r->fiches[0]->debut, $r->fiches[0]->fin, $r->fiches[0]->heure]);
    }

    public function test_journee_entiere_fin_exclusive(): void {
        $r = (new LecteurIcal())->lire($this->calendrier("UID:a3\r\nSUMMARY:Congrès\r\nDTSTART;VALUE=DATE:20270330\r\nDTEND;VALUE=DATE:20270401"), self::SOURCE);
        $f = $r->fiches[0];
        $this->assertSame(['2027-03-30', '2027-03-31', ''], [$f->debut, $f->fin, $f->heure]);
    }

    public function test_journee_entiere_sans_fin(): void {
        $r = (new LecteurIcal())->lire($this->calendrier("UID:a4\r\nSUMMARY:Un jour\r\nDTSTART;VALUE=DATE:20270330"), self::SOURCE);
        $this->assertSame(['2027-03-30', '2027-03-30', ''], [$r->fiches[0]->debut, $r->fiches[0]->fin, $r->fiches[0]->heure]);
    }

    public function test_tzid_montreal_heure_inchangee(): void {
        $r = (new LecteurIcal())->lire($this->calendrier("UID:a5\r\nSUMMARY:Local\r\nDTSTART;TZID=America/Toronto:20261104T170000\r\nDTEND;TZID=America/Toronto:20261104T183000"), self::SOURCE);
        $this->assertSame(['2026-11-04', '2026-11-04', '17:00'], [$r->fiches[0]->debut, $r->fiches[0]->fin, $r->fiches[0]->heure]);
    }

    public function test_tzid_autre_fuseau_converti(): void {
        $r = (new LecteurIcal())->lire($this->calendrier("UID:a6\r\nSUMMARY:Ouest\r\nDTSTART;TZID=America/Vancouver:20261104T090000"), self::SOURCE);
        $this->assertSame(['2026-11-04', '2026-11-04', '12:00'], [$r->fiches[0]->debut, $r->fiches[0]->fin, $r->fiches[0]->heure]);
    }

    public function test_heure_flottante_prise_telle_quelle_et_fin_absente(): void {
        $r = (new LecteurIcal())->lire($this->calendrier("UID:a7\r\nSUMMARY:Flottant\r\nDTSTART:20261104T170000"), self::SOURCE);
        $this->assertSame(['2026-11-04', '2026-11-04', '17:00'], [$r->fiches[0]->debut, $r->fiches[0]->fin, $r->fiches[0]->heure]);
    }

    public function test_echappements_decodes_et_lignes_depliees(): void {
        $ics = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:b1\r\nSUMMARY:Atelier\\, suite\; fin \\\\ ok\r\nDTSTART;VALUE=DATE:20261110\r\n"
            . "DESCRIPTION:Première ligne\\nSeconde\\Nligne avec une très longue ph\r\n rase repliée\r\n\tet une tabulation\r\n"
            . "LOCATION:HEC Montréal\\, Montréal\r\nURL:https://x.test/b1\r\nEND:VEVENT\r\nEND:VCALENDAR";
        $f = (new LecteurIcal())->lire($ics, self::SOURCE)->fiches[0];
        $this->assertSame('Atelier, suite; fin \\ ok', $f->titre);
        $this->assertSame('Première ligne Seconde ligne avec une très longue phrase repliéeet une tabulation', $f->resume);
        $this->assertSame('HEC Montréal, Montréal', $f->lieu);
        $this->assertSame('https://x.test/b1', $f->url);
    }

    public function test_fins_de_ligne_melangees_et_bom(): void {
        $ics = "\xEF\xBB\xBFBEGIN:VCALENDAR\nBEGIN:VEVENT\rUID:c1\nSUMMARY:Mélange\r\nDTSTART;VALUE=DATE:20261110\nEND:VEVENT\nEND:VCALENDAR\n";
        $r = (new LecteurIcal())->lire($ics, self::SOURCE);
        $this->assertSame([], $r->erreurs);
        $this->assertCount(1, $r->fiches);
        $this->assertSame('ics:c1', $r->fiches[0]->cle_source);
    }

    public function test_sous_composant_ne_masque_pas_les_proprietes(): void {
        $ics = $this->calendrier("UID:d1\r\nSUMMARY:Avec alarme\r\nDTSTART;VALUE=DATE:20261110\r\nBEGIN:VALARM\r\nACTION:DISPLAY\r\nDESCRIPTION:Rappel\r\nEND:VALARM\r\nDESCRIPTION:Vraie description");
        $f = (new LecteurIcal())->lire($ics, self::SOURCE)->fiches[0];
        $this->assertSame('Vraie description', $f->resume);
        $this->assertSame('', $f->url);
    }

    public function test_sans_uid_cle_construite(): void {
        $f = (new LecteurIcal())->lire($this->calendrier("SUMMARY:Sans UID\r\nDTSTART;VALUE=DATE:20261110"), self::SOURCE)->fiches[0];
        $this->assertSame('ics:' . md5('2026-11-10' . 'Sans UID'), $f->cle_source);
    }

    public function test_passes_ignores(): void {
        $ics = $this->calendrier(
            "UID:e1\r\nSUMMARY:Fini\r\nDTSTART;VALUE=DATE:20261001",
            "UID:e2\r\nSUMMARY:Encore en cours\r\nDTSTART;VALUE=DATE:20261024\r\nDTEND;VALUE=DATE:20261027"
        );
        $r = (new LecteurIcal())->lire($ics, self::SOURCE);
        $this->assertCount(1, $r->fiches);
        $this->assertSame(1, $r->ignores);
        $this->assertSame('Encore en cours', $r->fiches[0]->titre);
    }

    public function test_date_illisible_ou_titre_absent_signale(): void {
        $ics = $this->calendrier(
            "UID:f1\r\nSUMMARY:Date folle\r\nDTSTART:pas-une-date",
            "UID:f2\r\nSUMMARY:Sans début",
            "UID:f3\r\nDTSTART;VALUE=DATE:20261110",
            "UID:f4\r\nSUMMARY:Bon\r\nDTSTART;VALUE=DATE:20261110"
        );
        $r = (new LecteurIcal())->lire($ics, self::SOURCE);
        $this->assertCount(1, $r->fiches);
        $this->assertCount(3, $r->erreurs);
        $this->assertSame('Bon', $r->fiches[0]->titre);
    }

    public function test_contenu_invalide(): void {
        $r = (new LecteurIcal())->lire('<html>Pas un calendrier</html>', self::SOURCE);
        $this->assertSame([], $r->fiches);
        $this->assertSame(['Contenu iCal invalide'], $r->erreurs);
    }
}

<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RSN\Veille\Public\Ical;

final class IcalTest extends TestCase {
    private function evenement(array $champs = []): array {
        return array_merge([
            'type' => 'evenement',
            'titre' => 'Colloque santé numérique',
            'debut' => '2026-11-12',
            'fin' => '2026-11-13',
            'heure' => '',
            'lieu' => 'HEC Montréal',
            'ville' => 'Montréal',
            'resume' => 'Deux jours d’échanges.',
            'url' => 'https://www.obvia.ca/evenements/colloque',
            'cle_source' => 'obvia:42',
            'statut' => 'publie',
        ], $champs);
    }

    /** Lignes dépliées (RFC 5545 § 3.1) : une continuation commence par une espace. */
    private function deplier(string $ics): array {
        return explode("\r\n", rtrim(str_replace("\r\n ", '', $ics), "\r\n"));
    }

    public function test_calendrier_et_entete(): void {
        $ics = Ical::generer([], '2026-10-25');
        $lignes = $this->deplier($ics);
        $this->assertSame('BEGIN:VCALENDAR', $lignes[0]);
        $this->assertContains('VERSION:2.0', $lignes);
        $this->assertContains('PRODID:-//RSN//Veille//FR', $lignes);
        $this->assertContains("X-WR-CALNAME:Veille de l'écosystème RSN", $lignes);
        $this->assertSame('END:VCALENDAR', end($lignes));
        $this->assertStringNotContainsString('BEGIN:VEVENT', $ics);
    }

    public function test_journee_entiere_fin_exclusive(): void {
        $lignes = $this->deplier(Ical::generer([$this->evenement()], '2026-10-25'));
        $this->assertContains('BEGIN:VEVENT', $lignes);
        $this->assertContains('DTSTART;VALUE=DATE:20261112', $lignes);
        $this->assertContains('DTEND;VALUE=DATE:20261114', $lignes);
        $this->assertContains('DTSTAMP:20261025T000000Z', $lignes);
        $this->assertContains('SUMMARY:Colloque santé numérique', $lignes);
        $this->assertContains('LOCATION:HEC Montréal\, Montréal', $lignes);
        $this->assertContains('URL:https://www.obvia.ca/evenements/colloque', $lignes);
        $this->assertContains('DESCRIPTION:Deux jours d’échanges.\n\nhttps://www.obvia.ca/evenements/colloque', $lignes);
    }

    public function test_journee_entiere_sans_fin_dure_un_jour(): void {
        $lignes = $this->deplier(Ical::generer([$this->evenement(['fin' => null])], '2026-10-25'));
        $this->assertContains('DTSTART;VALUE=DATE:20261112', $lignes);
        $this->assertContains('DTEND;VALUE=DATE:20261113', $lignes);
    }

    public function test_fin_de_mois_et_d_annee(): void {
        $lignes = $this->deplier(Ical::generer([$this->evenement(['debut' => '2026-12-31', 'fin' => '2026-12-31'])], '2026-10-25'));
        $this->assertContains('DTEND;VALUE=DATE:20270101', $lignes);
    }

    public function test_avec_heure_deux_heures_par_defaut(): void {
        $ics = Ical::generer([$this->evenement(['heure' => '13:30', 'fin' => '2026-11-12'])], '2026-10-25');
        $lignes = $this->deplier($ics);
        $this->assertContains('DTSTART;TZID=America/Toronto:20261112T133000', $lignes);
        $this->assertContains('DTEND;TZID=America/Toronto:20261112T153000', $lignes);
        // Un TZID utilisé doit être décrit par un VTIMEZONE.
        $this->assertContains('BEGIN:VTIMEZONE', $lignes);
        $this->assertContains('TZID:America/Toronto', $lignes);
    }

    public function test_avec_heure_tardive_finit_le_lendemain(): void {
        $lignes = $this->deplier(Ical::generer([$this->evenement(['heure' => '23:00', 'fin' => null])], '2026-10-25'));
        $this->assertContains('DTEND;TZID=America/Toronto:20261113T010000', $lignes);
    }

    public function test_heure_illisible_traitee_comme_journee_entiere(): void {
        $lignes = $this->deplier(Ical::generer([$this->evenement(['heure' => 'midi'])], '2026-10-25'));
        $this->assertContains('DTSTART;VALUE=DATE:20261112', $lignes);
    }

    public function test_echappements(): void {
        $ics = Ical::generer([$this->evenement([
            'titre' => 'IA, santé; éthique \\ société',
            'resume' => "Première ligne\nseconde ligne",
            'lieu' => '', 'ville' => 'Québec',
        ])], '2026-10-25');
        $lignes = $this->deplier($ics);
        $this->assertContains('SUMMARY:IA\, santé\; éthique \\\\ société', $lignes);
        $this->assertContains('LOCATION:Québec', $lignes);
        $this->assertContains('DESCRIPTION:Première ligne\nseconde ligne\n\nhttps://www.obvia.ca/evenements/colloque', $lignes);
    }

    public function test_lignes_pliees_a_75_octets(): void {
        $long = str_repeat('Événement très long en santé numérique ', 8);
        $ics = Ical::generer([$this->evenement(['titre' => $long, 'resume' => $long])], '2026-10-25');
        foreach (explode("\r\n", $ics) as $ligne) {
            $this->assertLessThanOrEqual(75, strlen($ligne), $ligne);
            // Le pliage ne coupe jamais un caractère UTF-8 en deux.
            $this->assertTrue(mb_check_encoding($ligne, 'UTF-8'), $ligne);
        }
        $this->assertContains('SUMMARY:' . trim($long), $this->deplier($ics));
    }

    public function test_toutes_les_lignes_finissent_par_crlf(): void {
        $ics = Ical::generer([$this->evenement(), $this->evenement(['heure' => '09:00', 'cle_source' => 'obvia:43'])], '2026-10-25');
        $this->assertStringEndsWith("\r\n", $ics);
        $this->assertSame(0, preg_match('/(?<!\r)\n/', $ics));
        $this->assertSame(0, preg_match('/\r(?!\n)/', $ics));
    }

    public function test_uid_stable_depuis_la_cle_de_source_sinon_l_url(): void {
        $lignes = $this->deplier(Ical::generer([$this->evenement()], '2026-10-25'));
        $this->assertContains('UID:' . md5('obvia:42') . '@rsn.quebec', $lignes);
        // Même fiche, autre jour de génération, autre titre : même UID.
        $encore = $this->deplier(Ical::generer([$this->evenement(['titre' => 'Nouveau titre'])], '2026-11-01'));
        $this->assertContains('UID:' . md5('obvia:42') . '@rsn.quebec', $encore);

        $sans_cle = $this->deplier(Ical::generer([$this->evenement(['cle_source' => ''])], '2026-10-25'));
        $this->assertContains('UID:' . md5('https://www.obvia.ca/evenements/colloque') . '@rsn.quebec', $sans_cle);
    }

    public function test_seuls_les_evenements_dates_sortent(): void {
        $ics = Ical::generer([
            $this->evenement(['type' => 'appel']),
            $this->evenement(['debut' => null, 'cle_source' => 'x:1']),
            $this->evenement(['debut' => 'bientôt', 'cle_source' => 'x:2']),
            $this->evenement(['statut' => 'a_approuver', 'cle_source' => 'x:3']),
            $this->evenement(['cle_source' => 'x:4']),
        ], '2026-10-25');
        $this->assertSame(1, substr_count($ics, 'BEGIN:VEVENT'));
        $this->assertStringContainsString('UID:' . md5('x:4') . '@rsn.quebec', $ics);
    }
}

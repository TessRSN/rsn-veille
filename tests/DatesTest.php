<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RSN\Veille\Admin\Dates;

final class DatesTest extends TestCase {
    public function test_date_longue_avec_premier_du_mois(): void {
        $this->assertSame('1er janvier 2026', Dates::date_longue('2026-01-01'));
        $this->assertSame('3 novembre 2026', Dates::date_longue('2026-11-03'));
        $this->assertSame('', Dates::date_longue(null));
        $this->assertSame('', Dates::date_longue('2026-02-30'));
        $this->assertSame('', Dates::date_longue('bientôt'));
    }

    public function test_periode_un_jour_avec_ou_sans_heure(): void {
        $this->assertSame('12 novembre 2026', Dates::periode('2026-11-12', null));
        $this->assertSame('12 novembre 2026', Dates::periode('2026-11-12', '2026-11-12'));
        $this->assertSame('12 novembre 2026, 13 h 30', Dates::periode('2026-11-12', null, '13:30'));
        $this->assertSame('12 novembre 2026, 9 h', Dates::periode('2026-11-12', null, '09:00'));
    }

    public function test_periode_sur_plusieurs_jours(): void {
        $this->assertSame('Du 1er au 3 décembre 2026', Dates::periode('2026-12-01', '2026-12-03'));
        $this->assertSame('Du 28 novembre au 2 décembre 2026', Dates::periode('2026-11-28', '2026-12-02'));
        $this->assertSame('Du 30 décembre 2026 au 2 janvier 2027', Dates::periode('2026-12-30', '2027-01-02'));
    }

    public function test_periode_sans_debut_lisible(): void {
        $this->assertSame('', Dates::periode(null, null));
        $this->assertSame('', Dates::periode('n.d.', null));
        // Une fin illisible est ignorée.
        $this->assertSame('5 mai 2027', Dates::periode('2027-05-05', 'n.d.'));
    }

    public function test_date_et_heure(): void {
        $this->assertSame('26 octobre 2026, 3 h 05', Dates::date_heure('2026-10-26 03:05'));
        $this->assertSame('1er juin 2026', Dates::date_heure('2026-06-01'));
        $this->assertSame('', Dates::date_heure(''));
        $this->assertSame('', Dates::date_heure('hier'));
    }

    public function test_mois_court_numero_de_jour_et_date_courte(): void {
        $this->assertSame('janv.', Dates::mois_court('2026-01-15'));
        $this->assertSame('sept.', Dates::mois_court('2026-09-28'));
        $this->assertSame('mai', Dates::mois_court('2026-05-02'));
        $this->assertSame('', Dates::mois_court('2026-13-01'));
        $this->assertSame('', Dates::mois_court(null));
        $this->assertSame('1', Dates::numero_jour('2026-01-01'));
        $this->assertSame('28', Dates::numero_jour('2026-09-28'));
        $this->assertSame('', Dates::numero_jour('bientôt'));
        $this->assertSame('1 janv. 2026', Dates::date_courte('2026-01-01'));
        $this->assertSame('28 sept. 2026', Dates::date_courte('2026-09-28'));
        $this->assertSame('', Dates::date_courte('2026-02-30'));
    }
}

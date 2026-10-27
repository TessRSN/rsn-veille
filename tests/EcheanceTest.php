<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RSN\Veille\Lecteurs\Echeance;

final class EcheanceTest extends TestCase {
    public function test_date_complete(): void {
        $this->assertSame('2026-10-20', Echeance::trouver('Date limite de soumission : 20 octobre 2026', '2026-10-25'));
    }

    public function test_annee_absente_passee_donne_l_annee_suivante(): void {
        $this->assertSame('2027-09-03', Echeance::trouver("jusqu'au 3 septembre", '2026-10-25'));
    }

    public function test_aucune_date(): void {
        $this->assertNull(Echeance::trouver('aucune date', '2026-10-25'));
    }

    public function test_annee_absente_mois_courant_ou_suivant_garde_l_annee(): void {
        $this->assertSame('2026-10-30', Echeance::trouver('Échéance : 30 octobre', '2026-10-25'));
        $this->assertSame('2026-12-01', Echeance::trouver('avant le 1er décembre', '2026-10-25'));
    }

    public function test_premier_avec_er_et_apostrophe_courbe(): void {
        $this->assertSame('2026-11-01', Echeance::trouver('DATE LIMITE : 1er novembre 2026', '2026-10-25'));
        $this->assertSame('2027-02-15', Echeance::trouver('jusqu’au 15 février 2027', '2026-10-25'));
        $this->assertSame('2026-12-31', Echeance::trouver('d’ici le 31 decembre', '2026-10-25'));
    }

    public function test_aout_avec_ou_sans_accent_et_deadline(): void {
        $this->assertSame('2027-08-09', Echeance::trouver('Deadline: 9 août', '2026-10-25'));
        $this->assertSame('2027-08-09', Echeance::trouver('Deadline: 9 aout', '2026-10-25'));
    }

    public function test_date_invalide_ou_hors_de_portee(): void {
        $this->assertNull(Echeance::trouver('Date limite : 31 février 2026', '2026-10-25'));
        $this->assertNull(Echeance::trouver('Date limite : ' . str_repeat('x', 80) . ' le 20 octobre 2026', '2026-10-25'));
    }

    public function test_mois_sans_mot_plus_long(): void {
        $this->assertNull(Echeance::trouver('Date limite : 3 maintenant', '2026-10-25'));
    }

    public function test_balises_html_ignorees(): void {
        $this->assertSame('2026-11-30', Echeance::trouver('<p><strong>Date limite</strong> : 30&nbsp;novembre 2026</p>', '2026-10-25'));
    }
}

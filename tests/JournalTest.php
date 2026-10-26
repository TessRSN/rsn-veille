<?php
declare(strict_types=1);

require_once __DIR__ . '/bouchons-wordpress.php';

use PHPUnit\Framework\TestCase;
use RSN\Veille\Journal;

final class JournalTest extends TestCase {
    protected function setUp(): void {
        rsn_veille_bouchons_reinitialiser();
    }

    public function test_journal_vide(): void {
        $this->assertSame([], Journal::tout());
        $this->assertNull(Journal::derniere('dhc'));
    }

    public function test_plus_recente_en_premier_par_source(): void {
        Journal::consigner('dhc', ['lus' => 1]);
        Journal::consigner('mila', ['lus' => 7]);
        Journal::consigner('dhc', ['lus' => 2]);
        $tout = Journal::tout();
        $this->assertSame([['lus' => 2], ['lus' => 1]], $tout['dhc']);
        $this->assertSame([['lus' => 7]], $tout['mila']);
        $this->assertSame(['lus' => 2], Journal::derniere('dhc'));
        $this->assertSame(['lus' => 7], Journal::derniere('mila'));
        $this->assertNull(Journal::derniere('obvia'));
    }

    public function test_au_plus_60_entrees_par_source(): void {
        for ($i = 1; $i <= 65; $i++) {
            Journal::consigner('dhc', ['lus' => $i]);
        }
        Journal::consigner('mila', ['lus' => 0]);
        $dhc = Journal::tout()['dhc'];
        $this->assertCount(60, $dhc);
        $this->assertSame(['lus' => 65], $dhc[0]);
        $this->assertSame(['lus' => 6], $dhc[59]);
        $this->assertCount(1, Journal::tout()['mila']);
    }

    public function test_option_illisible_traitee_comme_vide(): void {
        update_option(Journal::OPTION, 'abîmée');
        $this->assertSame([], Journal::tout());
        Journal::consigner('dhc', ['lus' => 3]);
        $this->assertSame(['dhc' => [['lus' => 3]]], get_option(Journal::OPTION));
    }

    public function test_erreurs_plafonnees_a_10(): void {
        $erreurs = [];
        for ($i = 1; $i <= 15; $i++) {
            $erreurs[] = 'Erreur ' . $i;
        }
        Journal::consigner('ivado', ['lus' => 3, 'erreurs' => $erreurs]);
        $ligne = Journal::derniere('ivado');
        $this->assertCount(11, $ligne['erreurs']);
        $this->assertSame('Erreur 10', $ligne['erreurs'][9]);
        $this->assertSame('… et 5 autres', $ligne['erreurs'][10]);
        Journal::consigner('mila', ['erreurs' => array_slice($erreurs, 0, 11)]);
        $this->assertSame('… et 1 autre', Journal::derniere('mila')['erreurs'][10]);
        Journal::consigner('dhc', ['erreurs' => array_slice($erreurs, 0, 10)]);
        $this->assertCount(10, Journal::derniere('dhc')['erreurs']);
    }
}

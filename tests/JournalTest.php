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

    // --- Alerte de gabarit ---

    private static function entree(int $lus, int $ignores = 0, array $champs = []): array {
        return $champs + ['date' => '2026-11-20 03:10', 'lus' => $lus, 'ignores' => $ignores, 'erreurs' => []];
    }

    public function test_alerte_quand_une_source_ne_rend_plus_rien(): void {
        $habitude = [self::entree(6), self::entree(5, 2), self::entree(7), self::entree(6)];
        $alerte = Journal::alerte_gabarit(self::entree(0), $habitude);
        $this->assertStringContainsString('Rien lu', $alerte);
        $this->assertStringContainsString('7', $alerte);
        $this->assertStringContainsString('gabarit', $alerte);
        // Des événements passés comptent comme lus : la page est toujours comprise.
        $this->assertSame('', Journal::alerte_gabarit(self::entree(0, 3), $habitude));
        $this->assertSame('', Journal::alerte_gabarit(self::entree(4), $habitude));
        // Une erreur explique déjà la lecture vide.
        $this->assertSame('', Journal::alerte_gabarit(self::entree(0, 0, ['erreurs' => ['Réponse HTTP 503']]), $habitude));
    }

    public function test_alerte_quand_une_grosse_source_rend_bien_moins(): void {
        $habitude = [self::entree(18), self::entree(17), self::entree(19)];
        $alerte = Journal::alerte_gabarit(self::entree(4), $habitude);
        $this->assertStringContainsString('4', $alerte);
        $this->assertStringContainsString('18', $alerte);
        $this->assertSame('', Journal::alerte_gabarit(self::entree(6), $habitude));
        // Petite source : passer de 6 à 1 n'a rien d'anormal.
        $this->assertSame('', Journal::alerte_gabarit(self::entree(1), [self::entree(6), self::entree(6), self::entree(6)]));
    }

    public function test_pas_d_alerte_sans_habitude_connue(): void {
        $this->assertSame('', Journal::alerte_gabarit(self::entree(0), []));
        $this->assertSame('', Journal::alerte_gabarit(self::entree(0), [self::entree(6), self::entree(6)]));
        // Source d'ordinaire presque vide (appels) : zéro est normal.
        $this->assertSame('', Journal::alerte_gabarit(self::entree(0), [self::entree(1), self::entree(0), self::entree(2), self::entree(1)]));
        // Les lectures en erreur ou déjà signalées ne font pas l'habitude ; les entrées illisibles sont tolérées.
        $this->assertSame('', Journal::alerte_gabarit(self::entree(0), [self::entree(6), self::entree(6), self::entree(9, 0, ['erreurs' => ['x']]), 'x', self::entree(8, 0, ['alerte' => 'y'])]));
    }

    public function test_l_alerte_dure_tant_que_l_habitude_est_dans_la_fenetre(): void {
        $signalees = array_fill(0, 11, self::entree(0, 0, ['alerte' => 'Rien lu']));
        $habitude = [self::entree(6), self::entree(6), self::entree(6)];
        $this->assertNotSame('', Journal::alerte_gabarit(self::entree(0), array_merge($signalees, $habitude)));
        // Après deux semaines de lectures vides, la source est tenue pour vide : l'alerte s'éteint d'elle-même.
        $this->assertSame('', Journal::alerte_gabarit(self::entree(0), array_merge($signalees, [self::entree(0, 0, ['alerte' => 'Rien lu'])], $habitude)));
    }

    public function test_consigner_pose_l_alerte(): void {
        foreach ([6, 5, 7] as $lus) {
            Journal::consigner('obvia', self::entree($lus));
        }
        $this->assertArrayNotHasKey('alerte', Journal::derniere('obvia'));
        Journal::consigner('obvia', self::entree(0));
        $this->assertStringContainsString('Rien lu', Journal::derniere('obvia')['alerte']);
        // L'alerte d'un lecteur (infolettre) est gardée telle quelle.
        Journal::consigner('ssa_agenda', self::entree(3, 0, ['alerte' => 'Le bloc a changé']));
        $this->assertSame('Le bloc a changé', Journal::derniere('ssa_agenda')['alerte']);
        $this->assertSame(['obvia' => Journal::derniere('obvia')['alerte'], 'ssa_agenda' => 'Le bloc a changé'], Journal::alertes(Journal::tout()));
        $this->assertSame([], Journal::alertes(['x' => 'illisible', 'y' => [['lus' => 1]], 'z' => [['alerte' => ''], ['alerte' => 'ancienne']]]));
    }
}

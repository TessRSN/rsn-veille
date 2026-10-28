<?php
declare(strict_types=1);

require_once __DIR__ . '/bouchons-wordpress.php';

use PHPUnit\Framework\TestCase;
use RSN\Veille\Appariement;
use RSN\Veille\Fiche;

final class AppariementTest extends TestCase {
    private static function fiche(string $titre, string $debut, ?string $fin = null): Fiche {
        return Fiche::depuis_tableau(['type' => 'evenement', 'titre' => $titre, 'debut' => $debut, 'fin' => $fin]);
    }

    /** @param array<int, array{0: Fiche, 1: Fiche}> $paires */
    private static function titres(array $paires): array {
        return array_map(static fn(array $p): array => [$p[0]->titre, $p[1]->titre], $paires);
    }

    public function test_memes_dates_appariees(): void {
        $fr = self::fiche('Atelier', '2026-11-05', '2026-11-06');
        $en = self::fiche('Workshop', '2026-11-05', '2026-11-06');
        $resultat = Appariement::apparier([$fr], [$en]);
        $this->assertCount(1, $resultat['paires']);
        $this->assertSame($fr, $resultat['paires'][0][0]);
        $this->assertSame($en, $resultat['paires'][0][1]);
        $this->assertSame([], $resultat['orphelines']);
    }

    public function test_deux_le_meme_jour_appariees_dans_l_ordre(): void {
        $francaises = [self::fiche('Premier', '2026-11-05'), self::fiche('Second', '2026-11-05')];
        $anglaises = [self::fiche('First', '2026-11-05'), self::fiche('Second (en)', '2026-11-05')];
        $resultat = Appariement::apparier($francaises, $anglaises);
        $this->assertSame([['Premier', 'First'], ['Second', 'Second (en)']], self::titres($resultat['paires']));
        $this->assertSame([], $resultat['orphelines']);
    }

    public function test_fin_absente_vaut_le_debut(): void {
        $francaises = [self::fiche('Sans fin', '2026-11-05'), self::fiche('Fin le même jour', '2026-11-07', '2026-11-07')];
        $anglaises = [self::fiche('No end', '2026-11-05', '2026-11-05'), self::fiche('Same day end', '2026-11-07')];
        $resultat = Appariement::apparier($francaises, $anglaises);
        $this->assertSame([['Sans fin', 'No end'], ['Fin le même jour', 'Same day end']], self::titres($resultat['paires']));
    }

    public function test_fin_differente_non_appariee(): void {
        $fr = self::fiche('Colloque', '2026-11-05', '2026-11-06');
        $en = self::fiche('Symposium', '2026-11-05', '2026-11-07');
        $resultat = Appariement::apparier([$fr], [$en]);
        $this->assertSame([], $resultat['paires']);
        $this->assertSame([$en], $resultat['orphelines']);
    }

    public function test_anglaises_sans_equivalent_orphelines_dans_l_ordre(): void {
        $francaises = [self::fiche('Atelier', '2026-11-05')];
        $sans_equivalent = self::fiche('English only', '2026-11-20');
        $en_trop = self::fiche('Extra', '2026-11-05');
        $resultat = Appariement::apparier($francaises, [$sans_equivalent, self::fiche('Workshop', '2026-11-05'), $en_trop]);
        $this->assertSame([['Atelier', 'Workshop']], self::titres($resultat['paires']));
        $this->assertSame([$sans_equivalent, $en_trop], $resultat['orphelines']);
    }

    public function test_francaise_sans_equivalent_ignoree(): void {
        $resultat = Appariement::apparier([self::fiche('Seule', '2026-11-05')], []);
        $this->assertSame(['paires' => [], 'orphelines' => []], $resultat);
    }
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/bouchons-wordpress.php';

use PHPUnit\Framework\TestCase;
use RSN\Veille\Public\EvenementsRsn;

final class EvenementsRsnTest extends TestCase {
    private function article(int $id, string $titre): WP_Post {
        $article = new WP_Post();
        $article->ID = $id;
        $article->post_title = $titre;
        return $article;
    }

    /** @param array<int,int|null> $trids @param array<int,string|null> $langues */
    private function choisir(array $articles, string $langue, array $trids, array $langues): array {
        return EvenementsRsn::choisir_par_groupe(
            $articles,
            $langue,
            static fn (int $id): ?int => $trids[$id] ?? null,
            static fn (int $id): ?string => $langues[$id] ?? null
        );
    }

    public function test_groupe_francais_et_anglais_garde_la_langue_demandee(): void {
        $articles = [$this->article(12, 'Journées'), $this->article(45, 'Days')];
        $trids = [12 => 7, 45 => 7];
        $langues = [12 => 'fr', 45 => 'en'];

        $en = $this->choisir($articles, 'en', $trids, $langues);
        $this->assertCount(1, $en);
        $this->assertSame(45, $en[0]['garde']->ID);
        $this->assertSame(12, $en[0]['francais']->ID);
        $this->assertSame(7, $en[0]['trid']);

        $fr = $this->choisir($articles, 'fr', $trids, $langues);
        $this->assertCount(1, $fr);
        $this->assertSame(12, $fr[0]['garde']->ID);
        $this->assertSame(12, $fr[0]['francais']->ID);
    }

    public function test_groupe_francais_seul_en_page_anglaise_garde_le_francais(): void {
        $resultat = $this->choisir([$this->article(12, 'Journées')], 'en', [12 => 7], [12 => 'fr']);
        $this->assertCount(1, $resultat);
        $this->assertSame(12, $resultat[0]['garde']->ID);
        $this->assertSame(12, $resultat[0]['francais']->ID);
    }

    public function test_article_anglais_orphelin_en_page_francaise_est_garde_sans_francais(): void {
        $resultat = $this->choisir([$this->article(45, 'Days')], 'fr', [45 => 9], [45 => 'en']);
        $this->assertCount(1, $resultat);
        $this->assertSame(45, $resultat[0]['garde']->ID);
        $this->assertNull($resultat[0]['francais']);
        $this->assertSame(9, $resultat[0]['trid']);
    }

    public function test_sans_wpml_chaque_article_est_son_propre_groupe(): void {
        $articles = [$this->article(3, 'A'), $this->article(1, 'B'), $this->article(2, 'C')];
        $resultat = $this->choisir($articles, 'fr', [], []);
        $this->assertSame([3, 1, 2], array_map(static fn (array $g): int => $g['garde']->ID, $resultat));
        foreach ($resultat as $groupe) {
            $this->assertNull($groupe['trid']);
            $this->assertNull($groupe['francais']);
        }
    }

    public function test_ordre_de_sortie_suit_la_premiere_apparition_des_groupes(): void {
        $articles = [
            $this->article(45, 'Days'), $this->article(20, 'Autre'), $this->article(12, 'Journées'),
            $this->article(21, 'Other'),
        ];
        $trids = [45 => 7, 12 => 7, 20 => 8, 21 => 8];
        $langues = [45 => 'en', 12 => 'fr', 20 => 'fr', 21 => 'en'];
        $resultat = $this->choisir($articles, 'fr', $trids, $langues);
        $this->assertSame([12, 20], array_map(static fn (array $g): int => $g['garde']->ID, $resultat));
        $this->assertSame([7, 8], array_map(static fn (array $g): ?int => $g['trid'], $resultat));
    }

    public function test_sans_langue_connue_garde_le_premier_du_groupe(): void {
        $articles = [$this->article(5, 'A'), $this->article(6, 'B')];
        $resultat = $this->choisir($articles, 'en', [5 => 3, 6 => 3], []);
        $this->assertCount(1, $resultat);
        $this->assertSame(5, $resultat[0]['garde']->ID);
        $this->assertNull($resultat[0]['francais']);
    }

    public function test_lire_sans_tec_rend_une_liste_vide(): void {
        $this->assertSame([], EvenementsRsn::lire('2025-09-01', 'en'));
    }
}

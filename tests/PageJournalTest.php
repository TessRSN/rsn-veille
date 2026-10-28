<?php
declare(strict_types=1);

require_once __DIR__ . '/bouchons-wordpress.php';

use PHPUnit\Framework\TestCase;
use RSN\Veille\Admin\PageJournal;

final class PageJournalTest extends TestCase {
    private const SOURCES = [
        'mila' => ['id' => 'mila', 'nom' => 'Mila : événements'],
        'obvia' => ['id' => 'obvia', 'nom' => 'Obvia : événements'],
        'inspq' => ['id' => 'inspq', 'nom' => 'INSPQ : nouvelles', 'categorie_nouvelles' => true],
    ];

    private static function entree(array $champs = []): array {
        return $champs + [
            'date' => '2026-10-26 03:05', 'lus' => 12, 'crees' => 3, 'fusionnes' => 1, 'mis_a_jour' => 2,
            'inchanges' => 5, 'ecartes' => 1, 'annules' => 0, 'ignores' => 4, 'en_attente' => 0,
            'erreurs' => [], 'duree_ms' => 1530,
        ];
    }

    public function test_resume_une_ligne_par_source_collectee(): void {
        $journal = ['mila' => [self::entree(['lus' => 9]), self::entree(['lus' => 8])]];
        $lignes = PageJournal::resume($journal, self::SOURCES);
        $this->assertSame(['mila', 'obvia'], array_column($lignes, 'id'));
        $this->assertSame('Mila : événements', $lignes[0]['nom']);
        $this->assertSame(9, $lignes[0]['entree']['lus']);
        $this->assertCount(2, $lignes[0]['historique']);
        // Jamais collectée.
        $this->assertNull($lignes[1]['entree']);
        $this->assertSame([], $lignes[1]['historique']);
    }

    public function test_resume_garde_les_sources_disparues_du_registre(): void {
        $journal = ['ancienne' => [self::entree()], 'inspq' => [self::entree()], 'abimee' => 'texte'];
        $lignes = PageJournal::resume($journal, self::SOURCES);
        // Les flux de nouvelles ne sont pas collectés la nuit : montrés seulement s'ils ont un journal.
        $this->assertSame(['mila', 'obvia', 'inspq', 'ancienne', 'abimee'], array_column($lignes, 'id'));
        $this->assertSame('ancienne', $lignes[3]['nom']);
        $this->assertNull($lignes[4]['entree']);
    }

    public function test_ligne_d_une_source_jamais_collectee(): void {
        $html = PageJournal::ligne_html('obvia', 'Obvia : événements', null);
        $this->assertStringContainsString('Obvia : événements', $html);
        $this->assertStringContainsString('jamais collectée', $html);
        $this->assertStringContainsString('colspan="10"', $html);
    }

    public function test_ligne_avec_compteurs_et_duree(): void {
        $html = PageJournal::ligne_html('mila', 'Mila : événements', self::entree());
        $this->assertStringContainsString('26 octobre 2026, 3 h 05', $html);
        preg_match_all('/<td class="num">([^<]*)<\/td>/', $html, $m);
        $this->assertSame(['12', '3', '1', '2', '5', '1', '4'], $m[1]);
        $this->assertStringContainsString('1,5 s', $html);
        $this->assertStringContainsString('Aucune', $html);
        $this->assertStringNotContainsString('<details', $html);
    }

    public function test_erreurs_repliees_et_echappees(): void {
        $html = PageJournal::ligne_html('mila', 'Mila <b>', self::entree(['erreurs' => ['HTTP 403', '<script>x</script>']]));
        $this->assertMatchesRegularExpression('/<details><summary>2 erreurs<\/summary><ul><li>HTTP 403<\/li><li>&lt;script&gt;x&lt;\/script&gt;<\/li><\/ul><\/details>/', $html);
        $this->assertStringContainsString('Mila &lt;b&gt;', $html);
        $un = PageJournal::ligne_html('mila', 'Mila', self::entree(['erreurs' => ['HTTP 500']]));
        $this->assertStringContainsString('<summary>1 erreur</summary>', $un);
    }

    public function test_entree_illisible_toleree(): void {
        $html = PageJournal::ligne_html('mila', 'Mila', ['date' => 'hier', 'lus' => 'beaucoup', 'erreurs' => 'oups']);
        $this->assertStringContainsString('hier', $html);
        preg_match_all('/<td class="num">([^<]*)<\/td>/', $html, $m);
        $this->assertSame(['0', '0', '0', '0', '0', '0', '0'], $m[1]);
    }

    public function test_page_avec_derniere_collecte_et_deroule(): void {
        $lignes = PageJournal::resume(['mila' => [self::entree(), self::entree(['date' => '2026-10-25 03:04'])]], self::SOURCES);
        $html = PageJournal::page_html($lignes, '26 octobre 2026, 3 h 40');
        $this->assertStringContainsString('Dernière collecte terminée : 26 octobre 2026, 3 h 40', $html);
        $this->assertStringContainsString('25 octobre 2026, 3 h 04', $html);
        $this->assertStringContainsString('jamais collectée', $html);

        $this->assertStringContainsString('Aucune collecte terminée', PageJournal::page_html([], ''));
    }

    public function test_appariement_anglais_affiche_s_il_est_consigne(): void {
        $html = PageJournal::ligne_html('mila', 'Mila', self::entree(['appariees_en' => 3, 'orphelines_en' => 1]));
        $this->assertStringContainsString('3 appariées en anglais, 1 orpheline', $html);
        $this->assertStringContainsString('Aucune', $html);
        $un = PageJournal::ligne_html('ivado', 'IVADO', self::entree(['appariees_en' => 1, 'orphelines_en' => 0, 'erreurs' => ['HTTP 500']]));
        $this->assertStringContainsString('1 appariée en anglais, 0 orphelines', $un);
        $this->assertStringContainsString('1 erreur', $un);
        // Toujours une cellule de moins que de colonnes : la phrase est dans la cellule des erreurs.
        $this->assertSame(substr_count(PageJournal::ligne_html('mila', 'Mila', self::entree()), '<td'), substr_count($html, '<td'));
    }

    public function test_sans_appariement_anglais_rien_d_affiche(): void {
        $html = PageJournal::ligne_html('obvia', 'Obvia', self::entree());
        $this->assertStringNotContainsString('anglais', $html);
        $abime = PageJournal::ligne_html('mila', 'Mila', self::entree(['appariees_en' => 'beaucoup']));
        $this->assertStringContainsString('0 appariées en anglais, 0 orphelines', $abime);
    }
}

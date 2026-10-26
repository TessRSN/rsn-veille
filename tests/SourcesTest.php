<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RSN\Veille\Sources;

final class SourcesTest extends TestCase {
    public function test_par_defaut_contient_les_sources_attendues(): void {
        $sources = Sources::par_defaut();
        $this->assertGreaterThanOrEqual(25, count($sources));
        $this->assertSame('A', $sources['dhc']['niveau']);
        $this->assertSame('tec', $sources['dhc']['type']);
        $this->assertSame('en', $sources['dhc']['langue']);
        $attendues = [
            'rsn', 'dhc', 'rqrv', 'sommeil', 'obvia', 'mila', 'frq_evenements', 'ivado',
            'frq_appels', 'crm_appels', 'rsn_appels', 'cares_appels', 'hippocrate_appels',
            'frq', 'ivado_nouvelles', 'mila_nouvelles', 'inspq', 'ssa', 'conseil_innovation',
            'genome', 'rqrm', 'rqrd', 'cmdo', 'acfas', 'quebec_ca',
        ];
        foreach ($attendues as $id) {
            $this->assertArrayHasKey($id, $sources, $id);
        }
    }

    public function test_chaque_source_est_complete(): void {
        $types = ['tec', 'rss', 'ical', 'html_obvia', 'html_mila', 'html_frq', 'html_ivado'];
        foreach (Sources::par_defaut() as $id => $s) {
            $this->assertSame($id, $s['id']);
            foreach (['nom', 'url', 'organisateur', 'langue'] as $cle) {
                $this->assertNotSame('', (string) ($s[$cle] ?? ''), "$id : $cle");
            }
            $this->assertStringStartsWith('https://', $s['url'], $id);
            $this->assertContains($s['type'], $types, $id);
            $this->assertContains($s['niveau'], ['A', 'B', 'C'], $id);
            $this->assertContains($s['type_source'], ['sante_numerique', 'sante', 'numerique', 'generaliste'], $id);
            $this->assertTrue($s['actif'], $id);
            if ($s['type'] === 'rss') {
                $this->assertContains($s['mode'], ['evenements', 'appels', 'nouvelles'], $id);
                $this->assertSame($s['mode'] === 'nouvelles', !empty($s['categorie_nouvelles']), $id);
            }
            if (str_starts_with($s['type'], 'html_')) {
                $this->assertStringStartsWith('https://', $s['base'], $id);
            }
            $this->assertSame($id === 'rsn', !empty($s['inclure_passes']), $id);
        }
        $sources = Sources::par_defaut();
        $this->assertSame(['Appel', 'Concours ouvert'], $sources['crm_appels']['categories']);
        $this->assertSame('appel|candidature', $sources['hippocrate_appels']['motif_titre']);
        $this->assertSame(8, $sources['inspq']['limite']);
        $this->assertSame('https://ivado.ca/evenements/feed/', $sources['ivado']['flux_evenements_url']);
    }

    public function test_fusionner_applique_actif_et_niveau(): void {
        $defaut = Sources::par_defaut();
        $sources = Sources::fusionner($defaut, [
            'dhc' => ['actif' => false],
            'obvia' => ['niveau' => 'M'],
            'mila' => ['niveau' => 'Z', 'actif' => '0'],
            'inconnue' => ['actif' => true],
            'rqrv' => ['actif' => true, 'url' => 'https://ailleurs.test/'],
        ]);
        $this->assertFalse($sources['dhc']['actif']);
        $this->assertSame('M', $sources['obvia']['niveau']);
        $this->assertSame('C', $sources['mila']['niveau']);
        $this->assertFalse($sources['mila']['actif']);
        $this->assertArrayNotHasKey('inconnue', $sources);
        $this->assertSame($defaut['rqrv']['url'], $sources['rqrv']['url']);
        $this->assertSame(array_keys($defaut), array_keys($sources));

        $actives = Sources::actives($sources);
        $this->assertArrayNotHasKey('dhc', $actives);
        $this->assertArrayNotHasKey('mila', $actives);
        $this->assertArrayHasKey('rsn', $actives);
        $this->assertCount(count($defaut) - 2, $actives);
    }
}

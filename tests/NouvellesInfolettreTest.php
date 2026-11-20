<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RSN\Veille\Nouvelles;
use RSN\Veille\Pertinence;

require_once __DIR__ . '/bouchons-wordpress.php';
require_once __DIR__ . '/GabaritMailchimp.php';

/** Radar : une infolettre Mailchimp est découpée, chaque encadré devient une nouvelle. */
final class NouvellesInfolettreTest extends TestCase {
    private const AUJOURDHUI = '2026-11-21';
    private const FLUX = 'https://us16.campaign-archive.com/feed?u=abc&id=def';
    private const BOITE = ['Boîte à outils SSA', 'https://ssa.test/boite-a-outils/'];

    private static function source(array $extras = []): array {
        return $extras + [
            'id' => 'ssa_infolettre', 'nom' => 'SSA : infolettre', 'url' => self::FLUX, 'type' => 'rss', 'niveau' => 'B',
            'type_source' => 'sante', 'organisateur' => 'Unité SSA', 'langue' => 'fr', 'actif' => true,
            'mode' => 'nouvelles', 'limite' => 8, 'categorie_nouvelles' => true, 'decoupage' => 'mailchimp',
        ];
    }

    private static function lire(string $flux, array $source = []): array {
        $obtenir = static fn(string $url): array => ['corps' => $flux, 'type' => 'application/rss+xml', 'code' => 200, 'url_finale' => $url, 'erreur' => null];
        return Nouvelles::lire_source(self::source($source), $obtenir, new Pertinence(), self::AUJOURDHUI);
    }

    /** @param int $n Nombre d'encadrés propres au numéro, en plus de la boîte à outils. */
    private static function ordinaire(string $prefixe, int $n): string {
        $cartes = [];
        for ($i = 1; $i <= $n; $i++) {
            $cartes[] = ['Article ' . $prefixe . $i, 'https://ssa.test/' . $prefixe . '/' . $i];
        }
        $cartes[] = self::BOITE;
        return GabaritMailchimp::numero_de($cartes);
    }

    private static function flux_habituel(array $premiers = []): string {
        return GabaritMailchimp::flux(array_merge($premiers, [
            ['Infolettre - Novembre 2026', 'https://mailchi.mp/x/nov', 'Fri, 20 Nov 2026 15:00:00 +0000', GabaritMailchimp::numero_de([
                ['Un outil numérique pour les données de santé', 'https://ssa.test/nov/1?utm_source=mailchimp'], ['Article nov2', 'https://ssa.test/nov/2'], self::BOITE,
            ])],
            // Le même numéro envoyé deux fois, à une semaine d'écart.
            ['Infolettre - Octobre 2026', 'https://mailchi.mp/x/oct-b', 'Wed, 28 Oct 2026 12:00:00 +0000', self::ordinaire('oct', 2)],
            ['Infolettre - Octobre 2026', 'https://mailchi.mp/x/oct-a', 'Wed, 21 Oct 2026 12:00:00 +0000', self::ordinaire('oct', 2)],
            ['Infolettre - Septembre 2026', 'https://mailchi.mp/x/sep', 'Mon, 28 Sep 2026 12:00:00 +0000', self::ordinaire('sep', 2)],
            ['Infolettre - Août 2026', 'https://mailchi.mp/x/aou', 'Thu, 27 Aug 2026 12:00:00 +0000', self::ordinaire('aou', 2)],
        ]));
    }

    public function test_chaque_encadre_devient_une_nouvelle(): void {
        $r = self::lire(self::flux_habituel());
        $this->assertSame([], $r['erreurs']);
        $this->assertArrayNotHasKey('alertes', $r);
        $par_url = array_column($r['nouvelles'], null, 'url');
        // Novembre (3), octobre (2, un seul des deux envois), septembre (2) ; août a plus de 60 jours.
        $this->assertSame([
            'https://ssa.test/nov/1', 'https://ssa.test/nov/2', 'https://ssa.test/boite-a-outils/',
            'https://ssa.test/oct/1', 'https://ssa.test/oct/2', 'https://ssa.test/sep/1', 'https://ssa.test/sep/2',
        ], array_keys($par_url));
        $premiere = $par_url['https://ssa.test/nov/1'];
        $this->assertSame(['url', 'titre', 'date', 'source', 'organisateur', 'resume', 'statut_radar'], array_keys($premiere));
        $this->assertSame(['Un outil numérique pour les données de santé', '2026-11-20', 'SSA : infolettre', 'Unité SSA', 'Texte de présentation.', 'reperee'], array_slice(array_values($premiere), 1));
        $this->assertSame('a_trier', $par_url['https://ssa.test/nov/2']['statut_radar']);
        // Un encadré porte la date du dernier numéro où il paraît : le plus récent des deux envois d'octobre.
        $this->assertSame('2026-10-28', $par_url['https://ssa.test/oct/1']['date']);
    }

    public function test_deja_paru_compte_les_autres_numeros_pas_les_envois_en_double(): void {
        $par_url = array_column(self::lire(self::flux_habituel())['nouvelles'], null, 'url');
        // La boîte à outils revient dans les quatre numéros (cinq envois) : trois autres numéros que celui de novembre.
        $this->assertSame('3', $par_url['https://ssa.test/boite-a-outils/']['deja_paru']);
        $this->assertSame('2026-11-20', $par_url['https://ssa.test/boite-a-outils/']['date']);
        // Octobre a été envoyé deux fois : ses articles ne sont pas « déjà parus ».
        $this->assertArrayNotHasKey('deja_paru', $par_url['https://ssa.test/oct/1']);
        $this->assertArrayNotHasKey('deja_paru', $par_url['https://ssa.test/nov/1']);
    }

    public function test_envoi_special_sans_encadre_reste_une_seule_nouvelle_sans_alerte(): void {
        $special = '<html><head><title>x</title><style>p{margin:0}</style></head><body><table class="mcnTextBlock"><tr><td class="mcnTextContent">Nouveaux outils numériques en libre accès. <a href="https://ssa.test/outils">Voir</a></td></tr></table></body></html>';
        $r = self::lire(self::flux_habituel([['Nouveaux outils en libre accès', 'https://mailchi.mp/x/special', 'Sat, 21 Nov 2026 12:00:00 +0000', $special]]));
        $this->assertArrayNotHasKey('alertes', $r);
        $this->assertSame([], $r['erreurs']);
        $premiere = $r['nouvelles'][0];
        $this->assertSame(['https://mailchi.mp/x/special', 'Nouveaux outils en libre accès', '2026-11-21'], [$premiere['url'], $premiere['titre'], $premiere['date']]);
        $this->assertStringStartsWith('Nouveaux outils numériques en libre accès.', $premiere['resume']);
        $this->assertStringNotContainsString('margin', $premiere['resume']);
        $this->assertCount(8, $r['nouvelles']);
    }

    public function test_alerte_quand_le_dernier_numero_n_a_plus_d_encadre(): void {
        $autre_gabarit = '<html><body><div class="bloc-neuf"><h2>Article</h2><a href="https://ssa.test/dec/1">Lire</a></div></body></html>';
        $r = self::lire(self::flux_habituel([['Infolettre - Décembre 2026', 'https://mailchi.mp/x/dec', 'Sat, 21 Nov 2026 12:00:00 +0000', $autre_gabarit]]));
        $this->assertSame([], $r['erreurs']);
        $this->assertCount(1, $r['alertes']);
        $this->assertStringContainsString('SSA : infolettre', $r['alertes'][0]);
        $this->assertStringContainsString('« Infolettre - Décembre 2026 »', $r['alertes'][0]);
        $this->assertStringContainsString('gabarit', $r['alertes'][0]);
        // Le numéro reste visible, en une seule nouvelle.
        $this->assertSame('https://mailchi.mp/x/dec', $r['nouvelles'][0]['url']);
    }

    public function test_alerte_quand_le_dernier_numero_a_bien_moins_d_encadres(): void {
        $flux = GabaritMailchimp::flux([
            ['Infolettre - Novembre 2026', 'https://mailchi.mp/x/nov', 'Fri, 20 Nov 2026 15:00:00 +0000', self::ordinaire('nov', 1)],
            ['Infolettre - Octobre 2026', 'https://mailchi.mp/x/oct', 'Wed, 28 Oct 2026 12:00:00 +0000', self::ordinaire('oct', 8)],
            ['Infolettre - Septembre 2026', 'https://mailchi.mp/x/sep', 'Mon, 28 Sep 2026 12:00:00 +0000', self::ordinaire('sep', 9)],
            ['Infolettre - Août 2026', 'https://mailchi.mp/x/aou', 'Thu, 27 Aug 2026 12:00:00 +0000', self::ordinaire('aou', 7)],
        ]);
        $r = self::lire($flux);
        $this->assertCount(1, $r['alertes']);
        $this->assertStringContainsString('2', $r['alertes'][0]);
        $this->assertStringContainsString('9', $r['alertes'][0]);
        // Un numéro un peu plus court que les autres ne déclenche rien.
        $court = str_replace(self::ordinaire('nov', 1), self::ordinaire('nov', 4), $flux);
        $this->assertNotSame($flux, $court);
        $this->assertArrayNotHasKey('alertes', self::lire($court));
    }

    public function test_alerte_quand_aucun_numero_n_a_d_encadre(): void {
        $flux = GabaritMailchimp::flux([
            ['Lettre A', 'https://mailchi.mp/x/a', 'Fri, 20 Nov 2026 15:00:00 +0000', '<p>Bonjour <a href="https://ssa.test/a">a</a></p>'],
            ['Lettre B', 'https://mailchi.mp/x/b', 'Wed, 28 Oct 2026 12:00:00 +0000', '<p>Bonjour</p>'],
        ]);
        $r = self::lire($flux);
        $this->assertCount(1, $r['alertes']);
        $this->assertCount(2, $r['nouvelles']);
    }

    public function test_flux_illisible_et_assemblage_des_alertes(): void {
        $r = self::lire('<?xml version="1.0"?><rss><channel><title>Vide</title></channel></rss>');
        $this->assertSame([], $r['nouvelles']);
        $this->assertCount(1, $r['erreurs']);
        $this->assertStringStartsWith('SSA : infolettre : ', $r['erreurs'][0]);

        $assemble = Nouvelles::assembler([['nouvelles' => [], 'erreurs' => [], 'alertes' => ['Alerte 1']], ['nouvelles' => [], 'erreurs' => ['E']]], self::AUJOURDHUI);
        $this->assertSame(['Alerte 1'], $assemble['alertes']);
        $this->assertArrayNotHasKey('alertes', Nouvelles::assembler([['nouvelles' => [], 'erreurs' => []]], self::AUJOURDHUI));
    }
}

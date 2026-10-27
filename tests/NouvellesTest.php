<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RSN\Veille\Nouvelles;
use RSN\Veille\Pertinence;
use RSN\Veille\Sources;

final class NouvellesTest extends TestCase {
    private const AUJOURDHUI = '2026-10-25';

    /** FRQ (généraliste) et INSPQ (santé), tels que le registre les décrit. */
    private static function sources(): array {
        $toutes = Sources::par_defaut();
        return ['frq' => $toutes['frq'], 'inspq' => $toutes['inspq']];
    }

    /** Réponse comme `Http::obtenir` : flux XML en code 200. */
    private static function reponse(string $corps, array $champs = []): array {
        return $champs + ['corps' => $corps, 'type' => 'application/rss+xml; charset=UTF-8', 'code' => 200, 'url_finale' => '', 'erreur' => null];
    }

    /** @param array<string,array> $reponses url => réponse */
    private static function obtenir(array $reponses): callable {
        return static fn(string $url): array => $reponses[$url] ?? ['corps' => '', 'type' => '', 'code' => 0, 'url_finale' => $url, 'erreur' => 'Introuvable'];
    }

    private static function reponses_fixtures(): array {
        $s = self::sources();
        return [
            $s['frq']['url'] => self::reponse((string) file_get_contents(FIXTURES . '/frq.xml')),
            $s['inspq']['url'] => self::reponse((string) file_get_contents(FIXTURES . '/inspq.xml')),
        ];
    }

    private static function rss(string $items): string {
        return '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>T</title>' . $items . '</channel></rss>';
    }

    private static function item(string $titre, string $jour, string $description = ''): string {
        return '<item><title>' . $titre . '</title><link>https://x.test/' . rawurlencode($titre) . '</link>'
            . ($jour !== '' ? '<pubDate>' . gmdate('D, d M Y 12:00:00 +0000', (int) strtotime($jour)) . '</pubDate>' : '')
            . '<description>' . $description . '</description></item>';
    }

    private static function une_source(string $type_source = 'sante_numerique', array $extras = []): array {
        return ['x' => $extras + [
            'id' => 'x', 'nom' => 'Source X', 'url' => 'https://x.test/feed/', 'type' => 'rss', 'niveau' => 'B',
            'type_source' => $type_source, 'organisateur' => 'Organisme X', 'langue' => 'fr', 'actif' => true,
            'mode' => 'nouvelles', 'limite' => 8, 'categorie_nouvelles' => true,
        ]];
    }

    public function test_fixtures_frq_et_inspq(): void {
        $r = Nouvelles::lire(self::sources(), self::obtenir(self::reponses_fixtures()), new Pertinence(), self::AUJOURDHUI);
        $this->assertSame([], $r['erreurs']);
        $this->assertNotEmpty($r['nouvelles']);
        foreach ($r['nouvelles'] as $n) {
            $this->assertSame(['url', 'titre', 'date', 'source', 'organisateur', 'resume', 'statut_radar'], array_keys($n));
            $this->assertMatchesRegularExpression('/^2026-\d\d-\d\d$/', $n['date']);
            $this->assertNotSame('', $n['titre']);
            $this->assertMatchesRegularExpression('#^https?://#', $n['url']);
            $this->assertContains($n['statut_radar'], ['reperee', 'a_trier']);
        }
        $sources = array_unique(array_column($r['nouvelles'], 'source'));
        sort($sources);
        $this->assertSame(['FRQ : nouvelles', 'INSPQ : nouvelles'], $sources);
        $this->assertSame('INSPQ', array_values(array_filter($r['nouvelles'], fn($n) => $n['source'] === 'INSPQ : nouvelles'))[0]['organisateur']);
    }

    public function test_tri_par_date_decroissante(): void {
        $r = Nouvelles::lire(self::sources(), self::obtenir(self::reponses_fixtures()), new Pertinence(), self::AUJOURDHUI);
        $dates = array_column($r['nouvelles'], 'date');
        $triees = $dates;
        rsort($triees);
        $this->assertSame($triees, $dates);
        $this->assertGreaterThan(1, count(array_unique($dates)));
    }

    public function test_une_source_generaliste_donne_des_a_trier(): void {
        $r = Nouvelles::lire(['frq' => self::sources()['frq']], self::obtenir(self::reponses_fixtures()), new Pertinence(), self::AUJOURDHUI);
        $statuts = array_unique(array_column($r['nouvelles'], 'statut_radar'));
        $this->assertContains('a_trier', $statuts);
        // Les 8 plus récents du flux, moins les 2 qui ne touchent ni la santé ni le numérique (écartés).
        $this->assertCount(6, $r['nouvelles']);
    }

    public function test_statut_radar_suit_la_pertinence(): void {
        $flux = self::rss(
            self::item('Santé et intelligence artificielle', '2026-10-20')
            . self::item('Santé seulement', '2026-10-19')
            . self::item('Rien de pertinent ici', '2026-10-18', 'Fête annuelle')
        );
        $obtenir = self::obtenir(['https://x.test/feed/' => self::reponse($flux)]);
        $r = Nouvelles::lire(self::une_source('generaliste'), $obtenir, new Pertinence(), self::AUJOURDHUI);
        $this->assertSame(
            ['Santé et intelligence artificielle' => 'reperee', 'Santé seulement' => 'a_trier'],
            array_column($r['nouvelles'], 'statut_radar', 'titre')
        );
        // Source de santé et numérique : tout est repéré.
        $r = Nouvelles::lire(self::une_source('sante_numerique'), $obtenir, new Pertinence(), self::AUJOURDHUI);
        $this->assertSame(['reperee', 'reperee', 'reperee'], array_column($r['nouvelles'], 'statut_radar'));
    }

    public function test_retention_de_60_jours(): void {
        $flux = self::rss(
            self::item('Récente', '2026-10-01')
            . self::item('Limite exacte', '2026-08-26')
            . self::item('Trop vieille', '2026-08-25')
        );
        $obtenir = self::obtenir(['https://x.test/feed/' => self::reponse($flux)]);
        $r = Nouvelles::lire(self::une_source(), $obtenir, new Pertinence(), self::AUJOURDHUI);
        $this->assertSame(['Récente', 'Limite exacte'], array_column($r['nouvelles'], 'titre'));
    }

    public function test_sans_date_gardees_en_fin(): void {
        $flux = self::rss(self::item('Sans date', '') . self::item('Ancienne', '2026-09-01') . self::item('Récente', '2026-10-20'));
        $r = Nouvelles::lire(self::une_source(), self::obtenir(['https://x.test/feed/' => self::reponse($flux)]), new Pertinence(), self::AUJOURDHUI);
        $this->assertSame(['Récente', 'Ancienne', 'Sans date'], array_column($r['nouvelles'], 'titre'));
        $this->assertSame('', $r['nouvelles'][2]['date']);
    }

    public function test_limite_de_la_source(): void {
        $flux = self::rss(self::item('A', '2026-10-20') . self::item('B', '2026-10-19') . self::item('C', '2026-10-18'));
        $r = Nouvelles::lire(self::une_source('sante_numerique', ['limite' => 2]), self::obtenir(['https://x.test/feed/' => self::reponse($flux)]), new Pertinence(), self::AUJOURDHUI);
        $this->assertSame(['A', 'B'], array_column($r['nouvelles'], 'titre'));
    }

    public function test_source_en_erreur_consignee_sans_casser_les_autres(): void {
        $reponses = self::reponses_fixtures();
        $reponses[self::sources()['frq']['url']] = self::reponse('', ['code' => 403, 'erreur' => 'Réponse HTTP 403']);
        $r = Nouvelles::lire(self::sources(), self::obtenir($reponses), new Pertinence(), self::AUJOURDHUI);
        $this->assertSame(['FRQ : nouvelles : Réponse HTTP 403'], $r['erreurs']);
        $this->assertSame(['INSPQ : nouvelles'], array_values(array_unique(array_column($r['nouvelles'], 'source'))));
    }

    public function test_page_html_en_code_200_n_est_pas_un_flux(): void {
        $reponses = self::reponses_fixtures();
        $reponses[self::sources()['inspq']['url']] = self::reponse('<!doctype html><html><body>Accueil</body></html>', ['type' => 'text/html']);
        $r = Nouvelles::lire(self::sources(), self::obtenir($reponses), new Pertinence(), self::AUJOURDHUI);
        $this->assertCount(1, $r['erreurs']);
        $this->assertStringStartsWith('INSPQ : nouvelles : ', $r['erreurs'][0]);
        $this->assertSame(['FRQ : nouvelles'], array_values(array_unique(array_column($r['nouvelles'], 'source'))));
    }

    public function test_flux_vide_consigne(): void {
        $r = Nouvelles::lire(self::une_source(), self::obtenir(['https://x.test/feed/' => self::reponse(self::rss(''))]), new Pertinence(), self::AUJOURDHUI);
        $this->assertSame([], $r['nouvelles']);
        $this->assertSame(['Source X : Flux vide ou invalide'], $r['erreurs']);
    }

    public function test_sources_inactives_ou_hors_radar_ignorees(): void {
        $appels = Sources::par_defaut()['frq_appels'];
        $inactive = self::une_source('sante_numerique', ['actif' => false]);
        $demandes = [];
        $obtenir = static function (string $url) use (&$demandes): array {
            $demandes[] = $url;
            return self::reponse(self::rss(self::item('A', '2026-10-20')));
        };
        $r = Nouvelles::lire(['frq_appels' => $appels] + $inactive, $obtenir, new Pertinence(), self::AUJOURDHUI);
        $this->assertSame([], $demandes);
        $this->assertSame(['nouvelles' => [], 'erreurs' => []], ['nouvelles' => $r['nouvelles'], 'erreurs' => $r['erreurs']]);
    }

    public function test_adresses_non_http_ecartees_et_doublons_fusionnes(): void {
        $flux = self::rss(
            '<item><title>Piégée</title><link>javascript:alert(1)</link><pubDate>Tue, 20 Oct 2026 12:00:00 +0000</pubDate></item>'
            . self::item('Une', '2026-10-20')
            . self::item('Une', '2026-10-19')
        );
        $r = Nouvelles::lire(self::une_source(), self::obtenir(['https://x.test/feed/' => self::reponse($flux)]), new Pertinence(), self::AUJOURDHUI);
        $this->assertSame(['Une'], array_column($r['nouvelles'], 'titre'));
    }

    public function test_genere_le(): void {
        $r = Nouvelles::lire([], self::obtenir([]), new Pertinence(), self::AUJOURDHUI);
        $this->assertSame(['nouvelles' => [], 'erreurs' => [], 'genere_le' => self::AUJOURDHUI], $r);
    }
}

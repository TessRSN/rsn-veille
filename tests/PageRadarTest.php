<?php
declare(strict_types=1);

require_once __DIR__ . '/bouchons-wordpress.php';

use PHPUnit\Framework\TestCase;
use RSN\Veille\Admin\PageRadar;
use RSN\Veille\Collecte;

final class PageRadarTest extends TestCase {
    private static function nouvelle(int $n, array $champs = []): array {
        return $champs + [
            'url' => 'https://x.test/n' . $n, 'titre' => 'Titre ' . $n, 'date' => '2026-10-20', 'source' => 'Source X',
            'organisateur' => 'Organisme X', 'resume' => 'Résumé ' . $n, 'statut_radar' => 'reperee',
        ];
    }

    // --- Logique pure ---

    public function test_garder_met_en_tete_et_ajoute_la_date(): void {
        $gardees = PageRadar::garder([], self::nouvelle(1), '2026-10-25 09:00');
        $gardees = PageRadar::garder($gardees, self::nouvelle(2), '2026-10-25 09:05');
        $this->assertSame(['https://x.test/n2', 'https://x.test/n1'], array_column($gardees, 'url'));
        $this->assertSame(['url', 'titre', 'date', 'source', 'organisateur', 'resume', 'gardee_le'], array_keys($gardees[0]));
        $this->assertSame('2026-10-25 09:05', $gardees[0]['gardee_le']);
        $this->assertSame('Résumé 2', $gardees[0]['resume']);
    }

    public function test_garder_deduplique_par_url(): void {
        $gardees = PageRadar::garder([], self::nouvelle(1), '2026-10-25 09:00');
        $gardees = PageRadar::garder($gardees, self::nouvelle(2), '2026-10-25 09:05');
        $gardees = PageRadar::garder($gardees, self::nouvelle(1, ['titre' => 'Nouveau titre']), '2026-10-25 10:00');
        $this->assertSame(['https://x.test/n1', 'https://x.test/n2'], array_column($gardees, 'url'));
        $this->assertSame('Nouveau titre', $gardees[0]['titre']);
        $this->assertSame('2026-10-25 10:00', $gardees[0]['gardee_le']);
    }

    public function test_garder_borne_a_200_et_conserve_les_plus_recentes(): void {
        $gardees = [];
        for ($i = 1; $i <= 205; $i++) {
            $gardees = PageRadar::garder($gardees, self::nouvelle($i), '2026-10-25 09:00');
        }
        $this->assertCount(200, $gardees);
        $this->assertSame('https://x.test/n205', $gardees[0]['url']);
        $this->assertSame('https://x.test/n6', $gardees[199]['url']);
    }

    public function test_garder_tolere_des_gardees_illisibles(): void {
        $gardees = PageRadar::garder(['texte', ['titre' => 'sans url'], self::nouvelle(3)], self::nouvelle(1), 'maintenant');
        $this->assertSame(['https://x.test/n1', 'https://x.test/n3'], array_column($gardees, 'url'));
    }

    public function test_ignorer_ajoute_a_la_fin_sans_doublon(): void {
        $ignorees = PageRadar::ignorer(['https://x.test/a', 'https://x.test/b'], 'https://x.test/a');
        $this->assertSame(['https://x.test/b', 'https://x.test/a'], $ignorees);
    }

    public function test_ignorer_borne_aux_500_dernieres(): void {
        $ignorees = [];
        for ($i = 1; $i <= 505; $i++) {
            $ignorees = PageRadar::ignorer($ignorees, 'https://x.test/' . $i);
        }
        $this->assertCount(500, $ignorees);
        $this->assertSame('https://x.test/6', $ignorees[0]);
        $this->assertSame('https://x.test/505', $ignorees[499]);
    }

    public function test_retirer(): void {
        $gardees = [self::nouvelle(1), self::nouvelle(2)];
        $this->assertSame(['https://x.test/n2'], array_column(PageRadar::retirer($gardees, 'https://x.test/n1'), 'url'));
        $this->assertSame(['https://x.test/n1', 'https://x.test/n2'], array_column(PageRadar::retirer($gardees, 'https://x.test/inconnue'), 'url'));
    }

    public function test_visibles_exclut_gardees_et_ignorees(): void {
        $nouvelles = [self::nouvelle(1), self::nouvelle(2), self::nouvelle(3), self::nouvelle(4)];
        $visibles = PageRadar::visibles($nouvelles, [self::nouvelle(1)], ['https://x.test/n3']);
        $this->assertSame(['https://x.test/n2', 'https://x.test/n4'], array_column($visibles, 'url'));
    }

    // --- Rendu ---

    private static function options(): array {
        return ['url_action' => 'https://exemple.test/wp-admin/admin-post.php', 'nonce' => 'jeton-x'];
    }

    public function test_rendu_gardees_en_tete_puis_radar_par_source(): void {
        $donnees = ['genere_le' => '2026-10-25 09:00', 'erreurs' => [], 'nouvelles' => [
            self::nouvelle(1), self::nouvelle(2, ['source' => 'Autre source', 'statut_radar' => 'a_trier']), self::nouvelle(3),
        ]];
        $gardees = [self::nouvelle(3) + ['gardee_le' => '2026-10-25 08:00']];
        $html = PageRadar::page_html($donnees, $gardees, [], self::options());
        $this->assertLessThan(strpos($html, 'Autre source'), strpos($html, 'data-section="gardees"'));
        $this->assertLessThan(strpos($html, 'data-section="radar"'), strpos($html, 'data-section="gardees"'));
        $this->assertStringContainsString('1 gardée', $html);
        $this->assertStringContainsString('Retirer', $html);
        $this->assertStringContainsString('Garder pour l&#039;infolettre', $html);
        $this->assertStringContainsString('Ignorer', $html);
        $this->assertStringContainsString('Repérée', $html);
        $this->assertStringContainsString('À trier', $html);
        $this->assertStringContainsString('20 octobre 2026', $html);
        // Une nouvelle gardée n'est pas aussi dans le radar : « Titre 3 » en un seul <li> de radar exclu.
        $radar = substr($html, (int) strpos($html, 'data-section="radar"'));
        $this->assertStringNotContainsString('Titre 3', $radar);
        $this->assertSame(1, substr_count($html, 'role="status"'));
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('value="jeton-x"', $html);
    }

    public function test_rendu_exclut_les_ignorees(): void {
        $donnees = ['genere_le' => '', 'erreurs' => [], 'nouvelles' => [self::nouvelle(1), self::nouvelle(2)]];
        $html = PageRadar::page_html($donnees, [], ['https://x.test/n1'], self::options());
        $this->assertStringNotContainsString('Titre 1', $html);
        $this->assertStringContainsString('Titre 2', $html);
        $this->assertStringContainsString('0 gardée', $html);
    }

    public function test_rendu_echappe_tout(): void {
        $mechante = self::nouvelle(1, [
            'titre' => '<script>alert(1)</script>', 'resume' => '"><img src=x onerror=alert(2)>', 'source' => '<b>Src</b>',
            'organisateur' => '<i>Org</i>', 'url' => 'https://x.test/a?b=1&c="><script>',
        ]);
        $donnees = ['genere_le' => '<u>maintenant</u>', 'erreurs' => ['<script>erreur</script> : HTTP 403'], 'nouvelles' => [$mechante]];
        $html = PageRadar::page_html($donnees, [$mechante + ['gardee_le' => 'x']], [], self::options());
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<b>Src', $html);
        $this->assertStringNotContainsString('<u>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function test_rendu_adresse_non_http_jamais_affichee(): void {
        $donnees = ['genere_le' => '', 'erreurs' => [], 'nouvelles' => [self::nouvelle(1, ['url' => 'javascript:alert(1)'])]];
        $html = PageRadar::page_html($donnees, [], [], self::options());
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('Titre 1', $html);
    }

    public function test_rendu_erreurs_discretes_et_vide(): void {
        $donnees = ['genere_le' => '', 'erreurs' => ['FRQ : nouvelles : Réponse HTTP 403', 'INSPQ : nouvelles : Flux vide'], 'nouvelles' => []];
        $html = PageRadar::page_html($donnees, [], [], self::options());
        $this->assertStringContainsString('<details', $html);
        $this->assertStringContainsString('2 sources en erreur', $html);
        $this->assertStringContainsString('FRQ : nouvelles : Réponse HTTP 403', $html);
        $this->assertStringContainsString('Aucune nouvelle à trier', $html);
        $sans = PageRadar::page_html(['genere_le' => '', 'erreurs' => [], 'nouvelles' => []], [], [], self::options());
        $this->assertStringNotContainsString('<details', $sans);
    }

    // --- Cache et AJAX ---

    private const NONCE = 'rsn_veille_radar';

    protected function setUp(): void {
        rsn_veille_bouchons_reinitialiser();
        $_POST = $_REQUEST = [];
        $GLOBALS['rsn_veille_wp']['capacites'] = [\RSN\Veille\Admin\Menu::CAPACITE];
        Collecte::$obtenir_pour_tests = null;
    }

    protected function tearDown(): void {
        $_POST = $_REQUEST = [];
        Collecte::$obtenir_pour_tests = null;
    }

    private static function cache(array $nouvelles): void {
        set_transient('rsn_veille_radar', ['nouvelles' => $nouvelles, 'erreurs' => [], 'genere_le' => '2026-10-25 09:00'], 3600);
    }

    /** @return array{0: array, 1: int} */
    private static function ajax(string $methode, array $donnees): array {
        $_POST = $_REQUEST = $donnees;
        try {
            PageRadar::$methode();
        } catch (RsnVeilleArretRequete $arret) {
            return [$arret->reponse, $arret->statut_http];
        }
        throw new RuntimeException('Aucune réponse JSON');
    }

    private static function jeton(): string {
        return wp_create_nonce(self::NONCE);
    }

    public function test_garder_prend_les_donnees_dans_le_cache(): void {
        self::cache([self::nouvelle(1), self::nouvelle(2)]);
        [$reponse, $code] = self::ajax('ajax_garder', [
            'nonce' => self::jeton(), 'url' => 'https://x.test/n2', 'titre' => 'TITRE FORGÉ', 'resume' => 'FORGÉ',
        ]);
        $this->assertSame(200, $code);
        $this->assertSame(['success' => true, 'data' => ['ok' => true, 'gardees' => 1, 'libelle' => '1 gardée']], $reponse);
        $gardees = get_option('rsn_veille_nouvelles_gardees');
        $this->assertSame('Titre 2', $gardees[0]['titre']);
        $this->assertSame('Résumé 2', $gardees[0]['resume']);
        $this->assertSame('Source X', $gardees[0]['source']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d$/', $gardees[0]['gardee_le']);
    }

    public function test_garder_refuse_une_adresse_hors_du_cache(): void {
        self::cache([self::nouvelle(1)]);
        [$reponse, $code] = self::ajax('ajax_garder', ['nonce' => self::jeton(), 'url' => 'https://pirate.test/x']);
        $this->assertSame(404, $code);
        $this->assertFalse($reponse['success']);
        $this->assertFalse(get_option('rsn_veille_nouvelles_gardees', false));
    }

    public function test_garder_relit_les_flux_si_le_cache_est_perime(): void {
        $flux = '<?xml version="1.0"?><rss version="2.0"><channel><title>T</title><item><title>Santé numérique</title><link>https://frq.test/a</link><pubDate>Tue, 20 Oct 2026 12:00:00 +0000</pubDate></item></channel></rss>';
        Collecte::$obtenir_pour_tests = static fn(string $url, array $options = []): array
            => ['corps' => $flux, 'type' => 'application/rss+xml', 'code' => 200, 'url_finale' => $url, 'erreur' => null];
        [$reponse, $code] = self::ajax('ajax_garder', ['nonce' => self::jeton(), 'url' => 'https://frq.test/a']);
        $this->assertSame(200, $code, json_encode($reponse));
        $this->assertSame('Santé numérique', get_option('rsn_veille_nouvelles_gardees')[0]['titre']);
        // Le résultat est remis en cache pour une heure.
        $this->assertIsArray(get_transient('rsn_veille_radar'));
    }

    public function test_ignorer(): void {
        [$reponse, $code] = self::ajax('ajax_ignorer', ['nonce' => self::jeton(), 'url' => 'https://x.test/n1']);
        $this->assertSame(200, $code);
        $this->assertTrue($reponse['data']['ok']);
        $this->assertSame(['https://x.test/n1'], get_option('rsn_veille_nouvelles_ignorees'));
    }

    public function test_retirer_gardee(): void {
        update_option('rsn_veille_nouvelles_gardees', [self::nouvelle(1) + ['gardee_le' => 'x'], self::nouvelle(2) + ['gardee_le' => 'x']], false);
        [$reponse, $code] = self::ajax('ajax_retirer', ['nonce' => self::jeton(), 'url' => 'https://x.test/n1']);
        $this->assertSame(200, $code);
        $this->assertSame(1, $reponse['data']['gardees']);
        $this->assertSame(['https://x.test/n2'], array_column(get_option('rsn_veille_nouvelles_gardees'), 'url'));
    }

    public function test_refus_sans_jeton_ou_mauvais_jeton(): void {
        foreach (['ajax_garder', 'ajax_ignorer', 'ajax_retirer'] as $methode) {
            [$reponse, $code] = self::ajax($methode, ['url' => 'https://x.test/n1']);
            $this->assertSame(403, $code, $methode);
            [, $code] = self::ajax($methode, ['url' => 'https://x.test/n1', 'nonce' => 'faux']);
            $this->assertSame(403, $code, $methode);
        }
        $this->assertFalse(get_option('rsn_veille_nouvelles_ignorees', false));
    }

    public function test_refus_sans_capacite(): void {
        $GLOBALS['rsn_veille_wp']['capacites'] = [];
        self::cache([self::nouvelle(1)]);
        foreach (['ajax_garder', 'ajax_ignorer', 'ajax_retirer'] as $methode) {
            [, $code] = self::ajax($methode, ['url' => 'https://x.test/n1', 'nonce' => self::jeton()]);
            $this->assertSame(403, $code, $methode);
        }
        $this->assertFalse(get_option('rsn_veille_nouvelles_gardees', false));
        $this->assertFalse(get_option('rsn_veille_nouvelles_ignorees', false));
    }

    public function test_refus_d_une_adresse_invalide(): void {
        self::cache([self::nouvelle(1, ['url' => 'javascript:alert(1)'])]);
        foreach (['ajax_garder', 'ajax_ignorer', 'ajax_retirer'] as $methode) {
            foreach (['javascript:alert(1)', 'ftp://x.test/a', '', 'pas une adresse'] as $url) {
                [, $code] = self::ajax($methode, ['url' => $url, 'nonce' => self::jeton()]);
                $this->assertSame(400, $code, $methode . ' ' . $url);
            }
            [, $code] = self::ajax($methode, ['url' => ['https://x.test/n1'], 'nonce' => self::jeton()]);
            $this->assertSame(400, $code, $methode . ' tableau');
        }
        $this->assertFalse(get_option('rsn_veille_nouvelles_gardees', false));
    }

    public function test_donnees_cache_une_heure_et_rafraichir_le_vide(): void {
        $appels = 0;
        Collecte::$obtenir_pour_tests = static function (string $url, array $options = []) use (&$appels): array {
            $appels++;
            return ['corps' => '', 'type' => '', 'code' => 403, 'url_finale' => $url, 'erreur' => 'Réponse HTTP 403'];
        };
        $a = PageRadar::donnees();
        $this->assertNotEmpty($a['erreurs']);
        $this->assertSame([], $a['nouvelles']);
        $premiers = $appels;
        $this->assertGreaterThan(0, $premiers);
        // Tout a échoué : rien n'est mis en cache, la page réessaie au prochain affichage.
        $this->assertFalse(get_transient('rsn_veille_radar'));
        self::cache([self::nouvelle(1)]);
        $this->assertSame('Titre 1', PageRadar::donnees()['nouvelles'][0]['titre']);
        $this->assertSame($premiers, $appels);
        PageRadar::vider_cache();
        $this->assertFalse(get_transient('rsn_veille_radar'));
    }

    public function test_options_illisibles_toleree(): void {
        update_option('rsn_veille_nouvelles_gardees', 'abîmé', false);
        update_option('rsn_veille_nouvelles_ignorees', ['https://x.test/a', 12, ['x']], false);
        $this->assertSame([], PageRadar::lire_gardees());
        $this->assertSame(['https://x.test/a'], PageRadar::lire_ignorees());
    }
}

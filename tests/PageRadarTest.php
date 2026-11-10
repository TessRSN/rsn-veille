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

    private static function options(array $en_plus = []): array {
        return $en_plus + ['url_action' => 'https://exemple.test/wp-admin/admin-post.php', 'nonce' => 'jeton-x', 'url_page' => 'https://exemple.test/wp-admin/admin.php?page=rsn-veille-radar'];
    }

    private static function lot(): array {
        return ['genere_le' => '2026-10-25 09:00', 'erreurs' => [], 'nouvelles' => [
            self::nouvelle(1), self::nouvelle(2, ['source' => 'Autre source', 'statut_radar' => 'a_trier']), self::nouvelle(3), self::nouvelle(4),
        ]];
    }

    public function test_onglets_avec_compteurs_et_onglet_a_trier_par_defaut(): void {
        $gardees = [self::nouvelle(3) + ['gardee_le' => '2026-10-25 08:00']];
        $html = PageRadar::page_html(self::lot(), $gardees, ['https://x.test/n4'], self::options());
        // Trois onglets, comme la file d'approbation, avec leur nombre.
        $this->assertStringContainsString('<nav class="nav-tab-wrapper"', $html);
        $this->assertMatchesRegularExpression('~nav-tab nav-tab-active" aria-current="page" href="[^"]*onglet=a_trier[^"]*">À trier <span class="count" data-compteur="a_trier">\(2\)</span>~', $html);
        $this->assertMatchesRegularExpression('~href="[^"]*onglet=gardees[^"]*">Gardées pour l&#039;infolettre <span class="count" data-compteur="gardees">\(1\)</span>~', $html);
        $this->assertMatchesRegularExpression('~href="[^"]*onglet=ecartees[^"]*">Écartées <span class="count" data-compteur="ecartees">\(1\)</span>~', $html);
        // Onglet « À trier » : les nouvelles ni gardées ni écartées, groupées par source.
        $this->assertStringContainsString('data-section="radar"', $html);
        $this->assertStringNotContainsString('data-section="gardees"', $html);
        $this->assertStringNotContainsString('data-section="ecartees"', $html);
        $this->assertStringContainsString('Titre 1', $html);
        $this->assertStringContainsString('Autre source', $html);
        $this->assertStringNotContainsString('Titre 3', $html);
        $this->assertStringNotContainsString('Titre 4', $html);
        $this->assertStringContainsString('Garder pour l&#039;infolettre</button>', $html);
        $this->assertStringContainsString('data-act="ignorer">Écarter</button>', $html);
        $this->assertStringContainsString('Repérée', $html);
        $this->assertStringContainsString('20 octobre 2026', $html);
        $this->assertSame(1, substr_count($html, 'role="status"'));
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
        $this->assertStringContainsString('value="jeton-x"', $html);
    }

    public function test_onglet_gardees(): void {
        $gardees = [self::nouvelle(3) + ['gardee_le' => '2026-10-25 08:00']];
        $html = PageRadar::page_html(self::lot(), $gardees, [], self::options(['onglet' => 'gardees']));
        $this->assertMatchesRegularExpression('~nav-tab nav-tab-active" aria-current="page" href="[^"]*onglet=gardees~', $html);
        $this->assertStringContainsString('data-section="gardees"', $html);
        $this->assertStringNotContainsString('data-section="radar"', $html);
        $this->assertStringContainsString('Titre 3', $html);
        $this->assertStringNotContainsString('Titre 1', $html);
        $this->assertStringContainsString('data-act="retirer"', $html);
        $this->assertStringContainsString('gardée le 25 octobre 2026', $html);
        $vide = PageRadar::page_html(self::lot(), [], [], self::options(['onglet' => 'gardees']));
        $this->assertStringContainsString('Aucune nouvelle gardée', $vide);
    }

    public function test_onglet_ecartees_les_plus_recentes_d_abord(): void {
        $details = [
            'https://x.test/n1' => ['titre' => 'Titre 1', 'date' => '2026-10-20', 'source' => 'Source X', 'organisateur' => 'Organisme X', 'resume' => 'Résumé 1', 'ignoree_le' => '2026-11-09 10:00'],
        ];
        $html = PageRadar::page_html(self::lot(), [], ['https://x.test/n1', 'https://x.test/ancienne'], self::options(['onglet' => 'ecartees']), $details);
        $this->assertMatchesRegularExpression('~data-compteur="ecartees">\(2\)~', $html);
        $section = substr($html, (int) strpos($html, 'data-section="ecartees"'));
        // La plus récemment écartée (fin de la liste) en premier ; sans détails, l'adresse sert de titre.
        $this->assertLessThan(strpos($section, 'Titre 1'), strpos($section, 'https://x.test/ancienne'));
        $this->assertStringContainsString('data-act="retablir"', $section);
        $this->assertStringContainsString('Remettre dans le radar', $section);
        $this->assertStringContainsString('écartée le 9 novembre 2026', $section);
        // « Garder » seulement quand le titre est connu (sinon rien à garder).
        $this->assertSame(1, substr_count($section, 'data-act="garder"'));
        $this->assertStringNotContainsString('data-section="radar"', $html);
        $vide = PageRadar::page_html(self::lot(), [], [], self::options(['onglet' => 'ecartees']));
        $this->assertStringContainsString('Aucune nouvelle écartée', $vide);
    }

    public function test_onglet_inconnu_vaut_a_trier(): void {
        $html = PageRadar::page_html(self::lot(), [], [], self::options(['onglet' => 'n-importe-quoi']));
        $this->assertStringContainsString('data-section="radar"', $html);
    }

    public function test_dans_la_periode(): void {
        $n = [self::nouvelle(1, ['date' => '2026-10-01']), self::nouvelle(2, ['date' => '2026-10-15']), self::nouvelle(3, ['date' => '2026-11-02']), self::nouvelle(4, ['date' => ''])];
        $urls = static fn(array $l): array => array_column($l, 'url');
        $this->assertSame(['https://x.test/n1', 'https://x.test/n2', 'https://x.test/n3', 'https://x.test/n4'], $urls(PageRadar::dans_la_periode($n, '', '')));
        $this->assertSame(['https://x.test/n2', 'https://x.test/n3'], $urls(PageRadar::dans_la_periode($n, '2026-10-15', '')));
        $this->assertSame(['https://x.test/n1', 'https://x.test/n2'], $urls(PageRadar::dans_la_periode($n, '', '2026-10-15')));
        $this->assertSame(['https://x.test/n2'], $urls(PageRadar::dans_la_periode($n, '2026-10-10', '2026-10-31')));
        // Bornes inversées : remises dans l'ordre. Date illisible : ignorée.
        $this->assertSame(['https://x.test/n2'], $urls(PageRadar::dans_la_periode($n, '2026-10-31', '2026-10-10')));
        $this->assertSame(['https://x.test/n1', 'https://x.test/n2'], $urls(PageRadar::dans_la_periode($n, 'hier', '2026-10-15')));
    }

    public function test_filtre_par_dates_sur_chaque_onglet(): void {
        $donnees = ['genere_le' => '', 'erreurs' => [], 'nouvelles' => [
            self::nouvelle(1, ['date' => '2026-10-01']), self::nouvelle(2, ['date' => '2026-10-15']), self::nouvelle(3, ['date' => '2026-11-02']),
        ]];
        $html = PageRadar::page_html($donnees, [], [], self::options(['du' => '2026-10-10', 'au' => '2026-10-31']));
        // Le formulaire garde l'onglet et les dates ; les onglets gardent les dates ; les compteurs restent des totaux.
        $this->assertStringContainsString('<input type="hidden" name="page" value="rsn-veille-radar">', $html);
        $this->assertStringContainsString('<input type="hidden" name="onglet" value="a_trier">', $html);
        $this->assertStringContainsString('name="du" value="2026-10-10"', $html);
        $this->assertStringContainsString('name="au" value="2026-10-31"', $html);
        $this->assertMatchesRegularExpression('~onglet=gardees&amp;du=2026-10-10&amp;au=2026-10-31~', $html);
        $this->assertMatchesRegularExpression('~data-compteur="a_trier">\(3\)~', $html);
        $this->assertStringContainsString('Titre 2', $html);
        $this->assertStringNotContainsString('Titre 1', $html);
        $this->assertStringNotContainsString('Titre 3', $html);
        $this->assertStringContainsString('1 nouvelle publiée du 10 au 31 octobre 2026', $html);
        $this->assertStringContainsString('Tout afficher', $html);
        // Gardées et écartées : même filtre, sur la date de publication.
        $gardees = [self::nouvelle(1, ['date' => '2026-10-01']) + ['gardee_le' => '2026-11-01 08:00'], self::nouvelle(2, ['date' => '2026-10-15']) + ['gardee_le' => '2026-11-01 08:00']];
        $html = PageRadar::page_html($donnees, $gardees, [], self::options(['onglet' => 'gardees', 'du' => '2026-10-10']));
        $this->assertStringContainsString('Titre 2', $html);
        $this->assertStringNotContainsString('Titre 1', $html);
        $this->assertStringContainsString('1 nouvelle publiée depuis le 10 octobre 2026', $html);
        $details = ['https://x.test/n1' => ['titre' => 'Titre 1', 'date' => '2026-10-01'], 'https://x.test/n2' => ['titre' => 'Titre 2', 'date' => '2026-10-15']];
        $html = PageRadar::page_html($donnees, [], ['https://x.test/n1', 'https://x.test/n2'], self::options(['onglet' => 'ecartees', 'au' => '2026-10-05']), $details);
        $this->assertStringContainsString('Titre 1', $html);
        $this->assertStringNotContainsString('Titre 2', $html);
        $this->assertStringContainsString("1 nouvelle publiée jusqu&#039;au 5 octobre 2026", $html);
        // Sans filtre : ni résumé de période ni « Tout afficher ».
        $sans = PageRadar::page_html($donnees, [], [], self::options());
        $this->assertStringNotContainsString('Tout afficher', $sans);
        $this->assertStringNotContainsString('nouvelle publiée', $sans);
    }

    public function test_filtre_sans_resultat(): void {
        $html = PageRadar::page_html(self::lot(), [], [], self::options(['du' => '2027-01-01']));
        $this->assertStringContainsString('Aucune nouvelle publiée depuis le 1er janvier 2027', $html);
    }

    public function test_rendu_echappe_tout(): void {
        $mechante = self::nouvelle(1, [
            'titre' => '<script>alert(1)</script>', 'resume' => '"><img src=x onerror=alert(2)>', 'source' => '<b>Src</b>',
            'organisateur' => '<i>Org</i>', 'url' => 'https://x.test/a?b=1&c="><script>',
        ]);
        $donnees = ['genere_le' => '<u>maintenant</u>', 'erreurs' => ['<script>erreur</script> : HTTP 403'], 'nouvelles' => [$mechante, self::nouvelle(2, ['titre' => '<b>gras</b>'])]];
        $details = ['https://x.test/e?b="><script>' => ['titre' => '<script>alert(3)</script>', 'source' => '<b>Src</b>', 'ignoree_le' => '<i>']];
        foreach (['a_trier', 'gardees', 'ecartees'] as $onglet) {
            $html = PageRadar::page_html($donnees, [$mechante + ['gardee_le' => 'x']], ['https://x.test/e?b="><script>'], self::options(['onglet' => $onglet, 'du' => '"><script>', 'au' => '<b>']), $details);
            $this->assertStringNotContainsString('<script', $html);
            $this->assertStringNotContainsString('<img', $html);
            $this->assertStringNotContainsString('<b>', $html);
            $this->assertStringNotContainsString('<u>', $html);
        }
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', PageRadar::page_html($donnees, [$mechante + ['gardee_le' => 'x']], [], self::options(['onglet' => 'gardees'])));
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
        $this->assertSame(['ok' => true, 'gardees' => 1, 'libelle' => '1 gardée', 'compteurs' => ['a_trier' => 1, 'gardees' => 1, 'ecartees' => 0]], $reponse['data']);
        $this->assertTrue($reponse['success']);
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

    /** Remplace le réseau : compte les appels et renvoie le flux donné (ou une erreur 403 si `$flux` est vide). */
    private static function reseau(int &$appels, string $flux = '', array &$options_vues = []): void {
        Collecte::$obtenir_pour_tests = static function (string $url, array $options = []) use (&$appels, $flux, &$options_vues): array {
            $appels++;
            $options_vues[] = $options;
            return $flux === ''
                ? ['corps' => '', 'type' => '', 'code' => 403, 'url_finale' => $url, 'erreur' => 'Réponse HTTP 403']
                : ['corps' => $flux, 'type' => 'application/rss+xml', 'code' => 200, 'url_finale' => $url, 'erreur' => null];
        };
    }

    private const FLUX = '<?xml version="1.0"?><rss version="2.0"><channel><title>T</title><item><title>Santé numérique</title><link>https://frq.test/a</link><pubDate>Tue, 20 Oct 2026 12:00:00 +0000</pubDate></item></channel></rss>';

    public function test_garder_ne_touche_jamais_au_reseau_et_cherche_dans_la_copie_de_24_h(): void {
        $appels = 0;
        self::reseau($appels, self::FLUX);
        // Cache courant expiré, mais la copie de 24 h garde la nouvelle.
        set_transient('rsn_veille_radar_dernier', ['nouvelles' => [self::nouvelle(1)], 'erreurs' => [], 'genere_le' => '2026-10-24 09:00'], 86400);
        [$reponse, $code] = self::ajax('ajax_garder', ['nonce' => self::jeton(), 'url' => 'https://x.test/n1']);
        $this->assertSame(200, $code, json_encode($reponse));
        $this->assertSame('Titre 1', get_option('rsn_veille_nouvelles_gardees')[0]['titre']);
        // Introuvable partout : 404 sans réseau.
        [$reponse, $code] = self::ajax('ajax_garder', ['nonce' => self::jeton(), 'url' => 'https://x.test/inconnue']);
        $this->assertSame(404, $code);
        $this->assertStringContainsString('rafraîchissez la page', $reponse['data']['message']);
        $this->assertSame(0, $appels);
        $this->assertFalse(get_transient('rsn_veille_radar'));
    }

    public function test_garder_trouve_une_nouvelle_dans_le_cache_d_une_source(): void {
        $appels = 0;
        self::reseau($appels);
        set_transient('rsn_veille_radar_frq', ['nouvelles' => [self::nouvelle(7)], 'erreurs' => [], 'lu_le' => '2026-10-25 09:00'], 3600);
        [, $code] = self::ajax('ajax_garder', ['nonce' => self::jeton(), 'url' => 'https://x.test/n7']);
        $this->assertSame(200, $code);
        $this->assertSame(0, $appels);
    }

    public function test_chaque_source_est_mise_en_cache_des_qu_elle_est_lue(): void {
        $appels = 0;
        // La troisième source fait « tomber » la requête (le serveur coupe) : les deux premières restent en cache.
        Collecte::$obtenir_pour_tests = static function (string $url, array $options = []) use (&$appels): array {
            if (++$appels === 3) {
                throw new RuntimeException('Le serveur coupe');
            }
            return ['corps' => self::FLUX, 'type' => 'application/rss+xml', 'code' => 200, 'url_finale' => $url, 'erreur' => null];
        };
        try {
            PageRadar::donnees();
            $this->fail('La coupure aurait dû interrompre la lecture');
        } catch (RuntimeException $e) {
            $this->assertSame('Le serveur coupe', $e->getMessage());
        }
        $en_cache = array_filter(array_keys($GLOBALS['rsn_veille_wp']['transients']), static fn($n): bool => str_starts_with($n, 'rsn_veille_radar_') && !in_array($n, ['rsn_veille_radar_lecture', 'rsn_veille_radar_dernier'], true));
        $this->assertCount(2, $en_cache);
        // Le verrou est libéré, et la visite suivante ne relit que les sources manquantes.
        $this->assertFalse(get_transient('rsn_veille_radar_lecture'));
        $appels = 0;
        Collecte::$obtenir_pour_tests = static function (string $url, array $options = []) use (&$appels): array {
            $appels++;
            return ['corps' => self::FLUX, 'type' => 'application/rss+xml', 'code' => 200, 'url_finale' => $url, 'erreur' => null];
        };
        $donnees = PageRadar::donnees();
        $this->assertSame(10, $appels);
        $this->assertFalse($donnees['en_cours']);
        $this->assertIsArray(get_transient('rsn_veille_radar'));
        $this->assertIsArray(get_transient('rsn_veille_radar_dernier'));
    }

    public function test_les_flux_du_radar_sont_lus_avec_un_delai_de_8_secondes(): void {
        $appels = 0;
        $options_vues = [];
        self::reseau($appels, self::FLUX, $options_vues);
        PageRadar::donnees();
        $this->assertSame(12, $appels);
        foreach ($options_vues as $options) {
            $this->assertSame(['accept' => 'xml', 'timeout' => 8], $options);
        }
    }

    public function test_un_verrou_de_lecture_evite_une_seconde_lecture(): void {
        $appels = 0;
        self::reseau($appels, self::FLUX);
        set_transient('rsn_veille_radar_lecture', 1, 120);
        set_transient('rsn_veille_radar_frq', ['nouvelles' => [self::nouvelle(1)], 'erreurs' => [], 'lu_le' => '2026-10-25 09:00'], 3600);
        $donnees = PageRadar::donnees();
        $this->assertSame(0, $appels);
        $this->assertTrue($donnees['en_cours']);
        $this->assertSame(['https://x.test/n1'], array_column($donnees['nouvelles'], 'url'));
        // Rien d'incomplet n'est mis en cache comme résultat complet, et le verrou de l'autre reste.
        $this->assertFalse(get_transient('rsn_veille_radar'));
        $this->assertNotFalse(get_transient('rsn_veille_radar_lecture'));
        $html = PageRadar::page_html($donnees, [], [], self::options());
        $this->assertStringContainsString('Rafraîchissement en cours', $html);
        $this->assertStringNotContainsString('Rafraîchissement en cours', PageRadar::page_html(['en_cours' => false] + $donnees, [], [], self::options()));
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
        self::reseau($appels);
        $a = PageRadar::donnees();
        $this->assertCount(12, $a['erreurs']);
        $this->assertSame([], $a['nouvelles']);
        $this->assertSame(12, $appels);
        // Tout a échoué : pas de copie complète ; les erreurs sont gardées par source (10 min) sans relancer le réseau.
        $this->assertFalse(get_transient('rsn_veille_radar'));
        $this->assertFalse(get_transient('rsn_veille_radar_dernier'));
        PageRadar::donnees();
        $this->assertSame(12, $appels);

        PageRadar::vider_cache();
        $this->assertFalse(get_transient('rsn_veille_radar_frq'));
        self::cache([self::nouvelle(1)]);
        $this->assertSame('Titre 1', PageRadar::donnees()['nouvelles'][0]['titre']);
        $this->assertSame(12, $appels);
        PageRadar::vider_cache();
        $this->assertFalse(get_transient('rsn_veille_radar'));
    }

    public function test_rafraichir_garde_la_copie_de_24_h(): void {
        set_transient('rsn_veille_radar_dernier', ['nouvelles' => [self::nouvelle(1)], 'erreurs' => [], 'genere_le' => ''], 86400);
        self::cache([self::nouvelle(1)]);
        PageRadar::vider_cache();
        $this->assertIsArray(get_transient('rsn_veille_radar_dernier'));
    }

    // --- Nouvelles ignorées : les revoir, les remettre dans le radar (0.8.0) ---

    public function test_noter_ignoree_garde_les_details_et_suit_la_liste(): void {
        $details = PageRadar::noter_ignoree([], self::nouvelle(1), '2026-11-10 09:00', ['https://x.test/n1']);
        $this->assertSame(['https://x.test/n1'], array_keys($details));
        $this->assertSame(['titre', 'date', 'source', 'organisateur', 'resume', 'ignoree_le'], array_keys($details['https://x.test/n1']));
        $this->assertSame(['Titre 1', '2026-11-10 09:00'], [$details['https://x.test/n1']['titre'], $details['https://x.test/n1']['ignoree_le']]);
        // Une adresse sortie de la liste des ignorées (borne des 500, remise) perd ses détails.
        $details = PageRadar::noter_ignoree($details, self::nouvelle(2), '2026-11-10 09:05', ['https://x.test/n2']);
        $this->assertSame(['https://x.test/n2'], array_keys($details));
        // Détails illisibles tolérés ; nouvelle sans titre (plus dans les flux) : seulement la date.
        $details = PageRadar::noter_ignoree(['x' => 'abîmé', 12 => []], ['url' => 'https://x.test/n3'], '2026-11-10 09:10', ['https://x.test/n3']);
        $this->assertSame(['', '2026-11-10 09:10'], [$details['https://x.test/n3']['titre'], $details['https://x.test/n3']['ignoree_le']]);
    }

    public function test_retablir_retire_l_adresse(): void {
        $this->assertSame(['https://x.test/b'], PageRadar::retablir(['https://x.test/a', 'https://x.test/b', 12], 'https://x.test/a'));
        $this->assertSame(['https://x.test/a'], PageRadar::retablir(['https://x.test/a'], 'https://x.test/absente'));
    }

    public function test_ignorer_enregistre_les_details_du_cache(): void {
        self::cache([self::nouvelle(1)]);
        self::ajax('ajax_ignorer', ['nonce' => self::jeton(), 'url' => 'https://x.test/n1']);
        $details = PageRadar::lire_details_ignorees();
        $this->assertSame('Titre 1', $details['https://x.test/n1']['titre']);
        $this->assertNotSame('', $details['https://x.test/n1']['ignoree_le']);
    }

    public function test_retablir_remet_dans_le_radar(): void {
        self::cache([self::nouvelle(1), self::nouvelle(2)]);
        self::ajax('ajax_ignorer', ['nonce' => self::jeton(), 'url' => 'https://x.test/n1']);
        self::ajax('ajax_ignorer', ['nonce' => self::jeton(), 'url' => 'https://x.test/n2']);
        [$reponse, $code] = self::ajax('ajax_retablir', ['nonce' => self::jeton(), 'url' => 'https://x.test/n1']);
        $this->assertSame(200, $code);
        $this->assertSame(['a_trier' => 1, 'gardees' => 0, 'ecartees' => 1], $reponse['data']['compteurs']);
        $this->assertSame('Nouvelle remise dans le radar.', $reponse['data']['message']);
        $this->assertSame(['https://x.test/n2'], PageRadar::lire_ignorees());
        $this->assertSame(['https://x.test/n2'], array_keys(PageRadar::lire_details_ignorees()));
        // De nouveau visible dans le radar.
        $visibles = PageRadar::visibles([self::nouvelle(1), self::nouvelle(2)], [], PageRadar::lire_ignorees());
        $this->assertSame(['https://x.test/n1'], array_column($visibles, 'url'));
    }

    public function test_retablir_une_nouvelle_sortie_des_flux_le_dit(): void {
        update_option('rsn_veille_nouvelles_ignorees', ['https://x.test/vieille'], false);
        [$reponse] = self::ajax('ajax_retablir', ['nonce' => self::jeton(), 'url' => 'https://x.test/vieille']);
        $this->assertTrue($reponse['data']['ok']);
        $this->assertStringContainsString("n'est plus dans les flux", $reponse['data']['message']);
        $this->assertSame([], PageRadar::lire_ignorees());
    }

    public function test_garder_une_ignoree_sortie_des_flux_prend_ses_details(): void {
        update_option('rsn_veille_nouvelles_ignorees', ['https://x.test/n9'], false);
        update_option('rsn_veille_nouvelles_ignorees_details', ['https://x.test/n9' => ['titre' => 'Titre 9', 'date' => '2026-09-01', 'source' => 'Source X', 'organisateur' => 'Organisme X', 'resume' => 'Résumé 9', 'ignoree_le' => '2026-09-02 10:00']], false);
        [$reponse, $code] = self::ajax('ajax_garder', ['nonce' => self::jeton(), 'url' => 'https://x.test/n9']);
        $this->assertSame(200, $code);
        $gardees = PageRadar::lire_gardees();
        $this->assertSame(['https://x.test/n9', 'Titre 9', 'Résumé 9'], [$gardees[0]['url'], $gardees[0]['titre'], $gardees[0]['resume']]);
        // Gardée : elle sort des ignorées.
        $this->assertSame([], PageRadar::lire_ignorees());
        $this->assertSame([], PageRadar::lire_details_ignorees());
    }

    public function test_retablir_exige_jeton_et_droits(): void {
        update_option('rsn_veille_nouvelles_ignorees', ['https://x.test/n1'], false);
        [, $code] = self::ajax('ajax_retablir', ['nonce' => 'faux', 'url' => 'https://x.test/n1']);
        $this->assertSame(403, $code);
        $GLOBALS['rsn_veille_wp']['capacites'] = [];
        [, $code] = self::ajax('ajax_retablir', ['nonce' => self::jeton(), 'url' => 'https://x.test/n1']);
        $this->assertSame(403, $code);
        $this->assertSame(['https://x.test/n1'], PageRadar::lire_ignorees());
    }

    public function test_options_illisibles_toleree(): void {
        update_option('rsn_veille_nouvelles_gardees', 'abîmé', false);
        update_option('rsn_veille_nouvelles_ignorees', ['https://x.test/a', 12, ['x']], false);
        $this->assertSame([], PageRadar::lire_gardees());
        $this->assertSame(['https://x.test/a'], PageRadar::lire_ignorees());
    }
}

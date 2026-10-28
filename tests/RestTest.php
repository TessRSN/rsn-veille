<?php
declare(strict_types=1);

require_once __DIR__ . '/bouchons-wordpress.php';

use PHPUnit\Framework\TestCase;
use RSN\Veille\EntrepotWordPress;
use RSN\Veille\Fiche;
use RSN\Veille\Public\EvenementsRsn;
use RSN\Veille\Public\Rest;

final class RestTest extends TestCase {
    protected function setUp(): void {
        rsn_veille_bouchons_reinitialiser();
        Rest::accrocher();
    }

    public function test_route_publique_en_get(): void {
        do_action('rest_api_init');
        $route = $GLOBALS['rsn_veille_wp']['routes']['rsn/v1/veille'];
        $this->assertSame('GET', $route['methods']);
        $this->assertSame('__return_true', $route['permission_callback']);
        $this->assertSame([Rest::class, 'repondre'], $route['callback']);
    }

    public function test_reponse_json_publique_sans_cache_litespeed(): void {
        (new EntrepotWordPress())->creer(Fiche::depuis_tableau([
            'type' => 'evenement', 'titre' => 'Colloque', 'debut' => '2026-11-12', 'organisateur' => 'Obvia',
            'source' => 'Obvia : événements', 'niveau' => 'C', 'statut' => 'publie', 'cle_source' => 'obvia:1', 'cles_source' => ['obvia:1'],
        ]));
        $reponse = Rest::repondre();

        $this->assertInstanceOf(WP_REST_Response::class, $reponse);
        $this->assertSame('public, max-age=3600', $reponse->get_headers()['Cache-Control']);
        $this->assertCount(1, rsn_veille_bouchons_declenchements('litespeed_control_set_nocache'));
        $donnees = $reponse->get_data();
        $this->assertSame(['genere_le', 'langue', 'sources', 'items'], array_keys($donnees));
        $this->assertSame('Obvia', $donnees['items'][0]['organisateur']);
        $this->assertArrayNotHasKey('niveau', $donnees['items'][0]);
        $this->assertArrayNotHasKey('source', $donnees['items'][0]);
        $this->assertArrayNotHasKey('cle_source', $donnees['items'][0]);
    }

    /** Requête de test : seul `get_param` compte. */
    private static function requete(?string $lang): object {
        return new class($lang) {
            public function __construct(private ?string $lang) {
            }

            public function get_param(string $nom): mixed {
                return $nom === 'lang' ? $this->lang : null;
            }
        };
    }

    /** WPML simulé : la langue courante suit `wpml_switch_language`. */
    private static function simuler_wpml(string $langue): void {
        $GLOBALS['rsn_veille_langue_test'] = $langue;
        add_filter('wpml_current_language', static fn() => $GLOBALS['rsn_veille_langue_test']);
        add_action('wpml_switch_language', static function (string $nouvelle): void {
            $GLOBALS['rsn_veille_langue_test'] = $nouvelle;
        });
    }

    public function test_lang_en_avec_wpml_bascule_puis_restaure(): void {
        self::simuler_wpml('fr');
        $reponse = Rest::repondre(self::requete('en'));

        $this->assertSame([['en'], ['fr']], rsn_veille_bouchons_declenchements('wpml_switch_language'));
        $this->assertSame('en', $reponse->get_data()['langue']);
        $this->assertSame('fr', $GLOBALS['rsn_veille_langue_test']);
        $this->assertNotFalse(get_transient('rsn_veille_json_en'));
    }

    public function test_lang_en_bascule_aussi_la_locale_puis_la_restaure(): void {
        self::simuler_wpml('fr');
        Rest::repondre(self::requete('en'));
        $this->assertSame([['en_US']], rsn_veille_bouchons_declenchements('switch_to_locale'));
        $this->assertSame([[]], rsn_veille_bouchons_declenchements('restore_previous_locale'));

        $GLOBALS['rsn_veille_langue_test'] = 'en';
        Rest::repondre(self::requete('fr'));
        $this->assertSame([['en_US'], ['fr_FR']], rsn_veille_bouchons_declenchements('switch_to_locale'));
        $this->assertCount(2, rsn_veille_bouchons_declenchements('restore_previous_locale'));
    }

    public function test_sans_bascule_la_locale_ne_change_pas(): void {
        self::simuler_wpml('en');
        Rest::repondre(self::requete('en'));
        Rest::repondre(self::requete('de'));
        $this->assertSame([], rsn_veille_bouchons_declenchements('switch_to_locale'));
        $this->assertSame([], rsn_veille_bouchons_declenchements('restore_previous_locale'));
    }

    public function test_lang_identique_a_la_langue_courante_ne_bascule_pas(): void {
        self::simuler_wpml('en');
        Rest::repondre(self::requete('en'));
        $this->assertSame([], rsn_veille_bouchons_declenchements('wpml_switch_language'));
    }

    public function test_lang_ignoree_sans_wpml(): void {
        $reponse = Rest::repondre(self::requete('en'));
        $this->assertSame([], rsn_veille_bouchons_declenchements('wpml_switch_language'));
        $this->assertSame('fr', $reponse->get_data()['langue']);
    }

    public function test_lang_invalide_ou_absente_ne_bascule_pas(): void {
        self::simuler_wpml('fr');
        Rest::repondre(self::requete('de'));
        Rest::repondre(self::requete(null));
        Rest::repondre();
        $this->assertSame([], rsn_veille_bouchons_declenchements('wpml_switch_language'));
    }

    public function test_regle_ics_et_variable_de_requete(): void {
        do_action('init');
        $this->assertSame(['requete' => 'index.php?rsn_veille_ics=1', 'position' => 'top'], $GLOBALS['rsn_veille_wp']['regles']['^veille\.ics$']);
        $this->assertSame(['p', 'rsn_veille_ics'], apply_filters('query_vars', ['p']));
    }

    public function test_activation_et_desactivation_regenerent_les_regles(): void {
        Rest::activer();
        $this->assertArrayHasKey('^veille\.ics$', $GLOBALS['rsn_veille_wp']['regles']);
        $this->assertSame(1, $GLOBALS['rsn_veille_wp']['regles_regenerees']);
        Rest::desactiver();
        $this->assertSame(2, $GLOBALS['rsn_veille_wp']['regles_regenerees']);
    }

    public function test_veille_ics_servi_avant_redirect_canonical(): void {
        $crochets = $GLOBALS['rsn_veille_wp']['actions']['template_redirect'];
        $this->assertSame(0, $crochets[0]['priorite']);
    }

    public function test_hors_veille_ics_rien_n_est_servi(): void {
        ob_start();
        do_action('template_redirect');
        $this->assertSame('', ob_get_clean());
    }

    public function test_calendrier_des_fiches_publiees_avec_uid_de_la_cle_de_source(): void {
        (new EntrepotWordPress())->creer(Fiche::depuis_tableau([
            'type' => 'evenement', 'titre' => 'Colloque', 'debut' => '2026-11-12', 'statut' => 'publie',
            'cle_source' => 'obvia:1', 'cles_source' => ['obvia:1'], 'url' => 'https://www.obvia.ca/c',
        ]));
        (new EntrepotWordPress())->creer(Fiche::depuis_tableau([
            'type' => 'evenement', 'titre' => 'En attente', 'debut' => '2026-11-13', 'statut' => 'a_approuver',
            'cle_source' => 'obvia:2', 'cles_source' => ['obvia:2'],
        ]));
        $ics = Rest::ics();
        $this->assertSame(1, substr_count($ics, 'BEGIN:VEVENT'));
        $this->assertStringContainsString('UID:' . md5('obvia:1') . '@rsn.quebec', $ics);
        $this->assertSame($ics, get_transient('rsn_veille_ics_fr'));
    }

    public function test_sans_the_events_calendar_aucun_evenement_rsn(): void {
        $this->assertFalse(function_exists('tribe_get_events'));
        $this->assertSame([], EvenementsRsn::lire());
    }
}

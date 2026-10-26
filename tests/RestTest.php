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
        $this->assertSame(['genere_le', 'sources', 'items'], array_keys($donnees));
        $this->assertSame('Obvia', $donnees['items'][0]['organisateur']);
        $this->assertArrayNotHasKey('niveau', $donnees['items'][0]);
        $this->assertArrayNotHasKey('source', $donnees['items'][0]);
        $this->assertArrayNotHasKey('cle_source', $donnees['items'][0]);
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

<?php
declare(strict_types=1);

require_once __DIR__ . '/bouchons-wordpress.php';

use PHPUnit\Framework\TestCase;
use RSN\Veille\EntrepotWordPress;
use RSN\Veille\Fiche;
use RSN\Veille\Public\Donnees;
use RSN\Veille\Public\Raccourci;

final class RaccourciTest extends TestCase {
    protected function setUp(): void {
        rsn_veille_bouchons_reinitialiser();
        Raccourci::reinitialiser();
        Raccourci::accrocher();
        do_action('init');
    }

    private function publier(array $champs = []): int {
        return (new EntrepotWordPress())->creer(Fiche::depuis_tableau(array_merge([
            'type' => 'evenement',
            'titre' => 'Colloque santé numérique',
            'debut' => '2026-11-12',
            'organisateur' => 'Obvia',
            'source' => 'Obvia : événements',
            'niveau' => 'C',
            'statut' => 'publie',
            'cle_source' => 'obvia:42',
            'cles_source' => ['obvia:42'],
            'url' => 'https://www.obvia.ca/evenements/colloque',
        ], $champs)));
    }

    /** Le JSON incorporé dans #rsn-veille-donnees, décodé. */
    private function donnees_incorporees(string $html): array {
        $this->assertSame(1, preg_match('#<script type="application/json" id="rsn-veille-donnees">(.*?)</script>#s', $html, $m));
        $donnees = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($donnees);
        return $donnees;
    }

    public function test_rend_le_main_avec_les_donnees_et_sans_le_mode_equipe(): void {
        $this->publier();
        $html = do_shortcode('[rsn_veille]');

        $this->assertStringStartsWith('<main class="rsn-veille" data-section="tout">', trim($html));
        $donnees = $this->donnees_incorporees($html);
        $this->assertSame(['genere_le', 'sources', 'items'], array_keys($donnees));
        $this->assertCount(1, $donnees['items']);
        $this->assertSame('Colloque santé numérique', $donnees['items'][0]['titre']);
        $this->assertArrayNotHasKey('niveau', $donnees['items'][0]);
        $this->assertArrayNotHasKey('source', $donnees['items'][0]);
        $this->assertStringNotContainsString('obvia:42', $html);

        foreach (['Mode équipe', 'mode-equipe', 'Prototype', 'prototype', "File d'approbation", 'id="file"', 'Actualités', 'infolettre"', 'btn-infolettre', 'Niveau', 'col-niv', 'bandeau'] as $absent) {
            $this->assertStringNotContainsString($absent, $html, $absent);
        }
        $this->assertStringContainsString('id="btn-proposer"', $html);
        $this->assertStringContainsString('<section class="encadre" id="dialogue" hidden>', $html);
        $this->assertStringContainsString('id="vue-agenda"', $html);
        $this->assertStringContainsString('id="vue-appels" hidden', $html);
        $this->assertStringContainsString('id="tbl-sources"', $html);
        $textes = [];
        $this->assertSame(1, preg_match('#<script type="application/json" id="rsn-veille-textes">(.*?)</script>#s', $html, $textes));
        $this->assertSame('Envoi non encore branché.', json_decode($textes[1], true)['envoi_non_branche']);
    }

    public function test_filtre_qui_organise_au_dessus_de_format(): void {
        $html = Raccourci::rendre([]);
        $qui = strpos($html, 'id="f-qui"');
        $format = strpos($html, 'id="f-format"');
        $this->assertNotFalse($qui);
        $this->assertNotFalse($format);
        $this->assertLessThan($format, $qui);
        $this->assertGreaterThan(strpos($html, 'id="f-mois"'), $qui);
        $this->assertStringContainsString('Qui organise', $html);
    }

    public function test_json_incorpore_echappe(): void {
        // Données telles qu'elles sortiraient d'une source mal nettoyée (le cache court-circuite l'entrepôt).
        $titre = 'Atelier </script><script>alert(1)</script> & « IA »';
        set_transient(Donnees::TRANSIENT, ['genere_le' => '2026-10-25', 'sources' => [], 'items' => [
            ['type' => 'evenement', 'titre' => $titre, 'debut' => '2026-11-12', 'url' => 'https://exemple.test/?a=1&b=2'],
        ]], 3600);
        $html = Raccourci::rendre([]);

        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertSame(2, substr_count($html, '</script>'), 'seules les deux balises du gabarit ferment un script');
        $this->assertStringNotContainsString('&b=2', $html, '& encodé en \\u0026');
        $donnees = $this->donnees_incorporees($html);
        $this->assertSame($titre, $donnees['items'][0]['titre']);
        $this->assertSame('https://exemple.test/?a=1&b=2', $donnees['items'][0]['url']);
        $this->assertStringContainsString('« IA »', $html, 'Unicode lisible');
        $this->assertStringContainsString('https://exemple.test/', $html, 'barres obliques lisibles');
    }

    public function test_sections(): void {
        $agenda = Raccourci::rendre(['section' => 'agenda']);
        $this->assertStringContainsString('data-section="agenda"', $agenda);
        $this->assertStringContainsString('id="vue-agenda"', $agenda);
        $this->assertStringContainsString('id="btn-proposer"', $agenda);
        $this->assertStringNotContainsString('id="vue-appels"', $agenda);
        $this->assertStringNotContainsString('class="onglets"', $agenda);
        $this->assertStringNotContainsString('id="tbl-sources"', $agenda);

        Raccourci::reinitialiser();
        $appels = Raccourci::rendre(['section' => 'appels']);
        $this->assertStringContainsString('<div id="vue-appels">', $appels);
        $this->assertStringNotContainsString('id="vue-agenda"', $appels);
        $this->assertStringNotContainsString('id="btn-proposer"', $appels);

        Raccourci::reinitialiser();
        $this->assertStringContainsString('data-section="tout"', Raccourci::rendre(['section' => '<b>inconnue</b>']));
    }

    public function test_une_seule_fois_par_page(): void {
        $this->assertNotSame('', Raccourci::rendre([]));
        $this->assertSame('', Raccourci::rendre([]));
    }

    public function test_ressources_mises_en_file_au_rendu_sans_polices_par_defaut(): void {
        $this->assertSame([], $GLOBALS['rsn_veille_wp']['scripts']);
        Raccourci::rendre([]);
        $this->assertSame(RSN_VEILLE_VERSION, $GLOBALS['rsn_veille_wp']['styles']['rsn-veille']['version']);
        $this->assertStringEndsWith('assets/veille.css', $GLOBALS['rsn_veille_wp']['styles']['rsn-veille']['source']);
        $this->assertSame(RSN_VEILLE_VERSION, $GLOBALS['rsn_veille_wp']['scripts']['rsn-veille']['version']);
        $this->assertStringEndsWith('assets/veille.js', $GLOBALS['rsn_veille_wp']['scripts']['rsn-veille']['source']);
        $this->assertTrue($GLOBALS['rsn_veille_wp']['scripts']['rsn-veille']['pied']);
        $this->assertArrayNotHasKey('rsn-veille-polices', $GLOBALS['rsn_veille_wp']['styles']);
    }

    public function test_polices_chargees_si_le_filtre_le_demande(): void {
        add_filter('rsn_veille_charger_polices', '__return_true');
        Raccourci::mettre_en_file();
        $this->assertStringStartsWith('https://fonts.googleapis.com/css2?family=DM+Serif+Display', $GLOBALS['rsn_veille_wp']['styles']['rsn-veille-polices']['source']);
    }

    private function page(int $id, string $contenu): WP_Post {
        $page = new WP_Post();
        $page->ID = $id;
        $page->post_type = 'page';
        $page->post_content = $contenu;
        return $page;
    }

    public function test_ressources_absentes_des_pages_sans_raccourci(): void {
        do_action('wp_enqueue_scripts');
        $this->assertSame([], $GLOBALS['rsn_veille_wp']['scripts'], 'aucune page demandée');

        $GLOBALS['rsn_veille_wp']['objet_demande'] = $this->page(5, '<p>Événements du RSN [rsn_evenements]</p>');
        do_action('wp_enqueue_scripts');
        $this->assertSame([], $GLOBALS['rsn_veille_wp']['scripts']);
        $this->assertSame([], $GLOBALS['rsn_veille_wp']['styles']);
    }

    public function test_ressources_en_tete_si_la_page_contient_le_raccourci(): void {
        $GLOBALS['rsn_veille_wp']['objet_demande'] = $this->page(5, '<p>Intro</p>[rsn_veille section="agenda"]');
        do_action('wp_enqueue_scripts');
        $this->assertArrayHasKey('rsn-veille', $GLOBALS['rsn_veille_wp']['scripts']);
        $this->assertArrayHasKey('rsn-veille', $GLOBALS['rsn_veille_wp']['styles']);
    }

    public function test_ressources_en_tete_sur_la_page_veille_des_reglages(): void {
        update_option('rsn_veille_reglages', ['page_veille_id' => 9]);
        // Page Elementor : le raccourci n'est pas dans post_content.
        $GLOBALS['rsn_veille_wp']['objet_demande'] = $this->page(9, '');
        do_action('wp_enqueue_scripts');
        $this->assertArrayHasKey('rsn-veille', $GLOBALS['rsn_veille_wp']['scripts']);
    }

    public function test_json_en_cache_une_heure_et_vide_par_la_purge_et_la_collecte(): void {
        $this->publier();
        Raccourci::rendre([]);
        $this->assertIsArray(get_transient(Donnees::TRANSIENT));
        $this->assertGreaterThan(time() + 3500, $GLOBALS['rsn_veille_wp']['transients'][Donnees::TRANSIENT]['expire']);

        // Nouvelle fiche : la purge (déclenchée par l'écriture) vide le cache.
        $this->publier(['titre' => 'Deuxième', 'cle_source' => 'obvia:43', 'cles_source' => ['obvia:43']]);
        do_action('rsn_veille_caches_purges');
        $this->assertFalse(get_transient(Donnees::TRANSIENT));
        Raccourci::reinitialiser();
        $this->assertCount(2, $this->donnees_incorporees(Raccourci::rendre([]))['items']);

        set_transient('rsn_veille_ics', 'BEGIN:VCALENDAR', 3600);
        do_action('rsn_veille_apres_collecte');
        $this->assertFalse(get_transient(Donnees::TRANSIENT));
        $this->assertFalse(get_transient('rsn_veille_ics'));
    }

    public function test_cache_relu_sans_reconstruire(): void {
        set_transient(Donnees::TRANSIENT, ['genere_le' => '2026-10-01', 'sources' => [], 'items' => [['titre' => 'Depuis le cache']]], 3600);
        $this->assertSame('Depuis le cache', $this->donnees_incorporees(Raccourci::rendre([]))['items'][0]['titre']);
    }
}

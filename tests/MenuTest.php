<?php
declare(strict_types=1);

require_once __DIR__ . '/bouchons-wordpress.php';

use PHPUnit\Framework\TestCase;
use RSN\Veille\Admin\Menu;
use RSN\Veille\Admin\PageFile;
use RSN\Veille\Admin\PageJournal;
use RSN\Veille\Collecte;

final class MenuTest extends TestCase {
    protected function setUp(): void {
        rsn_veille_bouchons_reinitialiser();
    }

    private static function a_la_capacite(string $role): bool {
        return get_role($role)->has_cap(Menu::CAPACITE);
    }

    public function test_capacite_aux_administrateurs_et_editeurs_par_defaut(): void {
        Menu::synchroniser_roles();
        $this->assertTrue(self::a_la_capacite('administrator'));
        $this->assertTrue(self::a_la_capacite('editor'));
        $this->assertFalse(self::a_la_capacite('author'));
        $this->assertFalse(self::a_la_capacite('subscriber'));
    }

    public function test_capacite_suit_le_reglage_des_roles(): void {
        Menu::synchroniser_roles();
        update_option(Collecte::OPTION_REGLAGES, ['roles' => ['author']]);
        Menu::synchroniser_roles();
        $this->assertTrue(self::a_la_capacite('author'));
        $this->assertFalse(self::a_la_capacite('editor'));
        // L'administrateur la garde toujours : personne ne perd l'accès à la veille.
        $this->assertTrue(self::a_la_capacite('administrator'));
    }

    public function test_reglage_illisible_donne_les_roles_par_defaut(): void {
        update_option(Collecte::OPTION_REGLAGES, ['roles' => 'editor']);
        Menu::synchroniser_roles();
        $this->assertTrue(self::a_la_capacite('editor'));
        $this->assertSame(['administrator', 'editor'], Menu::roles());
        update_option(Collecte::OPTION_REGLAGES, ['roles' => ['editor', 7, '', 'editor']]);
        $this->assertSame(['administrator', 'editor'], Menu::roles());
    }

    public function test_aucune_ecriture_quand_rien_ne_change(): void {
        Menu::synchroniser_roles();
        $ecritures = $GLOBALS['rsn_veille_wp']['ecritures_roles'];
        $this->assertSame(2, $ecritures);
        Menu::synchroniser_roles();
        $this->assertSame($ecritures, $GLOBALS['rsn_veille_wp']['ecritures_roles']);
    }

    public function test_menu_et_sous_pages(): void {
        $GLOBALS['rsn_veille_wp']['capacites'] = [Menu::CAPACITE];
        Menu::enregistrer();
        $menu = $GLOBALS['rsn_veille_wp']['menus']['rsn-veille'];
        $this->assertSame('Veille', $menu['titre_menu']);
        $this->assertSame(Menu::CAPACITE, $menu['capacite']);
        $this->assertSame('dashicons-rss', $menu['icone']);
        $this->assertSame(26, $menu['position']);
        $this->assertSame([PageFile::class, 'afficher'], $menu['rappel']);

        $sous = $GLOBALS['rsn_veille_wp']['sous_menus']['rsn-veille'];
        $this->assertSame(['rsn-veille', 'rsn-veille-radar', 'rsn-veille-infolettre', 'rsn-veille-journal', 'rsn-veille-reglages'], array_keys($sous));
        $this->assertSame("File d'approbation", $sous['rsn-veille']['titre_menu']);
        $this->assertSame([PageJournal::class, 'afficher'], $sous['rsn-veille-journal']['rappel']);
        $this->assertSame([\RSN\Veille\Admin\PageRadar::class, 'afficher'], $sous['rsn-veille-radar']['rappel']);
        foreach ($sous as $page) {
            $this->assertSame(Menu::CAPACITE, $page['capacite']);
        }
    }

    public function test_ressources_seulement_sur_les_pages_de_la_veille(): void {
        $GLOBALS['rsn_veille_wp']['capacites'] = [Menu::CAPACITE];
        Menu::enregistrer();
        Menu::charger_ressources('edit.php');
        $this->assertSame([], $GLOBALS['rsn_veille_wp']['scripts']);
        $this->assertSame([], $GLOBALS['rsn_veille_wp']['styles']);

        Menu::charger_ressources('veille_page_rsn-veille-journal');
        $this->assertSame(RSN_VEILLE_VERSION, $GLOBALS['rsn_veille_wp']['styles']['rsn-veille-admin']['version']);
        $this->assertStringEndsWith('assets/admin.css', $GLOBALS['rsn_veille_wp']['styles']['rsn-veille-admin']['source']);
        $script = $GLOBALS['rsn_veille_wp']['scripts']['rsn-veille-admin'];
        $this->assertSame(RSN_VEILLE_VERSION, $script['version']);
        $this->assertStringEndsWith('assets/admin.js', $script['source']);
        $this->assertSame([], $script['dependances']);
        $config = $GLOBALS['rsn_veille_wp']['localisations']['rsn-veille-admin']['rsnVeilleAdmin'];
        $this->assertSame('https://exemple.test/wp-admin/admin-ajax.php', $config['ajaxurl']);
        $this->assertSame(wp_create_nonce('rsn_veille_file'), $config['nonce']);
        $this->assertSame('rsn_veille_changer_statut', $config['action']);
        $this->assertSame(wp_create_nonce('rsn_veille_radar'), $config['radar']['nonce']);
        $this->assertSame(['garder' => 'rsn_veille_garder', 'ignorer' => 'rsn_veille_ignorer', 'retirer' => 'rsn_veille_retirer_gardee'], $config['radar']['actions']);
    }

    public function test_page_principale_charge_aussi_les_ressources(): void {
        $GLOBALS['rsn_veille_wp']['capacites'] = [Menu::CAPACITE];
        Menu::enregistrer();
        Menu::charger_ressources('toplevel_page_rsn-veille');
        $this->assertArrayHasKey('rsn-veille-admin', $GLOBALS['rsn_veille_wp']['scripts']);
    }

    public function test_sans_capacite_aucune_sous_page_ni_ressource(): void {
        Menu::enregistrer();
        $this->assertSame([], $GLOBALS['rsn_veille_wp']['sous_menus']);
        Menu::charger_ressources('veille_page_rsn-veille-journal');
        $this->assertSame([], $GLOBALS['rsn_veille_wp']['scripts']);
    }

    public function test_accrochages(): void {
        Menu::accrocher();
        $actions = $GLOBALS['rsn_veille_wp']['actions'];
        foreach (['admin_menu', 'admin_init', 'admin_enqueue_scripts', 'wp_ajax_rsn_veille_changer_statut'] as $crochet) {
            $this->assertArrayHasKey($crochet, $actions, $crochet);
        }
        $this->assertArrayNotHasKey('wp_ajax_nopriv_rsn_veille_changer_statut', $actions);
        foreach (['rsn_veille_garder', 'rsn_veille_ignorer', 'rsn_veille_retirer_gardee'] as $action) {
            $this->assertArrayHasKey('wp_ajax_' . $action, $actions, $action);
            $this->assertArrayNotHasKey('wp_ajax_nopriv_' . $action, $actions, $action);
        }
        $this->assertArrayHasKey('admin_post_rsn_veille_radar_rafraichir', $actions);
    }
}

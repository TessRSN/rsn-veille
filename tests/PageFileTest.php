<?php
declare(strict_types=1);

require_once __DIR__ . '/bouchons-wordpress.php';

use PHPUnit\Framework\TestCase;
use RSN\Veille\Admin\PageFile;
use RSN\Veille\Collecte;
use RSN\Veille\EntrepotMemoire;
use RSN\Veille\EntrepotWordPress;
use RSN\Veille\Fiche;

final class PageFileTest extends TestCase {
    private const OPTIONS = ['url_admin' => 'https://exemple.test/wp-admin/'];

    protected function setUp(): void {
        rsn_veille_bouchons_reinitialiser();
        $_POST = $_REQUEST = [];
    }

    protected function tearDown(): void {
        $_POST = $_REQUEST = [];
    }

    private static function fiche(array $champs = []): Fiche {
        return Fiche::depuis_tableau($champs + [
            'id' => 7,
            'type' => 'evenement',
            'titre' => 'Midi-conférence en santé numérique',
            'debut' => '2026-11-12',
            'heure' => '12:00',
            'organisateur' => 'Mila',
            'source' => 'Mila : événements',
            'niveau' => 'C',
            'resume' => 'Une présentation sur l’IA en santé.',
            'url' => 'https://mila.quebec/fr/evenements/midi',
            'statut' => 'a_approuver',
        ]);
    }

    /** Valeurs des attributs data-statut des boutons d'action, dans l'ordre. */
    private static function boutons(string $html): array {
        preg_match_all('/<button[^>]*data-statut="([a-z_]+)"[^>]*>([^<]*)<\/button>/', $html, $m);
        return array_combine($m[2], $m[1]);
    }

    // --- ligne_html ---

    public function test_boutons_selon_le_statut(): void {
        $this->assertSame(['Publier' => 'publie', 'Écarter' => 'ecarte'], self::boutons(PageFile::ligne_html(self::fiche(), self::OPTIONS)));
        $this->assertSame(['Retirer' => 'ecarte'], self::boutons(PageFile::ligne_html(self::fiche(['statut' => 'publie']), self::OPTIONS)));
        $this->assertSame(['Publier' => 'publie'], self::boutons(PageFile::ligne_html(self::fiche(['statut' => 'ecarte']), self::OPTIONS)));
    }

    public function test_publier_est_le_bouton_principal(): void {
        $html = PageFile::ligne_html(self::fiche(), self::OPTIONS);
        $this->assertMatchesRegularExpression('/<button type="button" class="button button-primary rsn-veille-action" data-statut="publie">Publier/', $html);
        $this->assertMatchesRegularExpression('/<button type="button" class="button rsn-veille-action" data-statut="ecarte">Écarter/', $html);
    }

    public function test_ligne_porte_id_et_lien_modifier(): void {
        $html = PageFile::ligne_html(self::fiche(), self::OPTIONS);
        $this->assertStringContainsString('<tr id="rsn-veille-fiche-7" data-id="7"', $html);
        $this->assertStringContainsString('href="https://exemple.test/wp-admin/post.php?post=7&amp;action=edit"', $html);
        $this->assertStringContainsString('>Modifier<', $html);
    }

    public function test_contenu_de_la_ligne(): void {
        $html = PageFile::ligne_html(self::fiche(), self::OPTIONS);
        $this->assertStringContainsString('Midi-conférence en santé numérique', $html);
        $this->assertStringContainsString('Événement', $html);
        $this->assertStringContainsString('12 novembre 2026, 12 h', $html);
        $this->assertStringContainsString('Mila', $html);
        $this->assertStringContainsString('Mila : événements', $html);
        $this->assertMatchesRegularExpression('/<abbr[^>]*title="Page web structurée"[^>]*>C<\/abbr>/', $html);
        $this->assertStringContainsString('Une présentation sur l’IA en santé.', $html);
        $this->assertStringContainsString('href="https://mila.quebec/fr/evenements/midi"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
    }

    public function test_appel_montre_sa_date_limite(): void {
        $html = PageFile::ligne_html(self::fiche(['type' => 'appel', 'debut' => '2026-10-01', 'heure' => '', 'echeance' => '2026-12-15']), self::OPTIONS);
        $this->assertStringContainsString('Appel', $html);
        $this->assertStringContainsString('Date limite : 15 décembre 2026', $html);
    }

    public function test_titre_et_champs_echappes(): void {
        $html = PageFile::ligne_html(self::fiche([
            'titre' => '<script>alert(1)</script> & cie',
            'organisateur' => '"><img src=x onerror=alert(2)>',
            'resume' => '<b>gras</b>',
            'url' => 'javascript:alert(3)',
        ]), self::OPTIONS);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; cie', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<b>', $html);
        // Une URL dangereuse ne donne pas de lien.
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_titre_vide(): void {
        $this->assertStringContainsString('(sans titre)', PageFile::ligne_html(self::fiche(['titre' => '']), self::OPTIONS));
    }

    public function test_drapeau_absent(): void {
        $sans = PageFile::ligne_html(self::fiche(['statut' => 'publie']), self::OPTIONS);
        $this->assertStringNotContainsString('Absent de la source', $sans);

        $avec = PageFile::ligne_html(self::fiche(['statut' => 'publie', 'absent_depuis' => '2026-11-03']), self::OPTIONS);
        $this->assertStringContainsString('Absent de la source depuis le 3 novembre 2026', $avec);
        // Une fiche absente peut être gardée (drapeau levé) ou retirée.
        $this->assertSame(['Garder publiée' => 'publie', 'Retirer' => 'ecarte'], self::boutons($avec));
    }

    // --- filtrer ---

    /** @return Fiche[] */
    private static function lot(): array {
        return [
            self::fiche(['id' => 1, 'debut' => '2026-12-01', 'statut' => 'a_approuver', 'source' => 'Mila : événements']),
            self::fiche(['id' => 2, 'debut' => '2026-11-05', 'statut' => 'a_approuver', 'source' => 'Obvia : événements']),
            self::fiche(['id' => 3, 'debut' => '2026-11-20', 'statut' => 'a_approuver', 'type' => 'appel', 'source' => 'FRQ : appels']),
            self::fiche(['id' => 4, 'debut' => '2026-10-01', 'statut' => 'publie', 'absent_depuis' => '2026-10-20']),
            self::fiche(['id' => 5, 'debut' => '2026-09-01', 'statut' => 'publie']),
            self::fiche(['id' => 6, 'debut' => '2026-01-10', 'statut' => 'ecarte']),
            self::fiche(['id' => 7, 'debut' => '2026-08-10', 'statut' => 'ecarte']),
            self::fiche(['id' => 8, 'debut' => null, 'statut' => 'a_approuver', 'type' => 'appel', 'echeance' => '2026-11-10']),
            self::fiche(['id' => 9, 'debut' => null, 'statut' => 'a_approuver']),
        ];
    }

    private static function ids(array $fiches): array {
        return array_map(static fn(Fiche $f): int => $f->id, $fiches);
    }

    public function test_publiees_gardent_le_tri_par_date_d_evenement(): void {
        // Sans début, la date limite sert de date ; sans aucune date, en dernier.
        $this->assertSame([5, 4], self::ids(PageFile::filtrer(self::lot(), 'publie', '', '')));
        // Même avec un jour d'arrivée, une fiche publiée suit sa date d'événement.
        $lot = [
            self::fiche(['id' => 1, 'debut' => '2026-12-01', 'statut' => 'publie', 'cree_le' => '2026-10-27']),
            self::fiche(['id' => 2, 'debut' => '2026-11-05', 'statut' => 'publie', 'cree_le' => '2026-10-01']),
        ];
        $this->assertSame([2, 1], self::ids(PageFile::filtrer($lot, 'publie', '', '')));
    }

    public function test_a_approuver_trie_par_arrivee_decroissante(): void {
        $lot = [
            self::fiche(['id' => 1, 'debut' => '2026-11-01', 'statut' => 'a_approuver', 'cree_le' => '2026-10-20']),
            self::fiche(['id' => 2, 'debut' => '2026-11-02', 'statut' => 'a_approuver', 'cree_le' => '2026-10-27']),
            self::fiche(['id' => 3, 'debut' => '2026-12-30', 'statut' => 'a_approuver', 'cree_le' => '2026-10-27']),
            self::fiche(['id' => 4, 'debut' => '2026-10-30', 'statut' => 'a_approuver', 'cree_le' => null]),
        ];
        $this->assertSame([3, 2, 1, 4], self::ids(PageFile::filtrer($lot, 'a_approuver', '', '')));
    }

    public function test_a_verifier_trie_par_arrivee_decroissante(): void {
        $lot = [
            self::fiche(['id' => 1, 'debut' => '2026-11-01', 'statut' => 'publie', 'absent_depuis' => '2026-10-26', 'cree_le' => '2026-10-20']),
            self::fiche(['id' => 2, 'debut' => '2026-12-01', 'statut' => 'publie', 'absent_depuis' => '2026-10-26', 'cree_le' => '2026-10-24']),
        ];
        $this->assertSame([2, 1], self::ids(PageFile::filtrer($lot, 'a_verifier', '', '')));
    }

    public function test_ecartees_triees_par_debut_decroissant(): void {
        $this->assertSame([7, 6], self::ids(PageFile::filtrer(self::lot(), 'ecarte', '', '')));
    }

    public function test_a_verifier_publiees_absentes_seulement(): void {
        $lot = self::lot();
        $lot[] = self::fiche(['id' => 10, 'statut' => 'ecarte', 'absent_depuis' => '2026-10-20']);
        $this->assertSame([4], self::ids(PageFile::filtrer($lot, 'a_verifier', '', '')));
    }

    public function test_filtrer_par_type_et_par_source(): void {
        $this->assertSame([8, 3], self::ids(PageFile::filtrer(self::lot(), 'a_approuver', 'appel', '')));
        $this->assertSame([2], self::ids(PageFile::filtrer(self::lot(), 'a_approuver', 'evenement', 'Obvia : événements')));
        $this->assertSame([], self::ids(PageFile::filtrer(self::lot(), 'a_approuver', 'appel', 'Obvia : événements')));
    }

    // --- repère « Nouveau » ---

    public function test_repere_nouveau_aujourd_hui_ou_hier(): void {
        $this->assertTrue(PageFile::est_nouvelle(self::fiche(['cree_le' => '2026-10-27']), '2026-10-27'));
        $this->assertTrue(PageFile::est_nouvelle(self::fiche(['cree_le' => '2026-10-26']), '2026-10-27'));
        $this->assertFalse(PageFile::est_nouvelle(self::fiche(['cree_le' => '2026-10-25']), '2026-10-27'));
        $this->assertFalse(PageFile::est_nouvelle(self::fiche(['cree_le' => null]), '2026-10-27'));
        // Hier est calculé sur le calendrier : le 1er du mois a pour veille le dernier jour du mois précédent.
        $this->assertTrue(PageFile::est_nouvelle(self::fiche(['cree_le' => '2026-10-31']), '2026-11-01'));
    }

    public function test_rendu_a_approuver_affiche_nouveau(): void {
        $GLOBALS['rsn_veille_test_aujourdhui'] = '2026-10-27';
        $lot = [
            self::fiche(['id' => 1, 'titre' => 'Récente', 'cree_le' => '2026-10-27']),
            self::fiche(['id' => 2, 'titre' => 'Ancienne', 'cree_le' => '2026-10-10']),
        ];
        $html = PageFile::page_html($lot, 'a_approuver', '', '', 'https://exemple.test/wp-admin/admin.php?page=rsn-veille', self::OPTIONS);
        $this->assertSame(1, substr_count($html, 'rsn-veille-nouveau'));
        $this->assertMatchesRegularExpression('#<strong>Récente</strong> <span class="rsn-veille-nouveau">Nouveau</span>#u', $html);
        $this->assertDoesNotMatchRegularExpression('#Ancienne</strong> <span class="rsn-veille-nouveau"#u', $html);
    }

    public function test_rendu_a_verifier_affiche_nouveau_mais_pas_les_autres_onglets(): void {
        $GLOBALS['rsn_veille_test_aujourdhui'] = '2026-10-27';
        $lot = [self::fiche(['id' => 1, 'statut' => 'publie', 'absent_depuis' => '2026-10-26', 'cree_le' => '2026-10-26'])];
        $url = 'https://exemple.test/wp-admin/admin.php?page=rsn-veille';
        $this->assertStringContainsString('rsn-veille-nouveau', PageFile::page_html($lot, 'a_verifier', '', '', $url, self::OPTIONS));
        $this->assertStringNotContainsString('rsn-veille-nouveau', PageFile::page_html($lot, 'publie', '', '', $url, self::OPTIONS));
        $ecartee = [self::fiche(['id' => 2, 'statut' => 'ecarte', 'cree_le' => '2026-10-27'])];
        $this->assertStringNotContainsString('rsn-veille-nouveau', PageFile::page_html($ecartee, 'ecarte', '', '', $url, self::OPTIONS));
    }

    public function test_compteurs_et_sources_distinctes(): void {
        $this->assertSame(['a_approuver' => 5, 'publie' => 2, 'ecarte' => 2, 'a_verifier' => 1], PageFile::compteurs(self::lot()));
        $this->assertSame(['FRQ : appels', 'Mila : événements', 'Obvia : événements'], PageFile::sources_distinctes(self::lot()));
    }

    // --- changer_statut ---

    private static function entrepot_avec(array $champs): array {
        $e = new EntrepotMemoire();
        $f = self::fiche($champs);
        $f->id = null;
        return [$e, $e->creer($f)];
    }

    private static function obtenir(EntrepotMemoire $e, int $id): Fiche {
        foreach ($e->lister('') as $f) {
            if ($f->id === $id) {
                return $f;
            }
        }
        throw new RuntimeException('Fiche absente');
    }

    public function test_publier_remet_les_absences_a_zero(): void {
        [$e, $id] = self::entrepot_avec(['statut' => 'publie', 'absences' => 2, 'absent_depuis' => '2026-10-20']);
        $r = PageFile::changer_statut($e, $id, 'publie');
        $f = self::obtenir($e, $id);
        $this->assertTrue($r['ok']);
        $this->assertSame('publie', $r['statut']);
        $this->assertSame('publie', $f->statut);
        $this->assertSame(0, $f->absences);
        $this->assertNull($f->absent_depuis);
        $this->assertTrue($f->manuel);
        $this->assertSame(['a_approuver' => 0, 'publie' => 1, 'ecarte' => 0, 'a_verifier' => 0], $r['compteurs']);
    }

    public function test_ecarter_pose_manuel_sans_toucher_aux_absences(): void {
        [$e, $id] = self::entrepot_avec(['statut' => 'publie', 'absences' => 2, 'absent_depuis' => '2026-10-20']);
        $r = PageFile::changer_statut($e, $id, 'ecarte');
        $f = self::obtenir($e, $id);
        $this->assertTrue($r['ok']);
        $this->assertSame('ecarte', $f->statut);
        $this->assertTrue($f->manuel);
        $this->assertSame(2, $f->absences);
        $this->assertSame('2026-10-20', $f->absent_depuis);
        $this->assertSame(['a_approuver' => 0, 'publie' => 0, 'ecarte' => 1, 'a_verifier' => 0], $r['compteurs']);
    }

    public function test_changer_statut_purge_la_page_veille(): void {
        update_option(Collecte::OPTION_REGLAGES, ['page_veille_id' => 42]);
        [$e, $id] = self::entrepot_avec([]);
        PageFile::changer_statut($e, $id, 'publie');
        $this->assertSame([[42]], rsn_veille_bouchons_declenchements('litespeed_purge_post'));
        $this->assertCount(1, rsn_veille_bouchons_declenchements('rsn_veille_caches_purges'));
    }

    public function test_statut_invalide_ou_fiche_inconnue_refuses(): void {
        [$e, $id] = self::entrepot_avec([]);
        $r = PageFile::changer_statut($e, $id, 'supprime');
        $this->assertFalse($r['ok']);
        $this->assertNotSame('', $r['message']);
        $this->assertSame('a_approuver', self::obtenir($e, $id)->statut);

        $this->assertFalse(PageFile::changer_statut($e, 999, 'publie')['ok']);
        $this->assertFalse(PageFile::changer_statut($e, 0, 'publie')['ok']);
        $this->assertSame([], rsn_veille_bouchons_declenchements('rsn_veille_caches_purges'));
    }

    // --- Action AJAX ---

    /** Lance l'action AJAX et rend [réponse, statut HTTP]. */
    private static function ajax(array $donnees): array {
        $_POST = $_REQUEST = $donnees;
        try {
            PageFile::ajax_changer_statut();
        } catch (RsnVeilleArretRequete $arret) {
            return [$arret->reponse, $arret->statut_http];
        }
        throw new RuntimeException('Aucune réponse JSON');
    }

    private static function fiche_wordpress(): int {
        $f = self::fiche(['statut' => 'a_approuver']);
        $f->id = null;
        $f->cles_source = ['mila:1'];
        return (new EntrepotWordPress())->creer($f);
    }

    public function test_ajax_publie_la_fiche(): void {
        $GLOBALS['rsn_veille_wp']['capacites'] = ['rsn_veille_gerer'];
        $id = self::fiche_wordpress();
        [$reponse, $code] = self::ajax(['id' => (string) $id, 'statut' => 'publie', 'nonce' => wp_create_nonce('rsn_veille_file')]);
        $this->assertSame(200, $code);
        $this->assertTrue($reponse['success']);
        $this->assertSame('publie', $reponse['data']['statut']);
        $this->assertSame(1, $reponse['data']['compteurs']['publie']);
        $this->assertSame('publish', get_post_status($id));
        $this->assertTrue((new EntrepotWordPress())->obtenir($id)->manuel);
    }

    public function test_ajax_refuse_sans_capacite(): void {
        $id = self::fiche_wordpress();
        [$reponse, $code] = self::ajax(['id' => (string) $id, 'statut' => 'publie', 'nonce' => wp_create_nonce('rsn_veille_file')]);
        $this->assertSame(403, $code);
        $this->assertFalse($reponse['success']);
        $this->assertSame('pending', get_post_status($id));
    }

    public function test_ajax_refuse_un_mauvais_jeton(): void {
        $GLOBALS['rsn_veille_wp']['capacites'] = ['rsn_veille_gerer'];
        $id = self::fiche_wordpress();
        [$reponse, $code] = self::ajax(['id' => (string) $id, 'statut' => 'publie', 'nonce' => 'faux']);
        $this->assertSame(403, $code);
        $this->assertFalse($reponse['success']);
        $this->assertSame('pending', get_post_status($id));
        [, $code] = self::ajax(['id' => (string) $id, 'statut' => 'publie']);
        $this->assertSame(403, $code);
    }

    public function test_ajax_statut_invalide_ou_autre_type_de_contenu(): void {
        $GLOBALS['rsn_veille_wp']['capacites'] = ['rsn_veille_gerer'];
        $id = self::fiche_wordpress();
        $nonce = wp_create_nonce('rsn_veille_file');
        [$reponse, $code] = self::ajax(['id' => (string) $id, 'statut' => 'trash', 'nonce' => $nonce]);
        $this->assertSame(400, $code);
        $this->assertFalse($reponse['success']);

        // Un article ordinaire n'est pas une fiche : il n'est pas touché.
        $article = wp_insert_post(['post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Billet']);
        [$reponse, $code] = self::ajax(['id' => (string) $article, 'statut' => 'publie', 'nonce' => $nonce]);
        $this->assertSame(400, $code);
        $this->assertSame('draft', get_post_status($article));

        [, $code] = self::ajax(['id' => ['x'], 'statut' => ['publie'], 'nonce' => $nonce]);
        $this->assertSame(400, $code);
    }

    // --- Page ---

    public function test_tableau_vide_et_onglets(): void {
        $html = PageFile::page_html(self::lot(), 'a_verifier', '', '', 'https://exemple.test/wp-admin/admin.php?page=rsn-veille', self::OPTIONS);
        $this->assertStringContainsString('data-onglet="a_verifier"', $html);
        $this->assertMatchesRegularExpression('/nav-tab nav-tab-active"[^>]*>À vérifier <span class="count" data-compteur="a_verifier">\(1\)<\/span>/', $html);
        $this->assertStringContainsString('data-compteur="a_approuver">(5)<', $html);
        $this->assertStringContainsString('href="https://exemple.test/wp-admin/admin.php?page=rsn-veille&amp;statut=publie"', $html);
        $this->assertStringContainsString('<option value="Obvia : événements">Obvia : événements</option>', $html);

        $vide = PageFile::page_html([], 'publie', 'appel', '', 'https://exemple.test/wp-admin/admin.php?page=rsn-veille', self::OPTIONS);
        $this->assertStringContainsString('Aucune fiche', $vide);
        $this->assertStringContainsString('<option value="appel" selected>', $vide);
    }

    public function test_changer_statut_ne_charge_pas_toute_la_base(): void {
        $e = new EntrepotWordPress();
        for ($i = 1; $i <= 3; $i++) {
            $f = self::fiche(['statut' => 'publie', 'titre' => 'Fiche ' . $i]);
            $f->id = null;
            $f->cles_source = ['mila:' . $i];
            $e->creer($f);
        }
        $id = self::fiche_wordpress();
        $GLOBALS['rsn_veille_wp']['requetes'] = [];

        $r = PageFile::changer_statut($e, $id, 'ecarte');
        $this->assertSame(['a_approuver' => 0, 'publie' => 3, 'ecarte' => 1, 'a_verifier' => 0], $r['compteurs']);
        foreach ($GLOBALS['rsn_veille_wp']['requetes'] as $requete) {
            $this->assertFalse(($requete['posts_per_page'] ?? 0) === -1, 'aucune requête sans borne');
        }
    }

    public function test_onglets_publiees_et_ecartees_plafonnes_aux_plus_recentes(): void {
        $e = new EntrepotMemoire();
        $creer = static function (array $champs) use ($e): int {
            $f = self::fiche($champs);
            $f->id = null;
            return $e->creer($f);
        };
        $premiere = $creer(['statut' => 'ecarte', 'titre' => 'La plus ancienne']);
        for ($i = 1; $i <= PageFile::PLAFOND; $i++) {
            $creer(['statut' => 'ecarte', 'titre' => 'Écartée ' . $i]);
        }
        for ($i = 1; $i <= 3; $i++) {
            $creer(['statut' => 'a_approuver', 'titre' => 'En attente ' . $i]);
        }

        [$fiches, $plafond] = PageFile::fiches_de_l_onglet($e, 'ecarte', '');
        $this->assertCount(200, $fiches);
        $this->assertTrue($plafond);
        $this->assertNotContains($premiere, array_map(static fn(Fiche $f): ?int => $f->id, $fiches));
        [$fiches, $plafond] = PageFile::fiches_de_l_onglet($e, 'a_approuver', '');
        $this->assertCount(3, $fiches);
        $this->assertFalse($plafond);
        [$fiches, $plafond] = PageFile::fiches_de_l_onglet($e, 'publie', '');
        $this->assertSame([[], false], [$fiches, $plafond]);

        $html = PageFile::page_html($fiches, 'ecarte', '', '', 'https://exemple.test/wp-admin/admin.php?page=rsn-veille', self::OPTIONS + [
            'compteurs' => $e->compter(), 'plafond_atteint' => true,
        ]);
        $this->assertStringContainsString('Les 200 plus récentes sont affichées', $html);
        $this->assertStringContainsString('data-compteur="ecarte">(201)<', $html);
        $this->assertStringNotContainsString('plus récentes sont affichées', PageFile::page_html([], 'ecarte', '', '', 'https://exemple.test/', self::OPTIONS));
    }

    // --- Image d'une proposition écartée ---

    /**
     * Fiche dans l'imitation de WordPress, avec une pièce jointe en vignette, téléversée par le formulaire
     * (rattachée à la fiche, méta repère) sauf indication contraire ; rend [entrepôt, id, pièce].
     */
    private static function fiche_avec_vignette(string $source, bool $rattachee = true, bool $repere = true): array {
        $e = new EntrepotWordPress();
        $f = self::fiche(['source' => $source, 'image_url' => 'https://exemple.test/wp-content/uploads/a.png']);
        $f->id = null;
        $id = $e->creer($f);
        $piece = wp_insert_post(['post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'a.png', 'post_parent' => $rattachee ? $id : 0]);
        if ($repere) {
            update_post_meta($piece, 'rsn_veille_image_proposee', 1);
        }
        set_post_thumbnail($id, $piece);
        return [$e, $id, $piece];
    }

    public function test_image_de_la_mediatheque_choisie_par_l_equipe_jamais_supprimee(): void {
        // Pièce d'un autre parent (ou sans parent), même avec la méta repère.
        [$e, $id, $piece] = self::fiche_avec_vignette('proposition', false, true);
        PageFile::changer_statut($e, $id, 'ecarte');
        // Pièce rattachée à la fiche mais sans la méta repère (téléversée depuis l'éditeur).
        [$e2, $id2, $piece2] = self::fiche_avec_vignette('proposition', true, false);
        PageFile::changer_statut($e2, $id2, 'ecarte');

        $this->assertSame([], $GLOBALS['rsn_veille_wp']['pieces_supprimees'] ?? []);
        $this->assertNotNull(get_post($piece));
        $this->assertNotNull(get_post($piece2));
        $this->assertSame($piece, get_post_thumbnail_id($id));
        $this->assertSame('https://exemple.test/wp-content/uploads/a.png', $e->obtenir($id)->image_url);
    }

    public function test_proposition_ecartee_perd_son_image(): void {
        [$e, $id, $piece] = self::fiche_avec_vignette('proposition');
        $this->assertTrue(PageFile::changer_statut($e, $id, 'ecarte')['ok']);
        $this->assertSame([['id' => $piece, 'definitif' => true]], $GLOBALS['rsn_veille_wp']['pieces_supprimees']);
        $this->assertNull(get_post($piece));
        $this->assertSame(0, get_post_thumbnail_id($id));
        $this->assertSame('', $e->obtenir($id)->image_url);
        $this->assertSame('ecarte', $e->obtenir($id)->statut);
    }

    public function test_image_gardee_pour_une_fiche_collectee_ou_une_proposition_publiee(): void {
        [$e, $id, $piece] = self::fiche_avec_vignette('Mila : événements');
        PageFile::changer_statut($e, $id, 'ecarte');
        [$e2, $id2, $piece2] = self::fiche_avec_vignette('proposition');
        PageFile::changer_statut($e2, $id2, 'publie');
        $this->assertSame([], $GLOBALS['rsn_veille_wp']['pieces_supprimees'] ?? []);
        $this->assertSame($piece, get_post_thumbnail_id($id));
        $this->assertSame($piece2, get_post_thumbnail_id($id2));
        $this->assertNotSame('', $e2->obtenir($id2)->image_url);
    }

    public function test_proposition_ecartee_sans_image(): void {
        $e = new EntrepotWordPress();
        $f = self::fiche(['source' => 'proposition']);
        $f->id = null;
        $id = $e->creer($f);
        $this->assertTrue(PageFile::changer_statut($e, $id, 'ecarte')['ok']);
        $this->assertSame([], $GLOBALS['rsn_veille_wp']['pieces_supprimees'] ?? []);
    }
}

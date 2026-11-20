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
        $GLOBALS['rsn_veille_test_aujourdhui'] = '2026-10-27';
    }

    protected function tearDown(): void {
        $_POST = $_REQUEST = [];
        $GLOBALS['rsn_veille_test_aujourdhui'] = '2026-10-25';
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
        $this->assertSame(['Publier + infolettre' => 'publie', 'Publier seulement' => 'publie', 'Écarter' => 'ecarte'], self::boutons(PageFile::ligne_html(self::fiche(), self::OPTIONS)));
        $this->assertSame(['Retirer de la page' => 'ecarte'], self::boutons(PageFile::ligne_html(self::fiche(['statut' => 'publie']), self::OPTIONS)));
        $this->assertSame(['Publier' => 'publie'], self::boutons(PageFile::ligne_html(self::fiche(['statut' => 'ecarte']), self::OPTIONS)));
        // En attente mais déjà passée (onglet « Archivées ») : il ne reste qu'à l'écarter.
        $this->assertSame(['Écarter' => 'ecarte'], self::boutons(PageFile::ligne_html(self::fiche(['debut' => '2026-10-01']), self::OPTIONS)));
    }

    public function test_publier_est_le_bouton_principal(): void {
        $html = PageFile::ligne_html(self::fiche(), self::OPTIONS);
        $this->assertStringContainsString('<button type="button" class="button button-primary rsn-veille-action" data-statut="publie" data-infolettre="1">Publier + infolettre</button>', $html);
        $this->assertStringContainsString('<button type="button" class="button rsn-veille-action" data-statut="publie" data-infolettre="0">Publier seulement</button>', $html);
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
        $this->assertStringContainsString('<span class="rsn-veille-type" data-type="evenement"><span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span> Événement</span>', $html);
        $this->assertStringContainsString('12 novembre 2026, 12 h', $html);
        $this->assertStringContainsString('Mila', $html);
        $this->assertStringContainsString('Mila : événements', $html);
        // Le niveau en clair, plus de lettre seule à deviner.
        $this->assertMatchesRegularExpression('/<span class="rsn-veille-niveau" title="Page du site[^"]*">niveau C : page web lue<\/span>/', $html);
        $this->assertStringNotContainsString('<abbr', $html);
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
        $this->assertSame(['Garder publiée' => 'publie', 'Retirer de la page' => 'ecarte'], self::boutons($avec));
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

    public function test_publiees_de_la_plus_proche_a_la_plus_lointaine(): void {
        // Les deux fiches publiées du lot sont passées (1er octobre et 1er septembre) : plus dans « Publiées ».
        $this->assertSame([], self::ids(PageFile::filtrer(self::lot(), 'publie', '', '')));
        // Même avec un jour d'arrivée, une fiche publiée suit sa date d'événement.
        $lot = [
            self::fiche(['id' => 1, 'debut' => '2026-12-01', 'statut' => 'publie', 'cree_le' => '2026-10-27']),
            self::fiche(['id' => 2, 'debut' => '2026-11-05', 'statut' => 'publie', 'cree_le' => '2026-10-01']),
        ];
        $this->assertSame([2, 1], self::ids(PageFile::filtrer($lot, 'publie', '', '')));
    }

    public function test_un_appel_est_range_a_sa_date_limite_jamais_a_sa_date_de_publication(): void {
        foreach (['a_approuver' => [3, 1, 2, 4, 5], 'publie' => [3, 1, 2, 4, 5], 'ecarte' => [2, 1, 3, 4, 5]] as $statut => $attendu) {
            $lot = [
                self::fiche(['id' => 5, 'debut' => '2026-10-20', 'echeance' => null, 'type' => 'appel', 'statut' => $statut]),
                self::fiche(['id' => 4, 'debut' => null, 'echeance' => null, 'statut' => $statut]),
                // Appel publié le 1er octobre, à remettre le 10 novembre : c'est le 10 novembre qui compte.
                self::fiche(['id' => 3, 'debut' => '2026-10-01', 'echeance' => '2026-11-10', 'type' => 'appel', 'statut' => $statut]),
                self::fiche(['id' => 2, 'debut' => '2026-12-01', 'statut' => $statut]),
                self::fiche(['id' => 1, 'debut' => '2026-11-20', 'statut' => $statut]),
            ];
            // Sans date qui compte (appel sans date limite, événement sans début) : à la fin, par identifiant.
            $this->assertSame($attendu, self::ids(PageFile::filtrer($lot, $statut, '', '')), $statut);
        }
    }

    public function test_a_approuver_trie_par_date_de_l_evenement_plus_par_arrivee(): void {
        $lot = [
            self::fiche(['id' => 1, 'debut' => '2026-11-01', 'statut' => 'a_approuver', 'cree_le' => '2026-10-20']),
            self::fiche(['id' => 2, 'debut' => '2026-11-02', 'statut' => 'a_approuver', 'cree_le' => '2026-10-27']),
            self::fiche(['id' => 3, 'debut' => '2026-12-30', 'statut' => 'a_approuver', 'cree_le' => '2026-10-27']),
            self::fiche(['id' => 4, 'debut' => '2026-10-30', 'statut' => 'a_approuver', 'cree_le' => null]),
        ];
        $this->assertSame([4, 1, 2, 3], self::ids(PageFile::filtrer($lot, 'a_approuver', '', '')));
    }

    // --- Archivées : ce qui est dépassé quitte « À approuver » et « Publiées » (0.15.0) ---

    public function test_fiche_date_utile_et_depassee(): void {
        $jour = '2026-10-27';
        $this->assertSame('2026-11-12', self::fiche()->date_utile());
        $this->assertSame('2026-11-10', self::fiche(['type' => 'appel', 'debut' => '2026-10-01', 'echeance' => '2026-11-10'])->date_utile());
        $this->assertSame('', self::fiche(['type' => 'appel', 'debut' => '2026-10-01'])->date_utile());
        $this->assertSame('', self::fiche(['debut' => null])->date_utile());
        // Événement : passé quand sa fin (sinon son début) est avant aujourd'hui ; le jour même, il ne l'est pas.
        $this->assertFalse(self::fiche(['debut' => '2026-10-27'])->est_passee($jour));
        $this->assertTrue(self::fiche(['debut' => '2026-10-26'])->est_passee($jour));
        $this->assertFalse(self::fiche(['debut' => '2026-09-04', 'fin' => '2026-12-18'])->est_passee($jour));
        $this->assertFalse(self::fiche(['debut' => null])->est_passee($jour));
        // Appel : date limite passée, ou sans date limite et publié il y a plus de 30 jours.
        $this->assertTrue(self::fiche(['type' => 'appel', 'debut' => '2026-10-01', 'echeance' => '2026-10-26'])->est_passee($jour));
        $this->assertFalse(self::fiche(['type' => 'appel', 'debut' => '2026-06-01', 'echeance' => '2026-10-27'])->est_passee($jour));
        $this->assertFalse(self::fiche(['type' => 'appel', 'debut' => '2026-10-20'])->est_passee($jour));
        $this->assertTrue(self::fiche(['type' => 'appel', 'debut' => '2026-08-01'])->est_passee($jour));
        $this->assertFalse(self::fiche(['type' => 'nouvelle', 'debut' => '2026-01-01'])->est_passee($jour));
    }

    public function test_onglet_archivees(): void {
        $lot = [
            self::fiche(['id' => 1, 'titre' => 'Colloque passé', 'debut' => '2026-10-01', 'statut' => 'publie']),
            self::fiche(['id' => 2, 'titre' => 'Appel échu', 'type' => 'appel', 'debut' => '2026-09-01', 'echeance' => '2026-10-15', 'statut' => 'publie']),
            self::fiche(['id' => 3, 'titre' => 'Jamais approuvé', 'debut' => '2026-10-20', 'statut' => 'a_approuver']),
            self::fiche(['id' => 4, 'titre' => 'Midi à venir', 'debut' => '2026-11-12', 'statut' => 'publie']),
            self::fiche(['id' => 5, 'titre' => 'En attente à venir', 'debut' => '2026-11-15', 'statut' => 'a_approuver']),
            self::fiche(['id' => 6, 'titre' => 'Écartée passée', 'debut' => '2026-10-02', 'statut' => 'ecarte']),
        ];
        // De la plus récemment dépassée à la plus ancienne ; les écartées restent dans leur onglet.
        $this->assertSame([3, 2, 1], self::ids(PageFile::filtrer($lot, 'archive', '', '')));
        $this->assertSame([4], self::ids(PageFile::filtrer($lot, 'publie', '', '')));
        $this->assertSame([5], self::ids(PageFile::filtrer($lot, 'a_approuver', '', '')));
        $this->assertSame([6], self::ids(PageFile::filtrer($lot, 'ecarte', '', '')));
        $this->assertSame(['a_approuver' => 1, 'publie' => 1, 'archive' => 3, 'ecarte' => 1, 'a_verifier' => 0], PageFile::compteurs($lot));
        // Le lendemain du midi, il est archivé à son tour.
        $this->assertSame([4, 3, 2, 1], self::ids(PageFile::filtrer($lot, 'archive', '', '', '', '2026-11-13')));

        $html = PageFile::page_html($lot, 'archive', '', '', 'https://exemple.test/wp-admin/admin.php?page=rsn-veille', self::OPTIONS);
        $this->assertMatchesRegularExpression('/nav-tab nav-tab-active"[^>]*>Archivées <span class="count" data-compteur="archive">\(3\)<\/span>/', $html);
        $this->assertStringContainsString('href="https://exemple.test/wp-admin/admin.php?page=rsn-veille&amp;statut=archive"', $html);
        $this->assertStringContainsString('Événements passés et appels dont la date limite est dépassée', $html);
        $this->assertStringContainsString('Colloque passé', $html);
        $this->assertStringNotContainsString('Midi à venir', $html);
        $this->assertStringContainsString('<span class="rsn-veille-jamais-publiee">Jamais publiée</span>', $html);
        $this->assertSame(1, substr_count($html, 'Jamais publiée'));
        // Onglets dans l'ordre : À approuver, Publiées, Archivées, Écartées, À vérifier.
        preg_match_all('/data-compteur="([a-z_]+)"/', $html, $m);
        $this->assertSame(['a_approuver', 'publie', 'archive', 'ecarte', 'a_verifier'], $m[1]);
    }

    public function test_fiches_chargees_pour_publiees_et_archivees(): void {
        $e = new EntrepotMemoire();
        $creer = static function (array $champs) use ($e): int {
            $f = self::fiche($champs);
            $f->id = null;
            return $e->creer($f);
        };
        $venir = $creer(['statut' => 'publie', 'debut' => '2026-11-12']);
        $passee = $creer(['statut' => 'publie', 'debut' => '2026-06-01']);
        $appel = $creer(['statut' => 'publie', 'type' => 'appel', 'debut' => '2026-09-01', 'echeance' => '2026-12-01']);
        $attente_passee = $creer(['statut' => 'a_approuver', 'debut' => '2026-10-01']);
        $attente = $creer(['statut' => 'a_approuver', 'debut' => '2026-11-30']);
        $creer(['statut' => 'ecarte', 'debut' => '2026-06-01']);

        $page = static fn(string $onglet, string $type = ''): array => self::ids(PageFile::filtrer(PageFile::fiches_de_l_onglet($e, $onglet, $type, '2026-10-27')[0], $onglet, $type, '', '', '2026-10-27'));
        $this->assertSame([$venir, $appel], $page('publie'));
        $this->assertSame([$appel], $page('publie', 'appel'));
        $this->assertSame([$attente_passee, $passee], $page('archive'));
        $this->assertSame([$attente], $page('a_approuver'));
        $this->assertSame(['a_approuver' => 1, 'publie' => 2, 'archive' => 2, 'ecarte' => 1, 'a_verifier' => 0], PageFile::compteurs_de($e, '2026-10-27'));
        // Le 2 décembre : tout ce qui était publié ou en attente est dépassé.
        $this->assertSame(['a_approuver' => 0, 'publie' => 0, 'archive' => 5, 'ecarte' => 1, 'a_verifier' => 0], PageFile::compteurs_de($e, '2026-12-02'));
    }

    // --- Type bien visible et niveaux expliqués (0.15.0) ---

    public function test_pastille_de_type_et_legende_des_niveaux(): void {
        $appel = PageFile::ligne_html(self::fiche(['type' => 'appel', 'echeance' => '2026-11-30', 'niveau' => 'B']), self::OPTIONS);
        $this->assertStringContainsString('<span class="rsn-veille-type" data-type="appel"><span class="dashicons dashicons-megaphone" aria-hidden="true"></span> Appel</span>', $appel);
        $this->assertStringContainsString('>niveau B : flux RSS ou infolettre</span>', $appel);
        // Niveau inconnu : la lettre seule, sans explication inventée.
        $this->assertStringContainsString('<span class="rsn-veille-niveau">niveau Z</span>', PageFile::ligne_html(self::fiche(['niveau' => 'Z']), self::OPTIONS));

        $html = PageFile::page_html(self::lot(), 'a_approuver', '', '', 'https://exemple.test/wp-admin/admin.php?page=rsn-veille', self::OPTIONS);
        $this->assertStringContainsString('<details class="rsn-veille-deroule rsn-veille-legende"><summary>Que veulent dire les niveaux ?</summary>', $html);
        foreach (['Niveau A, flux structuré', 'Niveau C, page web lue', 'Niveau M, saisie à la main', 'Niveau B, flux RSS ou infolettre'] as $niveau) {
            $this->assertStringContainsString('<li><strong>' . $niveau . '</strong> : ', $html);
        }
        // Après le tableau.
        $this->assertGreaterThan(strpos($html, '</table>'), strpos($html, 'rsn-veille-legende'));
    }

    // --- Infolettre du RSN : dire ce qui y va (0.15.0) ---

    public function test_regler_infolettre(): void {
        $this->assertSame([4, 9], PageFile::regler_infolettre([4], 9, false));
        $this->assertSame([4, 9], PageFile::regler_infolettre([4, 9], 9, false));
        $this->assertSame([4], PageFile::regler_infolettre([4, 9], 9, true));
        $this->assertSame([7], PageFile::regler_infolettre(['x', -2, null, '7', 7], 3, true));
        $this->assertSame([], PageFile::regler_infolettre([], 0, false));
        // Bornée aux 500 derniers.
        $longue = PageFile::regler_infolettre(range(1, 500), 900, false);
        $this->assertCount(500, $longue);
        $this->assertSame([2, 900], [$longue[0], $longue[499]]);
        update_option(PageFile::OPTION_HORS_INFOLETTRE, 'illisible');
        $this->assertSame([], PageFile::lire_hors_infolettre());
        update_option(PageFile::OPTION_HORS_INFOLETTRE, ['12', 'x', 12, 0]);
        $this->assertSame([12], PageFile::lire_hors_infolettre());
    }

    public function test_colonne_infolettre_dans_publiees_seulement(): void {
        $lot = [self::fiche(['id' => 1, 'statut' => 'publie']), self::fiche(['id' => 2, 'statut' => 'publie', 'titre' => 'Sans infolettre'])];
        $html = PageFile::page_html($lot, 'publie', '', '', 'https://exemple.test/wp-admin/admin.php?page=rsn-veille', self::OPTIONS + ['hors_infolettre' => [2]]);
        $this->assertStringContainsString('<th scope="col">Infolettre du RSN</th><th scope="col">Actions</th>', $html);
        $this->assertMatchesRegularExpression('~id="rsn-veille-fiche-1".*?<td class="rsn-veille-infolettre-cellule"><span class="rsn-veille-pastille-infolettre" data-dans="1"[^>]*>Dans l&#039;infolettre</span><br><button type="button" class="button-link rsn-veille-action-infolettre" data-dans="0">Retirer de l&#039;infolettre</button></td>~s', $html);
        $this->assertMatchesRegularExpression('~id="rsn-veille-fiche-2".*?<span class="rsn-veille-pastille-infolettre" data-dans="0"[^>]*>Pas dans l&#039;infolettre</span><br><button type="button" class="button-link rsn-veille-action-infolettre" data-dans="1">Mettre dans l&#039;infolettre</button>~s', $html);
        // Ailleurs, pas de colonne : le choix se fait aux boutons « Publier ».
        foreach (['a_approuver', 'archive', 'ecarte', 'a_verifier'] as $onglet) {
            $this->assertStringNotContainsString('Infolettre du RSN', PageFile::page_html(self::lot(), $onglet, '', '', 'https://exemple.test/', self::OPTIONS), $onglet);
        }
    }

    public function test_ajax_publier_seulement_tient_la_fiche_hors_de_l_infolettre(): void {
        $GLOBALS['rsn_veille_wp']['capacites'] = ['rsn_veille_gerer'];
        $nonce = wp_create_nonce('rsn_veille_file');
        $id = self::fiche_wordpress();
        [$reponse] = self::ajax(['id' => (string) $id, 'statut' => 'publie', 'infolettre' => '0', 'nonce' => $nonce]);
        $this->assertTrue($reponse['success']);
        $this->assertSame([$id], PageFile::lire_hors_infolettre());
        // « Publier + infolettre » l'y remet ; sans le champ, rien ne change ; écarter ne touche pas au choix.
        self::ajax(['id' => (string) $id, 'statut' => 'publie', 'infolettre' => '1', 'nonce' => $nonce]);
        $this->assertSame([], PageFile::lire_hors_infolettre());
        self::ajax(['id' => (string) $id, 'statut' => 'publie', 'infolettre' => '0', 'nonce' => $nonce]);
        self::ajax(['id' => (string) $id, 'statut' => 'publie', 'nonce' => $nonce]);
        self::ajax(['id' => (string) $id, 'statut' => 'ecarte', 'infolettre' => '1', 'nonce' => $nonce]);
        $this->assertSame([$id], PageFile::lire_hors_infolettre());
        // Un statut refusé ne règle rien.
        update_option(PageFile::OPTION_HORS_INFOLETTRE, []);
        self::ajax(['id' => '999999', 'statut' => 'publie', 'infolettre' => '0', 'nonce' => $nonce]);
        $this->assertSame([], PageFile::lire_hors_infolettre());
    }

    /** Lance l'action AJAX de l'infolettre et rend [réponse, statut HTTP]. */
    private static function ajax_infolettre(array $donnees): array {
        $_POST = $_REQUEST = $donnees;
        try {
            PageFile::ajax_infolettre();
        } catch (RsnVeilleArretRequete $arret) {
            return [$arret->reponse, $arret->statut_http];
        }
        throw new RuntimeException('Aucune réponse JSON');
    }

    public function test_ajax_infolettre(): void {
        $id = self::fiche_wordpress();
        $nonce = wp_create_nonce('rsn_veille_file');
        // Sans capacité, ou sans jeton : refusé, rien n'est écrit.
        [$reponse, $code] = self::ajax_infolettre(['id' => (string) $id, 'dans' => '0', 'nonce' => $nonce]);
        $this->assertSame([403, false], [$code, $reponse['success']]);
        $GLOBALS['rsn_veille_wp']['capacites'] = ['rsn_veille_gerer'];
        [, $code] = self::ajax_infolettre(['id' => (string) $id, 'dans' => '0', 'nonce' => 'faux']);
        $this->assertSame(403, $code);
        $this->assertSame([], PageFile::lire_hors_infolettre());

        [$reponse, $code] = self::ajax_infolettre(['id' => (string) $id, 'dans' => '0', 'nonce' => $nonce]);
        $this->assertSame(200, $code);
        $this->assertFalse($reponse['data']['dans']);
        $this->assertStringContainsString('data-dans="0"', $reponse['data']['html']);
        $this->assertStringContainsString('Mettre dans l&#039;infolettre', $reponse['data']['html']);
        $this->assertSame([$id], PageFile::lire_hors_infolettre());
        [$reponse] = self::ajax_infolettre(['id' => (string) $id, 'dans' => '1', 'nonce' => $nonce]);
        $this->assertTrue($reponse['data']['dans']);
        $this->assertSame([], PageFile::lire_hors_infolettre());

        // Valeur inconnue, fiche inconnue ou autre type de contenu : refusé.
        $article = wp_insert_post(['post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Billet']);
        foreach ([['id' => (string) $id, 'dans' => 'oui'], ['id' => '0', 'dans' => '0'], ['id' => (string) $article, 'dans' => '0'], ['id' => ['x'], 'dans' => ['0']]] as $mauvais) {
            [, $code] = self::ajax_infolettre($mauvais + ['nonce' => $nonce]);
            $this->assertSame(400, $code);
        }
        $this->assertSame([], PageFile::lire_hors_infolettre());
    }

    public function test_a_verifier_trie_par_arrivee_decroissante(): void {
        $lot = [
            self::fiche(['id' => 1, 'debut' => '2026-11-01', 'statut' => 'publie', 'absent_depuis' => '2026-10-26', 'cree_le' => '2026-10-20']),
            self::fiche(['id' => 2, 'debut' => '2026-12-01', 'statut' => 'publie', 'absent_depuis' => '2026-10-26', 'cree_le' => '2026-10-24']),
        ];
        $this->assertSame([2, 1], self::ids(PageFile::filtrer($lot, 'a_verifier', '', '')));
    }

    public function test_ecartees_triees_par_date_decroissante(): void {
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
        $lot = [
            self::fiche(['id' => 1, 'titre' => 'Récente', 'cree_le' => '2026-10-27']),
            self::fiche(['id' => 2, 'titre' => 'Ancienne', 'cree_le' => '2026-10-10']),
        ];
        $html = PageFile::page_html($lot, 'a_approuver', '', '', 'https://exemple.test/wp-admin/admin.php?page=rsn-veille', self::OPTIONS);
        $this->assertSame(1, substr_count($html, 'rsn-veille-nouveau'));
        $this->assertMatchesRegularExpression('#<strong>Récente</strong> <span class="rsn-veille-nouveau">Nouveau</span>#u', $html);
        $this->assertDoesNotMatchRegularExpression('#Ancienne</strong> <span class="rsn-veille-nouveau"#u', $html);
    }

    public function test_bouton_ajouter_une_fiche_en_tete_de_la_file(): void {
        $html = PageFile::page_html([], 'a_approuver', '', '', 'https://exemple.test/wp-admin/admin.php?page=rsn-veille', self::OPTIONS);
        $this->assertStringContainsString('<a href="https://exemple.test/wp-admin/post-new.php?post_type=rsn_veille" class="page-title-action">Ajouter une fiche</a>', $html);
        // À côté du titre de la page, avant les onglets.
        $this->assertLessThan(strpos($html, 'nav-tab-wrapper'), strpos($html, 'Ajouter une fiche'));
        $this->assertGreaterThan(strpos($html, "File d'approbation"), strpos($html, 'Ajouter une fiche'));
        // Sans le droit de créer des fiches : pas de bouton.
        $sans = PageFile::page_html([], 'a_approuver', '', '', 'https://exemple.test/', self::OPTIONS + ['peut_ajouter' => false]);
        $this->assertStringNotContainsString('Ajouter une fiche', $sans);
    }

    public function test_rendu_a_verifier_affiche_nouveau_mais_pas_les_autres_onglets(): void {
        $lot = [self::fiche(['id' => 1, 'statut' => 'publie', 'absent_depuis' => '2026-10-26', 'cree_le' => '2026-10-26'])];
        $url = 'https://exemple.test/wp-admin/admin.php?page=rsn-veille';
        $this->assertStringContainsString('rsn-veille-nouveau', PageFile::page_html($lot, 'a_verifier', '', '', $url, self::OPTIONS));
        $this->assertStringNotContainsString('rsn-veille-nouveau', PageFile::page_html($lot, 'publie', '', '', $url, self::OPTIONS));
        $ecartee = [self::fiche(['id' => 2, 'statut' => 'ecarte', 'cree_le' => '2026-10-27'])];
        $this->assertStringNotContainsString('rsn-veille-nouveau', PageFile::page_html($ecartee, 'ecarte', '', '', $url, self::OPTIONS));
    }

    public function test_compteurs_et_sources_distinctes(): void {
        // Les deux fiches publiées du lot sont passées : archivées (l'une reste aussi « à vérifier »).
        $this->assertSame(['a_approuver' => 5, 'publie' => 0, 'archive' => 2, 'ecarte' => 2, 'a_verifier' => 1], PageFile::compteurs(self::lot()));
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
        $this->assertSame(['a_approuver' => 0, 'publie' => 1, 'archive' => 0, 'ecarte' => 0, 'a_verifier' => 0], $r['compteurs']);
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
        $this->assertSame(['a_approuver' => 0, 'publie' => 0, 'archive' => 0, 'ecarte' => 1, 'a_verifier' => 0], $r['compteurs']);
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
        $this->assertSame(['a_approuver' => 0, 'publie' => 3, 'archive' => 0, 'ecarte' => 1, 'a_verifier' => 0], $r['compteurs']);
        // Les seules lectures entières : la file en attente, et les fiches publiées bornées par leurs dates
        // (celles de la page publique). Jamais tout l'historique des publiées ou des écartées.
        foreach ($GLOBALS['rsn_veille_wp']['requetes'] as $requete) {
            if (($requete['posts_per_page'] ?? 0) === -1) {
                $this->assertTrue(($requete['post_status'] ?? []) === ['pending'] || !empty($requete['meta_query']), 'aucune requête sans borne : ' . json_encode($requete));
            }
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

    // --- Origine des fiches : proposition du public, collecte automatique, saisie par l'équipe (0.11.0) ---

    private static function trois_origines(): array {
        return [
            self::fiche(['id' => 1, 'titre' => 'Colloque proposé', 'source' => 'proposition', 'niveau' => 'M']),
            self::fiche(['id' => 2, 'titre' => 'Midi collecté']),
            self::fiche(['id' => 3, 'titre' => 'Saisie maison', 'source' => 'manuel', 'niveau' => 'M']),
            self::fiche(['id' => 4, 'titre' => 'Importé de l’infolettre', 'source' => 'infolettre', 'niveau' => 'M']),
        ];
    }

    public function test_origine_d_une_fiche(): void {
        $this->assertSame(['proposition', 'collecte', 'equipe', 'equipe'], array_map(static fn(Fiche $f): string => $f->origine(), self::trois_origines()));
        $this->assertSame('equipe', self::fiche(['source' => '', 'niveau' => ''])->origine());
    }

    public function test_la_file_distingue_les_propositions_du_public(): void {
        $url = 'https://exemple.test/wp-admin/admin.php?page=rsn-veille';
        $html = PageFile::page_html(self::trois_origines(), 'a_approuver', '', '', $url, self::OPTIONS + ['courriels' => [1 => 'marie@exemple.ca', 2 => 'intrus@exemple.ca']]);
        // Une ligne par fiche, avec son origine en pastille.
        preg_match_all('~<tr id="rsn-veille-fiche-(\d+)"[^>]*data-origine="([a-z]+)"~', $html, $m);
        $this->assertEquals(['1' => 'proposition', '2' => 'collecte', '3' => 'equipe', '4' => 'equipe'], array_combine($m[1], $m[2]));
        $ligne = static function (int $id) use ($html): string {
            preg_match('~<tr id="rsn-veille-fiche-' . $id . '".*?</tr>~s', $html, $l);
            return $l[0] ?? '';
        };
        $proposition = $ligne(1);
        $this->assertStringContainsString('class="rsn-veille-origine" data-origine="proposition"', $proposition);
        $this->assertStringContainsString('Proposition du public', $proposition);
        $this->assertStringContainsString('Formulaire de la page', $proposition);
        // Le courriel du proposant, pour lui répondre : seulement sur sa proposition.
        $this->assertStringContainsString('<a href="mailto:marie@exemple.ca">marie@exemple.ca</a>', $proposition);
        $this->assertStringNotContainsString('intrus@exemple.ca', $html);
        $collecte = $ligne(2);
        $this->assertStringContainsString('Collecte automatique', $collecte);
        $this->assertStringContainsString('Mila : événements', $collecte);
        $this->assertStringContainsString("Saisie par l&#039;équipe", $html);
        // Rappel au-dessus du tableau, avec un lien vers les seules propositions.
        $this->assertStringContainsString('1 proposition du public à traiter', $html);
        $this->assertMatchesRegularExpression('~href="[^"]*statut=a_approuver&amp;origine=proposition"~', $html);
    }

    public function test_filtre_par_origine(): void {
        $fiches = self::trois_origines();
        $this->assertSame([1], self::ids(PageFile::filtrer($fiches, 'a_approuver', '', '', 'proposition')));
        $this->assertSame([2], self::ids(PageFile::filtrer($fiches, 'a_approuver', '', '', 'collecte')));
        $this->assertEqualsCanonicalizing([3, 4], self::ids(PageFile::filtrer($fiches, 'a_approuver', '', '', 'equipe')));
        $this->assertCount(4, PageFile::filtrer($fiches, 'a_approuver', '', '', ''));
        $this->assertCount(4, PageFile::filtrer($fiches, 'a_approuver', '', '', 'inconnue'));

        $url = 'https://exemple.test/wp-admin/admin.php?page=rsn-veille';
        $html = PageFile::page_html($fiches, 'a_approuver', '', '', $url, self::OPTIONS + ['origine' => 'proposition']);
        $this->assertStringContainsString('Colloque proposé', $html);
        $this->assertStringNotContainsString('Midi collecté', $html);
        // Le filtre est dans le formulaire, choisi, et les onglets le gardent.
        $this->assertMatchesRegularExpression('~<select id="rsn-veille-origine" name="origine">.*<option value="proposition" selected~s', $html);
        $this->assertMatchesRegularExpression('~statut=publie&amp;origine=proposition~', $html);
        // Filtré sur les propositions : pas de rappel en double.
        $this->assertStringNotContainsString('à traiter', $html);
    }

    public function test_pas_de_rappel_sans_proposition_ni_hors_de_la_file_a_approuver(): void {
        $url = 'https://exemple.test/wp-admin/admin.php?page=rsn-veille';
        $this->assertStringNotContainsString('à traiter', PageFile::page_html([self::fiche()], 'a_approuver', '', '', $url, self::OPTIONS));
        $publiee = self::fiche(['id' => 1, 'source' => 'proposition', 'niveau' => 'M', 'statut' => 'publie']);
        $this->assertStringNotContainsString('à traiter', PageFile::page_html([$publiee], 'publie', '', '', $url, self::OPTIONS));
    }

    public function test_rappel_des_sources_dont_le_gabarit_est_a_verifier(): void {
        $url = 'https://exemple.test/wp-admin/admin.php?page=rsn-veille';
        $options = self::OPTIONS + ['sources_a_verifier' => ['Obvia : événements', 'SSA <b>', 7], 'url_journal' => 'https://exemple.test/wp-admin/admin.php?page=rsn-veille-journal'];
        $html = PageFile::page_html([self::fiche()], 'a_approuver', '', '', $url, $options);
        $this->assertStringContainsString('<p class="rsn-veille-rappel-gabarit">', $html);
        $this->assertStringContainsString('2 sources à vérifier (gabarit peut-être changé) : Obvia : événements, SSA &lt;b&gt;', $html);
        $this->assertStringContainsString('<a href="https://exemple.test/wp-admin/admin.php?page=rsn-veille-journal">Voir le journal</a>', $html);
        $this->assertLessThan(strpos($html, '<table'), strpos($html, 'rsn-veille-rappel-gabarit'));
        $une = PageFile::page_html([self::fiche()], 'a_approuver', '', '', $url, ['sources_a_verifier' => ['Mila : événements']] + $options);
        $this->assertStringContainsString('1 source à vérifier (gabarit peut-être changé) : Mila : événements', $une);
        // Seulement dans la file à approuver, et seulement s'il y en a.
        $this->assertStringNotContainsString('rsn-veille-rappel-gabarit', PageFile::page_html([self::fiche()], 'publie', '', '', $url, $options));
        $this->assertStringNotContainsString('rsn-veille-rappel-gabarit', PageFile::page_html([self::fiche()], 'a_approuver', '', '', $url, self::OPTIONS));
    }

    // --- Mêmes repères que le Radar : ce qui est peut-être déjà là, ce qu'une infolettre répète (0.13.0) ---

    public function test_etiquette_peut_etre_deja_dans_l_agenda(): void {
        $url = 'https://exemple.test/wp-admin/admin.php?page=rsn-veille';
        $forum = self::fiche(['id' => 10, 'titre' => 'Forum de la santé de Québec', 'debut' => '2026-11-24', 'fin' => '2026-11-25']);
        $publiee = self::fiche(['id' => 2, 'titre' => '20e Forum <b>de la santé</b>', 'debut' => '2026-11-24', 'fin' => '2026-11-25', 'heure' => '', 'statut' => 'publie']);
        $collectee = self::fiche(['id' => 11, 'titre' => 'Colloque du sommeil']);
        $proposee = self::fiche(['id' => 12, 'titre' => 'Colloque sur le sommeil', 'source' => 'proposition', 'niveau' => 'M']);
        $seule = self::fiche(['id' => 13, 'titre' => 'Sans pareille']);
        $options = self::OPTIONS + ['ressemblances' => [10 => $publiee, 11 => $proposee, 12 => $collectee, 99 => 'illisible']];
        $html = PageFile::page_html([$forum, $collectee, $proposee, $seule], 'a_approuver', '', '', $url, $options);
        $ligne = static function (int $id) use ($html): string {
            preg_match('~<tr id="rsn-veille-fiche-' . $id . '".*?</tr>~s', $html, $l);
            return $l[0] ?? '';
        };
        // Ressemble à une fiche publiée : étiquette orange (celle du Radar), lien vers la fiche publiée, ses dates.
        $this->assertStringContainsString('data-ressemble="publie"', $ligne(10));
        $this->assertStringContainsString('<span class="rsn-veille-deja-paru">Peut-être déjà dans l&#039;agenda</span>', $ligne(10));
        $this->assertStringContainsString('<a href="https://exemple.test/wp-admin/post.php?post=2&#038;action=edit">20e Forum &lt;b&gt;de la santé&lt;/b&gt;</a>', str_replace('&amp;', '&#038;', $ligne(10)));
        $this->assertStringContainsString('Du 24 au 25 novembre 2026', $ligne(10));
        // Ressemble à une autre fiche en attente : lien vers sa ligne, dans les deux sens.
        $this->assertStringContainsString('data-ressemble="a_approuver"', $ligne(11));
        $this->assertStringContainsString('<span class="rsn-veille-deja-paru">Ressemble à une autre fiche en attente</span>', $ligne(11));
        $this->assertStringContainsString('<a href="#rsn-veille-fiche-12">Colloque sur le sommeil</a>', $ligne(11));
        $this->assertStringContainsString('<a href="#rsn-veille-fiche-11">Colloque du sommeil</a>', $ligne(12));
        $this->assertStringNotContainsString('data-ressemble', $ligne(13));
        $this->assertStringNotContainsString('rsn-veille-deja-paru', $ligne(13));
        // La fiche pareille est cachée par un filtre : son titre reste, sans lien vers une ligne absente.
        $filtre = PageFile::page_html([$forum, $collectee, $proposee, $seule], 'a_approuver', '', '', $url, $options + ['origine' => 'proposition']);
        $this->assertStringContainsString('Ressemble à une autre fiche en attente</span> Colloque du sommeil', $filtre);
        $this->assertStringNotContainsString('href="#rsn-veille-fiche-11"', $filtre);
        // Seulement dans la file à approuver.
        $publie = PageFile::page_html([self::fiche(['id' => 10, 'statut' => 'publie'])], 'publie', '', '', $url, $options);
        $this->assertStringNotContainsString('data-ressemble', $publie);
        $this->assertStringNotContainsString('data-ressemble', PageFile::page_html([$forum], 'a_approuver', '', '', $url, self::OPTIONS));
    }

    public function test_ressemblances_ne_charge_que_les_fiches_publiees_d_actualite(): void {
        $GLOBALS['rsn_veille_test_aujourdhui'] = '2026-11-20';
        $entrepot = new \RSN\Veille\EntrepotMemoire();
        $base = ['type' => 'evenement', 'debut' => '2026-12-03', 'fin' => '2026-12-03', 'statut' => 'publie', 'source' => 'X', 'niveau' => 'A'];
        $publie = $entrepot->creer(Fiche::depuis_tableau(['titre' => '4e Colloque sur le sommeil et la santé', 'cle_source' => 'x:1', 'cles_source' => ['x:1']] + $base));
        // Appel de l'an passé, encore publié mais échu.
        $entrepot->creer(Fiche::depuis_tableau(['type' => 'appel', 'titre' => 'Bourses de formation postdoctorale', 'debut' => '2025-08-01', 'fin' => '2025-10-01', 'echeance' => '2025-10-01', 'cle_source' => 'x:2', 'cles_source' => ['x:2']] + $base));
        $colloque = $entrepot->creer(Fiche::depuis_tableau(['titre' => 'Colloque du sommeil et de la santé', 'statut' => 'a_approuver', 'cle_source' => 'y:1', 'cles_source' => ['y:1']] + $base));
        $bourses = $entrepot->creer(Fiche::depuis_tableau(['type' => 'appel', 'titre' => 'Bourses de formation postdoctorale 2027-2028', 'debut' => '2026-11-10', 'fin' => '2026-11-10', 'statut' => 'a_approuver', 'cle_source' => 'y:2', 'cles_source' => ['y:2']] + $base));
        $r = PageFile::ressemblances($entrepot, $entrepot->lister('a_approuver'), '2026-11-20');
        $this->assertSame([$colloque => $publie], array_map(static fn(Fiche $f): int => (int) $f->id, $r));
        $this->assertArrayNotHasKey($bourses, $r);
        $this->assertSame([], PageFile::ressemblances($entrepot, [], '2026-11-20'));
    }

    public function test_etiquette_annonce_dans_n_numeros(): void {
        $url = 'https://exemple.test/wp-admin/admin.php?page=rsn-veille';
        $trois = self::fiche(['id' => 20, 'source' => 'SSA : agenda (infolettre)', 'niveau' => 'B', 'cle_source' => 'ssa_agenda:a', 'cles_source' => ['autre:z', 'ssa_agenda:a']]);
        $une = self::fiche(['id' => 21, 'source' => 'SSA : agenda (infolettre)', 'niveau' => 'B', 'cle_source' => 'ssa_agenda:b', 'cles_source' => ['ssa_agenda:b']]);
        $html = PageFile::page_html([$trois, $une], 'a_approuver', '', '', $url, self::OPTIONS + ['mentions' => ['ssa_agenda:a' => 3, 'ssa_agenda:b' => 1, 'ssa_agenda:c' => 5, 'autre:z' => 'x']]);
        $this->assertSame(1, substr_count($html, 'class="rsn-veille-mentions"'));
        $this->assertMatchesRegularExpression('~<tr id="rsn-veille-fiche-20".*?<span class="rsn-veille-mentions"[^>]*>Annoncé dans 3 numéros</span>.*?</tr>~s', $html);
        $this->assertStringNotContainsString('rsn-veille-mentions', PageFile::page_html([$trois], 'a_approuver', '', '', $url, self::OPTIONS));
    }

    public function test_courriel_du_proposant_echappe(): void {
        $url = 'https://exemple.test/wp-admin/admin.php?page=rsn-veille';
        $f = self::fiche(['id' => 1, 'source' => 'proposition', 'niveau' => 'M']);
        $html = PageFile::page_html([$f], 'a_approuver', '', '', $url, self::OPTIONS + ['courriels' => [1 => '"><script>alert(1)</script>']]);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('mailto:', $html);
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

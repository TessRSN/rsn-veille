<?php
declare(strict_types=1);

require_once __DIR__ . '/bouchons-wordpress.php';

use PHPUnit\Framework\TestCase;
use RSN\Veille\Admin\PageInfolettre;
use RSN\Veille\Admin\PageRadar;
use RSN\Veille\Admin\Menu;

final class PageInfolettreTest extends TestCase {
    private const OPTIONS = [
        'aujourdhui' => '2026-10-26', 'nombre' => 8, 'jusqu_au' => null,
        'url_page' => 'https://exemple.test/wp-admin/admin.php', 'url_action' => 'https://exemple.test/wp-admin/admin-post.php',
        'nonce' => 'jeton-rsn_veille_infolettre', 'vide' => false,
    ];

    protected function setUp(): void {
        rsn_veille_bouchons_reinitialiser();
        $_POST = $_REQUEST = $_GET = [];
    }

    protected function tearDown(): void {
        $_POST = $_REQUEST = $_GET = [];
    }

    private static function evenement(array $champs = []): array {
        return $champs + [
            'type' => 'evenement', 'titre' => 'Midi-conférence', 'debut' => '2026-11-12', 'fin' => null, 'organisateur' => 'Mila',
            'lieu' => '', 'ville' => '', 'format' => 'Virtuel', 'prix' => '', 'langue' => 'fr', 'themes' => [], 'resume' => 'Résumé.', 'url' => 'https://mila.test/midi',
        ];
    }

    private static function gardee(): array {
        return ['url' => 'https://inspq.test/n1', 'titre' => 'Une nouvelle', 'date' => '2026-10-20', 'source' => 'INSPQ', 'organisateur' => 'INSPQ', 'resume' => 'R.', 'gardee_le' => '2026-10-25 09:00'];
    }

    // --- Réglages demandés ---

    public function test_etat_d_un_programme_lu_par_l_identifiant_sans_casser_la_page(): void {
        $article = new WP_Post();
        $article->ID = 1234;
        $recu = null;
        // Forme réelle de l'extrait 45 : un tableau ['cle' => …, 'libelle' => …].
        $etat = static function (int $id) use (&$recu): array {
            $recu = $id;
            return ['cle' => 'en-cours', 'libelle' => 'En cours'];
        };
        $this->assertSame(PageInfolettre::ETAT_OUVERT, PageInfolettre::etat_programme($etat, $article));
        $this->assertSame(1234, $recu, "l'identifiant de l'article, pas l'objet");
        $this->assertSame('a-venir', PageInfolettre::etat_programme(static fn(int $id): array => ['cle' => 'a-venir', 'libelle' => 'À venir'], $article));
        // Une chaîne nue reste acceptée.
        $this->assertSame('en-cours', PageInfolettre::etat_programme(static fn(int $id): string => 'en-cours', $article));

        // Signature inattendue ou erreur dans l'extrait du site : état inconnu, la page continue.
        $this->assertSame(PageInfolettre::ETAT_INCONNU, PageInfolettre::etat_programme(static fn(\WP_Post $p): string => 'ouvert', $article));
        $this->assertSame(PageInfolettre::ETAT_INCONNU, PageInfolettre::etat_programme(static function (int $id): string {
            throw new RuntimeException('base indisponible');
        }, $article));
        $this->assertSame(PageInfolettre::ETAT_INCONNU, PageInfolettre::etat_programme(static fn(int $id): array => [], $article));
        $this->assertSame(PageInfolettre::ETAT_INCONNU, PageInfolettre::etat_programme(static fn(int $id): array => ['libelle' => 'En cours'], $article));
        $this->assertSame(PageInfolettre::ETAT_INCONNU, PageInfolettre::etat_programme(static fn(int $id): string => '', $article));
    }

    public function test_nombre_demande_parmi_6_8_10_12(): void {
        $this->assertSame(10, PageInfolettre::nombre_demande('10'));
        $this->assertSame(6, PageInfolettre::nombre_demande(6));
        $this->assertSame(8, PageInfolettre::nombre_demande('7'));
        $this->assertSame(8, PageInfolettre::nombre_demande('abc'));
        $this->assertSame(8, PageInfolettre::nombre_demande(null));
        $this->assertSame(8, PageInfolettre::nombre_demande(['10']));
    }

    public function test_horizon_demande_date_valide_seulement(): void {
        $this->assertSame('2026-12-31', PageInfolettre::horizon_demande('2026-12-31'));
        $this->assertNull(PageInfolettre::horizon_demande('2026-02-30'));
        $this->assertNull(PageInfolettre::horizon_demande(''));
        $this->assertNull(PageInfolettre::horizon_demande('demain'));
        $this->assertNull(PageInfolettre::horizon_demande(['2026-12-31']));
    }

    // --- Rendu ---

    public function test_trois_blocs_avec_apercu_et_boutons(): void {
        $html = PageInfolettre::page_html([self::evenement()], [self::gardee()], [], self::OPTIONS);
        foreach (['agenda', 'appels', 'nouvelles'] as $bloc) {
            $this->assertStringContainsString('data-bloc="' . $bloc . '"', $html);
        }
        $this->assertSame(3, substr_count($html, '<iframe'));
        $this->assertSame(3, substr_count($html, '>Copier</button>'));
        $this->assertSame(3, substr_count($html, '>Copier le code HTML</button>'));
        $this->assertStringContainsString('rsn-veille-infolettre', $html);
        $this->assertStringContainsString('role="status"', $html);
        // Le bloc Appels est vide : ses boutons sont désactivés, pas ceux des blocs garnis.
        preg_match('/<section[^>]*data-bloc="appels".*?<\/section>/s', $html, $appels);
        $this->assertStringContainsString('disabled', $appels[0]);
        preg_match('/<section[^>]*data-bloc="agenda".*?<\/section>/s', $html, $agenda);
        $this->assertStringNotContainsString('disabled', $agenda[0]);
    }

    public function test_apercu_isole_sans_script(): void {
        $html = PageInfolettre::page_html([self::evenement()], [], [], self::OPTIONS);
        preg_match_all('/<iframe[^>]*>/', $html, $cadres);
        foreach ($cadres[0] as $cadre) {
            $this->assertStringContainsString('sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"', $cadre);
            $this->assertStringNotContainsString('allow-scripts', $cadre);
            $this->assertStringContainsString('srcdoc="', $cadre);
        }
        // Le titre et le résumé contenant du HTML restent du texte dans l'aperçu (double échappement dans srcdoc).
        $html = PageInfolettre::page_html([self::evenement(['titre' => '<b>Gras</b> & <script>x</script>'])], [], [], self::OPTIONS);
        $this->assertStringContainsString('&amp;lt;b&amp;gt;Gras&amp;lt;/b&amp;gt;', $html);
        $this->assertStringNotContainsString('<b>Gras', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    public function test_codes_html_et_texte_dans_des_textareas_caches(): void {
        $html = PageInfolettre::page_html([self::evenement(['titre' => 'A & <i>B</i>'])], [], [], self::OPTIONS);
        preg_match('/<section[^>]*data-bloc="agenda".*?<\/section>/s', $html, $agenda);
        $this->assertMatchesRegularExpression('/<textarea[^>]*hidden[^>]*data-role="html"[^>]*>/', $agenda[0]);
        $this->assertMatchesRegularExpression('/<textarea[^>]*hidden[^>]*data-role="texte"[^>]*>/', $agenda[0]);
        preg_match('/<textarea[^>]*data-role="html"[^>]*>(.*?)<\/textarea>/s', $agenda[0], $code);
        $this->assertStringContainsString('&lt;!-- Agenda', $code[1]);
        // Le code contient « A &amp; &lt;i&gt;B&lt;/i&gt; » (échappé pour le bloc), lui-même échappé pour la zone de texte.
        $this->assertStringContainsString('A &amp;amp; &amp;lt;i&amp;gt;B&amp;lt;/i&amp;gt;', $code[1]);
        // Aucune balise de textarea ne peut être refermée par le contenu.
        $this->assertSame(substr_count($agenda[0], '<textarea'), substr_count($agenda[0], '</textarea>'));
        preg_match('/<textarea[^>]*data-role="texte"[^>]*>(.*?)<\/textarea>/s', $agenda[0], $texte);
        $this->assertStringContainsString("12 nov.\nA &amp; &lt;i&gt;B&lt;/i&gt;", $texte[1]);
    }

    public function test_reglages_de_l_agenda_en_get(): void {
        $html = PageInfolettre::page_html([], [], [], ['nombre' => 10, 'jusqu_au' => '2026-12-31'] + self::OPTIONS);
        $this->assertMatchesRegularExpression('/<form method="get" action="https:\/\/exemple\.test\/wp-admin\/admin\.php" class="rsn-veille-reglages-bloc">/', $html);
        $this->assertStringContainsString('<input type="hidden" name="page" value="rsn-veille-infolettre">', $html);
        foreach ([6, 8, 10, 12] as $n) {
            $this->assertStringContainsString('<option value="' . $n . '"' . ($n === 10 ? ' selected' : '') . '>' . $n . '</option>', $html);
        }
        $this->assertStringContainsString('<input type="date" name="jusqu_au" id="rsn-veille-jusqu-au" value="2026-12-31">', $html);
        // Le formulaire de réglage est une requête de lecture : pas de jeton.
        preg_match('/<form method="get".*?<\/form>/s', $html, $reglage);
        $this->assertStringNotContainsString('_wpnonce', $reglage[0]);
    }

    public function test_horizon_applique_a_l_agenda(): void {
        $fiches = [self::evenement(['titre' => 'Proche', 'debut' => '2026-11-02']), self::evenement(['titre' => 'Lointain', 'debut' => '2027-02-02'])];
        $html = PageInfolettre::page_html($fiches, [], [], ['jusqu_au' => '2026-12-31'] + self::OPTIONS);
        $this->assertStringContainsString('Proche', $html);
        $this->assertStringNotContainsString('Lointain', $html);
        $sans = PageInfolettre::page_html($fiches, [], [], self::OPTIONS);
        $this->assertStringContainsString('Lointain', $sans);
    }

    public function test_bouton_vider_en_post_avec_jeton(): void {
        $html = PageInfolettre::page_html([], [self::gardee()], [], self::OPTIONS);
        preg_match('/<form[^>]*class="rsn-veille-vider"[^>]*>.*?<\/form>/s', $html, $formulaire);
        $this->assertStringContainsString('method="post"', $formulaire[0]);
        $this->assertStringContainsString('action="https://exemple.test/wp-admin/admin-post.php"', $formulaire[0]);
        $this->assertStringContainsString('<input type="hidden" name="action" value="rsn_veille_infolettre_vider">', $formulaire[0]);
        $this->assertStringContainsString('<input type="hidden" name="_wpnonce" value="jeton-rsn_veille_infolettre">', $formulaire[0]);
        $this->assertStringContainsString('Vider les nouvelles gardées', $formulaire[0]);
        $this->assertStringNotContainsString('disabled', $formulaire[0]);
        // Sans nouvelle gardée, le bouton est désactivé.
        $vide = PageInfolettre::page_html([], [], [], self::OPTIONS);
        preg_match('/<form[^>]*class="rsn-veille-vider"[^>]*>.*?<\/form>/s', $vide, $formulaire_vide);
        $this->assertStringContainsString('disabled', $formulaire_vide[0]);
    }

    public function test_avis_apres_vidage(): void {
        $this->assertStringContainsString('Nouvelles gardées vidées.', PageInfolettre::page_html([], [], [], ['vide' => true] + self::OPTIONS));
        $this->assertStringNotContainsString('Nouvelles gardées vidées.', PageInfolettre::page_html([], [], [], self::OPTIONS));
    }

    public function test_les_appels_ne_prennent_que_les_appels_des_donnees(): void {
        $appel = ['type' => 'appel', 'titre' => 'Bourse X', 'debut' => '2026-10-01', 'echeance' => '2026-11-20', 'organisateur' => 'FRQ', 'categorie' => 'Bourse', 'resume' => '', 'url' => 'https://frq.test/x'];
        $programme = ['type' => 'appel', 'titre' => 'Programme Y', 'debut' => '2026-10-02', 'echeance' => null, 'organisateur' => 'RSN', 'categorie' => 'Programme du RSN', 'resume' => '', 'url' => 'https://rsn.test/y'];
        $html = PageInfolettre::page_html([self::evenement(), $appel], [], [$programme], self::OPTIONS);
        preg_match('/<section[^>]*data-bloc="appels".*?<\/section>/s', $html, $appels);
        $this->assertStringContainsString('Bourse X', $appels[0]);
        $this->assertStringContainsString('Programme Y', $appels[0]);
        $this->assertStringNotContainsString('Midi-conférence', $appels[0]);
        preg_match('/<section[^>]*data-bloc="agenda".*?<\/section>/s', $html, $agenda);
        $this->assertStringNotContainsString('Bourse X', $agenda[0]);
    }

    // --- Vider les gardées, accès, programmes ---

    public function test_vider_gardees_vide_l_option(): void {
        update_option(PageRadar::OPTION_GARDEES, [self::gardee()], false);
        $this->assertCount(1, PageRadar::lire_gardees());
        PageInfolettre::vider_gardees();
        $this->assertSame([], PageRadar::lire_gardees());
        $this->assertSame([], get_option(PageRadar::OPTION_GARDEES));
    }

    public function test_vider_exige_la_capacite_puis_le_jeton(): void {
        update_option(PageRadar::OPTION_GARDEES, [self::gardee()], false);
        $_REQUEST['_wpnonce'] = 'jeton-rsn_veille_infolettre';
        try {
            PageInfolettre::traiter_vider();
            $this->fail('Sans capacité, la requête doit s’arrêter.');
        } catch (RsnVeilleArretRequete $arret) {
            $this->assertSame(403, $arret->statut_http);
        }
        $GLOBALS['rsn_veille_wp']['capacites'] = [Menu::CAPACITE];
        $_REQUEST['_wpnonce'] = 'mauvais';
        try {
            PageInfolettre::traiter_vider();
            $this->fail('Sans jeton valide, la requête doit s’arrêter.');
        } catch (RsnVeilleArretRequete $arret) {
            $this->assertSame(403, $arret->statut_http);
        }
        $this->assertCount(1, PageRadar::lire_gardees(), 'rien n’est vidé');
    }

    public function test_afficher_refuse_sans_capacite(): void {
        $this->expectException(RsnVeilleArretRequete::class);
        PageInfolettre::afficher();
    }

    public function test_programmes_rsn_sans_la_fonction_du_site_liste_vide(): void {
        $this->assertFalse(function_exists('rsn_appel_etat'));
        $this->assertSame([], PageInfolettre::programmes_rsn());
    }

    public function test_accrochage(): void {
        PageInfolettre::accrocher();
        $this->assertArrayHasKey('admin_post_rsn_veille_infolettre_vider', $GLOBALS['rsn_veille_wp']['actions']);
    }
}

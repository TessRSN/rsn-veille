<?php
declare(strict_types=1);

require_once __DIR__ . '/bouchons-wordpress.php';

use PHPUnit\Framework\TestCase;
use RSN\Veille\Admin\Import;
use RSN\Veille\Admin\Menu;
use RSN\Veille\Admin\NettoyageReglages;
use RSN\Veille\Admin\PageReglages;
use RSN\Veille\Collecte;
use RSN\Veille\Depot;
use RSN\Veille\EntrepotMemoire;
use RSN\Veille\Fiche;
use RSN\Veille\Pertinence;
use RSN\Veille\Sources;

final class PageReglagesTest extends TestCase {
    private const MAQUETTE = __DIR__ . '/../docs/maquette/evenements-infolettres-2025-2026.json';

    protected function setUp(): void {
        rsn_veille_bouchons_reinitialiser();
        $GLOBALS['rsn_veille_wp']['capacites'] = [Menu::CAPACITE];
        $_REQUEST = [];
        $_POST = [];
        $_GET = [];
    }

    /** Codes des messages notés par add_settings_error. */
    private static function codes_erreurs(): array {
        return array_column(rsn_veille_bouchons_erreurs_reglages(), 'code');
    }

    // --- nettoyer ---

    public function test_mots_gardent_les_espaces_de_bordure_en_minuscules_sans_lignes_vides(): void {
        $r = PageReglages::nettoyer(['mots_numerique' => "Numérique\r\n ia \n\n   \nL'IA\r ai-\n Formation"]);
        $this->assertSame(['numérique', ' ia ', "l'ia", ' ai-', ' formation'], $r['mots_numerique']);
        $this->assertSame([], self::codes_erreurs());
    }

    public function test_mots_deja_en_liste_sont_relus_tels_quels(): void {
        $r = PageReglages::nettoyer(['mots_sante' => ['Santé', ' ia ', '', 'care ', 'santé']]);
        $this->assertSame(['santé', ' ia ', 'care '], $r['mots_sante']);
    }

    public function test_themes_nom_deux_points_mots_separes_par_des_virgules(): void {
        $r = PageReglages::nettoyer(['themes' => "IA :  ia , Intelligence artificielle,l'ia\n\nFormation :  formation, atelier\nSans deux-points\n : orphelin\nVide :  , \n"]);
        $this->assertSame([
            ['nom' => 'IA', 'mots' => [' ia ', 'intelligence artificielle', "l'ia"]],
            ['nom' => 'Formation', 'mots' => [' formation', 'atelier']],
        ], $r['themes']);
        // Trois lignes ignorées, chacune signalée.
        $this->assertCount(3, array_filter(self::codes_erreurs(), static fn(string $c): bool => $c === 'rsn_veille_themes'));
    }

    public function test_themes_relus_depuis_le_format_enregistre_et_reaffiches_a_l_identique(): void {
        $themes = Pertinence::config_par_defaut()['themes'];
        $r = PageReglages::nettoyer(['themes' => $themes]);
        $this->assertSame($themes, $r['themes']);
        // Le texte affiché dans la zone de saisie redonne exactement les mêmes thèmes.
        $this->assertSame($themes, PageReglages::nettoyer(['themes' => NettoyageReglages::themes_en_texte($themes)])['themes']);
    }

    public function test_sources_connues_seulement_niveau_invalide_par_defaut(): void {
        $defaut = Sources::par_defaut();
        $r = PageReglages::nettoyer(['sources' => [
            // Case décochée : seul le niveau est envoyé.
            'quebec_ca' => ['niveau' => 'B'],
            'rsn' => ['actif' => '1', 'niveau' => 'Z'],
            'obvia' => ['actif' => '1', 'niveau' => 'M'],
            'inconnue' => ['actif' => '1', 'niveau' => 'A'],
        ]]);
        $this->assertSame(array_keys($defaut), array_keys($r['sources']));
        $this->assertSame(['actif' => false, 'niveau' => 'B'], $r['sources']['quebec_ca']);
        $this->assertSame(['actif' => true, 'niveau' => $defaut['rsn']['niveau']], $r['sources']['rsn']);
        $this->assertSame(['actif' => true, 'niveau' => 'M'], $r['sources']['obvia']);
        // Source absente du formulaire : valeurs par défaut.
        $this->assertSame(['actif' => true, 'niveau' => $defaut['dhc']['niveau']], $r['sources']['dhc']);
        $this->assertSame(['rsn_veille_sources'], self::codes_erreurs());
    }

    public function test_heure_invalide_reprend_03_00(): void {
        $this->assertSame('03:00', PageReglages::nettoyer(['heure_collecte' => '25:00'])['heure_collecte']);
        $this->assertSame(['rsn_veille_heure_collecte'], self::codes_erreurs());
        $this->assertSame('07:05', PageReglages::nettoyer(['heure_collecte' => '7:05'])['heure_collecte']);
        $this->assertSame('03:00', PageReglages::nettoyer([])['heure_collecte']);
        $this->assertCount(1, self::codes_erreurs());
    }

    public function test_courriel_adresses_invalides_retirees_jour_borne(): void {
        $r = PageReglages::nettoyer(['courriel' => ['actif' => '1', 'destinataires' => "a@exemple.test\nn'importe quoi\n  b@exemple.test  \n\na@exemple.test", 'jour' => '9']]);
        $this->assertSame(['actif' => true, 'destinataires' => ['a@exemple.test', 'b@exemple.test'], 'jour' => 1], $r['courriel']);
        $this->assertSame(['rsn_veille_courriel', 'rsn_veille_courriel_jour'], self::codes_erreurs());
        $this->assertSame(['actif' => false, 'destinataires' => [], 'jour' => 0], PageReglages::nettoyer(['courriel' => ['jour' => '0']])['courriel']);
        $this->assertSame(['actif' => false, 'destinataires' => [], 'jour' => 1], PageReglages::nettoyer([])['courriel']);
    }

    public function test_administrateur_toujours_dans_les_roles(): void {
        $this->assertSame(['administrator', 'editor'], PageReglages::nettoyer(['roles' => ['editor', 'inconnu', 'editor']])['roles']);
        $this->assertSame(['administrator'], PageReglages::nettoyer(['roles' => []])['roles']);
        $this->assertSame(['administrator', 'author'], PageReglages::nettoyer(['roles' => ['author', 'administrator']])['roles']);
        // Réglage absent : rôles par défaut.
        $this->assertSame(Menu::ROLES_PAR_DEFAUT, PageReglages::nettoyer([])['roles']);
    }

    public function test_page_veille_doit_etre_une_page(): void {
        $page = wp_insert_post(['post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Veille']);
        $article = wp_insert_post(['post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Article']);
        $this->assertSame($page, PageReglages::nettoyer(['page_veille_id' => (string) $page])['page_veille_id']);
        $this->assertSame(0, PageReglages::nettoyer(['page_veille_id' => (string) $article])['page_veille_id']);
        $this->assertSame(['rsn_veille_page_veille_id'], self::codes_erreurs());
        $this->assertSame(0, PageReglages::nettoyer(['page_veille_id' => ''])['page_veille_id']);
    }

    public function test_nettoyer_est_idempotent_et_ignore_les_cles_inconnues(): void {
        $une_fois = PageReglages::nettoyer([
            'sources' => ['frq' => ['niveau' => 'C']], 'mots_sante' => " santé\nCare ", 'themes' => "IA :  ia ",
            'roles' => ['editor'], 'courriel' => ['actif' => '1', 'destinataires' => 'a@exemple.test', 'jour' => '3'],
            'heure_collecte' => '04:30', 'page_veille_id' => '0', 'pirate' => 'x',
        ]);
        $this->assertArrayNotHasKey('pirate', $une_fois);
        $this->assertSame($une_fois, PageReglages::nettoyer($une_fois));
        $this->assertSame([], self::codes_erreurs());
        $this->assertSame(PageReglages::nettoyer('illisible'), PageReglages::nettoyer([]));
    }

    public function test_mots_bordes_conserves_du_formulaire_a_la_pertinence(): void {
        PageReglages::enregistrer_reglage();
        // Enregistrement par options.php : la valeur passe par le nettoyage.
        update_option(Collecte::OPTION_REGLAGES, ['mots_numerique' => "numérique\n ia ", 'roles' => ['editor']]);
        $this->assertSame(['numérique', ' ia '], Collecte::reglages()['mots_numerique']);

        // Réaffichée dans la zone de saisie, puis renvoyée telle quelle par le navigateur.
        $html = PageReglages::page_html(PageReglages::contexte(Collecte::reglages()));
        $this->assertSame(1, preg_match('/<textarea[^>]*name="rsn_veille_reglages\[mots_numerique\]"[^>]*>(.*?)<\/textarea>/s', $html, $m));
        $renvoye = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        $this->assertSame("numérique\n ia ", $renvoye);
        update_option(Collecte::OPTION_REGLAGES, ['mots_numerique' => $renvoye] + Collecte::reglages());
        $this->assertSame(['numérique', ' ia '], Collecte::reglages()['mots_numerique']);

        // « ia » reste un mot entier : « mai » ne le touche pas.
        $p = Collecte::pertinence();
        $this->assertTrue($p->touche('Atelier sur la IA en santé')['numerique']);
        $this->assertFalse($p->touche('Colloque du 12 mai')['numerique']);
    }

    // --- Après l'enregistrement ---

    public function test_changer_l_heure_replanifie_la_collecte_et_purge(): void {
        PageReglages::accrocher();
        PageReglages::enregistrer_reglage();
        update_option(Collecte::OPTION_REGLAGES, ['heure_collecte' => '03:00', 'roles' => ['author'], 'page_veille_id' => 0]);
        Collecte::planifier_nuit();
        $avant = wp_next_scheduled(Collecte::CROCHET_NUIT);
        $this->assertTrue(get_role('author')->has_cap(Menu::CAPACITE));

        update_option(Collecte::OPTION_REGLAGES, ['heure_collecte' => '05:15'] + Collecte::reglages());
        $apres = wp_next_scheduled(Collecte::CROCHET_NUIT);
        $this->assertNotSame($avant, $apres);
        $this->assertSame('05:15', (new DateTimeImmutable('@' . $apres))->setTimezone(wp_timezone())->format('H:i'));
        $this->assertCount(1, rsn_veille_bouchons_planifies(Collecte::CROCHET_NUIT));
        $this->assertNotEmpty(rsn_veille_bouchons_declenchements('rsn_veille_caches_purges'));

        // Autre réglage changé : l'heure ne bouge pas.
        update_option(Collecte::OPTION_REGLAGES, ['roles' => ['editor']] + Collecte::reglages());
        $this->assertSame($apres, wp_next_scheduled(Collecte::CROCHET_NUIT));
        $this->assertFalse(get_role('author')->has_cap(Menu::CAPACITE));
        $this->assertTrue(get_role('editor')->has_cap(Menu::CAPACITE));
    }

    public function test_capacite_de_la_page_d_options(): void {
        PageReglages::accrocher();
        $this->assertSame(Menu::CAPACITE, apply_filters('option_page_capability_' . PageReglages::GROUPE, 'manage_options'));
    }

    // --- Lancer la collecte ---

    public function test_lancer_la_collecte_met_en_file_et_avance_la_premiere_source(): void {
        update_option(Collecte::OPTION_REGLAGES, ['sources' => ['quebec_ca' => ['actif' => false], 'rsn' => ['actif' => false]]]);
        $n = PageReglages::lancer_collecte();
        $file = get_option(Collecte::OPTION_FILE);
        $this->assertSame(count($file), $n);
        $this->assertNotContains('rsn', $file);
        $planifies = rsn_veille_bouchons_planifies(Collecte::CROCHET_SOURCE);
        $this->assertCount(1, $planifies);
        $this->assertLessThanOrEqual(time(), $planifies[0]['horodatage']);
        $this->assertSame(1, $GLOBALS['rsn_veille_wp']['cron_lances']);
    }

    // --- Import ---

    private static function maquette(): string {
        return (string) file_get_contents(self::MAQUETTE);
    }

    public function test_import_de_la_maquette_puis_rejoue(): void {
        $e = new EntrepotMemoire();
        $r = Import::importer(self::maquette(), new Depot($e), new Pertinence(), '2026-10-26');
        $this->assertSame(['lus' => 24, 'crees' => 24, 'fusionnes' => 0, 'mis_a_jour' => 0, 'inchanges' => 0, 'erreurs' => []], $r);
        $this->assertCount(24, $e->lister('publie', 'evenement'));

        $gala = $e->trouver_par_cle_source('infolettre:' . md5('2025-10-16Gala du Prix Hippocrate 2025'));
        $this->assertNotNull($gala);
        $this->assertSame(
            ['M', 'infolettre', 'publie', 'Prix Hippocrate', ['Innovation', 'Réseautage'], 'Québec', 'Présentiel', 'fr', [$gala->cle_source]],
            [$gala->niveau, $gala->source, $gala->statut, $gala->organisateur, $gala->themes, $gala->region, $gala->format, $gala->langue, $gala->cles_source]
        );
        $this->assertSame('https://mailchi.mp/f296f98d0b65/b67j7bewg8-51261', $gala->url);

        // Région et langue fournies par le fichier.
        $entrees = json_decode(self::maquette(), true);
        $source = array_values(array_filter($entrees, static fn(array $x): bool => $x['debut'] === '2025-10-29'))[0];
        $open = $e->trouver_par_cle_source('infolettre:' . md5($source['debut'] . $source['titre']));
        $this->assertSame(['Hors Québec', 'en'], [$open->region, $open->langue]);

        $r2 = Import::importer(self::maquette(), new Depot($e), new Pertinence(), '2026-10-26');
        $this->assertSame(['lus' => 24, 'crees' => 0, 'fusionnes' => 0, 'mis_a_jour' => 0, 'inchanges' => 24, 'erreurs' => []], $r2);
        $this->assertCount(24, $e->lister(''));
    }

    public function test_import_fusionne_un_evenement_rsn_deja_collecte(): void {
        $e = new EntrepotMemoire();
        $rsn = Fiche::depuis_tableau([
            'type' => 'evenement', 'titre' => 'Hackathon en santé numérique 2025', 'debut' => '2025-11-13', 'organisateur' => 'RSN',
            'source' => 'rsn', 'niveau' => 'A', 'statut' => 'publie', 'cle_source' => 'rsn:42', 'cles_source' => ['rsn:42'],
            'url' => 'https://rsn.quebec/evenement/hackathon-2025/',
        ]);
        $e->creer($rsn);
        $r = Import::importer(self::maquette(), new Depot($e), new Pertinence(), '2026-10-26');
        $this->assertSame([24, 23, 1, []], [$r['lus'], $r['crees'], $r['fusionnes'], $r['erreurs']]);
        $x = $e->trouver_par_cle_source('rsn:42');
        $this->assertSame(['A', 'rsn', 'https://rsn.quebec/evenement/hackathon-2025/'], [$x->niveau, $x->source, $x->url]);
        $this->assertContains('infolettre:' . md5('2025-11-13Hackathon en santé numérique 2025'), $x->cles_source);
        $this->assertCount(24, $e->lister(''));

        $r2 = Import::importer(self::maquette(), new Depot($e), new Pertinence(), '2026-10-26');
        $this->assertSame([0, 0, 24], [$r2['crees'], $r2['fusionnes'], $r2['inchanges']]);
    }

    public function test_entree_invalide_signalee_les_autres_importees(): void {
        $entrees = [
            ['titre' => 'Atelier données', 'debut' => '2026-11-20', 'organisateur' => 'Obvia', 'ville' => 'Québec', 'resume' => 'Atelier sur les données de santé.'],
            ['titre' => 'Sans organisateur', 'debut' => '2026-11-21'],
            ['titre' => 'Date illisible', 'debut' => '21 novembre', 'organisateur' => 'Mila'],
            'pas un objet',
            ['titre' => 'Webinaire IA', 'debut' => '2026-11-22', 'heure' => '9:30', 'organisateur' => 'Mila', 'lieu' => 'Zoom', 'url' => 'javascript:alert(1)', 'etiquettes' => []],
        ];
        $e = new EntrepotMemoire();
        $r = Import::importer((string) json_encode($entrees), new Depot($e), new Pertinence(), '2026-10-26');
        $this->assertSame([5, 2, 0, 0], [$r['lus'], $r['crees'], $r['fusionnes'], $r['inchanges']]);
        $this->assertCount(3, $r['erreurs']);
        $this->assertStringContainsString('2', $r['erreurs'][0]);

        $atelier = $e->trouver_par_cle_source('infolettre:' . md5('2026-11-20Atelier données'));
        // Sans étiquettes, les thèmes viennent des règles de pertinence ; région et format devinés.
        $this->assertSame([['Données', 'Formation'], 'Québec', 'Présentiel'], [$atelier->themes, $atelier->region, $atelier->format]);
        $webinaire = $e->trouver_par_cle_source('infolettre:' . md5('2026-11-22Webinaire IA'));
        $this->assertSame(['09:30', 'Virtuel', 'En ligne', ''], [$webinaire->heure, $webinaire->format, $webinaire->region, $webinaire->url]);
    }

    public function test_fichier_illisible(): void {
        $r = Import::importer('{pas du json', new Depot(new EntrepotMemoire()), new Pertinence(), '2026-10-26');
        $this->assertSame([0, 0], [$r['lus'], $r['crees']]);
        $this->assertCount(1, $r['erreurs']);
        $this->assertCount(1, Import::importer('{"titre": "objet seul"}', new Depot(new EntrepotMemoire()), new Pertinence(), '2026-10-26')['erreurs']);
    }

    public function test_verification_du_fichier_televerse(): void {
        $ok = ['name' => 'evenements.JSON', 'type' => 'application/json', 'tmp_name' => '/tmp/x', 'error' => UPLOAD_ERR_OK, 'size' => 1000];
        $this->assertNull(PageReglages::erreur_fichier($ok));
        $this->assertNotNull(PageReglages::erreur_fichier(null));
        $this->assertNotNull(PageReglages::erreur_fichier(['name' => 'a.csv'] + $ok));
        $this->assertNotNull(PageReglages::erreur_fichier(['size' => 3 * 1024 * 1024] + $ok));
        $this->assertNotNull(PageReglages::erreur_fichier(['error' => UPLOAD_ERR_INI_SIZE] + $ok));
        $this->assertNotNull(PageReglages::erreur_fichier(['error' => UPLOAD_ERR_NO_FILE] + $ok));
    }

    // --- Page ---

    public function test_la_liste_des_pages_inclut_les_privees(): void {
        foreach (['publish' => 'Veille publiée', 'draft' => 'Veille brouillon', 'private' => 'Veille privée', 'trash' => 'Veille corbeille'] as $statut => $titre) {
            wp_insert_post(['post_type' => 'page', 'post_status' => $statut, 'post_title' => $titre, 'post_date' => '2026-01-05 10:00:00']);
        }
        $html = PageReglages::page_html(PageReglages::contexte(Collecte::reglages()));
        $this->assertSame(1, preg_match('/<select id="rsn-veille-page".*?<\/select>/s', $html, $m));
        $this->assertStringContainsString('Veille publiée', $m[0]);
        $this->assertStringContainsString('Veille brouillon', $m[0]);
        $this->assertStringContainsString('Veille privée', $m[0]);
        $this->assertStringNotContainsString('Veille corbeille', $m[0]);
    }

    public function test_page_echappe_et_montre_l_etat_de_la_collecte(): void {
        $contexte = PageReglages::contexte(['themes' => [['nom' => 'Données "ouvertes" <b>&</b> co', 'mots' => ['"x"']]]]);
        $contexte['derniere'] = strtotime('2026-10-26 03:05:00');
        $contexte['prochaine'] = false;
        $contexte['cron_desactive'] = true;
        $contexte['message'] = ['niveau' => 'success', 'texte' => 'Import <terminé>', 'details' => ['<script>']];
        $html = PageReglages::page_html($contexte);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('>Données &quot;ouvertes&quot; &amp; co : &quot;x&quot;</textarea>', $html);
        $this->assertStringContainsString('wp-cron.php', $html);
        $this->assertStringContainsString('name="_wpnonce" value="jeton-rsn_veille_actions"', $html);
        $this->assertStringContainsString('enctype="multipart/form-data"', $html);
        // Administrateur coché, verrouillé, et envoyé par un champ caché.
        $this->assertMatchesRegularExpression('/value="administrator"[^>]*checked[^>]*disabled/', $html);
        $this->assertStringContainsString('<input type="hidden" name="rsn_veille_reglages[roles][]" value="administrator">', $html);
    }
}

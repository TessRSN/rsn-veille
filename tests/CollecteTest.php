<?php
declare(strict_types=1);

require_once __DIR__ . '/bouchons-wordpress.php';

use PHPUnit\Framework\TestCase;
use RSN\Veille\Collecte;
use RSN\Veille\Depot;
use RSN\Veille\EntrepotMemoire;
use RSN\Veille\EntrepotWordPress;
use RSN\Veille\Fiche;
use RSN\Veille\Journal;
use RSN\Veille\Lecteurs;
use RSN\Veille\Lecteurs\LecteurTec;
use RSN\Veille\Lecteurs\Resultat;
use RSN\Veille\Pertinence;
use RSN\Veille\ResolutionIvado;
use RSN\Veille\Sources;

final class CollecteTest extends TestCase {
    /** URL demandées au faux client HTTP, dans l'ordre. */
    private array $demandes = [];

    protected function setUp(): void {
        rsn_veille_bouchons_reinitialiser();
        Collecte::accrocher();
        $GLOBALS['rsn_veille_test_aujourdhui'] = '2026-10-25';
        Collecte::$obtenir_pour_tests = null;
        $this->demandes = [];
    }

    protected function tearDown(): void {
        Collecte::$obtenir_pour_tests = null;
    }

    private static function fixture(string $nom): string {
        return (string) file_get_contents(FIXTURES . '/' . $nom);
    }

    private static function reponse(string $type, string $corps, string $url_finale = '', int $code = 200, ?string $erreur = null): array {
        return ['corps' => $corps, 'type' => $type, 'code' => $code, 'url_finale' => $url_finale, 'erreur' => $erreur];
    }

    private static function lire_dhc(): Resultat {
        return (new LecteurTec())->lire(self::fixture('dhc.json'), Sources::par_defaut()['dhc']);
    }

    private static function fiche(array $champs): Fiche {
        return Fiche::depuis_tableau($champs + ['type' => 'evenement', 'debut' => '2026-11-15', 'niveau' => 'C']);
    }

    private static function resultat(Fiche ...$fiches): Resultat {
        $r = new Resultat();
        $r->fiches = $fiches;
        return $r;
    }

    // --- integrer_resultat (pur) ---

    public function test_dhc_18_creees_puis_aucune_seconde_fois(): void {
        $entrepot = new EntrepotMemoire();
        $depot = new Depot($entrepot);
        $source = Sources::par_defaut()['dhc'];

        $premiere = Collecte::integrer_resultat(self::lire_dhc(), $source, $depot, new Pertinence());
        $this->assertSame(['crees' => 18, 'fusionnes' => 0, 'mis_a_jour' => 0, 'inchanges' => 0, 'ecartes' => 0, 'annules' => 0], $premiere);
        $this->assertCount(18, $entrepot->lister('publie'));

        $seconde = Collecte::integrer_resultat(self::lire_dhc(), $source, new Depot($entrepot), new Pertinence());
        $this->assertSame(['crees' => 0, 'fusionnes' => 0, 'mis_a_jour' => 0, 'inchanges' => 18, 'ecartes' => 0, 'annules' => 0], $seconde);
        $this->assertCount(18, $entrepot->lister(''));
    }

    public function test_hackathon_ecohull_de_source_numerique_a_approuver(): void {
        $entrepot = new EntrepotMemoire();
        $source = Sources::par_defaut()['mila'];
        $fiche = self::fiche(['titre' => 'Hackathon EcoHull', 'cle_source' => 'mila:1', 'url' => 'https://mila.quebec/fr/ecohull']);
        $compteurs = Collecte::integrer_resultat(self::resultat($fiche), $source, new Depot($entrepot), new Pertinence());
        $this->assertSame(1, $compteurs['crees']);
        $enregistree = $entrepot->trouver_par_cle_source('mila:1');
        $this->assertSame('a_approuver', $enregistree->statut);
    }

    public function test_fiche_generaliste_sans_mot_cle_ecartee_et_non_ecrite(): void {
        $entrepot = new EntrepotMemoire();
        $source = Sources::par_defaut()['frq_evenements'];
        $fiche = self::fiche(['titre' => 'Assemblée générale annuelle des membres', 'cle_source' => 'frq_evenements:9', 'url' => 'https://frq.gouv.qc.ca/agm']);
        $compteurs = Collecte::integrer_resultat(self::resultat($fiche), $source, new Depot($entrepot), new Pertinence());
        $this->assertSame(1, $compteurs['ecartes']);
        $this->assertSame(0, $compteurs['crees']);
        $this->assertSame([], $entrepot->lister(''));
    }

    public function test_themes_poses_a_la_creation(): void {
        $entrepot = new EntrepotMemoire();
        $source = Sources::par_defaut()['mila'];
        $fiche = self::fiche(['titre' => 'Atelier sur l’IA en santé', 'resume' => 'Gouvernance des données.', 'cle_source' => 'mila:2', 'url' => 'https://mila.quebec/fr/atelier']);
        Collecte::integrer_resultat(self::resultat($fiche), $source, new Depot($entrepot), new Pertinence());
        $enregistree = $entrepot->trouver_par_cle_source('mila:2');
        $this->assertSame('publie', $enregistree->statut);
        $this->assertSame(['IA', 'Données'], $enregistree->themes);
    }

    public function test_statut_et_themes_jamais_reecrits_sur_une_fiche_existante(): void {
        $entrepot = new EntrepotMemoire();
        $source = Sources::par_defaut()['dhc'];
        Collecte::integrer_resultat(self::lire_dhc(), $source, new Depot($entrepot), new Pertinence());
        $fiche = $entrepot->lister('publie')[0];
        $entrepot->mettre_a_jour($fiche->id, ['statut' => 'ecarte', 'themes' => ['Éthique']]);

        Collecte::integrer_resultat(self::lire_dhc(), $source, new Depot($entrepot), new Pertinence());
        $apres = $entrepot->trouver_par_cle_source($fiche->cle_source !== '' ? $fiche->cle_source : $fiche->cles_source[0]);
        $this->assertSame('ecarte', $apres->statut);
        $this->assertSame(['Éthique'], $apres->themes);
    }

    public function test_doublon_d_une_autre_source_fusionne_meme_si_la_source_est_generaliste(): void {
        $entrepot = new EntrepotMemoire();
        $existante = self::fiche(['titre' => 'Assemblée générale annuelle des membres', 'cle_source' => 'rsn:4', 'cles_source' => ['rsn:4'], 'niveau' => 'A', 'statut' => 'publie']);
        $entrepot->creer($existante);
        $entrante = self::fiche(['titre' => 'Assemblée générale annuelle des membres', 'cle_source' => 'frq_evenements:4', 'niveau' => 'C']);
        $compteurs = Collecte::integrer_resultat(self::resultat($entrante), Sources::par_defaut()['frq_evenements'], new Depot($entrepot), new Pertinence());
        $this->assertSame(['crees' => 0, 'fusionnes' => 1, 'mis_a_jour' => 0, 'inchanges' => 0, 'ecartes' => 0, 'annules' => 0], $compteurs);
        $this->assertSame('publie', $entrepot->trouver_par_cle_source('frq_evenements:4')->statut);
    }

    public function test_annulation_comptee(): void {
        $entrepot = new EntrepotMemoire();
        $source = Sources::par_defaut()['dhc'];
        $fiche = self::fiche(['titre' => 'Forum santé numérique', 'cle_source' => 'dhc:5', 'niveau' => 'A', 'url' => 'https://x.test/forum']);
        Collecte::integrer_resultat(self::resultat($fiche), $source, new Depot($entrepot), new Pertinence());
        $annulee = self::fiche(['titre' => 'ANNULÉ : Forum santé numérique', 'cle_source' => 'dhc:5', 'niveau' => 'A', 'url' => 'https://x.test/forum']);
        $compteurs = Collecte::integrer_resultat(self::resultat($annulee), $source, new Depot($entrepot), new Pertinence());
        $this->assertSame([1, 1], [$compteurs['mis_a_jour'], $compteurs['annules']]);
        $this->assertSame('a_approuver', $entrepot->trouver_par_cle_source('dhc:5')->statut);
    }

    // --- Résolution IVADO ---

    public function test_resolution_ivado_limitee_a_25_et_fiches_connues_non_redemandees(): void {
        $entrepot = new EntrepotMemoire();
        $connue = self::fiche(['titre' => 'Déjà connue', 'cle_source' => 'ivado:1', 'cles_source' => ['ivado:1'], 'url' => 'https://ivado.ca/evenements/connue/', 'statut' => 'publie']);
        $connue->titres_source = ['ivado:1' => 'Déjà connue'];
        $entrepot->creer($connue);

        $fiches = [];
        for ($id = 1; $id <= 30; $id++) {
            $fiches[] = self::fiche(['titre' => '', 'cle_source' => 'ivado:' . $id, 'url' => 'https://ivado.ca/?p=' . $id]);
        }
        $obtenir = function (string $url, array $options): array {
            $this->demandes[] = $url;
            $id = (int) substr($url, strlen('https://ivado.ca/?p='));
            if ($id === 2) {
                return self::reponse('text/html', '<html><body>Sans titre</body></html>', 'https://ivado.ca/evenements/2/');
            }
            if ($id === 3) {
                return self::reponse('text/html', '', $url, 404, 'Réponse HTTP 404');
            }
            return self::reponse('text/html', '<html><head><title>Événement ' . $id . ' | IVADO</title></head></html>', 'https://ivado.ca/evenements/' . $id . '/');
        };

        $resolution = ResolutionIvado::resoudre($fiches, ['id' => 'ivado'], new Depot($entrepot), $obtenir);

        $this->assertCount(25, $this->demandes);
        $this->assertNotContains('https://ivado.ca/?p=1', $this->demandes);
        $this->assertSame('https://ivado.ca/?p=26', end($this->demandes));
        $this->assertSame(4, $resolution['en_attente']);
        $this->assertCount(2, $resolution['erreurs']);
        $this->assertCount(24, $resolution['fiches']);
        $par_cle = [];
        foreach ($resolution['fiches'] as $f) {
            $par_cle[$f->cle_source] = $f;
        }
        $this->assertSame(['Déjà connue', 'https://ivado.ca/evenements/connue/'], [$par_cle['ivado:1']->titre, $par_cle['ivado:1']->url]);
        $this->assertSame(['Événement 4', 'https://ivado.ca/evenements/4/'], [$par_cle['ivado:4']->titre, $par_cle['ivado:4']->url]);
        $this->assertArrayNotHasKey('ivado:2', $par_cle);
        $this->assertArrayNotHasKey('ivado:3', $par_cle);
    }

    public function test_resolution_ivado_exception_d_une_page_non_fatale(): void {
        $fiches = [
            self::fiche(['titre' => '', 'cle_source' => 'ivado:1', 'url' => 'https://ivado.ca/?p=1']),
            self::fiche(['titre' => '', 'cle_source' => 'ivado:2', 'url' => 'https://ivado.ca/?p=2']),
        ];
        $obtenir = static function (string $url): array {
            if (str_ends_with($url, '=1')) {
                throw new RuntimeException('délai dépassé');
            }
            return self::reponse('text/html', '<title>Deux | IVADO</title>', 'https://ivado.ca/evenements/deux/');
        };
        $resolution = ResolutionIvado::resoudre($fiches, ['id' => 'ivado'], new Depot(new EntrepotMemoire()), $obtenir);
        $this->assertCount(1, $resolution['fiches']);
        $this->assertSame('Deux', $resolution['fiches'][0]->titre);
        $this->assertStringContainsString('délai dépassé', $resolution['erreurs'][0]);
    }

    // --- Configuration ---

    public function test_lecteur_selon_le_type(): void {
        $attendus = [
            'tec' => Lecteurs\LecteurTec::class, 'rss' => Lecteurs\LecteurRss::class, 'ical' => Lecteurs\LecteurIcal::class,
            'html_obvia' => Lecteurs\LecteurObvia::class, 'html_mila' => Lecteurs\LecteurMila::class,
            'html_frq' => Lecteurs\LecteurFrqEvenements::class, 'html_ivado' => Lecteurs\LecteurIvado::class,
        ];
        foreach ($attendus as $type => $classe) {
            $this->assertInstanceOf($classe, Collecte::lecteur_pour(['type' => $type]));
        }
        $this->expectException(InvalidArgumentException::class);
        Collecte::lecteur_pour(['type' => 'inconnu']);
    }

    public function test_pertinence_depuis_les_reglages_listes_vides_ignorees(): void {
        update_option(Collecte::OPTION_REGLAGES, [
            'mots_sante' => [],
            'themes' => [['nom' => 'Essai', 'mots' => ['hackathon']]],
        ]);
        $p = Collecte::pertinence();
        $this->assertSame(['Essai'], $p->themes('Grand hackathon'));
        $this->assertSame('publie', $p->statut('numerique', 'Journée santé et hackathon'));
    }

    public function test_sources_actives_selon_les_reglages(): void {
        update_option(Collecte::OPTION_REGLAGES, ['sources' => ['dhc' => ['actif' => false], 'mila' => ['niveau' => 'A']]]);
        $sources = Collecte::sources();
        $this->assertArrayNotHasKey('dhc', $sources);
        $this->assertSame('A', $sources['mila']['niveau']);
    }

    public function test_prochaine_collecte_a_l_heure_de_montreal(): void {
        // 26 octobre 2026, 12 h à Montréal (UTC-4) : prochaine collecte le 27 à 3 h, soit 7 h UTC.
        $midi = (new DateTimeImmutable('2026-10-26 12:00', new DateTimeZone('America/Toronto')))->getTimestamp();
        $this->assertSame('2026-10-27 07:00', gmdate('Y-m-d H:i', Collecte::prochaine_collecte($midi)));
        // 1 h du matin : le jour même.
        $nuit = (new DateTimeImmutable('2026-10-26 01:00', new DateTimeZone('America/Toronto')))->getTimestamp();
        $this->assertSame('2026-10-26 07:00', gmdate('Y-m-d H:i', Collecte::prochaine_collecte($nuit)));
        // Heure des réglages ; heure illisible = 3 h.
        update_option(Collecte::OPTION_REGLAGES, ['heure_collecte' => '22:30']);
        $this->assertSame('2026-10-27 02:30', gmdate('Y-m-d H:i', Collecte::prochaine_collecte($midi)));
        update_option(Collecte::OPTION_REGLAGES, ['heure_collecte' => '25:99']);
        $this->assertSame('2026-10-27 07:00', gmdate('Y-m-d H:i', Collecte::prochaine_collecte($midi)));
    }

    public function test_planifier_la_nuit_une_seule_fois_puis_tout_deplanifier(): void {
        Collecte::planifier_nuit();
        Collecte::planifier_nuit();
        $nuit = rsn_veille_bouchons_planifies(Collecte::CROCHET_NUIT);
        $this->assertCount(1, $nuit);
        $this->assertSame('daily', $nuit[0]['recurrence']);
        $this->assertGreaterThan(time(), $nuit[0]['horodatage']);

        Collecte::mettre_en_file();
        Collecte::deplanifier();
        $this->assertFalse(wp_next_scheduled(Collecte::CROCHET_NUIT));
        $this->assertFalse(wp_next_scheduled(Collecte::CROCHET_SOURCE));
    }

    public function test_mettre_en_file_recale_la_collecte_quotidienne(): void {
        // Collecte quotidienne décalée d'une heure (changement d'heure) : replacée à 3 h, heure de Montréal.
        wp_schedule_event(Collecte::prochaine_collecte() + 3600, 'daily', Collecte::CROCHET_NUIT);
        Collecte::mettre_en_file();
        $nuit = rsn_veille_bouchons_planifies(Collecte::CROCHET_NUIT);
        $this->assertCount(1, $nuit);
        $this->assertSame(Collecte::prochaine_collecte(), $nuit[0]['horodatage']);
        $this->assertSame('daily', $nuit[0]['recurrence']);
        $this->assertSame('03:00', (new DateTimeImmutable('@' . $nuit[0]['horodatage']))->setTimezone(new DateTimeZone('America/Toronto'))->format('H:i'));
    }

    // --- File de collecte (faux WordPress) ---

    public function test_mettre_en_file_toutes_les_sources_actives_sauf_les_nouvelles(): void {
        Collecte::mettre_en_file();
        $this->assertSame(
            ['rsn', 'dhc', 'rqrv', 'sommeil', 'obvia', 'mila', 'frq_evenements', 'ivado', 'frq_appels', 'crm_appels', 'rsn_appels', 'cares_appels', 'hippocrate_appels'],
            get_option(Collecte::OPTION_FILE)
        );
        $prochaine = wp_next_scheduled(Collecte::CROCHET_SOURCE);
        $this->assertGreaterThanOrEqual(time() + 55, $prochaine);
        $this->assertLessThanOrEqual(time() + 65, $prochaine);
    }

    /** Seules ces sources restent actives. */
    private static function activer_seulement(array $ids, array $reglages = []): void {
        $sources = [];
        foreach (array_keys(Sources::par_defaut()) as $id) {
            $sources[$id] = ['actif' => in_array($id, $ids, true)];
        }
        update_option(Collecte::OPTION_REGLAGES, ['sources' => $sources] + $reglages);
    }

    /** Faux client HTTP des sources dhc, sommeil et ivado. */
    private function brancher_http(): void {
        $sources = Sources::par_defaut();
        $pages = [
            $sources['dhc']['url'] => self::reponse('application/json; charset=UTF-8', self::fixture('dhc.json')),
            // Piège : page HTML en 200 à la place du flux.
            $sources['sommeil']['url'] => self::reponse('text/html; charset=UTF-8', '<!DOCTYPE html><html><head><title>Accueil</title></head></html>'),
            $sources['ivado']['url'] => self::reponse('text/html; charset=UTF-8', self::fixture('ivado.html')),
            $sources['ivado']['flux_evenements_url'] => self::reponse('application/rss+xml; charset=UTF-8', self::fixture('ivado_ev.xml')),
        ];
        Collecte::$obtenir_pour_tests = function (string $url, array $options = []) use ($pages): array {
            $this->demandes[] = $url;
            if (isset($pages[$url])) {
                return $pages[$url] + ['url_finale' => $url];
            }
            if (preg_match('#^https://ivado\.ca/\?p=(\d+)$#', $url, $m) === 1) {
                $titre = $m[1] === '205515' ? '' : '<title>Événement ' . $m[1] . ' | IVADO</title>';
                return self::reponse('text/html', '<html><head>' . $titre . '</head><body></body></html>', 'https://ivado.ca/evenements/' . $m[1] . '/');
            }
            return self::reponse('', '', $url, 404, 'Réponse HTTP 404');
        };
    }

    public function test_traiter_les_sources_une_a_une_jusqu_a_la_fin(): void {
        self::activer_seulement(['dhc', 'sommeil', 'ivado'], ['page_veille_id' => 42]);
        update_post_meta(42, '_elementor_element_cache', 'vieux rendu');
        $this->brancher_http();
        Collecte::mettre_en_file();
        $this->assertSame(['dhc', 'sommeil', 'ivado'], get_option(Collecte::OPTION_FILE));

        // 1. DHC : 18 fiches créées et publiées, page Veille purgée, source suivante planifiée.
        $this->assertTrue(rsn_veille_bouchons_executer_cron(Collecte::CROCHET_SOURCE));
        $this->assertSame(['sommeil', 'ivado'], get_option(Collecte::OPTION_FILE));
        $dhc = Journal::derniere('dhc');
        $this->assertSame([18, 18, 0, 0, 0, 0, 0, []], [$dhc['lus'], $dhc['crees'], $dhc['fusionnes'], $dhc['mis_a_jour'], $dhc['inchanges'], $dhc['ecartes'], $dhc['ignores'], $dhc['erreurs']]);
        $this->assertIsInt($dhc['duree_ms']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $dhc['date']);
        $this->assertCount(18, (new EntrepotWordPress())->lister('publie'));
        $this->assertSame([[42]], rsn_veille_bouchons_declenchements('litespeed_purge_post'));
        $this->assertSame('', get_post_meta(42, '_elementor_element_cache', true));
        $prochaine = wp_next_scheduled(Collecte::CROCHET_SOURCE);
        $this->assertGreaterThanOrEqual(time() + 115, $prochaine);
        $this->assertFalse(get_option(Collecte::OPTION_DERNIERE));

        // 2. Sommeil : page HTML au lieu du flux, consignée en erreur, rien d'écrit.
        $this->assertTrue(rsn_veille_bouchons_executer_cron(Collecte::CROCHET_SOURCE));
        $sommeil = Journal::derniere('sommeil');
        $this->assertSame(0, $sommeil['lus']);
        $this->assertStringContainsString('Contenu inattendu (type text/html', $sommeil['erreurs'][0]);
        $this->assertCount(1, rsn_veille_bouchons_declenchements('litespeed_purge_post'));
        $this->assertNotFalse(wp_next_scheduled(Collecte::CROCHET_SOURCE));

        // 3. IVADO : fiches résolues par leur page, la fiche sans titre écartée avec une erreur ; fin de la collecte.
        $this->assertTrue(rsn_veille_bouchons_executer_cron(Collecte::CROCHET_SOURCE));
        $ivado = Journal::derniere('ivado');
        $this->assertSame([6, 5], [$ivado['lus'], $ivado['crees']]);
        $this->assertCount(1, $ivado['erreurs']);
        $this->assertStringContainsString('205515', $ivado['erreurs'][0]);
        $this->assertContains('https://ivado.ca/evenements/feed/', $this->demandes);
        $this->assertContains('https://ivado.ca/?p=205434', $this->demandes);
        $resolue = (new EntrepotWordPress())->trouver_par_cle_source('ivado:205434');
        $this->assertSame(['Événement 205434', 'https://ivado.ca/evenements/205434/', 'a_approuver'], [$resolue->titre, $resolue->url, $resolue->statut]);

        $this->assertSame([], get_option(Collecte::OPTION_FILE));
        $this->assertFalse(wp_next_scheduled(Collecte::CROCHET_SOURCE));
        $this->assertFalse(rsn_veille_bouchons_executer_cron(Collecte::CROCHET_SOURCE));
        $this->assertIsInt(get_option(Collecte::OPTION_DERNIERE));
        $this->assertCount(1, rsn_veille_bouchons_declenchements('rsn_veille_apres_collecte'));
        $this->assertGreaterThanOrEqual(3, count(rsn_veille_bouchons_declenchements('litespeed_purge_post')));
        $this->assertFalse(get_transient(Collecte::VERROU));
    }

    public function test_exception_d_une_source_consignee_et_file_poursuivie(): void {
        self::activer_seulement(['dhc', 'sommeil']);
        Collecte::$obtenir_pour_tests = static function (string $url): array {
            throw new RuntimeException('connexion refusée');
        };
        Collecte::mettre_en_file();
        rsn_veille_bouchons_executer_cron(Collecte::CROCHET_SOURCE);
        $this->assertStringContainsString('connexion refusée', Journal::derniere('dhc')['erreurs'][0]);
        $this->assertSame(['sommeil'], get_option(Collecte::OPTION_FILE));
        $this->assertNotFalse(wp_next_scheduled(Collecte::CROCHET_SOURCE));
        $this->assertFalse(get_transient(Collecte::VERROU));
    }

    public function test_erreur_http_consignee_sans_compter_d_absence(): void {
        self::activer_seulement(['dhc']);
        $entrepot = new EntrepotWordPress();
        $publiee = self::fiche(['titre' => 'Déjà publiée', 'cle_source' => 'dhc:1', 'cles_source' => ['dhc:1'], 'niveau' => 'A', 'statut' => 'publie', 'debut' => '2026-12-01']);
        $id = $entrepot->creer($publiee);
        Collecte::$obtenir_pour_tests = static fn(string $url): array => self::reponse('text/html', 'Accès refusé', $url, 403, 'Réponse HTTP 403');
        Collecte::mettre_en_file();
        rsn_veille_bouchons_executer_cron(Collecte::CROCHET_SOURCE);
        $this->assertSame(['Réponse HTTP 403'], Journal::derniere('dhc')['erreurs']);
        $this->assertSame(0, $entrepot->obtenir($id)->absences);
    }

    public function test_source_inconnue_ou_desactivee_consignee(): void {
        self::activer_seulement([]);
        update_option(Collecte::OPTION_FILE, ['disparue']);
        Collecte::traiter_une_source();
        $this->assertNotEmpty(Journal::derniere('disparue')['erreurs']);
        $this->assertIsInt(get_option(Collecte::OPTION_DERNIERE));
    }

    public function test_verrou_empeche_deux_traitements_simultanes(): void {
        self::activer_seulement(['dhc']);
        $this->brancher_http();
        Collecte::mettre_en_file();
        wp_clear_scheduled_hook(Collecte::CROCHET_SOURCE);
        set_transient(Collecte::VERROU, 1, 600);
        Collecte::traiter_une_source();
        $this->assertSame(['dhc'], get_option(Collecte::OPTION_FILE));
        $this->assertNull(Journal::derniere('dhc'));
        // Replanifiée pour reprendre une fois le verrou libéré.
        $this->assertNotFalse(wp_next_scheduled(Collecte::CROCHET_SOURCE));
        delete_transient(Collecte::VERROU);
        rsn_veille_bouchons_executer_cron(Collecte::CROCHET_SOURCE);
        $this->assertSame(18, Journal::derniere('dhc')['crees']);
    }

    public function test_file_vide_ne_fait_rien(): void {
        Collecte::traiter_une_source();
        $this->assertSame([], Journal::tout());
        $this->assertFalse(get_option(Collecte::OPTION_DERNIERE));
    }

    // --- Purge des caches ---

    public function test_purge_sans_page_veille_declenche_seulement_l_action_des_modules(): void {
        Collecte::purger_caches();
        $this->assertSame([], rsn_veille_bouchons_declenchements('litespeed_purge_post'));
        $this->assertCount(1, rsn_veille_bouchons_declenchements('rsn_veille_caches_purges'));
    }

    public function test_purge_a_la_suppression_d_une_fiche_seulement(): void {
        update_option(Collecte::OPTION_REGLAGES, ['page_veille_id' => 42]);
        $article = wp_insert_post(['post_type' => 'post', 'post_title' => 'Article', 'post_status' => 'publish']);
        $fiche = wp_insert_post(['post_type' => 'rsn_veille', 'post_title' => 'Fiche', 'post_status' => 'pending']);
        $GLOBALS['rsn_veille_wp']['declenchees'] = [];
        wp_delete_post($article, true);
        $this->assertSame([], rsn_veille_bouchons_declenchements('litespeed_purge_post'));
        wp_delete_post($fiche, true);
        $this->assertSame([[42]], rsn_veille_bouchons_declenchements('litespeed_purge_post'));
    }

    public function test_purge_a_l_enregistrement_d_une_fiche_hors_collecte(): void {
        update_option(Collecte::OPTION_REGLAGES, ['page_veille_id' => 42]);
        wp_insert_post(['post_type' => 'post', 'post_title' => 'Article', 'post_status' => 'publish']);
        $this->assertSame([], rsn_veille_bouchons_declenchements('litespeed_purge_post'));
        wp_insert_post(['post_type' => 'rsn_veille', 'post_title' => 'Fiche', 'post_status' => 'pending']);
        $this->assertSame([[42]], rsn_veille_bouchons_declenchements('litespeed_purge_post'));
    }

    public function test_crochets_accroches(): void {
        $actions = $GLOBALS['rsn_veille_wp']['actions'];
        foreach (['rsn_veille_nuit', 'rsn_veille_source', 'save_post_rsn_veille', 'deleted_post', 'admin_init'] as $crochet) {
            $this->assertArrayHasKey($crochet, $actions, $crochet);
        }
        $this->assertSame(2, $actions['deleted_post'][0]['nb_arguments']);
        // La tâche quotidienne : met en file (rappel appelé avec la chaîne vide de do_action).
        do_action('rsn_veille_nuit');
        $this->assertNotEmpty(get_option(Collecte::OPTION_FILE));
    }
}

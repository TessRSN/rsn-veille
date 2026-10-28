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
        Collecte::$horloge_pour_tests = null;
        $this->demandes = [];
    }

    protected function tearDown(): void {
        Collecte::$obtenir_pour_tests = null;
        Collecte::$horloge_pour_tests = null;
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

        $integrees = [];
        $integrer = static function (Fiche $f) use (&$integrees): void {
            $integrees[] = $f;
        };
        $resolution = ResolutionIvado::resoudre($fiches, ['id' => 'ivado'], new Depot($entrepot), $obtenir, $integrer);

        $this->assertCount(25, $this->demandes);
        $this->assertNotContains('https://ivado.ca/?p=1', $this->demandes);
        $this->assertSame('https://ivado.ca/?p=26', end($this->demandes));
        $this->assertSame(4, $resolution['en_attente']);
        $this->assertCount(2, $resolution['erreurs']);
        $this->assertSame(23, $resolution['resolues']);
        $this->assertCount(24, $integrees);
        $par_cle = [];
        foreach ($integrees as $f) {
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
        $integrees = [];
        $resolution = ResolutionIvado::resoudre($fiches, ['id' => 'ivado'], new Depot(new EntrepotMemoire()), $obtenir, static function (Fiche $f) use (&$integrees): void {
            $integrees[] = $f;
        });
        $this->assertCount(1, $integrees);
        $this->assertSame('Deux', $integrees[0]->titre);
        $this->assertStringContainsString('délai dépassé', $resolution['erreurs'][0]);
    }

    public function test_resolution_ivado_dans_un_budget_de_temps_et_integration_au_fil_de_l_eau(): void {
        $temps = 0.0;
        $journal = [];
        $fiches = [];
        for ($id = 1; $id <= 8; $id++) {
            $fiches[] = self::fiche(['titre' => '', 'cle_source' => 'ivado:' . $id, 'url' => 'https://ivado.ca/?p=' . $id]);
        }
        // Chaque page « prend » 6 s : budget de 20 s → 4 résolutions (départs à 0, 6, 12 et 18 s).
        $obtenir = static function (string $url) use (&$temps, &$journal): array {
            $journal[] = 'requête ' . $url;
            $temps += 6.0;
            return self::reponse('text/html', '<title>Page | IVADO</title>', $url . '&final');
        };
        $integrer = static function (Fiche $f) use (&$journal): void {
            $journal[] = 'intégration ' . $f->cle_source;
        };
        $horloge = static function () use (&$temps): float {
            return $temps;
        };
        $resolution = ResolutionIvado::resoudre($fiches, ['id' => 'ivado'], new Depot(new EntrepotMemoire()), $obtenir, $integrer, 25, 20.0, $horloge);
        $this->assertSame(4, $resolution['resolues']);
        $this->assertSame(4, $resolution['en_attente']);
        $this->assertSame([
            'requête https://ivado.ca/?p=1', 'intégration ivado:1',
            'requête https://ivado.ca/?p=2', 'intégration ivado:2',
            'requête https://ivado.ca/?p=3', 'intégration ivado:3',
            'requête https://ivado.ca/?p=4', 'intégration ivado:4',
        ], $journal);
    }

    public function test_collecte_ivado_au_budget_ecrit_les_fiches_resolues(): void {
        self::activer_seulement(['ivado']);
        $temps = 0.0;
        $this->brancher_http();
        $http = Collecte::$obtenir_pour_tests;
        Collecte::$obtenir_pour_tests = static function (string $url, array $options = []) use ($http, &$temps): array {
            if (str_contains($url, '?p=')) {
                $temps += 6.0;
            }
            return $http($url, $options);
        };
        Collecte::$horloge_pour_tests = static function () use (&$temps): float {
            return $temps;
        };
        Collecte::mettre_en_file();
        rsn_veille_bouchons_executer_cron(Collecte::CROCHET_SOURCE);
        $ivado = Journal::derniere('ivado');
        // 4 pages dans le budget, dont celle sans titre ; 2 fiches attendront la nuit suivante.
        $this->assertSame([6, 3, 2, 1], [$ivado['lus'], $ivado['crees'], $ivado['en_attente'], count($ivado['erreurs'])]);
        $this->assertCount(3, (new EntrepotWordPress())->lister(''));
    }

    // --- Version anglaise (Mila, IVADO) ---

    /** Page d'événements de Mila : [nid, titre, chemin, résumé, date de début, date de fin]. */
    private static function page_mila(array $evenements): string {
        $html = '<html><body>';
        foreach ($evenements as [$nid, $titre, $chemin, $resume, $debut, $fin]) {
            $html .= '<div data-nid="' . $nid . '"><div class="node--type-event">'
                . '<div class="field-name-node-title"><a href="' . $chemin . '">' . $titre . '</a></div>'
                . '<div class="field-name-body">' . $resume . '</div>'
                . '<time datetime="' . $debut . 'T18:00:00-05:00">début</time><time datetime="' . $fin . 'T21:00:00-05:00">fin</time>'
                . '</div></div>';
        }
        return $html . '</body></html>';
    }

    /** Calendrier d'IVADO : identifiant => date. */
    private static function calendrier_ivado(array $evenements): string {
        $html = '<html><body>';
        foreach ($evenements as $id => $date) {
            $html .= '<div class="calendarEvents" data-event-id="' . $id . '" data-event-start="' . $date . ' 00:00:00" data-event-end="' . $date . ' 00:00:00"></div>';
        }
        return $html . '</body></html>';
    }

    /** Flux RSS des événements : adresse => résumé. */
    private static function flux(array $resumes): string {
        $items = '';
        foreach ($resumes as $lien => $resume) {
            $items .= '<item><title>T</title><link>' . $lien . '</link><description>' . $resume . '</description><pubDate>Mon, 26 Oct 2026 10:00:00 +0000</pubDate></item>';
        }
        return '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Flux</title>' . $items . '</channel></rss>';
    }

    /** Faux client HTTP : réponses par adresse exacte, 404 sinon ; les adresses demandées vont dans $this->demandes. */
    private function servir(array $pages): void {
        Collecte::$obtenir_pour_tests = function (string $url, array $options = []) use ($pages): array {
            $this->demandes[] = $url;
            $page = $pages[$url] ?? self::reponse('', '', $url, 404, 'Réponse HTTP 404');
            if ($page instanceof \Throwable) {
                throw $page;
            }
            return $page + ['url_finale' => $url];
        };
    }

    private const MILA_FR = [
        [11, 'Atelier IA', '/fr/evenement/atelier', 'Résumé français', '2026-11-05', '2026-11-05'],
        [12, 'Colloque données', '/fr/evenement/colloque', 'Deux jours', '2026-11-12', '2026-11-13'],
    ];

    private const MILA_EN = [
        [11, 'AI Workshop', '/en/event/workshop', 'English summary', '2026-11-05', '2026-11-05'],
        [12, 'Data Symposium', '/en/event/symposium', 'Two days', '2026-11-12', '2026-11-13'],
        [13, 'English only', '/en/event/english-only', 'Only in English', '2026-11-20', '2026-11-20'],
    ];

    private function servir_mila(array|\Throwable|null $anglaise = null): void {
        $this->servir([
            'https://mila.quebec/fr/evenements' => self::reponse('text/html; charset=UTF-8', self::page_mila(self::MILA_FR)),
            'https://mila.quebec/en/events' => $anglaise ?? self::reponse('text/html; charset=UTF-8', self::page_mila(self::MILA_EN)),
        ]);
    }

    public function test_mila_version_anglaise_lue_et_appariee_aux_fiches_connues(): void {
        $entrepot = new EntrepotMemoire();
        $this->servir_mila();
        $ligne = Collecte::collecter_source(Sources::par_defaut()['mila'], new Depot($entrepot), new Pertinence());

        $this->assertSame(['https://mila.quebec/fr/evenements', 'https://mila.quebec/en/events'], $this->demandes);
        $this->assertSame([2, 2, []], [$ligne['lus'], $ligne['crees'], $ligne['erreurs']]);
        $this->assertSame([2, 1], [$ligne['appariees_en'], $ligne['orphelines_en']]);
        $atelier = $entrepot->trouver_par_cle_source('mila:11');
        $this->assertSame(['Atelier IA', 'https://mila.quebec/fr/evenement/atelier'], [$atelier->titre, $atelier->url]);
        $this->assertSame(['AI Workshop', 'English summary', 'https://mila.quebec/en/event/workshop'], [$atelier->titre_en, $atelier->resume_en, $atelier->url_en]);
        $this->assertSame('Data Symposium', $entrepot->trouver_par_cle_source('mila:12')->titre_en);
        // Les fiches anglaises ne sont jamais intégrées.
        $this->assertCount(2, $entrepot->lister(''));

        // Seconde collecte : paires déjà à jour, toujours comptées, rien de réécrit.
        $this->demandes = [];
        $ligne = Collecte::collecter_source(Sources::par_defaut()['mila'], new Depot($entrepot), new Pertinence());
        $this->assertSame([0, 2, 2, 1], [$ligne['crees'], $ligne['inchanges'], $ligne['appariees_en'], $ligne['orphelines_en']]);
        $this->assertCount(2, $this->demandes);
    }

    public function test_mila_evenement_non_traduit_a_la_meme_adresse_n_est_pas_apparie(): void {
        $entrepot = new EntrepotMemoire();
        // La page anglaise liste l'atelier 11 avec l'adresse française : pas de version anglaise.
        $en = self::MILA_EN;
        $en[0] = [11, 'Atelier IA', '/fr/evenement/atelier', 'Résumé français', '2026-11-05', '2026-11-05'];
        $this->servir_mila(self::reponse('text/html; charset=UTF-8', self::page_mila($en)));
        $ligne = Collecte::collecter_source(Sources::par_defaut()['mila'], new Depot($entrepot), new Pertinence());

        $this->assertSame([2, []], [$ligne['crees'], $ligne['erreurs']]);
        $this->assertSame(1, $ligne['appariees_en'], 'seul le colloque est apparié');
        $this->assertSame(['', '', ''], [$entrepot->trouver_par_cle_source('mila:11')->titre_en, $entrepot->trouver_par_cle_source('mila:11')->resume_en, $entrepot->trouver_par_cle_source('mila:11')->url_en]);
        $this->assertSame('Data Symposium', $entrepot->trouver_par_cle_source('mila:12')->titre_en);
    }

    public function test_titre_anglais_ecrit_purge_les_caches(): void {
        update_option(Collecte::OPTION_REGLAGES, ['page_veille_id' => 42]);
        $entrepot = new EntrepotMemoire();
        $this->servir_mila(self::reponse('text/html', '<html><body>vide</body></html>'));
        Collecte::collecter_source(Sources::par_defaut()['mila'], new Depot($entrepot), new Pertinence());
        $purges = count(rsn_veille_bouchons_declenchements('litespeed_purge_post'));

        // Seule la version anglaise apporte du nouveau : la page publique change, donc purge.
        $this->servir_mila();
        $ligne = Collecte::collecter_source(Sources::par_defaut()['mila'], new Depot($entrepot), new Pertinence());
        $this->assertSame([0, 2], [$ligne['crees'] + $ligne['mis_a_jour'] + $ligne['fusionnes'], $ligne['appariees_en']]);
        $this->assertCount($purges + 1, rsn_veille_bouchons_declenchements('litespeed_purge_post'));
    }

    public function test_version_anglaise_illisible_consignee_sans_toucher_aux_fiches_francaises(): void {
        foreach ([
            'HTTP' => self::reponse('', '', 'https://mila.quebec/en/events', 500, 'Réponse HTTP 500'),
            'contenu' => self::reponse('application/json', '{"a": 1}'),
            'exception' => new RuntimeException('connexion coupée'),
        ] as $cas => $anglaise) {
            $entrepot = new EntrepotMemoire();
            $this->servir_mila($anglaise);
            $ligne = Collecte::collecter_source(Sources::par_defaut()['mila'], new Depot($entrepot), new Pertinence());
            $this->assertSame(2, $ligne['crees'], $cas);
            $this->assertCount(1, $ligne['erreurs'], $cas);
            $this->assertStringContainsString('anglaise', $ligne['erreurs'][0], $cas);
            $this->assertSame([0, 0], [$ligne['appariees_en'], $ligne['orphelines_en']], $cas);
            $atelier = $entrepot->trouver_par_cle_source('mila:11');
            $this->assertSame(['Atelier IA', ''], [$atelier->titre, $atelier->titre_en], $cas);
        }
    }

    public function test_source_sans_version_anglaise_inchangee(): void {
        $source = Sources::par_defaut()['mila'];
        unset($source['url_en']);
        $this->servir_mila();
        $ligne = Collecte::collecter_source($source, new Depot(new EntrepotMemoire()), new Pertinence());
        $this->assertSame(['https://mila.quebec/fr/evenements'], $this->demandes);
        $this->assertArrayNotHasKey('appariees_en', $ligne);
        $this->assertArrayNotHasKey('orphelines_en', $ligne);
    }

    /** IVADO : un événement le 6 novembre, 100 en français et 101 en anglais (WPML : identifiants distincts). */
    private function servir_ivado(array $en_plus = []): void {
        $this->servir($en_plus + [
            'https://ivado.ca/evenements/' => self::reponse('text/html', self::calendrier_ivado([100 => '2026-11-06'])),
            'https://ivado.ca/evenements/feed/' => self::reponse('application/rss+xml', self::flux(['https://ivado.ca/evenements/atelier/' => 'Résumé français'])),
            'https://ivado.ca/?p=100' => self::reponse('text/html', '<html><head><title>Atelier | IVADO</title></head></html>', 'https://ivado.ca/evenements/atelier/'),
            'https://ivado.ca/en/events/' => self::reponse('text/html', self::calendrier_ivado([101 => '2026-11-06', 102 => '2026-11-30'])),
            'https://ivado.ca/en/events/feed/' => self::reponse('application/rss+xml', self::flux(['https://ivado.ca/en/events/workshop/' => 'English summary'])),
            'https://ivado.ca/?p=101' => self::reponse('text/html', '<html><head><title>Workshop | IVADO</title></head></html>', 'https://ivado.ca/en/events/workshop/'),
        ]);
    }

    public function test_ivado_page_anglaise_lue_seulement_tant_que_le_titre_anglais_manque(): void {
        $entrepot = new EntrepotMemoire();
        $this->servir_ivado();
        $ligne = Collecte::collecter_source(Sources::par_defaut()['ivado'], new Depot($entrepot), new Pertinence());

        $this->assertSame([1, 1, []], [$ligne['lus'], $ligne['crees'], $ligne['erreurs']]);
        $this->assertSame([1, 1], [$ligne['appariees_en'], $ligne['orphelines_en']]);
        $this->assertContains('https://ivado.ca/en/events/feed/', $this->demandes);
        $this->assertContains('https://ivado.ca/?p=101', $this->demandes);
        // L'anglaise orpheline n'est pas résolue : aucune fiche connue à compléter.
        $this->assertNotContains('https://ivado.ca/?p=102', $this->demandes);
        $atelier = $entrepot->trouver_par_cle_source('ivado:100');
        $this->assertSame(['Atelier', 'https://ivado.ca/evenements/atelier/', 'Résumé français'], [$atelier->titre, $atelier->url, $atelier->resume]);
        $this->assertSame(['Workshop', 'https://ivado.ca/en/events/workshop/', 'English summary'], [$atelier->titre_en, $atelier->url_en, $atelier->resume_en]);
        $this->assertNull($entrepot->trouver_par_cle_source('ivado:101'));

        // Titre anglais déjà présent : aucune page d'événement demandée, l'adresse anglaise finale est gardée.
        $this->demandes = [];
        $ligne = Collecte::collecter_source(Sources::par_defaut()['ivado'], new Depot($entrepot), new Pertinence());
        $this->assertSame([1, []], [$ligne['appariees_en'], $ligne['erreurs']]);
        $this->assertSame([], array_values(array_filter($this->demandes, static fn(string $u): bool => str_contains($u, '?p='))));
        $this->assertSame('https://ivado.ca/en/events/workshop/', $entrepot->trouver_par_cle_source('ivado:100')->url_en);
    }

    public function test_ivado_page_anglaise_en_erreur_consignee(): void {
        $entrepot = new EntrepotMemoire();
        $this->servir_ivado(['https://ivado.ca/?p=101' => self::reponse('', '', 'https://ivado.ca/?p=101', 503, 'Réponse HTTP 503')]);
        $ligne = Collecte::collecter_source(Sources::par_defaut()['ivado'], new Depot($entrepot), new Pertinence());
        $this->assertSame([1, 0], [$ligne['crees'], $ligne['appariees_en']]);
        $this->assertCount(1, $ligne['erreurs']);
        $this->assertStringContainsString('?p=101', $ligne['erreurs'][0]);
        $this->assertSame(['Atelier', ''], [$entrepot->trouver_par_cle_source('ivado:100')->titre, $entrepot->trouver_par_cle_source('ivado:100')->titre_en]);
    }

    public function test_ivado_evenement_non_traduit_liste_sous_son_identifiant_francais_n_est_pas_apparie(): void {
        $entrepot = new EntrepotMemoire();
        // La page anglaise liste l'événement 100 (non traduit) sous son identifiant français : même clé de source.
        $this->servir_ivado(['https://ivado.ca/en/events/' => self::reponse('text/html', self::calendrier_ivado([100 => '2026-11-06']))]);
        $ligne = Collecte::collecter_source(Sources::par_defaut()['ivado'], new Depot($entrepot), new Pertinence());

        $this->assertSame([1, []], [$ligne['crees'], $ligne['erreurs']]);
        $this->assertSame(0, $ligne['appariees_en']);
        $atelier = $entrepot->trouver_par_cle_source('ivado:100');
        $this->assertSame(['Atelier', '', '', ''], [$atelier->titre, $atelier->titre_en, $atelier->resume_en, $atelier->url_en]);
    }

    public function test_ivado_flux_anglais_illisible_porte_le_prefixe_de_la_passe_anglaise(): void {
        $entrepot = new EntrepotMemoire();
        $this->servir_ivado(['https://ivado.ca/en/events/feed/' => self::reponse('', '', 'https://ivado.ca/en/events/feed/', 503, 'Réponse HTTP 503')]);
        $ligne = Collecte::collecter_source(Sources::par_defaut()['ivado'], new Depot($entrepot), new Pertinence());
        $this->assertCount(1, $ligne['erreurs']);
        $this->assertStringStartsWith('Version anglaise : Flux des événements IVADO illisible', $ligne['erreurs'][0]);
        // Le flux français, lui, n'est pas préfixé (aucune erreur ici) et la fiche française est intacte.
        $this->assertSame(1, $ligne['crees']);
    }

    public function test_ivado_pages_anglaises_dans_le_meme_budget_que_les_francaises(): void {
        $entrepot = new EntrepotMemoire();
        $temps = 0.0;
        $this->servir_ivado();
        $http = Collecte::$obtenir_pour_tests;
        // La page française « prend » tout le budget : la page anglaise attend la collecte suivante.
        Collecte::$obtenir_pour_tests = static function (string $url, array $options = []) use ($http, &$temps): array {
            if (str_contains($url, '?p=')) {
                $temps += ResolutionIvado::BUDGET;
            }
            return $http($url, $options);
        };
        Collecte::$horloge_pour_tests = static function () use (&$temps): float {
            return $temps;
        };
        $ligne = Collecte::collecter_source(Sources::par_defaut()['ivado'], new Depot($entrepot), new Pertinence());
        $this->assertSame(1, $ligne['crees']);
        $this->assertContains('https://ivado.ca/?p=100', $this->demandes);
        $this->assertNotContains('https://ivado.ca/?p=101', $this->demandes);
        $this->assertSame([0, []], [$ligne['appariees_en'], $ligne['erreurs']]);
        $this->assertSame('', $entrepot->trouver_par_cle_source('ivado:100')->titre_en);
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

    public function test_la_tache_quotidienne_recale_la_collecte_quotidienne(): void {
        // Collecte quotidienne décalée d'une heure (changement d'heure) : replacée à 3 h, heure de Montréal.
        wp_schedule_event(Collecte::prochaine_collecte(time() + 12 * 3600) + 3600, 'daily', Collecte::CROCHET_NUIT);
        do_action(Collecte::CROCHET_NUIT);
        $nuit = rsn_veille_bouchons_planifies(Collecte::CROCHET_NUIT);
        $this->assertCount(1, $nuit);
        $this->assertSame(Collecte::prochaine_collecte(time() + 12 * 3600), $nuit[0]['horodatage']);
        $this->assertSame('daily', $nuit[0]['recurrence']);
        $this->assertSame('03:00', (new DateTimeImmutable('@' . $nuit[0]['horodatage']))->setTimezone(new DateTimeZone('America/Toronto'))->format('H:i'));
    }

    public function test_passage_a_l_heure_normale_sans_seconde_collecte_la_meme_nuit(): void {
        $montreal = new DateTimeZone('America/Toronto');
        // 1er novembre 2026 : la tâche de 3 h HAE s'exécute à 2 h HNE ; WordPress l'a replanifiée 24 h plus tard (2 h HNE le 2).
        $maintenant = (new DateTimeImmutable('2026-11-01 02:00', $montreal))->getTimestamp();
        wp_schedule_event($maintenant + DAY_IN_SECONDS, 'daily', Collecte::CROCHET_NUIT);
        Collecte::recaler_nuit_depuis($maintenant);
        $nuit = rsn_veille_bouchons_planifies(Collecte::CROCHET_NUIT);
        $this->assertCount(1, $nuit);
        $this->assertSame('2026-11-02 03:00', (new DateTimeImmutable('@' . $nuit[0]['horodatage']))->setTimezone($montreal)->format('Y-m-d H:i'));
    }

    public function test_passage_a_l_heure_avancee(): void {
        $montreal = new DateTimeZone('America/Toronto');
        // 13 mars 2027, 3 h HNE ; WordPress replanifie 24 h plus tard, soit 4 h HAE le 14.
        $maintenant = (new DateTimeImmutable('2027-03-13 03:00', $montreal))->getTimestamp();
        wp_schedule_event($maintenant + DAY_IN_SECONDS, 'daily', Collecte::CROCHET_NUIT);
        Collecte::recaler_nuit_depuis($maintenant);
        $nuit = rsn_veille_bouchons_planifies(Collecte::CROCHET_NUIT);
        $this->assertCount(1, $nuit);
        $this->assertSame('2027-03-14 03:00 EDT', (new DateTimeImmutable('@' . $nuit[0]['horodatage']))->setTimezone($montreal)->format('Y-m-d H:i T'));
    }

    public function test_tache_executee_en_retard_ne_saute_pas_la_nuit_suivante(): void {
        $montreal = new DateTimeZone('America/Toronto');
        // Tâche de 3 h exécutée à 15 h 30 (cache de page, peu de visites) ; WordPress a replanifié au lendemain 3 h.
        $maintenant = (new DateTimeImmutable('2026-12-10 15:30', $montreal))->getTimestamp();
        $lendemain = (new DateTimeImmutable('2026-12-11 03:00', $montreal))->getTimestamp();
        wp_schedule_event($lendemain, 'daily', Collecte::CROCHET_NUIT);
        Collecte::recaler_nuit_depuis($maintenant);
        $this->assertSame($lendemain, wp_next_scheduled(Collecte::CROCHET_NUIT));
    }

    public function test_heure_voulue_deja_passee_laisse_la_prevision(): void {
        $montreal = new DateTimeZone('America/Toronto');
        // Réglage passé à 22 h 30 ; tâche de 3 h exécutée à 23 h : 22 h 30 est passé, la prévision de 3 h reste.
        update_option(Collecte::OPTION_REGLAGES, ['heure_collecte' => '22:30']);
        $maintenant = (new DateTimeImmutable('2026-12-10 23:00', $montreal))->getTimestamp();
        $lendemain = (new DateTimeImmutable('2026-12-11 03:00', $montreal))->getTimestamp();
        wp_schedule_event($lendemain, 'daily', Collecte::CROCHET_NUIT);
        Collecte::recaler_nuit_depuis($maintenant);
        $this->assertSame($lendemain, wp_next_scheduled(Collecte::CROCHET_NUIT));
        // Exécutée à 3 h : ramenée à 22 h 30 le jour même.
        Collecte::recaler_nuit_depuis($lendemain - DAY_IN_SECONDS);
        $this->assertSame('2026-12-10 22:30', (new DateTimeImmutable('@' . wp_next_scheduled(Collecte::CROCHET_NUIT)))->setTimezone($montreal)->format('Y-m-d H:i'));
    }

    public function test_lancement_manuel_l_apres_midi_ne_saute_pas_la_nuit_suivante(): void {
        Collecte::planifier_nuit();
        $prevue = wp_next_scheduled(Collecte::CROCHET_NUIT);
        Collecte::mettre_en_file();
        $this->assertSame($prevue, wp_next_scheduled(Collecte::CROCHET_NUIT));
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
            // Version anglaise sans événement : rien à apparier, aucune erreur.
            $sources['ivado']['url_en'] => self::reponse('text/html; charset=UTF-8', '<!DOCTYPE html><html><body></body></html>'),
            $sources['ivado']['flux_evenements_url_en'] => self::reponse('application/rss+xml; charset=UTF-8', self::fixture('ivado_ev.xml')),
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
        $this->assertArrayNotHasKey('appariees_en', $dhc);
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
        $this->assertFalse(get_option(Collecte::VERROU));
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
        $this->assertFalse(get_option(Collecte::VERROU));
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
        add_option(Collecte::VERROU, ['jeton' => 'autre', 'depuis' => time()], '', 'no');
        Collecte::traiter_une_source();
        $this->assertSame(['dhc'], get_option(Collecte::OPTION_FILE));
        $this->assertNull(Journal::derniere('dhc'));
        // Replanifiée pour reprendre une fois le verrou libéré.
        $this->assertNotFalse(wp_next_scheduled(Collecte::CROCHET_SOURCE));
        $this->assertSame(['jeton' => 'autre'], array_intersect_key(get_option(Collecte::VERROU), ['jeton' => 1]));
        delete_option(Collecte::VERROU);
        rsn_veille_bouchons_executer_cron(Collecte::CROCHET_SOURCE);
        $this->assertSame(18, Journal::derniere('dhc')['crees']);
    }

    public function test_deux_traitements_simultanes_une_seule_source_depilee(): void {
        self::activer_seulement(['dhc', 'sommeil']);
        $this->brancher_http();
        $http = Collecte::$obtenir_pour_tests;
        $verrou_pendant = null;
        $second_lance = false;
        // Pendant le téléchargement de dhc, une seconde exécution démarre.
        Collecte::$obtenir_pour_tests = static function (string $url, array $options = []) use ($http, &$second_lance, &$verrou_pendant): array {
            if (!$second_lance) {
                $second_lance = true;
                Collecte::traiter_une_source();
                $verrou_pendant = get_option(Collecte::VERROU);
            }
            return $http($url, $options);
        };
        Collecte::mettre_en_file();
        Collecte::traiter_une_source();
        $this->assertSame(['sommeil'], get_option(Collecte::OPTION_FILE));
        $this->assertNull(Journal::derniere('sommeil'));
        $this->assertSame(18, Journal::derniere('dhc')['crees']);
        $this->assertIsArray($verrou_pendant, 'la seconde exécution ne libère pas le verrou de la première');
        $this->assertFalse(get_option(Collecte::VERROU));
    }

    public function test_verrou_repris_par_un_autre_traitement_non_libere(): void {
        self::activer_seulement(['dhc']);
        $this->brancher_http();
        $http = Collecte::$obtenir_pour_tests;
        // Pendant le traitement, un autre traitement a jugé le verrou périmé et l'a repris.
        Collecte::$obtenir_pour_tests = static function (string $url, array $options = []) use ($http): array {
            rsn_veille_bouchons_ecrire_option_ailleurs(Collecte::VERROU, ['jeton' => 'autre', 'depuis' => time()]);
            return $http($url, $options);
        };
        Collecte::mettre_en_file();
        Collecte::traiter_une_source();
        wp_cache_delete(Collecte::VERROU, 'options');
        $this->assertSame('autre', get_option(Collecte::VERROU)['jeton']);
    }

    public function test_jeton_ecrase_par_un_ajout_concurrent_le_premier_abandonne(): void {
        self::activer_seulement(['dhc']);
        $this->brancher_http();
        Collecte::mettre_en_file();
        wp_clear_scheduled_hook(Collecte::CROCHET_SOURCE);
        // Un second processus insère la même option juste après nous et écrase notre jeton.
        $GLOBALS['rsn_veille_wp']['apres_add_option'] = static function (string $nom): void {
            rsn_veille_bouchons_ecrire_option_ailleurs($nom, ['jeton' => 'concurrent', 'depuis' => time()]);
        };
        Collecte::traiter_une_source();
        $this->assertSame(['dhc'], get_option(Collecte::OPTION_FILE));
        $this->assertNull(Journal::derniere('dhc'));
        wp_cache_delete(Collecte::VERROU, 'options');
        $this->assertSame('concurrent', get_option(Collecte::VERROU)['jeton']);
        $this->assertNotFalse(wp_next_scheduled(Collecte::CROCHET_SOURCE));
    }

    public function test_verrou_perime_repris(): void {
        self::activer_seulement(['dhc']);
        $this->brancher_http();
        Collecte::mettre_en_file();
        add_option(Collecte::VERROU, ['jeton' => 'abandonné', 'depuis' => time() - 11 * 60], '', 'no');
        Collecte::traiter_une_source();
        $this->assertSame(18, Journal::derniere('dhc')['crees']);
        $this->assertFalse(get_option(Collecte::VERROU));
    }

    public function test_reglages_malformes_consignes_et_file_poursuivie(): void {
        self::activer_seulement(['dhc', 'sommeil'], ['themes' => [['nom' => 'Sans mots']]]);
        $this->brancher_http();
        Collecte::mettre_en_file();
        Collecte::traiter_une_source();
        $this->assertNotEmpty(Journal::derniere('dhc')['erreurs']);
        $this->assertSame(['sommeil'], get_option(Collecte::OPTION_FILE));
        $this->assertNotFalse(wp_next_scheduled(Collecte::CROCHET_SOURCE));
    }

    public function test_element_non_scalaire_de_la_file_ignore(): void {
        self::activer_seulement(['dhc']);
        $this->brancher_http();
        update_option(Collecte::OPTION_FILE, [['abîmé'], null, 'dhc']);
        Collecte::traiter_une_source();
        $this->assertSame(18, Journal::derniere('dhc')['crees']);
        $this->assertSame([], get_option(Collecte::OPTION_FILE));
    }

    public function test_file_vide_ne_fait_rien(): void {
        Collecte::traiter_une_source();
        $this->assertSame([], Journal::tout());
        $this->assertFalse(get_option(Collecte::OPTION_DERNIERE));
    }

    public function test_api_du_site_rsn_demandee_avec_un_parametre_anti_cache(): void {
        $urls = [];
        Collecte::$obtenir_pour_tests = static function (string $url, array $options = []) use (&$urls): array {
            $urls[] = $url;
            return self::reponse('application/json', self::fixture('rsn_ev.json'));
        };
        $sources = Sources::par_defaut();
        Collecte::collecter_source($sources['rsn'], new Depot(new EntrepotMemoire()), new Pertinence());
        Collecte::collecter_source($sources['dhc'], new Depot(new EntrepotMemoire()), new Pertinence());
        $this->assertMatchesRegularExpression('~^https://rsn\.quebec/wp-json/tribe/events/v1/events\?per_page=50&start_date=2025-05-01&end_date=\d{4}-12-31&t=\d+$~', $urls[0]);
        $this->assertSame($sources['dhc']['url'], $urls[1], 'seule la source du site reçoit le paramètre');
        $this->assertStringNotContainsString('t=', $sources['rsn']['url'], "l'horodatage n'est pas figé dans le registre");
    }

    // --- Purge des caches ---

    public function test_purge_au_changement_de_version(): void {
        update_option(Collecte::OPTION_REGLAGES, ['page_veille_id' => 42]);
        // Option absente (mise à jour depuis 0.1.0) : purge puis version enregistrée.
        Collecte::purger_si_nouvelle_version();
        $this->assertSame([[42]], rsn_veille_bouchons_declenchements('litespeed_purge_post'));
        $this->assertCount(1, rsn_veille_bouchons_declenchements('rsn_veille_caches_purges'));
        $this->assertSame(RSN_VEILLE_VERSION, get_option(Collecte::OPTION_VERSION));
        // Même version : rien de plus.
        Collecte::purger_si_nouvelle_version();
        $this->assertCount(1, rsn_veille_bouchons_declenchements('litespeed_purge_post'));
        $this->assertCount(1, rsn_veille_bouchons_declenchements('rsn_veille_caches_purges'));
        // Autre version enregistrée : purge de nouveau.
        update_option(Collecte::OPTION_VERSION, '0.1.0');
        Collecte::purger_si_nouvelle_version();
        $this->assertCount(2, rsn_veille_bouchons_declenchements('litespeed_purge_post'));
        $this->assertSame(RSN_VEILLE_VERSION, get_option(Collecte::OPTION_VERSION));
    }

    public function test_la_purge_de_version_est_accrochee_a_admin_init(): void {
        $rappels = array_column($GLOBALS['rsn_veille_wp']['actions']['admin_init'] ?? [], 'rappel');
        $this->assertContains([Collecte::class, 'purger_si_nouvelle_version'], $rappels);
    }

    public function test_purge_sans_page_veille_declenche_seulement_l_action_des_modules(): void {
        Collecte::purger_caches();
        $this->assertSame([], rsn_veille_bouchons_declenchements('litespeed_purge_post'));
        $this->assertCount(1, rsn_veille_bouchons_declenchements('rsn_veille_caches_purges'));
    }

    public function test_purge_des_pages_traduites_par_wpml(): void {
        update_option(Collecte::OPTION_REGLAGES, ['page_veille_id' => 42]);
        add_filter('wpml_active_languages', static fn() => ['fr' => ['code' => 'fr'], 'en' => ['code' => 'en'], 'es' => ['code' => 'es']]);
        // Page traduite en anglais (43) ; pas de traduction espagnole (WPML rend null).
        add_filter('wpml_object_id', static fn($id, $type = '', $original = false, $langue = null) => match ($langue) {
            'fr' => $id, 'en' => 43, default => null,
        });
        update_post_meta(42, '_elementor_element_cache', 'rendu fr');
        update_post_meta(43, '_elementor_element_cache', 'rendu en');

        Collecte::purger_caches();
        $this->assertSame([[42], [43]], rsn_veille_bouchons_declenchements('litespeed_purge_post'));
        $this->assertSame('', get_post_meta(42, '_elementor_element_cache', true));
        $this->assertSame('', get_post_meta(43, '_elementor_element_cache', true));
        $this->assertSame([42, 43], Collecte::pages_veille());
    }

    public function test_sans_wpml_seule_la_page_des_reglages_est_purgee(): void {
        update_option(Collecte::OPTION_REGLAGES, ['page_veille_id' => 42]);
        Collecte::purger_caches();
        $this->assertSame([[42]], rsn_veille_bouchons_declenchements('litespeed_purge_post'));
        $this->assertSame([42], Collecte::pages_veille());
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

<?php
declare(strict_types=1);

require_once __DIR__ . '/bouchons-wordpress.php';

use PHPUnit\Framework\TestCase;
use RSN\Veille\EntrepotMemoire;
use RSN\Veille\Fiche;
use RSN\Veille\Journal;
use RSN\Veille\Public\Donnees;

final class DonneesTest extends TestCase {
    private EntrepotMemoire $entrepot;

    protected function setUp(): void {
        rsn_veille_bouchons_reinitialiser();
        $this->entrepot = new EntrepotMemoire();
    }

    private function fiche(array $champs): Fiche {
        return Fiche::depuis_tableau(array_merge([
            'type' => 'evenement',
            'titre' => 'Colloque sur la santé numérique',
            'debut' => '2026-11-12',
            'organisateur' => 'Obvia',
            'source' => 'Obvia : événements',
            'niveau' => 'C',
            'statut' => 'publie',
            'cle_source' => 'obvia:42',
            'cles_source' => ['obvia:42'],
            'titres_source' => ['obvia:42' => 'Colloque sur la santé numérique'],
            'url' => 'https://www.obvia.ca/evenements/colloque',
            'absences' => 1,
            'absent_depuis' => '2026-10-20',
            'manuel' => true,
        ], $champs));
    }

    private function evenement_rsn(array $champs = []): array {
        return array_merge([
            'type' => 'evenement',
            'titre' => 'Colloque sur la santé numérique',
            'debut' => '2026-11-12',
            'fin' => '2026-11-12',
            'heure' => '09:00',
            'organisateur' => 'RSN',
            'categorie_rsn' => 'Événements RSN',
            'url' => 'https://rsn.quebec/evenement/colloque/',
            'image_url' => 'https://rsn.quebec/wp-content/uploads/colloque.jpg',
            'source' => 'RSN',
            'niveau' => 'A',
            'statut' => 'publie',
            'cle_source' => 'rsn-tec:7',
            'courriel_proposant' => 'personne@exemple.test',
        ], $champs);
    }

    public function test_seules_les_fiches_publiees_sortent_et_le_doublon_rsn_est_fusionne(): void {
        $this->entrepot->creer($this->fiche(['image_url' => 'https://www.obvia.ca/image.jpg']));
        $this->entrepot->creer($this->fiche(['titre' => 'Atelier en attente', 'statut' => 'a_approuver', 'cle_source' => 'obvia:43']));
        $donnees = Donnees::construire('2026-10-25', $this->entrepot, [$this->evenement_rsn()]);

        $this->assertCount(1, $donnees['items']);
        $item = $donnees['items'][0];
        $this->assertArrayNotHasKey('courriel_proposant', $item);
        $this->assertArrayNotHasKey('niveau', $item);
        $this->assertArrayNotHasKey('source', $item);
        $this->assertSame('Obvia', $item['organisateur']);
    }

    public function test_champs_prives_jamais_publies(): void {
        $this->entrepot->creer($this->fiche([]));
        $donnees = Donnees::construire('2026-10-25', $this->entrepot, [$this->evenement_rsn(['titre' => 'Autre', 'debut' => '2026-12-01'])], true);

        $this->assertCount(2, $donnees['items']);
        foreach ($donnees['items'] as $item) {
            foreach (['courriel_proposant', 'cles_source', 'titres_source', 'cle_source', 'absences', 'absent_depuis', 'manuel'] as $cle) {
                $this->assertArrayNotHasKey($cle, $item, $cle);
            }
        }
        $json = (string) json_encode($donnees);
        $this->assertStringNotContainsString('personne@exemple.test', $json);
        $this->assertStringNotContainsString('obvia:42', $json);
        $this->assertStringNotContainsString('rsn-tec:7', $json);
    }

    public function test_cles_publiques_de_la_fiche(): void {
        $this->entrepot->creer($this->fiche(['themes' => ['IA', 'Éthique'], 'image_url' => 'https://www.obvia.ca/i.jpg']));
        $item = Donnees::construire('2026-10-25', $this->entrepot, [])['items'][0];
        $attendues = ['id', 'type', 'titre', 'debut', 'fin', 'heure', 'organisateur', 'categorie_rsn', 'lieu', 'ville', 'region',
            'format', 'prix', 'langue', 'themes', 'resume', 'url', 'image_url', 'statut', 'categorie', 'echeance', 'organise_par'];
        $this->assertSame($attendues, array_keys($item));
        $this->assertSame(['IA', 'Éthique'], $item['themes']);
        $this->assertSame('https://www.obvia.ca/i.jpg', $item['image_url']);
    }

    public function test_mode_equipe_montre_niveau_et_source(): void {
        $this->entrepot->creer($this->fiche([]));
        $item = Donnees::construire('2026-10-25', $this->entrepot, [], true)['items'][0];
        $this->assertSame('C', $item['niveau']);
        $this->assertSame('Obvia : événements', $item['source']);
    }

    public function test_doublon_la_fiche_collectee_avec_image_gagne(): void {
        $this->entrepot->creer($this->fiche(['image_url' => 'https://www.obvia.ca/image.jpg']));
        $items = Donnees::construire('2026-10-25', $this->entrepot, [$this->evenement_rsn()])['items'];
        $this->assertCount(1, $items);
        $this->assertSame('https://www.obvia.ca/evenements/colloque', $items[0]['url']);
        $this->assertSame('https://www.obvia.ca/image.jpg', $items[0]['image_url']);
    }

    public function test_doublon_sans_image_l_evenement_rsn_gagne(): void {
        // Même début, même titre à la ponctuation près : même clé de dédoublonnage.
        $this->entrepot->creer($this->fiche(['titre' => 'Colloque sur la santé numérique !']));
        $items = Donnees::construire('2026-10-25', $this->entrepot, [$this->evenement_rsn()])['items'];
        $this->assertCount(1, $items);
        $this->assertSame('https://rsn.quebec/evenement/colloque/', $items[0]['url']);
        $this->assertSame('Événements RSN', $items[0]['categorie_rsn']);
        $this->assertSame('RSN', $items[0]['organisateur']);
    }

    public function test_evenement_rsn_sans_doublon_ajoute(): void {
        $this->entrepot->creer($this->fiche([]));
        $items = Donnees::construire('2026-10-25', $this->entrepot, [$this->evenement_rsn(['titre' => 'Journées scientifiques', 'debut' => '2025-09-15'])])['items'];
        $this->assertCount(2, $items);
        // Les événements passés du RSN sont gardés (section « Événements passés »).
        $this->assertSame('Journées scientifiques', $items[0]['titre']);
    }

    public function test_tri_evenements_par_debut_puis_appels_par_echeance(): void {
        $this->entrepot->creer($this->fiche(['titre' => 'Décembre', 'debut' => '2026-12-03', 'cle_source' => 'a:1']));
        $this->entrepot->creer($this->fiche(['titre' => 'Novembre', 'debut' => '2026-11-02', 'cle_source' => 'a:2']));
        $this->entrepot->creer($this->fiche(['type' => 'appel', 'titre' => 'Appel sans échéance', 'debut' => '2026-09-01', 'cle_source' => 'b:1']));
        $this->entrepot->creer($this->fiche(['type' => 'appel', 'titre' => 'Appel tardif', 'debut' => '2026-08-01', 'echeance' => '2027-01-15', 'cle_source' => 'b:2']));
        $this->entrepot->creer($this->fiche(['type' => 'appel', 'titre' => 'Appel proche', 'debut' => '2026-10-01', 'echeance' => '2026-11-30', 'cle_source' => 'b:3']));
        $this->entrepot->creer($this->fiche(['type' => 'appel', 'titre' => 'Appel même échéance, publié avant', 'debut' => '2026-07-01', 'echeance' => '2026-11-30', 'cle_source' => 'b:4']));
        $this->entrepot->creer($this->fiche(['type' => 'nouvelle', 'titre' => 'Nouvelle', 'debut' => '2026-10-01', 'cle_source' => 'c:1']));

        $titres = array_column(Donnees::construire('2026-10-25', $this->entrepot, [])['items'], 'titre');
        $this->assertSame(['Novembre', 'Décembre', 'Appel même échéance, publié avant', 'Appel proche', 'Appel tardif', 'Appel sans échéance'], $titres);
    }

    public function test_appels_perimes_absents_du_json_et_des_elements(): void {
        $this->entrepot->creer($this->fiche(['type' => 'appel', 'titre' => 'Récent sans limite', 'debut' => '2026-10-23', 'fin' => '2026-10-23', 'cle_source' => 'a:1']));
        $this->entrepot->creer($this->fiche(['type' => 'appel', 'titre' => 'Vieux sans limite', 'debut' => '2026-06-22', 'fin' => '2026-06-22', 'cree_le' => '2026-10-25', 'cle_source' => 'a:2']));
        $this->entrepot->creer($this->fiche(['type' => 'appel', 'titre' => 'Échéance passée', 'debut' => '2026-10-01', 'echeance' => '2026-10-24', 'cle_source' => 'a:3']));
        $this->entrepot->creer($this->fiche(['type' => 'appel', 'titre' => 'Fin passée', 'debut' => '2026-10-01', 'fin' => '2026-10-20', 'cle_source' => 'a:4']));
        $this->entrepot->creer($this->fiche(['type' => 'appel', 'titre' => 'Vieux avec échéance à venir', 'debut' => '2025-01-01', 'echeance' => '2026-12-01', 'cle_source' => 'a:5']));

        $titres = array_column(Donnees::construire('2026-10-25', $this->entrepot, [])['items'], 'titre');
        $this->assertSame(['Vieux avec échéance à venir', 'Récent sans limite'], $titres);
        $this->assertSame($titres, array_column(Donnees::elements($this->entrepot, [], '2026-10-25'), 'titre'));
    }

    public function test_evenements_finis_depuis_plus_de_douze_mois_absents(): void {
        $this->entrepot->creer($this->fiche(['titre' => 'Fini il y a treize mois', 'debut' => '2025-09-20', 'fin' => '2025-09-24', 'cle_source' => 'e:1']));
        $this->entrepot->creer($this->fiche(['titre' => 'Fini il y a onze mois', 'debut' => '2025-11-20', 'cle_source' => 'e:2']));
        $this->entrepot->creer($this->fiche(['titre' => 'Commencé il y a deux ans, fini le mois prochain', 'debut' => '2024-10-01', 'fin' => '2026-11-30', 'cle_source' => 'e:3']));
        $rsn = [
            $this->evenement_rsn(['titre' => 'RSN ancien', 'debut' => '2025-05-01', 'fin' => '2025-05-01']),
            $this->evenement_rsn(['titre' => 'RSN récent', 'debut' => '2025-10-25', 'fin' => '2025-10-25']),
        ];

        $titres = array_column(Donnees::construire('2026-10-25', $this->entrepot, $rsn)['items'], 'titre');
        sort($titres);
        $this->assertSame(['Commencé il y a deux ans, fini le mois prochain', 'Fini il y a onze mois', 'RSN récent'], $titres);
    }

    public function test_sources_publiques_sans_niveau_avec_nombre_d_elements(): void {
        Journal::consigner('obvia', ['date' => '2026-10-25 03:05', 'lus' => 12]);
        $sources = Donnees::construire('2026-10-25', $this->entrepot, [])['sources'];
        $parNom = array_column($sources, null, 'nom');

        $this->assertArrayHasKey('Obvia : événements', $parNom);
        $obvia = $parNom['Obvia : événements'];
        $this->assertSame(12, $obvia['nb']);
        $this->assertNotSame('', $obvia['flux']);
        $this->assertSame('https://www.obvia.ca/evenements', $obvia['url']);
        $this->assertArrayNotHasKey('niveau', $obvia);
        // Les flux de nouvelles (radar de l'équipe) ne sont pas listés au public.
        $this->assertArrayNotHasKey('INSPQ : nouvelles', $parNom);
        // Une source jamais collectée compte 0 élément.
        $this->assertSame(0, $parNom['Mila : événements']['nb']);
        // Un flux structuré est présenté par son site, pas par l'adresse de son API.
        $this->assertSame('https://digitalhealthcanada.com/', $parNom['Digital Health Canada']['url']);
    }

    public function test_sources_en_mode_equipe_avec_niveau(): void {
        $sources = array_column(Donnees::construire('2026-10-25', $this->entrepot, [], true)['sources'], null, 'nom');
        $this->assertSame('C', $sources['Obvia : événements']['niveau']);
    }

    public function test_sources_desactivees_absentes(): void {
        update_option('rsn_veille_reglages', ['sources' => ['obvia' => ['actif' => false]]]);
        $noms = array_column(Donnees::construire('2026-10-25', $this->entrepot, [])['sources'], 'nom');
        $this->assertNotContains('Obvia : événements', $noms);
    }

    public function test_genere_le_depuis_la_derniere_collecte_sinon_aujourdhui(): void {
        $this->assertSame('2026-10-25', Donnees::construire('2026-10-25', $this->entrepot, [])['genere_le']);
        update_option('rsn_veille_derniere_collecte', 1793000000);
        $this->assertSame(wp_date('c', 1793000000), Donnees::construire('2026-10-25', $this->entrepot, [])['genere_le']);
    }

    public function test_qui_organise_calcule_d_apres_la_categorie(): void {
        $this->entrepot->creer($this->fiche(['titre' => 'A', 'categorie_rsn' => 'Événements RSN', 'cle_source' => 'x:1']));
        $this->entrepot->creer($this->fiche(['titre' => 'B', 'categorie_rsn' => 'Événements partenaires', 'cle_source' => 'x:2']));
        $this->entrepot->creer($this->fiche(['titre' => 'C', 'categorie_rsn' => 'Écosystème numérique', 'cle_source' => 'x:3']));
        $this->entrepot->creer($this->fiche(['titre' => 'D', 'categorie_rsn' => '', 'cle_source' => 'x:4']));
        $this->entrepot->creer($this->fiche(['titre' => 'E', 'categorie_rsn' => 'RSN Events', 'cle_source' => 'x:5']));
        $items = array_column(Donnees::construire('2026-10-25', $this->entrepot, [])['items'], 'organise_par', 'titre');
        $this->assertSame(['A' => 'rsn', 'B' => 'partenaires', 'C' => 'ecosysteme', 'D' => 'ecosysteme', 'E' => 'rsn'], $items);
    }

    public function test_qui_organise_rsn_pour_une_fiche_collectee_depuis_le_site_du_rsn(): void {
        // Source `rsn` (API TEC de rsn.quebec) : pas de catégorie TEC dans la fiche, mais c'est le RSN qui organise.
        $this->entrepot->creer($this->fiche(['titre' => 'Journées scientifiques', 'categorie_rsn' => '', 'cle_source' => 'rsn:207', 'cles_source' => ['rsn:207'], 'source' => 'RSN : événements', 'organisateur' => 'RSN']));
        $this->entrepot->creer($this->fiche(['titre' => 'Appel RSN', 'type' => 'appel', 'echeance' => '2026-12-01', 'categorie_rsn' => '', 'cle_source' => 'rsn_appels:9', 'cles_source' => ['rsn_appels:9']]));
        $items = array_column(Donnees::construire('2026-10-25', $this->entrepot, [])['items'], 'organise_par', 'titre');
        $this->assertSame('rsn', $items['Journées scientifiques']);
        $this->assertSame('rsn', $items['Appel RSN'], 'la clé `rsn_appels:` (flux d’appels du RSN) compte aussi');
    }

    public function test_la_categorie_du_site_decide_de_qui_organise_meme_pour_une_fiche_rsn(): void {
        $rsn = ['source' => 'RSN : événements', 'organisateur' => 'RSN'];
        $this->entrepot->creer($this->fiche($rsn + ['titre' => 'Partenaire', 'categorie_rsn' => 'Événements partenaires', 'cle_source' => 'rsn:1', 'cles_source' => ['rsn:1']]));
        $this->entrepot->creer($this->fiche($rsn + ['titre' => 'Écosystème', 'categorie_rsn' => 'Écosystème numérique', 'cle_source' => 'rsn:2', 'cles_source' => ['rsn:2']]));
        $this->entrepot->creer($this->fiche($rsn + ['titre' => 'Maison', 'categorie_rsn' => 'Événements RSN', 'cle_source' => 'rsn:3', 'cles_source' => ['rsn:3']]));
        $this->entrepot->creer($this->fiche($rsn + ['titre' => 'Sans catégorie', 'categorie_rsn' => '', 'cle_source' => 'rsn:4', 'cles_source' => ['rsn:4']]));
        $items = array_column(Donnees::construire('2026-10-25', $this->entrepot, [])['items'], 'organise_par', 'titre');
        $this->assertSame('partenaires', $items['Partenaire']);
        $this->assertSame('ecosysteme', $items['Écosystème']);
        $this->assertSame('rsn', $items['Maison']);
        $this->assertSame('rsn', $items['Sans catégorie'], 'sans catégorie, la clé `rsn:` suffit');
    }

    public function test_doublon_collecte_avec_image_garde_qui_organise_de_l_evenement_rsn(): void {
        // La fiche collectée l'emporte pour son image, mais TEC fait foi pour « Qui organise ».
        $this->entrepot->creer($this->fiche([
            'image_url' => 'https://rsn.quebec/wp-content/uploads/collectee.jpg',
            'source' => 'RSN : événements', 'organisateur' => 'RSN', 'categorie_rsn' => '',
            'cle_source' => 'rsn:8', 'cles_source' => ['rsn:8'],
        ]));
        $partenaire = $this->evenement_rsn(['categorie_rsn' => 'Événements partenaires', 'organise_par' => 'partenaires']);
        $items = Donnees::construire('2026-10-25', $this->entrepot, [$partenaire])['items'];
        $this->assertCount(1, $items);
        $this->assertSame('partenaires', $items[0]['organise_par']);
        $this->assertSame('Événements partenaires', $items[0]['categorie_rsn']);
        $this->assertSame('https://rsn.quebec/wp-content/uploads/collectee.jpg', $items[0]['image_url']);
    }

    public function test_organise_par_reconnait_les_slugs_nus(): void {
        $this->assertSame('rsn', Donnees::organise_par('rsn'));
        $this->assertSame('partenaires', Donnees::organise_par('partenaires'));
        $this->assertSame('ecosysteme', Donnees::organise_par('ecosysteme'));
        $this->assertSame('ecosysteme', Donnees::organise_par('digital-ecosystem'));
        $this->assertSame('rsn', Donnees::organise_par('RSN Events'));
        $this->assertSame('ecosysteme', Donnees::organise_par('rsn-quelque-chose'), 'seul le slug exact `rsn` compte');
    }

    public function test_appel_du_flux_rsn_est_organise_par_le_rsn(): void {
        $this->entrepot->creer($this->fiche(['titre' => 'Appel du flux', 'type' => 'appel', 'echeance' => '2026-12-01', 'organisateur' => 'RSN', 'categorie_rsn' => '', 'cle_source' => 'rsn_appels:123', 'cles_source' => ['rsn_appels:123']]));
        $this->entrepot->creer($this->fiche(['titre' => 'Appel d’Obvia', 'type' => 'appel', 'echeance' => '2026-12-01', 'categorie_rsn' => '', 'cle_source' => 'obvia:9', 'cles_source' => ['obvia:9']]));
        $items = array_column(Donnees::construire('2026-10-25', $this->entrepot, [])['items'], 'organise_par', 'titre');
        $this->assertSame('rsn', $items['Appel du flux']);
        $this->assertSame('ecosysteme', $items['Appel d’Obvia']);
    }

    public function test_organisateur_rsn_canonique_donne_rsn(): void {
        $this->entrepot->creer($this->fiche(['titre' => 'Proposition', 'organisateur' => 'Réseau santé numérique', 'categorie_rsn' => '', 'cle_source' => 'proposition:abc', 'cles_source' => ['proposition:abc']]));
        $items = Donnees::construire('2026-10-25', $this->entrepot, [])['items'];
        $this->assertSame('rsn', $items[0]['organise_par']);
        $this->assertSame('RSN', $items[0]['organisateur']);
    }

    public function test_image_manuelle_prime_sur_image_url(): void {
        $this->entrepot->creer($this->fiche(['titre' => 'A', 'image_url' => 'https://www.obvia.ca/source.jpg', 'image_manuelle' => 'https://rsn.quebec/wp-content/uploads/mise-en-avant.jpg', 'cle_source' => 'x:1', 'cles_source' => ['x:1']]));
        $this->entrepot->creer($this->fiche(['titre' => 'B', 'image_url' => 'https://www.obvia.ca/source.jpg', 'image_manuelle' => '', 'cle_source' => 'x:2', 'cles_source' => ['x:2']]));
        $items = array_column(Donnees::construire('2026-10-25', $this->entrepot, [])['items'], 'image_url', 'titre');
        $this->assertSame('https://rsn.quebec/wp-content/uploads/mise-en-avant.jpg', $items['A']);
        $this->assertSame('https://www.obvia.ca/source.jpg', $items['B']);
    }

    public function test_organisateur_canonique_fusionne_les_graphies(): void {
        $this->assertSame('Obvia', Donnees::organisateur_canonique('OBVIA'));
        $this->assertSame('Obvia', Donnees::organisateur_canonique(' obvia '));
        $this->assertSame('RSN', Donnees::organisateur_canonique('Réseau santé numérique'));
        $this->assertSame('RSN', Donnees::organisateur_canonique('RSN'));
        $this->assertSame('Fonds de recherche du Québec', Donnees::organisateur_canonique('FRQ'));
        $this->assertSame('Université Laval', Donnees::organisateur_canonique('Université Laval'), 'nom inconnu rendu tel quel');
        $this->assertSame('', Donnees::organisateur_canonique(''));

        $this->entrepot->creer($this->fiche(['titre' => 'A', 'organisateur' => 'OBVIA', 'cle_source' => 'x:1']));
        $this->entrepot->creer($this->fiche(['titre' => 'B', 'organisateur' => 'Réseau santé numérique', 'cle_source' => 'x:2']));
        $items = array_column(Donnees::construire('2026-10-25', $this->entrepot, [])['items'], 'organisateur', 'titre');
        $this->assertSame(['A' => 'Obvia', 'B' => 'RSN'], $items);
    }

    public function test_qui_organise_fourni_par_evenements_rsn_garde(): void {
        // Catégorie reconnue par son slug dans TEC : le nom (traduit, renommé) ne compte plus.
        $items = Donnees::construire('2026-10-25', $this->entrepot, [$this->evenement_rsn(['categorie_rsn' => 'Partner events', 'organise_par' => 'partenaires'])])['items'];
        $this->assertSame('partenaires', $items[0]['organise_par']);
        $items = Donnees::construire('2026-10-25', $this->entrepot, [$this->evenement_rsn(['organise_par' => 'inconnu'])])['items'];
        $this->assertSame('rsn', $items[0]['organise_par'], 'valeur invalide : recalculée depuis la catégorie');
    }

    public function test_slugs_de_categorie(): void {
        $this->assertSame('rsn', Donnees::organise_par('evenements-rsn'));
        $this->assertSame('rsn', Donnees::organise_par('evenements-rsn-en'));
        $this->assertSame('partenaires', Donnees::organise_par('evenements-partenaires'));
        $this->assertSame('ecosysteme', Donnees::organise_par('ecosysteme-numerique'));
    }

    public function test_transients_par_langue(): void {
        $this->assertSame('rsn_veille_json_fr', Donnees::transient(Donnees::TRANSIENT));
        add_filter('wpml_current_language', static fn() => 'en');
        $this->assertSame('rsn_veille_json_en', Donnees::transient(Donnees::TRANSIENT));
        $this->assertSame('rsn_veille_ics_fr', Donnees::transient('rsn_veille_ics', 'fr'));
    }

    public function test_evenement_rsn_partiel_complete_par_les_valeurs_de_fiche(): void {
        $items = Donnees::construire('2026-10-25', $this->entrepot, [['titre' => 'Midi-conférence', 'debut' => '2026-11-20', 'categorie_rsn' => 'Événements partenaires']])['items'];
        $this->assertCount(1, $items);
        $this->assertSame('evenement', $items[0]['type']);
        $this->assertSame('publie', $items[0]['statut']);
        $this->assertSame([], $items[0]['themes']);
        $this->assertSame('Événements partenaires', $items[0]['categorie_rsn']);
    }

    public function test_proposition_publiee_cede_la_place_a_la_fiche_collectee(): void {
        $this->entrepot->creer($this->fiche(['source' => 'proposition', 'niveau' => 'M', 'cle_source' => 'proposition:1', 'cles_source' => ['proposition:1'], 'url' => 'https://proposant.example/']));
        $this->entrepot->creer($this->fiche([]));
        $items = Donnees::construire('2026-10-25', $this->entrepot, [], true)['items'];
        $this->assertCount(1, $items);
        $this->assertSame('Obvia : événements', $items[0]['source']);
        $this->assertSame('https://www.obvia.ca/evenements/colloque', $items[0]['url']);
    }

    public function test_doublon_entre_fiches_collectees_la_premiere_reste_seule(): void {
        $this->entrepot->creer($this->fiche([]));
        $this->entrepot->creer($this->fiche(['source' => 'Mila : événements', 'cle_source' => 'mila:1', 'cles_source' => ['mila:1'], 'url' => 'https://mila.quebec/x']));
        $this->entrepot->creer($this->fiche(['titre' => 'Autre atelier', 'cle_source' => 'mila:2', 'cles_source' => ['mila:2']]));
        $items = Donnees::construire('2026-10-25', $this->entrepot, [], true)['items'];
        $this->assertSame(['Autre atelier', 'Colloque sur la santé numérique'], array_column($items, 'titre'));
        $this->assertSame('Obvia : événements', $items[1]['source'], 'la première fiche collectée reste');
        $this->assertNotContains('https://mila.quebec/x', array_column($items, 'url'));
    }

    public function test_proposition_seule_publiee_reste_affichee(): void {
        $this->entrepot->creer($this->fiche(['source' => 'proposition', 'cle_source' => 'proposition:1', 'cles_source' => ['proposition:1']]));
        $this->assertCount(1, Donnees::construire('2026-10-25', $this->entrepot, [])['items']);
    }
}

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

    public function test_doublon_la_fiche_collectee_avec_image_mise_en_avant_gagne(): void {
        // Pas d'image de la source, mais une image mise en avant choisie à la main : la fiche collectée l'emporte.
        $this->entrepot->creer($this->fiche(['image_url' => '', 'image_manuelle' => 'https://rsn.quebec/wp-content/uploads/choisie.jpg']));
        $items = Donnees::construire('2026-10-25', $this->entrepot, [$this->evenement_rsn(['image_url' => ''])])['items'];
        $this->assertCount(1, $items);
        $this->assertSame('https://www.obvia.ca/evenements/colloque', $items[0]['url']);
        $this->assertSame('https://rsn.quebec/wp-content/uploads/choisie.jpg', $items[0]['image_url']);
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
        // Publié le 2026-10-05, soit moins de 30 jours avant le jour du test (2026-10-25) : sans date limite, il reste en cours.
        $this->entrepot->creer($this->fiche(['type' => 'appel', 'titre' => 'Appel sans échéance', 'debut' => '2026-10-05', 'cle_source' => 'b:1']));
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
        // Publié le 2026-09-20, soit 35 jours avant le jour du test (2026-10-25) : au-delà des 30 jours, absent (il serait présent avec 90).
        $this->entrepot->creer($this->fiche(['type' => 'appel', 'titre' => 'Sans limite, publié il y a 35 jours', 'debut' => '2026-09-20', 'fin' => '2026-09-20', 'cle_source' => 'a:6']));
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

    public function test_langue_courante_fr_sans_wpml_et_en_avec_wpml(): void {
        $this->assertSame('fr', Donnees::langue_courante());
        add_filter('wpml_current_language', static fn() => 'en');
        $this->assertSame('en', Donnees::langue_courante());
    }

    public function test_langue_courante_hors_fr_en_vaut_fr(): void {
        add_filter('wpml_current_language', static fn() => 'de');
        $this->assertSame('fr', Donnees::langue_courante());
    }

    public function test_json_porte_la_langue(): void {
        $this->assertSame('fr', Donnees::construire('2026-10-25', $this->entrepot, [])['langue']);
        $this->assertSame('en', Donnees::construire('2026-10-25', $this->entrepot, [], false, 'en')['langue']);
        $this->assertSame('fr', Donnees::construire('2026-10-25', $this->entrepot, [], false, 'xx')['langue']);
        add_filter('wpml_current_language', static fn() => 'en');
        $this->assertSame('en', Donnees::construire('2026-10-25', $this->entrepot, [])['langue']);
        $this->assertSame('en', Donnees::en_cache()['langue']);
    }

    public function test_elements_accepte_la_langue(): void {
        $this->assertSame([], Donnees::elements($this->entrepot, [], '2026-10-25', 'en'));
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

    // --- Dédoublonnage par groupe de traduction (WPML) ---

    /** Fiche collectée depuis le site (article français `rsn:12`), avec image. */
    private function fiche_du_site(array $champs = []): Fiche {
        return $this->fiche(array_merge([
            'titre' => 'Journées scientifiques', 'organisateur' => 'RSN', 'source' => 'RSN : événements', 'niveau' => 'A',
            'cle_source' => 'rsn:12', 'cles_source' => ['rsn:12'], 'titres_source' => ['rsn:12' => 'Journées scientifiques'],
            'url' => 'https://rsn.quebec/evenement/journees-scientifiques/', 'image_url' => 'https://rsn.quebec/wp-content/uploads/js.jpg',
            'lieu' => 'Montréal', 'ville' => 'Montréal', 'resume' => 'Résumé en français.',
        ], $champs));
    }

    /** Événement du RSN gardé en anglais (article 45, groupe 7), dont le titre français est « Journées scientifiques ». */
    private function evenement_anglais(array $champs = []): array {
        return $this->evenement_rsn(array_merge([
            'titre' => 'Scientific Days', 'titre_fr' => 'Journées scientifiques', 'tec_trid' => 7, 'langue' => 'en',
            'url' => 'https://rsn.quebec/en/event/scientific-days/', 'image_url' => '', 'lieu' => 'Montreal venue', 'ville' => 'Montreal',
            'resume' => 'English summary.', 'cle_source' => 'rsn-tec:45',
        ], $champs));
    }

    private function groupes_wpml(array $trids): void {
        add_filter('wpml_element_trid', static fn($valeur, $id, $type) => $type === 'post_tribe_events' ? ($trids[$id] ?? $valeur) : $valeur);
    }

    public function test_evenement_rsn_et_fiche_collectee_du_meme_groupe_sont_un_doublon_en_anglais(): void {
        $this->entrepot->creer($this->fiche_du_site());
        $this->groupes_wpml([12 => 7]);
        $items = Donnees::elements($this->entrepot, [$this->evenement_anglais()], '2026-10-25', 'en');
        $this->assertCount(1, $items);
        $this->assertSame('https://rsn.quebec/wp-content/uploads/js.jpg', $items[0]['image_url'], "l'image de la fiche collectée");
        $this->assertSame('Scientific Days', $items[0]['titre']);
        $this->assertSame('https://rsn.quebec/en/event/scientific-days/', $items[0]['url']);
        $this->assertSame('en', $items[0]['langue']);
        $this->assertSame('English summary.', $items[0]['resume']);
        $this->assertSame('Montreal venue', $items[0]['lieu']);
        $this->assertSame('Montreal', $items[0]['ville']);
        $this->assertSame('rsn:12', $items[0]['cle_source'], 'la fiche collectée reste la fiche');
    }

    public function test_meme_groupe_en_francais_la_fiche_garde_son_texte(): void {
        $this->entrepot->creer($this->fiche_du_site());
        $this->groupes_wpml([12 => 7]);
        $evenement = $this->evenement_rsn(['titre' => 'Journées scientifiques', 'titre_fr' => 'Journées scientifiques', 'tec_trid' => 7, 'langue' => 'fr', 'image_url' => '', 'cle_source' => 'rsn-tec:12']);
        $items = Donnees::elements($this->entrepot, [$evenement], '2026-10-25', 'fr');
        $this->assertCount(1, $items);
        $this->assertSame('https://rsn.quebec/evenement/journees-scientifiques/', $items[0]['url']);
        $this->assertSame('fr', $items[0]['langue']);
    }

    public function test_sans_wpml_le_groupe_est_l_identifiant(): void {
        $this->entrepot->creer($this->fiche_du_site());
        // Même identifiant (12), titres sans rapport : doublon par le groupe seul.
        $meme = $this->evenement_rsn(['titre' => 'Tout autre titre', 'titre_fr' => 'Tout autre titre', 'tec_trid' => null, 'cle_source' => 'rsn-tec:12']);
        $this->assertCount(1, Donnees::elements($this->entrepot, [$meme], '2026-10-25', 'fr'));
        // Autre identifiant (13) et autre titre : deux éléments.
        $autre = $this->evenement_rsn(['titre' => 'Tout autre titre', 'titre_fr' => 'Tout autre titre', 'tec_trid' => null, 'cle_source' => 'rsn-tec:13']);
        $this->assertCount(2, Donnees::elements($this->entrepot, [$autre], '2026-10-25', 'fr'));
    }

    public function test_titres_differents_mais_meme_groupe(): void {
        $this->entrepot->creer($this->fiche_du_site(['titre' => 'Journées scientifiques du RSN 2026']));
        $this->groupes_wpml([12 => 7]);
        $evenement = $this->evenement_anglais(['titre_fr' => 'JS 2026 : un titre sans rapport']);
        $items = Donnees::elements($this->entrepot, [$evenement], '2026-10-25', 'en');
        $this->assertCount(1, $items);
        $this->assertSame('Scientific Days', $items[0]['titre']);
        // Sans le groupe commun, les titres différents donnent deux éléments.
        $this->assertCount(2, Donnees::elements($this->entrepot, [$this->evenement_anglais(['titre_fr' => 'JS 2026 : un titre sans rapport', 'tec_trid' => 8])], '2026-10-25', 'en'));
    }

    public function test_titres_francais_identiques_sans_groupe_commun_restent_un_doublon(): void {
        // Le titre français (titre_fr) fait la clé, pas le titre anglais affiché : comme en 0.3.0 pour deux fiches de même titre.
        $this->entrepot->creer($this->fiche_du_site());
        $this->assertCount(1, Donnees::elements($this->entrepot, [$this->evenement_anglais(['tec_trid' => null, 'cle_source' => 'rsn-tec:45'])], '2026-10-25', 'en'));
    }

    public function test_fiche_collectee_sans_image_cede_a_l_evenement_du_meme_groupe(): void {
        $this->entrepot->creer($this->fiche_du_site(['image_url' => '']));
        $this->groupes_wpml([12 => 7]);
        $items = Donnees::elements($this->entrepot, [$this->evenement_anglais(['image_url' => 'https://rsn.quebec/wp-content/uploads/en.jpg'])], '2026-10-25', 'en');
        $this->assertCount(1, $items);
        $this->assertSame('rsn-tec:45', $items[0]['cle_source']);
        $this->assertSame('Scientific Days', $items[0]['titre']);
    }

    public function test_la_fiche_collectee_garde_son_image_mais_l_evenement_fait_foi_pour_qui_organise(): void {
        $this->entrepot->creer($this->fiche_du_site());
        $this->groupes_wpml([12 => 7]);
        $items = Donnees::elements($this->entrepot, [$this->evenement_anglais(['categorie_rsn' => 'Partner events', 'organise_par' => 'partenaires'])], '2026-10-25', 'en');
        $this->assertSame('partenaires', $items[0]['organise_par']);
        $this->assertSame('Partner events', $items[0]['categorie_rsn']);
    }

    public function test_evenement_anglais_sans_lieu_ni_resume_la_fiche_garde_les_siens(): void {
        $this->entrepot->creer($this->fiche_du_site());
        $this->groupes_wpml([12 => 7]);
        $items = Donnees::elements($this->entrepot, [$this->evenement_anglais(['lieu' => '', 'ville' => '', 'resume' => ''])], '2026-10-25', 'en');
        $this->assertCount(1, $items);
        $this->assertSame('Scientific Days', $items[0]['titre']);
        $this->assertSame('https://rsn.quebec/en/event/scientific-days/', $items[0]['url']);
        $this->assertSame('en', $items[0]['langue']);
        $this->assertSame('Montréal', $items[0]['lieu']);
        $this->assertSame('Montréal', $items[0]['ville']);
        $this->assertSame('Résumé en français.', $items[0]['resume']);
    }

    public function test_un_trid_et_un_identifiant_d_article_de_meme_valeur_ne_sont_pas_un_doublon(): void {
        // Fiche rsn:12 sans groupe WPML (groupe « id 12 ») contre un événement du groupe de traduction 12 : deux groupes distincts.
        $this->entrepot->creer($this->fiche_du_site());
        $evenement = $this->evenement_anglais(['titre_fr' => 'Tout autre titre', 'tec_trid' => 12]);
        $this->assertCount(2, Donnees::elements($this->entrepot, [$evenement], '2026-10-25', 'en'));
    }

    // --- Appels du RSN traduits ---

    private function appel_rsn(): Fiche {
        return $this->fiche([
            'type' => 'appel', 'titre' => 'Appel de projets', 'debut' => '2026-10-05', 'echeance' => '2026-12-15',
            'organisateur' => 'RSN', 'source' => 'RSN : appels', 'niveau' => 'B', 'cle_source' => 'rsn_appels:abc', 'cles_source' => ['rsn_appels:abc'],
            'url' => 'https://rsn.quebec/appel-de-projets/', 'resume' => 'Résumé en français.', 'categorie' => 'Financement',
        ]);
    }

    /** Article anglais 2749 (traduction de 2710). */
    private function traduction_appel(array $champs = []): void {
        $GLOBALS['rsn_veille_wp']['urls']['https://rsn.quebec/appel-de-projets/'] = 2710;
        wp_insert_post(array_merge(['ID' => 2749, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Call for projects', 'post_excerpt' => '<p>English excerpt &amp; more.</p>'], $champs));
        $GLOBALS['rsn_veille_wp']['permaliens'][2749] = 'https://rsn.quebec/en/call-for-projects/';
        $GLOBALS['rsn_veille_wp']['categories'][2749] = ['Funding', 'Other'];
        add_filter('wpml_object_id', static fn($id, $type, $retour, $langue) => $id === 2710 && $type === 'post' && $retour === false && $langue === 'en' ? 2749 : null);
    }

    public function test_appel_rsn_traduit_en_anglais(): void {
        $this->entrepot->creer($this->appel_rsn());
        $this->traduction_appel();
        $items = Donnees::elements($this->entrepot, [], '2026-10-25', 'en');
        $this->assertCount(1, $items);
        $this->assertSame('Call for projects', $items[0]['titre']);
        $this->assertSame('English excerpt & more.', $items[0]['resume']);
        $this->assertSame('https://rsn.quebec/en/call-for-projects/', $items[0]['url']);
        $this->assertSame('Funding', $items[0]['categorie']);
        $this->assertSame('en', $items[0]['langue']);
        $this->assertSame('2026-12-15', $items[0]['echeance'], "l'échéance ne change pas");
        $this->assertSame('rsn_appels:abc', $items[0]['cle_source']);
    }

    public function test_appel_rsn_identifiant_tire_du_guid_de_la_cle_de_source(): void {
        // Le guid `?p=2710` donne l'article même si l'adresse de la fiche n'est pas dans la table des adresses.
        $fiche = $this->appel_rsn();
        $fiche->cle_source = 'rsn_appels:https://rsn.quebec/?p=2710';
        $fiche->cles_source = ['rsn_appels:https://rsn.quebec/?p=2710'];
        $this->entrepot->creer($fiche);
        $this->traduction_appel();
        $GLOBALS['rsn_veille_wp']['urls'] = [];
        $items = Donnees::elements($this->entrepot, [], '2026-10-25', 'en');
        $this->assertSame('Call for projects', $items[0]['titre']);
        $this->assertSame('https://rsn.quebec/en/call-for-projects/', $items[0]['url']);
        $this->assertSame('en', $items[0]['langue']);
    }

    public function test_appel_rsn_guid_dans_cles_source_et_cle_sans_p_repli_sur_l_adresse(): void {
        // Identifiant dans `cles_source` seulement (clé principale sans `?p=`).
        $fiche = $this->appel_rsn();
        $fiche->cles_source = ['rsn_appels:abc', 'rsn_appels:https://rsn.quebec/?post_type=x&p=2710'];
        $this->entrepot->creer($fiche);
        $this->traduction_appel();
        $GLOBALS['rsn_veille_wp']['urls'] = [];
        $this->assertSame('Call for projects', Donnees::elements($this->entrepot, [], '2026-10-25', 'en')[0]['titre']);
    }

    public function test_appel_rsn_cle_sans_p_repli_sur_url_to_postid(): void {
        // Clé `rsn_appels:abc` sans `?p=` : l'identifiant vient de l'adresse (table des adresses).
        $this->entrepot->creer($this->appel_rsn());
        $this->traduction_appel();
        $this->assertSame('Call for projects', Donnees::elements($this->entrepot, [], '2026-10-25', 'en')[0]['titre']);
    }

    public function test_appel_rsn_extrait_de_l_article_traduit_nettoye_et_raccourci(): void {
        $this->entrepot->creer($this->appel_rsn());
        $this->traduction_appel(['post_excerpt' => '<p>' . str_repeat('word ', 100) . '</p>', 'post_content' => 'Contenu complet qui ne sert pas.']);
        $resume = Donnees::elements($this->entrepot, [], '2026-10-25', 'en')[0]['resume'];
        $this->assertStringEndsWith('…', $resume);
        $this->assertLessThanOrEqual(261, mb_strlen($resume));
        $this->assertStringNotContainsString('<p>', $resume);
        $this->assertStringNotContainsString('Contenu complet', $resume);
    }

    public function test_appel_rsn_en_francais_aucune_traduction_tentee(): void {
        $this->entrepot->creer($this->appel_rsn());
        $this->traduction_appel();
        $appels = 0;
        add_filter('wpml_object_id', static function ($valeur) use (&$appels) {
            $appels++;
            return $valeur;
        });
        $items = Donnees::elements($this->entrepot, [], '2026-10-25', 'fr');
        $this->assertSame(0, $appels);
        $this->assertSame('Appel de projets', $items[0]['titre']);
        $this->assertSame('https://rsn.quebec/appel-de-projets/', $items[0]['url']);
        $this->assertSame('fr', $items[0]['langue']);
    }

    public function test_appel_rsn_sans_traduction_reste_francais(): void {
        $this->entrepot->creer($this->appel_rsn());
        $this->traduction_appel();
        $GLOBALS['rsn_veille_wp']['filtres']['wpml_object_id'] = [static fn($valeur) => null];
        $items = Donnees::elements($this->entrepot, [], '2026-10-25', 'en');
        $this->assertSame('Appel de projets', $items[0]['titre']);
        $this->assertSame('Résumé en français.', $items[0]['resume']);
        $this->assertSame('https://rsn.quebec/appel-de-projets/', $items[0]['url']);
        $this->assertSame('Financement', $items[0]['categorie']);
        $this->assertSame('fr', $items[0]['langue']);
    }

    public function test_appel_rsn_dont_la_traduction_est_l_article_d_origine_reste_inchange(): void {
        $this->entrepot->creer($this->appel_rsn());
        $this->traduction_appel();
        // WPML rend l'article d'origine (pas de traduction en anglais) : ni titre, ni langue changés.
        $GLOBALS['rsn_veille_wp']['filtres']['wpml_object_id'] = [static fn($id) => $id];
        wp_insert_post(['ID' => 2710, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Titre de l\'article d\'origine', 'post_excerpt' => 'Extrait d\'origine.']);
        $items = Donnees::elements($this->entrepot, [], '2026-10-25', 'en');
        $this->assertSame('Appel de projets', $items[0]['titre']);
        $this->assertSame('Résumé en français.', $items[0]['resume']);
        $this->assertSame('https://rsn.quebec/appel-de-projets/', $items[0]['url']);
        $this->assertSame('fr', $items[0]['langue']);
    }

    public function test_appel_rsn_url_inconnue(): void {
        $this->entrepot->creer($this->appel_rsn());
        $this->traduction_appel();
        $GLOBALS['rsn_veille_wp']['urls'] = [];
        $items = Donnees::elements($this->entrepot, [], '2026-10-25', 'en');
        $this->assertSame('Appel de projets', $items[0]['titre']);
        $this->assertSame('fr', $items[0]['langue']);
    }

    public function test_appel_rsn_extrait_vide_garde_le_resume(): void {
        $this->entrepot->creer($this->appel_rsn());
        $this->traduction_appel(['post_excerpt' => '']);
        $items = Donnees::elements($this->entrepot, [], '2026-10-25', 'en');
        $this->assertSame('Call for projects', $items[0]['titre']);
        $this->assertSame('Résumé en français.', $items[0]['resume']);
    }

    public function test_appel_rsn_sans_categorie_traduite_garde_la_categorie(): void {
        $this->entrepot->creer($this->appel_rsn());
        $this->traduction_appel();
        $GLOBALS['rsn_veille_wp']['categories'][2749] = [];
        $items = Donnees::elements($this->entrepot, [], '2026-10-25', 'en');
        $this->assertSame('Financement', $items[0]['categorie']);
        $this->assertSame('Call for projects', $items[0]['titre']);
    }

    public function test_seuls_les_appels_du_rsn_sont_traduits(): void {
        $this->entrepot->creer($this->fiche(['type' => 'appel', 'titre' => 'Appel de projets', 'debut' => '2026-10-05', 'echeance' => '2026-12-15', 'cle_source' => 'ivado:1', 'cles_source' => ['ivado:1'], 'url' => 'https://rsn.quebec/appel-de-projets/']));
        $this->traduction_appel();
        $items = Donnees::elements($this->entrepot, [], '2026-10-25', 'en');
        $this->assertSame('Appel de projets', $items[0]['titre']);
    }

    public function test_champs_anglais_appliques_en_anglais_seulement(): void {
        $this->entrepot->creer($this->fiche(['titre_en' => 'Digital health colloquium', 'resume' => 'Résumé FR', 'resume_en' => 'EN summary', 'url_en' => 'https://www.obvia.ca/en/colloque']));
        $this->entrepot->creer($this->fiche(['titre' => 'Atelier', 'debut' => '2026-11-20', 'cle_source' => 'obvia:2', 'cles_source' => ['obvia:2'], 'resume' => 'Résumé FR 2', 'titre_en' => 'Workshop']));

        $en = Donnees::elements($this->entrepot, [], '2026-10-25', 'en');
        $this->assertSame(['Digital health colloquium', 'EN summary', 'https://www.obvia.ca/en/colloque', 'en'], [$en[0]['titre'], $en[0]['resume'], $en[0]['url'], $en[0]['langue']]);
        // Résumé et adresse anglais vides : ceux de la fiche restent.
        $this->assertSame(['Workshop', 'Résumé FR 2', 'https://www.obvia.ca/evenements/colloque', 'en'], [$en[1]['titre'], $en[1]['resume'], $en[1]['url'], $en[1]['langue']]);

        $fr = Donnees::elements($this->entrepot, [], '2026-10-25', 'fr');
        $this->assertSame(['Colloque sur la santé numérique', 'Résumé FR', 'https://www.obvia.ca/evenements/colloque', 'fr'], [$fr[0]['titre'], $fr[0]['resume'], $fr[0]['url'], $fr[0]['langue']]);
    }

    public function test_fiche_sans_titre_en_reste_inchangee_en_anglais(): void {
        $this->entrepot->creer($this->fiche(['resume_en' => 'EN summary', 'url_en' => 'https://www.obvia.ca/en/colloque']));
        $e = Donnees::elements($this->entrepot, [], '2026-10-25', 'en')[0];
        $this->assertSame(['Colloque sur la santé numérique', 'fr', 'https://www.obvia.ca/evenements/colloque'], [$e['titre'], $e['langue'], $e['url']]);
    }

    public function test_appel_collecte_prend_ses_champs_anglais(): void {
        $this->entrepot->creer($this->fiche(['type' => 'appel', 'titre' => 'Appel à projets', 'debut' => '2026-10-20', 'echeance' => '2026-12-01', 'cle_source' => 'ivado:5', 'cles_source' => ['ivado:5'], 'titre_en' => 'Call for projects', 'url_en' => 'https://ivado.ca/en/call']));
        $a = Donnees::elements($this->entrepot, [], '2026-10-25', 'en')[0];
        $this->assertSame(['Call for projects', 'https://ivado.ca/en/call', 'en', '2026-12-01'], [$a['titre'], $a['url'], $a['langue'], $a['echeance']]);
    }

    public function test_les_champs_anglais_ne_sont_jamais_dans_le_json(): void {
        $this->entrepot->creer($this->fiche(['titre_en' => 'Colloquium', 'resume_en' => 'EN summary', 'url_en' => 'https://www.obvia.ca/en/colloque']));
        foreach (['fr', 'en'] as $langue) {
            $item = Donnees::construire('2026-10-25', $this->entrepot, [], false, $langue)['items'][0];
            $this->assertArrayNotHasKey('titre_en', $item);
            $this->assertArrayNotHasKey('resume_en', $item);
            $this->assertArrayNotHasKey('url_en', $item);
            $this->assertStringNotContainsString('_en"', (string) json_encode($item));
        }
        $this->assertSame('Colloquium', Donnees::construire('2026-10-25', $this->entrepot, [], false, 'en')['items'][0]['titre']);
    }

    public function test_dedoublonnage_garde_la_cle_du_titre_francais_malgre_titre_en(): void {
        $this->entrepot->creer($this->fiche(['titre_en' => 'Colloquium']));
        $this->entrepot->creer($this->fiche(['cle_source' => 'obvia:99', 'cles_source' => ['obvia:99'], 'titre_en' => 'Colloquium bis']));
        $this->assertCount(1, Donnees::elements($this->entrepot, [], '2026-10-25', 'en'));
        // Un événement du RSN au titre français identique reste un doublon.
        $rsn = $this->evenement_rsn(['titre' => 'Colloque sur la santé numérique', 'cle_source' => 'rsn-tec:7']);
        $this->assertCount(1, Donnees::elements($this->entrepot, [$rsn], '2026-10-25', 'en'));
    }

    public function test_appel_du_rsn_traduit_par_wpml_prime_sur_les_champs_anglais(): void {
        $this->traduction_appel();
        $appel = $this->appel_rsn();
        $appel->titre_en = 'Titre anglais de la fiche';
        $this->entrepot->creer($appel);
        $a = Donnees::elements($this->entrepot, [], '2026-10-25', 'en')[0];
        $this->assertSame('Call for projects', $a['titre']);
    }

    public function test_cle_dedoublonnage_de_est_celle_de_la_fiche(): void {
        $fiche = $this->fiche(['titre' => 'Journées scientifiques !']);
        $this->assertSame($fiche->cle_dedoublonnage(), Fiche::cle_dedoublonnage_de('2026-11-12', 'Journées scientifiques !'));
        $this->assertSame('2026-11-12|journeesscientifiques', Fiche::cle_dedoublonnage_de('2026-11-12', 'Journées scientifiques'));
        $this->assertSame('|', Fiche::cle_dedoublonnage_de('', ''));
    }
}

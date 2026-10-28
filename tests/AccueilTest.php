<?php
declare(strict_types=1);

require_once __DIR__ . '/bouchons-wordpress.php';

use PHPUnit\Framework\TestCase;
use RSN\Veille\Collecte;
use RSN\Veille\Public\Accueil;
use RSN\Veille\Public\Raccourci;

/** Le code court `[rsn_veille_accueil]` : sélection pure, rendu, ressources et purge de l'accueil. */
final class AccueilTest extends TestCase {
    private const AUJ = '2026-10-28';

    protected function setUp(): void {
        rsn_veille_bouchons_reinitialiser();
        $GLOBALS['rsn_veille_test_aujourdhui'] = self::AUJ;
        Accueil::accrocher();
        do_action('init');
    }

    protected function tearDown(): void {
        $GLOBALS['rsn_veille_test_aujourdhui'] = '2026-10-25';
    }

    /** Un événement de la forme de `Donnees::construire` (champs publics). */
    private function ev(string $debut, array $champs = []): array {
        static $id = 0;
        $id++;
        return array_merge([
            'id' => $id, 'type' => 'evenement', 'titre' => 'Événement ' . $id, 'debut' => $debut, 'fin' => null,
            'heure' => null, 'organisateur' => 'Obvia', 'categorie_rsn' => null, 'lieu' => 'Salle A', 'ville' => 'Montréal',
            'region' => 'Québec', 'format' => 'Présentiel', 'prix' => '', 'langue' => 'fr', 'themes' => [],
            'resume' => 'Un résumé.', 'url' => 'https://exemple.org/' . $id, 'image_url' => '', 'statut' => 'publie',
            'categorie' => null, 'echeance' => null, 'organise_par' => 'ecosysteme',
        ], $champs);
    }

    private function titres(array $liste): array {
        return array_column($liste, 'titre');
    }

    // --- selection ---

    public function test_un_seul_evenement_par_organisateur_dans_l_ecosysteme(): void {
        $items = [
            $this->ev('2026-11-10', ['titre' => 'DHC 2', 'organisateur' => 'Digital Health Canada']),
            $this->ev('2026-11-02', ['titre' => 'DHC 1', 'organisateur' => 'Digital Health Canada']),
            $this->ev('2026-11-05', ['titre' => 'Mila 1', 'organisateur' => 'Mila']),
            $this->ev('2026-11-20', ['titre' => 'Mila 2', 'organisateur' => 'Mila']),
        ];
        $sel = Accueil::selection($items, self::AUJ);
        $this->assertSame(['DHC 1', 'Mila 1'], $this->titres($sel['eco']));
    }

    public function test_quatre_au_plus_dans_l_ecosysteme_et_nombre_configurable(): void {
        $items = [];
        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $i => $org) {
            $items[] = $this->ev('2026-11-0' . ($i + 1), ['titre' => 'Ev ' . $org, 'organisateur' => $org]);
        }
        $this->assertSame(['Ev A', 'Ev B', 'Ev C', 'Ev D'], $this->titres(Accueil::selection($items, self::AUJ)['eco']));
        $this->assertSame(['Ev A', 'Ev B'], $this->titres(Accueil::selection($items, self::AUJ, 2)['eco']));
        $this->assertCount(6, Accueil::selection($items, self::AUJ, 10)['eco']);
    }

    public function test_serie_longue_commencee_exclue_des_deux_colonnes_mais_presente_au_calendrier(): void {
        $items = [
            $this->ev('2026-10-01', ['titre' => 'Cours (série)', 'fin' => '2026-12-15', 'organisateur' => 'Mila']),
            $this->ev('2026-10-05', ['titre' => 'Série RSN', 'fin' => '2026-11-30', 'organise_par' => 'rsn', 'organisateur' => 'RSN']),
            $this->ev('2026-11-03', ['titre' => 'Colloque', 'organisateur' => 'Obvia']),
        ];
        $sel = Accueil::selection($items, self::AUJ);
        $this->assertSame(['Colloque'], $this->titres($sel['eco']));
        $this->assertSame([], $sel['rsn']);
        $this->assertSame(['Cours (série)', 'Série RSN', 'Colloque'], $this->titres($sel['calendrier']));
    }

    public function test_serie_longue_a_venir_ou_du_jour_est_gardee(): void {
        $items = [
            $this->ev('2026-11-09', ['titre' => 'Série à venir', 'fin' => '2026-12-20', 'organisateur' => 'Mila']),
            $this->ev(self::AUJ, ['titre' => 'Série qui débute aujourd\'hui', 'fin' => '2026-12-20', 'organisateur' => 'IVADO']),
        ];
        $this->assertSame(['Série qui débute aujourd\'hui', 'Série à venir'], $this->titres(Accueil::selection($items, self::AUJ)['eco']));
    }

    public function test_sept_jours_n_est_pas_une_serie_longue(): void {
        $items = [
            $this->ev('2026-10-24', ['titre' => 'Semaine', 'fin' => '2026-10-31', 'organisateur' => 'Obvia']),
            $this->ev('2026-10-24', ['titre' => 'Huit jours', 'fin' => '2026-11-01', 'organisateur' => 'Mila']),
        ];
        $this->assertSame(['Semaine'], $this->titres(Accueil::selection($items, self::AUJ)['eco']));
    }

    public function test_evenement_court_en_cours_reste_a_venir_et_passe_est_ecarte(): void {
        $items = [
            $this->ev('2026-10-27', ['titre' => 'En cours', 'fin' => '2026-10-29', 'organisateur' => 'Obvia']),
            $this->ev('2026-10-27', ['titre' => 'Fini hier', 'fin' => '2026-10-27', 'organisateur' => 'Mila']),
            $this->ev('2026-10-20', ['titre' => 'Passé', 'organisateur' => 'IVADO']),
            $this->ev(self::AUJ, ['titre' => 'Aujourd\'hui', 'organisateur' => 'Acfas']),
        ];
        $sel = Accueil::selection($items, self::AUJ);
        $this->assertSame(['En cours', 'Aujourd\'hui'], $this->titres($sel['eco']));
    }

    public function test_rsn_tous_gardes_et_tries_par_debut_puis_titre(): void {
        $rsn = ['organise_par' => 'rsn', 'organisateur' => 'RSN'];
        $items = [
            $this->ev('2026-12-01', $rsn + ['titre' => 'Zeta']),
            $this->ev('2026-11-10', $rsn + ['titre' => 'Beta']),
            $this->ev('2026-11-10', $rsn + ['titre' => 'Alpha']),
            $this->ev('2026-11-02', $rsn + ['titre' => 'Premier']),
            $this->ev('2026-11-03', $rsn + ['titre' => 'Deuxième']),
            $this->ev('2026-11-04', $rsn + ['titre' => 'Troisième']),
        ];
        $sel = Accueil::selection($items, self::AUJ);
        $this->assertSame(['Premier', 'Deuxième', 'Troisième', 'Alpha', 'Beta', 'Zeta'], $this->titres($sel['rsn']));
        $this->assertSame([], $sel['eco'], 'les événements du RSN ne sont pas dans la colonne de l\'écosystème');
        $this->assertCount(6, $sel['calendrier'], 'le calendrier garde les événements du RSN');
    }

    public function test_ecosysteme_trie_par_debut_et_partenaires_comptent_dans_l_ecosysteme(): void {
        $items = [
            $this->ev('2026-11-20', ['titre' => 'Tard', 'organisateur' => 'A', 'organise_par' => 'partenaires']),
            $this->ev('2026-11-02', ['titre' => 'Tôt', 'organisateur' => 'B']),
        ];
        $this->assertSame(['Tôt', 'Tard'], $this->titres(Accueil::selection($items, self::AUJ)['eco']));
    }

    public function test_les_appels_sont_ignores(): void {
        $items = [
            $this->ev('2026-11-02', ['titre' => 'Appel', 'type' => 'appel', 'echeance' => '2026-11-30']),
            $this->ev('2026-11-03', ['titre' => 'Colloque']),
        ];
        $sel = Accueil::selection($items, self::AUJ);
        $this->assertSame(['Colloque'], $this->titres($sel['eco']));
        $this->assertSame(['Colloque'], $this->titres($sel['calendrier']));
    }

    public function test_calendrier_a_partir_du_premier_du_mois_selon_la_fin(): void {
        $items = [
            $this->ev('2026-09-15', ['titre' => 'Septembre']),
            $this->ev('2026-10-01', ['titre' => 'Début octobre, passé']),
            $this->ev('2026-10-02', ['titre' => 'Passé ce mois', 'organise_par' => 'rsn', 'organisateur' => 'RSN']),
            $this->ev('2026-09-25', ['titre' => 'Fini le 1er octobre', 'fin' => '2026-10-01']),
            $this->ev('2026-09-25', ['titre' => 'Fini en septembre', 'fin' => '2026-09-30']),
            $this->ev('2027-02-10', ['titre' => 'Loin']),
            $this->ev('2026-10-30', ['titre' => 'Bientôt']),
        ];
        $sel = Accueil::selection($items, self::AUJ);
        $this->assertSame(
            ['Fini le 1er octobre', 'Début octobre, passé', 'Passé ce mois', 'Bientôt', 'Loin'],
            $this->titres($sel['calendrier'])
        );
    }

    public function test_champs_reduits(): void {
        $sel = Accueil::selection([$this->ev('2026-11-03', ['organise_par' => 'rsn', 'organisateur' => 'RSN', 'image_url' => 'https://exemple.org/i.jpg'])], self::AUJ);
        $cles = ['id', 'titre', 'debut', 'fin', 'organisateur', 'organise_par', 'format', 'url', 'ville', 'langue', 'image_url'];
        $this->assertSame($cles, array_keys($sel['rsn'][0]));
        $this->assertSame($cles, array_keys($sel['calendrier'][0]));
        $this->assertSame('https://exemple.org/i.jpg', $sel['rsn'][0]['image_url']);
        foreach (['resume', 'lieu', 'prix', 'themes', 'type', 'statut'] as $absent) {
            $this->assertArrayNotHasKey($absent, $sel['rsn'][0]);
        }
    }

    public function test_champ_absent_devient_null(): void {
        $sel = Accueil::selection([['type' => 'evenement', 'titre' => 'Minimal', 'debut' => '2026-11-03', 'organise_par' => 'rsn']], self::AUJ);
        $this->assertNull($sel['rsn'][0]['ville']);
        $this->assertNull($sel['rsn'][0]['image_url']);
    }

    public function test_aucun_rsn_et_liste_vide(): void {
        $sel = Accueil::selection([$this->ev('2026-11-03')], self::AUJ);
        $this->assertSame([], $sel['rsn']);
        $this->assertCount(1, $sel['eco']);
        $this->assertSame(['rsn' => [], 'eco' => [], 'calendrier' => []], Accueil::selection([], self::AUJ));
    }

    public function test_evenement_sans_debut_est_ignore(): void {
        $this->assertSame(['rsn' => [], 'eco' => [], 'calendrier' => []], Accueil::selection([$this->ev('', ['debut' => null])], self::AUJ));
    }

    // --- rendre ---

    private function noeud_json(string $html, string $attribut): array {
        $this->assertSame(1, preg_match('#<script type="application/json" ' . $attribut . '>(.*?)</script>#s', $html, $m), $attribut);
        $donnees = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($donnees);
        return $donnees;
    }

    public function test_le_code_court_est_enregistre(): void {
        $this->assertArrayHasKey('rsn_veille_accueil', $GLOBALS['rsn_veille_wp']['raccourcis']);
        $this->assertSame('rsn_veille_accueil', Accueil::NOM);
        $this->assertStringContainsString('class="rsn-veille-accueil"', do_shortcode('[rsn_veille_accueil]'));
    }

    public function test_structure_du_rendu(): void {
        $html = Accueil::rendre();
        $this->assertStringStartsWith('<div class="rsn-veille-accueil" lang="fr" data-aujourdhui="2026-10-28">', trim($html));
        $this->assertStringContainsString('<div class="rsna-cal"></div>', $html);
        $this->assertStringContainsString('<div class="rsna-rsn"><p class="rsna-sous">Au RSN</p><div data-rsn></div></div>', $html);
        $this->assertStringContainsString('<div class="rsna-eco"><p class="rsna-sous"><span data-sous>Dans l&#039;écosystème</span><button type="button" data-tous hidden>Tous</button></p><div data-liste></div></div>', $html);
        $this->assertSame(1, substr_count($html, 'rsn-veille-accueil"'));
    }

    public function test_langue_anglaise_selon_wpml(): void {
        add_filter('wpml_current_language', static fn() => 'en');
        $html = Accueil::rendre();
        $this->assertStringContainsString('<div class="rsn-veille-accueil" lang="en" data-aujourdhui="2026-10-28">', $html);
        $this->assertSame('en', $this->noeud_json($html, 'data-rsn-accueil-donnees')['langue']);
    }

    public function test_noeud_de_donnees(): void {
        $this->assertSame([], rsn_veille_bouchons_declenchements('litespeed_purge_post'));
        set_transient(\RSN\Veille\Public\Donnees::transient(\RSN\Veille\Public\Donnees::TRANSIENT, 'fr'), [
            'genere_le' => self::AUJ, 'langue' => 'fr', 'sources' => [],
            'items' => [
                $this->ev('2026-11-03', ['titre' => 'Du RSN', 'organise_par' => 'rsn', 'organisateur' => 'RSN']),
                $this->ev('2026-11-05', ['titre' => 'Colloque <b>&</b> </script>']),
            ],
        ]);
        $html = Accueil::rendre();
        $donnees = $this->noeud_json($html, 'data-rsn-accueil-donnees');
        $this->assertSame(['rsn', 'eco', 'calendrier', 'agenda', 'langue'], array_keys($donnees));
        $this->assertSame(['Du RSN'], $this->titres($donnees['rsn']));
        $this->assertCount(1, $donnees['eco']);
        $this->assertCount(2, $donnees['calendrier']);
        $this->assertSame('fr', $donnees['langue']);
        // Aucune balise de fermeture dans le JSON : le script ne peut pas être refermé.
        $this->assertSame(1, preg_match('#data-rsn-accueil-donnees>(.*?)</script>#s', $html, $m));
        $this->assertStringNotContainsString('</script', $m[1]);
        $this->assertStringContainsString('\\u003C/script\\u003E', $m[1]);
    }

    public function test_permalien_de_l_agenda_et_repli(): void {
        // Aucune page Veille choisie : l'accueil du site.
        $this->assertSame('https://exemple.test/', $this->noeud_json(Accueil::rendre(), 'data-rsn-accueil-donnees')['agenda']);

        update_option(Collecte::OPTION_REGLAGES, ['page_veille_id' => 42]);
        $GLOBALS['rsn_veille_wp']['permaliens'] = [42 => 'https://rsn.exemple/veille/', 43 => 'https://rsn.exemple/en/veille/'];
        $this->assertSame('https://rsn.exemple/veille/', $this->noeud_json(Accueil::rendre(), 'data-rsn-accueil-donnees')['agenda']);

        // Page anglaise : la traduction WPML de la page Veille.
        add_filter('wpml_current_language', static fn() => 'en');
        add_filter('wpml_object_id', static fn($id, $type = '', $original = false, $langue = null) => $langue === null ? 43 : $id);
        $this->assertSame('https://rsn.exemple/en/veille/', $this->noeud_json(Accueil::rendre(), 'data-rsn-accueil-donnees')['agenda']);
    }

    public function test_noeud_de_textes_et_noscript(): void {
        $html = Accueil::rendre();
        $textes = $this->noeud_json($html, 'data-rsn-accueil-textes');
        $this->assertSame(Accueil::textes(), $textes);
        $this->assertStringContainsString('<noscript><p><a href="https://exemple.test/">Voir l&#039;agenda de l&#039;écosystème</a></p></noscript>', $html);
    }

    public function test_rendre_met_les_ressources_en_file(): void {
        Accueil::rendre();
        $wp = $GLOBALS['rsn_veille_wp'];
        $this->assertSame(RSN_VEILLE_URL . 'assets/accueil.css', $wp['styles']['rsn-veille-accueil']['source']);
        $this->assertSame(RSN_VEILLE_VERSION, $wp['styles']['rsn-veille-accueil']['version']);
        $this->assertSame(RSN_VEILLE_URL . 'assets/accueil.js', $wp['scripts']['rsn-veille-accueil']['source']);
        $this->assertSame(RSN_VEILLE_VERSION, $wp['scripts']['rsn-veille-accueil']['version']);
        $this->assertTrue($wp['scripts']['rsn-veille-accueil']['pied']);
        $this->assertArrayNotHasKey('rsn-veille', $wp['scripts'], 'les ressources de la page Veille ne sont pas chargées');
    }

    // --- chargement en tête ---

    private function demander_page(string $contenu): void {
        $id = wp_insert_post(['post_type' => 'page', 'post_title' => 'Page', 'post_status' => 'publish', 'post_content' => $contenu]);
        $GLOBALS['rsn_veille_wp']['objet_demande'] = get_post($id);
    }

    public function test_ressources_en_tete_sur_l_accueil(): void {
        $GLOBALS['rsn_veille_wp']['accueil'] = true;
        do_action('wp_enqueue_scripts');
        $this->assertArrayHasKey('rsn-veille-accueil', $GLOBALS['rsn_veille_wp']['styles']);
        $this->assertArrayHasKey('rsn-veille-accueil', $GLOBALS['rsn_veille_wp']['scripts']);
    }

    public function test_ressources_en_tete_sur_une_page_contenant_le_code_court(): void {
        $this->demander_page('Avant [rsn_veille_accueil] après');
        do_action('wp_enqueue_scripts');
        $this->assertArrayHasKey('rsn-veille-accueil', $GLOBALS['rsn_veille_wp']['styles']);
        $this->assertArrayHasKey('rsn-veille-accueil', $GLOBALS['rsn_veille_wp']['scripts']);
    }

    public function test_rien_ailleurs(): void {
        $this->demander_page('Un texte sans code court [rsn_veille]');
        do_action('wp_enqueue_scripts');
        $this->assertArrayNotHasKey('rsn-veille-accueil', $GLOBALS['rsn_veille_wp']['styles']);
        $this->assertArrayNotHasKey('rsn-veille-accueil', $GLOBALS['rsn_veille_wp']['scripts']);
    }

    public function test_rien_sans_page_demandee_et_filtre_pour_forcer(): void {
        do_action('wp_enqueue_scripts');
        $this->assertArrayNotHasKey('rsn-veille-accueil', $GLOBALS['rsn_veille_wp']['scripts']);

        add_filter('rsn_veille_charger_accueil', '__return_true');
        do_action('wp_enqueue_scripts');
        $this->assertArrayHasKey('rsn-veille-accueil', $GLOBALS['rsn_veille_wp']['scripts']);
    }

    public function test_filtre_pour_empecher_le_chargement_sur_l_accueil(): void {
        $GLOBALS['rsn_veille_wp']['accueil'] = true;
        add_filter('rsn_veille_charger_accueil', static fn() => false);
        do_action('wp_enqueue_scripts');
        $this->assertArrayNotHasKey('rsn-veille-accueil', $GLOBALS['rsn_veille_wp']['scripts']);
    }

    public function test_accrochages(): void {
        $actions = $GLOBALS['rsn_veille_wp']['actions'];
        $rappels = array_column($actions['init'] ?? [], 'rappel');
        $this->assertContains([Accueil::class, 'enregistrer'], $rappels);
        $rappels = array_column($actions['wp_enqueue_scripts'] ?? [], 'rappel');
        $this->assertContains([Accueil::class, 'charger_si_present'], $rappels);
    }

    // --- Collecte::pages_accueil et purge ---

    public function test_pages_accueil_sans_page_statique(): void {
        $this->assertSame([], Collecte::pages_accueil());
        update_option('page_on_front', 0);
        $this->assertSame([], Collecte::pages_accueil());
    }

    public function test_pages_accueil_sans_wpml(): void {
        update_option('page_on_front', 152);
        $this->assertSame([152], Collecte::pages_accueil());
    }

    public function test_pages_accueil_avec_wpml(): void {
        update_option('page_on_front', 152);
        add_filter('wpml_active_languages', static fn() => ['fr' => ['code' => 'fr'], 'en' => ['code' => 'en'], 'es' => ['code' => 'es']]);
        add_filter('wpml_object_id', static fn($id, $type = '', $original = false, $langue = null) => match ($langue) {
            'fr' => $id, 'en' => 1147, default => null,
        });
        $this->assertSame([152, 1147], Collecte::pages_accueil());
    }

    public function test_purge_de_l_accueil_et_de_sa_traduction(): void {
        update_option('page_on_front', 152);
        update_option(Collecte::OPTION_REGLAGES, ['page_veille_id' => 42]);
        add_filter('wpml_active_languages', static fn() => ['fr' => ['code' => 'fr'], 'en' => ['code' => 'en']]);
        add_filter('wpml_object_id', static fn($id, $type = '', $original = false, $langue = null) => match ($id) {
            152 => $langue === 'en' ? 1147 : 152,
            42 => $langue === 'en' ? 43 : 42,
            default => null,
        });
        foreach ([152, 1147, 42, 43] as $id) {
            update_post_meta($id, '_elementor_element_cache', 'rendu ' . $id);
        }

        Collecte::purger_caches();

        $purgees = array_column(rsn_veille_bouchons_declenchements('litespeed_purge_post'), 0);
        sort($purgees);
        $this->assertSame([42, 43, 152, 1147], $purgees);
        foreach ([152, 1147, 42, 43] as $id) {
            $this->assertSame('', get_post_meta($id, '_elementor_element_cache', true), (string) $id);
        }
    }

    public function test_purge_sans_doublon_si_l_accueil_est_la_page_veille(): void {
        update_option('page_on_front', 42);
        update_option(Collecte::OPTION_REGLAGES, ['page_veille_id' => 42]);
        Collecte::purger_caches();
        $this->assertSame([[42]], rsn_veille_bouchons_declenchements('litespeed_purge_post'));
    }

    // --- textes ---

    public function test_textes_reprennent_les_dates_de_raccourci_et_ajoutent_les_cles_de_l_accueil(): void {
        $textes = Accueil::textes();
        $raccourci = Raccourci::textes();
        foreach (['mois', 'mois_longs', 'jours_courts', 'premier_du_mois', 'date_longue', 'du_au_meme_mois', 'du_au', 'formats', 'en_anglais', 'en_francais'] as $cle) {
            $this->assertSame($raccourci[$cle], $textes[$cle], $cle);
        }
        $this->assertSame('Au RSN', $textes['au_rsn']);
        $this->assertSame('Dans l\'écosystème', $textes['dans_ecosysteme']);
        $this->assertSame('Le %s', $textes['le_jour']);
        $this->assertSame('Tous', $textes['tous']);
        $this->assertSame('Aucun événement ce jour-là.', $textes['aucun_jour']);
        $this->assertSame('Pas d\'événement du RSN à venir pour le moment. Les prochains seront annoncés ici.', $textes['aucun_rsn']);
        $this->assertSame(['+ %d autre événement du RSN →', '+ %d autres événements du RSN →'], $textes['autres_rsn']);
        $this->assertSame('Mois précédent', $textes['mois_precedent']);
        $this->assertSame('Mois suivant', $textes['mois_suivant']);
        $this->assertSame('RSN', $textes['legende_rsn']);
        $this->assertSame('Écosystème', $textes['legende_ecosysteme']);
        $this->assertSame('RSN', $textes['rsn']);
        $this->assertSame(12, count($textes['mois']));
        $this->assertCount(7, $textes['jours_courts']);
    }
}

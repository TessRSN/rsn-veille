<?php
declare(strict_types=1);

require_once __DIR__ . '/bouchons-wordpress.php';

use PHPUnit\Framework\TestCase;
use RSN\Veille\EntrepotWordPress;
use RSN\Veille\Fiche;
use RSN\Veille\Public\Formulaire;

final class FormulaireTest extends TestCase {
    private const AUJOURDHUI = '2026-10-25';

    /** PNG de 1 × 1 pixel. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /** @var string[] Fichiers temporaires à supprimer. */
    private array $temporaires = [];

    protected function setUp(): void {
        rsn_veille_bouchons_reinitialiser();
        $_POST = $_REQUEST = $_FILES = [];
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
    }

    protected function tearDown(): void {
        $_POST = $_REQUEST = $_FILES = [];
        unset($_SERVER['REMOTE_ADDR']);
        foreach ($this->temporaires as $fichier) {
            @unlink($fichier);
        }
    }

    /** Jeu complet et valide. */
    private static function champs(array $champs = []): array {
        return $champs + [
            'titre' => 'Colloque sur la souveraineté numérique en santé',
            'debut' => '2026-11-20',
            'fin' => '2026-11-21',
            'organisateur' => 'Obvia',
            'format' => 'Hybride',
            'lieu' => 'HEC Montréal, Montréal',
            'url' => 'https://www.obvia.ca/colloque',
            'resume' => "Deux jours d'échanges sur l'intelligence artificielle et les données en santé.",
            'courriel' => 'personne@exemple.org',
            'site_web' => '',
        ];
    }

    // --- valider ---

    public function test_jeu_complet_donne_une_fiche_a_approuver_et_le_courriel_a_part(): void {
        $resultat = Formulaire::valider(self::champs(), self::AUJOURDHUI);

        $this->assertSame([], $resultat['erreurs']);
        $this->assertSame('personne@exemple.org', $resultat['courriel']);
        $fiche = $resultat['fiche'];
        $this->assertInstanceOf(Fiche::class, $fiche);
        $this->assertSame('evenement', $fiche->type);
        $this->assertSame('a_approuver', $fiche->statut);
        $this->assertSame('proposition', $fiche->source);
        $this->assertSame('M', $fiche->niveau);
        $this->assertSame('fr', $fiche->langue);
        $this->assertStringStartsWith('proposition:', $fiche->cle_source);
        $this->assertSame([$fiche->cle_source], $fiche->cles_source);
        $this->assertSame('Colloque sur la souveraineté numérique en santé', $fiche->titre);
        $this->assertSame('2026-11-20', $fiche->debut);
        $this->assertSame('2026-11-21', $fiche->fin);
        $this->assertSame('Obvia', $fiche->organisateur);
        $this->assertSame('Hybride', $fiche->format);
        $this->assertSame('HEC Montréal, Montréal', $fiche->lieu);
        $this->assertSame('Québec', $fiche->region);
        $this->assertSame('https://www.obvia.ca/colloque', $fiche->url);
        $this->assertSame(['IA', 'Données'], $fiche->themes);
        $this->assertArrayNotHasKey('courriel', $fiche->vers_tableau());
        $this->assertStringNotContainsString('personne@exemple.org', serialize($fiche->vers_tableau()));
    }

    public function test_deux_propositions_ont_des_cles_distinctes(): void {
        $a = Formulaire::valider(self::champs(), self::AUJOURDHUI)['fiche'];
        $b = Formulaire::valider(self::champs(), self::AUJOURDHUI)['fiche'];
        $this->assertNotSame($a->cle_source, $b->cle_source);
    }

    public function test_champ_piege_rempli_rejet_silencieux(): void {
        $resultat = Formulaire::valider(self::champs(['site_web' => 'x']), self::AUJOURDHUI);
        $this->assertNull($resultat['fiche']);
        $this->assertSame([], $resultat['erreurs']);
    }

    public function test_champ_piege_rejete_meme_un_formulaire_invalide(): void {
        $resultat = Formulaire::valider(['site_web' => 'https://pourriel.example'], self::AUJOURDHUI);
        $this->assertNull($resultat['fiche']);
        $this->assertSame([], $resultat['erreurs']);
    }

    public function test_titre_tronque_a_200_caracteres(): void {
        $fiche = Formulaire::valider(self::champs(['titre' => str_repeat('é', 5000)]), self::AUJOURDHUI)['fiche'];
        $this->assertSame(200, mb_strlen($fiche->titre));
    }

    public function test_autres_longueurs_maximales(): void {
        $fiche = Formulaire::valider(self::champs([
            'organisateur' => str_repeat('o', 500),
            'lieu' => str_repeat('l', 500),
            'resume' => str_repeat('r', 5000),
        ]), self::AUJOURDHUI)['fiche'];
        $this->assertSame(120, mb_strlen($fiche->organisateur));
        $this->assertSame(160, mb_strlen($fiche->lieu));
        $this->assertSame(1000, mb_strlen($fiche->resume));
    }

    public function test_url_javascript_videe(): void {
        $resultat = Formulaire::valider(self::champs(['url' => 'javascript:alert(1)']), self::AUJOURDHUI);
        $this->assertSame('', $resultat['fiche']->url);
        $this->assertSame([], $resultat['erreurs']);
    }

    public function test_url_hors_http_videe(): void {
        foreach (['ftp://exemple.org/a', 'data:text/html,<b>x</b>', '//exemple.org', 'https://', 'https://exemple.org/a b', 'JAVASCRIPT:alert(1)'] as $url) {
            $this->assertSame('', Formulaire::valider(self::champs(['url' => $url]), self::AUJOURDHUI)['fiche']->url, $url);
        }
        $this->assertSame('http://exemple.org/page?a=1&b=2', Formulaire::valider(self::champs(['url' => ' http://exemple.org/page?a=1&b=2 ']), self::AUJOURDHUI)['fiche']->url);
    }

    public function test_date_de_debut_manquante(): void {
        $resultat = Formulaire::valider(self::champs(['debut' => '']), self::AUJOURDHUI);
        $this->assertNull($resultat['fiche']);
        $this->assertContains('Date de début obligatoire', $resultat['erreurs']);
        $this->assertSame('Date de début obligatoire', $resultat['erreurs']['debut']);
    }

    public function test_date_de_debut_invalide(): void {
        foreach (['2026-02-30', '20/11/2026', '2026-11-20T10:00', 'demain'] as $date) {
            $resultat = Formulaire::valider(self::champs(['debut' => $date]), self::AUJOURDHUI);
            $this->assertNull($resultat['fiche'], $date);
            $this->assertArrayHasKey('debut', $resultat['erreurs'], $date);
        }
    }

    public function test_date_de_debut_trop_ancienne(): void {
        $resultat = Formulaire::valider(self::champs(['debut' => '2025-10-24', 'fin' => '']), self::AUJOURDHUI);
        $this->assertNull($resultat['fiche']);
        $this->assertArrayHasKey('debut', $resultat['erreurs']);
        $this->assertNotSame('Date de début obligatoire', $resultat['erreurs']['debut']);

        // Il y a 365 jours exactement : accepté.
        $this->assertNotNull(Formulaire::valider(self::champs(['debut' => '2025-10-25', 'fin' => '']), self::AUJOURDHUI)['fiche']);
    }

    public function test_fin_avant_le_debut_ou_invalide_ramenee_au_debut(): void {
        foreach (['2026-11-19', '', 'n importe quoi', '2026-13-01'] as $fin) {
            $fiche = Formulaire::valider(self::champs(['fin' => $fin]), self::AUJOURDHUI)['fiche'];
            $this->assertSame('2026-11-20', $fiche->fin, $fin);
        }
    }

    public function test_titre_et_organisateur_obligatoires(): void {
        $resultat = Formulaire::valider(self::champs(['titre' => '   ', 'organisateur' => '<b></b>']), self::AUJOURDHUI);
        $this->assertNull($resultat['fiche']);
        $this->assertSame(['titre', 'organisateur'], array_keys($resultat['erreurs']));
    }

    public function test_balises_retirees(): void {
        $fiche = Formulaire::valider(self::champs([
            'titre' => '<script>alert(1)</script>Colloque <b>santé</b>',
            'resume' => '<p>Un</p><p>deux</p><img src=x onerror=alert(1)>',
            'organisateur' => 'Obvia <i>et</i> Mila',
        ]), self::AUJOURDHUI)['fiche'];
        $this->assertStringNotContainsString('<', $fiche->titre);
        $this->assertStringNotContainsString('script', $fiche->titre);
        $this->assertSame('Colloque santé', $fiche->titre);
        $this->assertSame('Un deux', $fiche->resume);
        $this->assertSame('Obvia et Mila', $fiche->organisateur);
    }

    public function test_format_inconnu_devient_presentiel(): void {
        $this->assertSame('Présentiel', Formulaire::valider(self::champs(['format' => 'Martien']), self::AUJOURDHUI)['fiche']->format);
        $virtuel = Formulaire::valider(self::champs(['format' => 'Virtuel', 'lieu' => '']), self::AUJOURDHUI)['fiche'];
        $this->assertSame('Virtuel', $virtuel->format);
        $this->assertSame('En ligne', $virtuel->region);
        $this->assertSame('Hors Québec', Formulaire::valider(self::champs(['format' => 'Présentiel', 'lieu' => 'Palais des congrès, Toronto']), self::AUJOURDHUI)['fiche']->region);
    }

    public function test_courriel_invalide_vide_sans_erreur(): void {
        foreach (['pas-un-courriel', 'a@b', '<script>@x.org', 'a b@exemple.org'] as $courriel) {
            $resultat = Formulaire::valider(self::champs(['courriel' => $courriel]), self::AUJOURDHUI);
            $this->assertSame('', $resultat['courriel'], $courriel);
            $this->assertNotNull($resultat['fiche'], $courriel);
        }
        $this->assertSame('', Formulaire::valider(self::champs(['courriel' => '']), self::AUJOURDHUI)['courriel']);
    }

    public function test_valeurs_non_textuelles_ignorees(): void {
        $resultat = Formulaire::valider(self::champs(['titre' => ['a', 'b'], 'url' => ['https://x.org']]), self::AUJOURDHUI);
        $this->assertNull($resultat['fiche']);
        $this->assertArrayHasKey('titre', $resultat['erreurs']);
    }

    // --- Limite de débit ---

    public function test_limite_de_debit_cinq_envois_par_heure(): void {
        $cle = Formulaire::cle_debit('203.0.113.7');
        for ($i = 1; $i <= 5; $i++) {
            $this->assertFalse(Formulaire::debit_depasse($cle), "envoi $i");
        }
        $this->assertTrue(Formulaire::debit_depasse($cle), '6e envoi');
        $this->assertTrue(Formulaire::debit_depasse($cle), '7e envoi');
        // Une autre adresse a son propre compteur.
        $this->assertFalse(Formulaire::debit_depasse(Formulaire::cle_debit('198.51.100.1')));
    }

    public function test_cle_de_debit_hachee_et_salee(): void {
        $cle = Formulaire::cle_debit('203.0.113.7');
        $this->assertStringStartsWith('rsn_veille_debit_', $cle);
        $this->assertStringNotContainsString('203.0.113.7', $cle);
        $this->assertSame('rsn_veille_debit_' . hash('sha256', '203.0.113.7' . wp_salt('nonce')), $cle);
        $this->assertLessThanOrEqual(172, strlen($cle), 'longueur maximale d’un nom de transient');
    }

    public function test_fenetre_d_une_heure_qui_ne_glisse_pas(): void {
        $cle = Formulaire::cle_debit('203.0.113.7');
        Formulaire::debit_depasse($cle);
        $expire = $GLOBALS['rsn_veille_wp']['transients'][$cle]['expire'];
        $this->assertEqualsWithDelta(time() + 3600, $expire, 2);
        // Le compteur expire : la fenêtre repart.
        $GLOBALS['rsn_veille_wp']['transients'][$cle]['valeur'] = ['n' => 5, 'debut' => time() - 3601];
        $this->assertFalse(Formulaire::debit_depasse($cle));
    }

    // --- traiter (action AJAX) ---

    /** Lance l'action AJAX et rend [réponse, statut HTTP]. */
    private function envoyer(array $champs, ?string $jeton = 'jeton-rsn_veille_proposer'): array {
        $_POST = $champs + ['action' => 'rsn_veille_proposer'];
        if ($jeton !== null) {
            $_POST['nonce'] = $jeton;
        }
        $_POST = wp_slash($_POST);
        $_REQUEST = $_POST;
        try {
            Formulaire::traiter();
        } catch (RsnVeilleArretRequete $arret) {
            return [$arret->reponse, $arret->statut_http];
        }
        $this->fail('traiter() doit répondre en JSON');
    }

    /** @return WP_Post[] Fiches de veille enregistrées. */
    private static function fiches(): array {
        return array_values(array_filter($GLOBALS['rsn_veille_wp']['posts'], static fn(WP_Post $p): bool => $p->post_type === 'rsn_veille'));
    }

    private function image(string $nom = 'affiche.png', ?string $contenu = null, ?int $taille = null): array {
        $chemin = tempnam(sys_get_temp_dir(), 'rsn');
        $this->temporaires[] = $chemin;
        file_put_contents($chemin, $contenu ?? base64_decode(self::PNG));
        return ['name' => $nom, 'type' => 'image/png', 'tmp_name' => $chemin, 'error' => UPLOAD_ERR_OK, 'size' => $taille ?? filesize($chemin)];
    }

    public function test_accroche_les_actions_ajax_avec_et_sans_connexion(): void {
        Formulaire::accrocher();
        foreach (['wp_ajax_rsn_veille_proposer', 'wp_ajax_nopriv_rsn_veille_proposer', 'wp_ajax_rsn_veille_jeton', 'wp_ajax_nopriv_rsn_veille_jeton'] as $crochet) {
            $this->assertNotEmpty($GLOBALS['rsn_veille_wp']['actions'][$crochet] ?? [], $crochet);
        }
    }

    public function test_jeton_invalide_refuse_en_403(): void {
        [$reponse, $statut] = $this->envoyer(self::champs(), 'mauvais');
        $this->assertSame(403, $statut);
        $this->assertFalse($reponse['data']['ok']);
        $this->assertSame([], self::fiches());

        [, $statut] = $this->envoyer(self::champs(), null);
        $this->assertSame(403, $statut);
    }

    public function test_proposition_creee_a_approuver_avec_courriel_prive(): void {
        [$reponse, $statut] = $this->envoyer(self::champs(['titre' => "L'IA & la santé"]));

        $this->assertSame(200, $statut);
        $this->assertTrue($reponse['success']);
        $this->assertTrue($reponse['data']['ok']);
        $this->assertSame("Merci ! Votre proposition est dans la file d'approbation de l'équipe du RSN.", $reponse['data']['message']);
        $fiches = self::fiches();
        $this->assertCount(1, $fiches);
        $this->assertSame('pending', $fiches[0]->post_status);
        $fiche = (new EntrepotWordPress())->obtenir($fiches[0]->ID);
        $this->assertSame("L'IA & la santé", $fiche->titre, 'apostrophe déslashée');
        $this->assertSame('proposition', $fiche->source);
        $this->assertSame('a_approuver', $fiche->statut);
        $this->assertSame('personne@exemple.org', get_post_meta($fiches[0]->ID, 'rsn_veille_courriel_proposant', true));
        $this->assertStringNotContainsString('personne@exemple.org', json_encode($reponse));
    }

    public function test_sans_courriel_aucune_meta(): void {
        $this->envoyer(self::champs(['courriel' => '']));
        $this->assertFalse(metadata_exists('post', self::fiches()[0]->ID, 'rsn_veille_courriel_proposant'));
    }

    public function test_erreurs_de_validation_en_400(): void {
        [$reponse, $statut] = $this->envoyer(self::champs(['debut' => '', 'titre' => '']));
        $this->assertSame(400, $statut);
        $this->assertFalse($reponse['data']['ok']);
        $this->assertSame('Date de début obligatoire', $reponse['data']['erreurs']['debut']);
        $this->assertArrayHasKey('titre', $reponse['data']['erreurs']);
        $this->assertSame([], self::fiches());
    }

    public function test_piege_rempli_reponse_normale_sans_fiche(): void {
        [$reponse, $statut] = $this->envoyer(self::champs(['site_web' => 'https://pourriel.example']));
        $this->assertSame(200, $statut);
        $this->assertTrue($reponse['data']['ok']);
        $this->assertSame([], self::fiches());
    }

    public function test_sixieme_envoi_dans_l_heure_refuse_en_429(): void {
        for ($i = 1; $i <= 5; $i++) {
            [, $statut] = $this->envoyer(self::champs(['titre' => 'Atelier numéro ' . $i]));
            $this->assertSame(200, $statut, "envoi $i");
        }
        [$reponse, $statut] = $this->envoyer(self::champs(['titre' => 'Atelier numéro 6']));
        $this->assertSame(429, $statut);
        $this->assertSame('Trop de propositions, réessayez plus tard.', $reponse['data']['message']);
        $this->assertCount(5, self::fiches());

        // Une autre adresse passe.
        $_SERVER['REMOTE_ADDR'] = '198.51.100.1';
        [, $statut] = $this->envoyer(self::champs(['titre' => 'Atelier numéro 7']));
        $this->assertSame(200, $statut);
    }

    public function test_doublon_d_un_evenement_publie_n_ecrit_rien(): void {
        $entrepot = new EntrepotWordPress();
        $id = $entrepot->creer(Fiche::depuis_tableau([
            'type' => 'evenement', 'titre' => 'Colloque sur la souveraineté numérique en santé', 'debut' => '2026-11-20',
            'organisateur' => 'Obvia', 'source' => 'Obvia : événements', 'niveau' => 'B', 'statut' => 'publie',
            'cle_source' => 'obvia:9', 'cles_source' => ['obvia:9'], 'url' => '',
        ]));
        $avant = serialize($GLOBALS['rsn_veille_wp']['meta'][$id]);

        [$reponse, $statut] = $this->envoyer(self::champs(['url' => 'https://hameconnage.example/']));

        $this->assertSame(200, $statut);
        $this->assertSame('Merci, cet événement est déjà dans la veille.', $reponse['data']['message']);
        $this->assertCount(1, self::fiches());
        $this->assertSame($avant, serialize($GLOBALS['rsn_veille_wp']['meta'][$id]), 'la fiche publiée ne change pas');
    }

    public function test_meme_url_mais_autre_evenement_cree_une_fiche(): void {
        (new EntrepotWordPress())->creer(Fiche::depuis_tableau([
            'type' => 'evenement', 'titre' => 'Autre atelier', 'debut' => '2026-11-02', 'organisateur' => 'Obvia',
            'statut' => 'publie', 'cle_source' => 'obvia:1', 'url' => 'https://www.obvia.ca/colloque',
        ]));
        $this->envoyer(self::champs());
        $this->assertCount(2, self::fiches());
    }

    public function test_image_attachee_en_vignette(): void {
        $_FILES['image'] = $this->image();
        [$reponse, $statut] = $this->envoyer(self::champs());

        $this->assertSame(200, $statut, json_encode($reponse));
        $id = self::fiches()[0]->ID;
        $televersements = $GLOBALS['rsn_veille_wp']['televersements'];
        $this->assertCount(1, $televersements);
        $this->assertSame('image', $televersements[0]['champ']);
        $this->assertSame(0, $televersements[0]['parent']);
        $this->assertSame(['jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'], $televersements[0]['substitutions']['mimes']);
        $piece = (int) get_post_meta($id, '_thumbnail_id', true);
        $this->assertSame($televersements[0]['id'], $piece);
        $this->assertSame(wp_get_attachment_image_url($piece, 'medium_large'), (new EntrepotWordPress())->obtenir($id)->image_url);
    }

    public function test_image_d_un_autre_type_refusee(): void {
        $_FILES['image'] = $this->image('affiche.png', '<?php echo "pas une image";');
        [$reponse, $statut] = $this->envoyer(self::champs());
        $this->assertSame(400, $statut);
        $this->assertArrayHasKey('image', $reponse['data']['erreurs']);
        $this->assertSame([], self::fiches());
        $this->assertSame([], $GLOBALS['rsn_veille_wp']['televersements'] ?? []);

        $_FILES['image'] = $this->image('dessin.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        [, $statut] = $this->envoyer(self::champs());
        $this->assertSame(400, $statut);

        // Vraie image PNG déguisée en .gif : l'extension doit aussi être permise.
        $_FILES['image'] = $this->image('affiche.gif');
        [, $statut] = $this->envoyer(self::champs());
        $this->assertSame(400, $statut);
    }

    public function test_image_trop_lourde_refusee(): void {
        $_FILES['image'] = $this->image('affiche.png', null, 3 * 1024 * 1024 + 1);
        [$reponse, $statut] = $this->envoyer(self::champs());
        $this->assertSame(400, $statut);
        $this->assertArrayHasKey('image', $reponse['data']['erreurs']);

        $_FILES['image'] = ['name' => 'affiche.png', 'type' => 'image/png', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0];
        [, $statut] = $this->envoyer(self::champs());
        $this->assertSame(400, $statut);
    }

    public function test_champ_image_vide_ignore(): void {
        $_FILES['image'] = ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0];
        [, $statut] = $this->envoyer(self::champs());
        $this->assertSame(200, $statut);
        $this->assertSame([], $GLOBALS['rsn_veille_wp']['televersements'] ?? []);
    }

    public function test_televersement_en_echec_garde_la_fiche(): void {
        $_FILES['image'] = $this->image();
        $GLOBALS['rsn_veille_wp']['televersement_erreur'] = 'Disque plein';
        [$reponse, $statut] = $this->envoyer(self::champs());
        $this->assertSame(200, $statut);
        $this->assertTrue($reponse['data']['ok']);
        $this->assertCount(1, self::fiches());
        $this->assertFalse(metadata_exists('post', self::fiches()[0]->ID, '_thumbnail_id'));
    }

    public function test_jeton_renouvele(): void {
        try {
            Formulaire::jeton();
            $this->fail('jeton() doit répondre en JSON');
        } catch (RsnVeilleArretRequete $arret) {
            $this->assertSame(200, $arret->statut_http);
            $this->assertSame('jeton-rsn_veille_proposer', $arret->reponse['data']['nonce']);
        }
    }
}

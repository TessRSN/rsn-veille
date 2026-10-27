<?php
declare(strict_types=1);

require_once __DIR__ . '/bouchons-wordpress.php';

use PHPUnit\Framework\TestCase;
use RSN\Veille\Admin\Menu;
use RSN\Veille\Admin\PageRadar;
use RSN\Veille\Admin\PageReglages;
use RSN\Veille\Collecte;
use RSN\Veille\Courriel;
use RSN\Veille\EntrepotWordPress;
use RSN\Veille\Fiche;

final class CourrielTest extends TestCase {
    private const AUJOURDHUI = '2026-10-26';
    private const URL_FILE = 'https://exemple.test/wp-admin/admin.php?page=rsn-veille';

    protected function setUp(): void {
        rsn_veille_bouchons_reinitialiser();
        $GLOBALS['rsn_veille_test_aujourdhui'] = self::AUJOURDHUI;
        Collecte::$obtenir_pour_tests = null;
    }

    protected function tearDown(): void {
        Collecte::$obtenir_pour_tests = null;
        $GLOBALS['rsn_veille_test_aujourdhui'] = '2026-10-25';
    }

    private static function fiche(string $titre, array $champs = []): array {
        return $champs + [
            'type' => 'evenement', 'titre' => $titre, 'debut' => '2026-11-20', 'heure' => '13:30',
            'organisateur' => 'Obvia', 'url' => 'https://exemple.ca/' . rawurlencode($titre),
        ];
    }

    // --- composer ---

    public function test_sujet_html_et_texte_de_deux_publiees_et_une_en_attente(): void {
        $c = Courriel::composer(
            [self::fiche('Atelier données'), self::fiche('Webinaire IA', ['debut' => '2026-11-22', 'heure' => '', 'organisateur' => 'Mila'])],
            [self::fiche('Colloque santé', ['organisateur' => 'IVADO'])],
            [],
            self::URL_FILE,
            self::AUJOURDHUI
        );
        $this->assertNotNull($c);
        $this->assertSame("Veille de l'écosystème : 3 nouveautés cette semaine", $c['sujet']);

        $html = $c['html'];
        $this->assertStringContainsString('Publiées automatiquement (2)', $html);
        $this->assertStringContainsString('À approuver (1)', $html);
        $this->assertStringContainsString('href="' . self::URL_FILE . '"', $html);
        $this->assertStringContainsString("Ouvrir la file d'approbation", $html);
        $this->assertStringNotContainsString('Nouvelles repérées', $html);
        // Titre, date, organisateur et lien de chaque fiche.
        $this->assertStringContainsString('Atelier données', $html);
        $this->assertStringContainsString('20 novembre 2026, 13 h 30 · Obvia', $html);
        $this->assertStringContainsString('22 novembre 2026 · Mila', $html);
        $this->assertStringContainsString('href="https://exemple.ca/Atelier%20donn%C3%A9es"', $html);
        // Pied et styles en ligne.
        $this->assertStringContainsString("Envoyé par l'extension RSN : veille de l'écosystème. Pour ne plus recevoir ce courriel, décochez l'option dans Veille › Réglages.", $html);
        $this->assertStringContainsString('font-family:Arial', $html);
        $this->assertStringContainsString('#003469', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('lang="fr"', $html);

        $texte = $c['texte'];
        $this->assertStringContainsString('Cette semaine : 2 fiches publiées automatiquement et 1 fiche à approuver.', $texte);
        $this->assertStringContainsString("PUBLIÉES AUTOMATIQUEMENT (2)\n\n- Atelier données\n  20 novembre 2026, 13 h 30 · Obvia\n  https://exemple.ca/Atelier%20donn%C3%A9es", $texte);
        $this->assertStringContainsString("Ouvrir la file d'approbation : " . self::URL_FILE, $texte);
        $this->assertStringContainsString("décochez l'option dans Veille › Réglages.", $texte);
        $this->assertStringNotContainsString('<', $texte);
        $this->assertStringNotContainsString('NOUVELLES REPÉRÉES', $texte);
    }

    public function test_une_seule_nouveaute_au_singulier(): void {
        $c = Courriel::composer([self::fiche('Atelier données')], [], [], self::URL_FILE, self::AUJOURDHUI);
        $this->assertSame("Veille de l'écosystème : 1 nouveauté cette semaine", $c['sujet']);
        // Section vide absente, et le bouton de la file avec elle.
        $this->assertStringNotContainsString('À approuver', $c['html']);
        $this->assertStringNotContainsString("Ouvrir la file d'approbation", $c['html']);
        $this->assertStringContainsString('Cette semaine : 1 fiche publiée automatiquement.', $c['texte']);
    }

    public function test_aucune_nouveaute_rien_a_envoyer_meme_avec_des_nouvelles_du_radar(): void {
        $this->assertNull(Courriel::composer([], [], [], self::URL_FILE, self::AUJOURDHUI));
        $radar = [['titre' => 'Nouvelle', 'source' => 'Mila', 'date' => '2026-10-24', 'url' => 'https://exemple.ca/n']];
        $this->assertNull(Courriel::composer([], [], $radar, self::URL_FILE, self::AUJOURDHUI));
        // Éléments sans titre ignorés.
        $this->assertNull(Courriel::composer([['titre' => '  ']], ['pas une fiche'], [], self::URL_FILE, self::AUJOURDHUI));
    }

    public function test_nouvelles_reperees_seulement_si_le_radar_en_a(): void {
        $radar = [
            ['titre' => 'Mila lance un programme', 'source' => 'Mila', 'date' => '2026-10-24', 'url' => 'https://exemple.ca/mila'],
            ['titre' => 'Sans lien', 'source' => '', 'date' => '', 'url' => 'javascript:alert(1)'],
        ];
        $c = Courriel::composer([self::fiche('Atelier')], [], $radar, self::URL_FILE, self::AUJOURDHUI);
        $this->assertStringContainsString('Nouvelles repérées (2)', $c['html']);
        $this->assertStringContainsString('24 oct. 2026 · Mila', $c['html']);
        $this->assertStringContainsString('href="https://exemple.ca/mila"', $c['html']);
        $this->assertStringNotContainsString('javascript:', $c['html']);
        $this->assertStringContainsString("NOUVELLES REPÉRÉES (2)\n\n- Mila lance un programme\n  24 oct. 2026 · Mila\n  https://exemple.ca/mila", $c['texte']);
        $this->assertStringNotContainsString('javascript:', $c['texte']);
        // Les nouvelles ne comptent pas parmi les nouveautés du sujet.
        $this->assertStringContainsString('1 nouveauté', $c['sujet']);
    }

    public function test_tout_est_echappe(): void {
        $piege = '<script>alert(1)</script> & <b>gras</b>';
        $c = Courriel::composer(
            [self::fiche($piege, ['organisateur' => '<i>Org</i>', 'url' => 'javascript:alert(1)'])],
            [self::fiche('Attente', ['url' => 'https://exemple.ca/a?x=1&y="2"'])],
            [['titre' => $piege, 'source' => '<img src=x>', 'date' => '2026-10-24', 'url' => 'https://exemple.ca/"onmouseover="x']],
            'https://exemple.test/wp-admin/admin.php?page=rsn-veille&a="b',
            self::AUJOURDHUI
        );
        $html = $c['html'];
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<b>gras', $html);
        $this->assertStringNotContainsString('<i>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; &lt;b&gt;gras&lt;/b&gt;', $html);
        $this->assertStringContainsString('&lt;i&gt;Org&lt;/i&gt;', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('"onmouseover=', $html);
        $this->assertStringNotContainsString('"b"', $html);
        $this->assertStringContainsString('href="https://exemple.ca/a?x=1&amp;y=2"', $html);
        // Le texte brut garde le titre tel quel (aucun HTML à échapper) mais pas l'adresse dangereuse.
        $this->assertStringNotContainsString('javascript:', $c['texte']);
    }

    public function test_appel_montre_sa_date_limite_et_les_longues_listes_sont_coupees(): void {
        $appel = self::fiche('Appel de projets', ['type' => 'appel', 'debut' => '2026-10-01', 'echeance' => '2026-11-15']);
        $beaucoup = [];
        for ($i = 1; $i <= 28; $i++) {
            $beaucoup[] = self::fiche('Fiche ' . $i);
        }
        $c = Courriel::composer(array_merge([$appel], $beaucoup), [], [], self::URL_FILE, self::AUJOURDHUI);
        $this->assertStringContainsString('Date limite : 15 novembre 2026 · Obvia', $c['html']);
        $this->assertStringContainsString('Publiées automatiquement (29)', $c['html']);
        $this->assertStringContainsString('Et 4 autres.', $c['html']);
        $this->assertStringContainsString('Fiche 24', $c['html']);
        $this->assertStringNotContainsString('Fiche 25', $c['html']);
        $this->assertStringContainsString('29 nouveautés', $c['sujet']);
    }

    // --- Réglages et planification ---

    private static function regler(array $courriel = []): void {
        update_option(Collecte::OPTION_REGLAGES, ['courriel' => $courriel + [
            'actif' => true, 'destinataires' => ['equipe@exemple.test', 'tess@exemple.test'], 'jour' => 1,
        ]]);
    }

    private static function en_montreal(int $horodatage, string $format): string {
        return (new DateTimeImmutable('@' . $horodatage))->setTimezone(wp_timezone())->format($format);
    }

    public function test_reglage_lu_avec_tolerance(): void {
        $this->assertSame(['actif' => false, 'destinataires' => [], 'jour' => 1], Courriel::reglages());
        update_option(Collecte::OPTION_REGLAGES, ['courriel' => ['actif' => '1', 'destinataires' => ['a@exemple.test', 'pas une adresse', 7, ' b@exemple.test ', 'a@exemple.test'], 'jour' => '9']]);
        $this->assertSame(['actif' => true, 'destinataires' => ['a@exemple.test', 'b@exemple.test'], 'jour' => 1], Courriel::reglages());
        self::regler(['jour' => 0]);
        $this->assertSame(0, Courriel::reglages()['jour']);
    }

    public function test_prochain_envoi_jour_choisi_a_08h_heure_de_montreal(): void {
        $lundi_7h = (new DateTimeImmutable('2026-10-26 07:00', wp_timezone()))->getTimestamp();
        $lundi_9h = (new DateTimeImmutable('2026-10-26 09:00', wp_timezone()))->getTimestamp();
        $lundi_8h = (new DateTimeImmutable('2026-10-26 08:00', wp_timezone()))->getTimestamp();
        $this->assertSame('2026-10-26 08:00', self::en_montreal(Courriel::prochain_envoi(1, $lundi_7h), 'Y-m-d H:i'));
        // Passé ou pile à l'heure : la semaine suivante.
        $this->assertSame('2026-11-02 08:00', self::en_montreal(Courriel::prochain_envoi(1, $lundi_9h), 'Y-m-d H:i'));
        $this->assertSame('2026-11-02 08:00', self::en_montreal(Courriel::prochain_envoi(1, $lundi_8h), 'Y-m-d H:i'));
        // Dimanche (0) et samedi (6) ; le dimanche 1er novembre est déjà à l'heure d'hiver (08:00 locale).
        $this->assertSame('2026-11-01 08:00', self::en_montreal(Courriel::prochain_envoi(0, $lundi_9h), 'Y-m-d H:i'));
        $this->assertSame('2026-10-31 08:00', self::en_montreal(Courriel::prochain_envoi(6, $lundi_9h), 'Y-m-d H:i'));
        $this->assertSame('0', self::en_montreal(Courriel::prochain_envoi(0, $lundi_9h), 'w'));
    }

    public function test_planifier_cree_un_evenement_hebdomadaire_au_bon_jour_et_a_la_bonne_heure(): void {
        self::regler(['jour' => 3]);
        Courriel::planifier();
        $evenements = rsn_veille_bouchons_planifies(Courriel::CROCHET);
        $this->assertCount(1, $evenements);
        $this->assertSame('weekly', $evenements[0]['recurrence']);
        $this->assertSame('3', self::en_montreal($evenements[0]['horodatage'], 'w'));
        $this->assertSame('08:00', self::en_montreal($evenements[0]['horodatage'], 'H:i'));
        $this->assertGreaterThan(time(), $evenements[0]['horodatage']);
        $this->assertLessThanOrEqual(time() + 7 * DAY_IN_SECONDS, $evenements[0]['horodatage']);

        // Une seule tâche, même appelée deux fois.
        Courriel::planifier();
        $this->assertCount(1, rsn_veille_bouchons_planifies(Courriel::CROCHET));
    }

    public function test_planifier_ne_fait_rien_si_l_envoi_est_inactif(): void {
        self::regler(['actif' => false]);
        Courriel::planifier();
        $this->assertSame([], rsn_veille_bouchons_planifies(Courriel::CROCHET));
        // Activation de l'extension : WordPress passe $network_wide au rappel.
        call_user_func([Courriel::class, 'planifier'], false);
        $this->assertSame([], rsn_veille_bouchons_planifies(Courriel::CROCHET));
    }

    public function test_replanifier_change_le_jour_et_deplanifier_retire_la_tache(): void {
        self::regler(['jour' => 3]);
        Courriel::planifier();
        self::regler(['jour' => 5]);
        Courriel::replanifier();
        $evenements = rsn_veille_bouchons_planifies(Courriel::CROCHET);
        $this->assertCount(1, $evenements);
        $this->assertSame('5', self::en_montreal($evenements[0]['horodatage'], 'w'));

        self::regler(['actif' => false]);
        Courriel::replanifier();
        $this->assertSame([], rsn_veille_bouchons_planifies(Courriel::CROCHET));

        self::regler();
        Courriel::planifier();
        Courriel::deplanifier();
        $this->assertSame([], rsn_veille_bouchons_planifies(Courriel::CROCHET));
    }

    public function test_l_enregistrement_des_reglages_planifie_replanifie_et_deplanifie(): void {
        PageReglages::accrocher();
        PageReglages::enregistrer_reglage();
        update_option(Collecte::OPTION_REGLAGES, ['courriel' => ['actif' => false, 'destinataires' => ['a@exemple.test'], 'jour' => 1]]);
        $this->assertSame([], rsn_veille_bouchons_planifies(Courriel::CROCHET));

        // Premier enregistrement avec l'option active, puis activation depuis un réglage inactif.
        update_option(Collecte::OPTION_REGLAGES, ['courriel' => ['actif' => true, 'destinataires' => ['a@exemple.test'], 'jour' => 1]]);
        $evenements = rsn_veille_bouchons_planifies(Courriel::CROCHET);
        $this->assertCount(1, $evenements);
        $this->assertSame('1', self::en_montreal($evenements[0]['horodatage'], 'w'));

        // Un autre réglage ou les destinataires qui changent : la tâche reste telle quelle.
        update_option(Collecte::OPTION_REGLAGES, ['courriel' => ['actif' => true, 'destinataires' => ['b@exemple.test'], 'jour' => 1], 'page_veille_id' => 0]);
        $this->assertSame($evenements[0]['horodatage'], wp_next_scheduled(Courriel::CROCHET));

        // Autre jour : replanifié.
        update_option(Collecte::OPTION_REGLAGES, ['courriel' => ['actif' => true, 'destinataires' => ['b@exemple.test'], 'jour' => 4]]);
        $evenements = rsn_veille_bouchons_planifies(Courriel::CROCHET);
        $this->assertCount(1, $evenements);
        $this->assertSame('4', self::en_montreal($evenements[0]['horodatage'], 'w'));

        // Désactivé : retiré.
        update_option(Collecte::OPTION_REGLAGES, ['courriel' => ['actif' => false, 'destinataires' => ['b@exemple.test'], 'jour' => 4]]);
        $this->assertSame([], rsn_veille_bouchons_planifies(Courriel::CROCHET));
    }

    public function test_recaler_ramene_l_envoi_a_08h_apres_le_changement_d_heure(): void {
        self::regler(['jour' => 1]);
        // Lundi 26 octobre à 08:00 (heure d'été) + 7 × 24 h = lundi 2 novembre à 07:00 (heure d'hiver).
        $lundi = (new DateTimeImmutable('2026-10-26 08:00', wp_timezone()))->getTimestamp();
        wp_schedule_event($lundi + 7 * DAY_IN_SECONDS, 'weekly', Courriel::CROCHET);
        $this->assertSame('07:00', self::en_montreal(wp_next_scheduled(Courriel::CROCHET), 'H:i'));
        Courriel::recaler_depuis($lundi + 60);
        $evenements = rsn_veille_bouchons_planifies(Courriel::CROCHET);
        $this->assertCount(1, $evenements);
        $this->assertSame('2026-11-02 08:00', self::en_montreal($evenements[0]['horodatage'], 'Y-m-d H:i'));
        $this->assertSame('weekly', $evenements[0]['recurrence']);

        // Déjà à l'heure : rien ne bouge.
        $avant = wp_next_scheduled(Courriel::CROCHET);
        Courriel::recaler_depuis($lundi + 60);
        $this->assertSame($avant, wp_next_scheduled(Courriel::CROCHET));
    }

    // --- Envoi ---

    /** Fiches de la semaine : 2 publiées récentes, 1 ancienne, 1 sans date de création, 1 à approuver, 1 écartée. */
    private static function fiches_de_test(): void {
        $e = new EntrepotWordPress();
        $base = ['type' => 'evenement', 'debut' => '2026-11-20', 'organisateur' => 'Obvia', 'source' => 'obvia', 'niveau' => 'A'];
        $cree = static function (string $titre, string $statut, ?string $cree_le, string $cle) use ($e, $base): int {
            return $e->creer(Fiche::depuis_tableau($base + [
                'titre' => $titre, 'statut' => $statut, 'cree_le' => $cree_le, 'cle_source' => $cle, 'cles_source' => [$cle],
                'url' => 'https://exemple.ca/' . $cle,
            ]));
        };
        $cree('Atelier données', 'publie', '2026-10-26', 'o:1');
        $cree('Webinaire IA', 'publie', '2026-10-19', 'o:2');
        $cree('Vieille fiche', 'publie', '2026-10-18', 'o:3');
        $sans = $cree('Fiche sans date de création', 'publie', null, 'o:4');
        delete_post_meta($sans, 'rsn_veille_cree_le');
        $cree('Colloque santé', 'a_approuver', '2026-09-01', 'o:5');
        $cree('Fiche écartée', 'ecarte', '2026-10-26', 'o:6');
    }

    private static function courriels(): array {
        return $GLOBALS['rsn_veille_wp']['courriels'];
    }

    public function test_envoyer_compose_et_remet_le_courriel_aux_destinataires(): void {
        self::regler();
        self::fiches_de_test();
        Courriel::accrocher();

        $this->assertTrue(Courriel::envoyer());
        $this->assertCount(1, self::courriels());
        $c = self::courriels()[0];
        $this->assertSame(['equipe@exemple.test', 'tess@exemple.test'], $c['a']);
        $this->assertContains('Content-Type: text/html; charset=UTF-8', (array) $c['entetes']);
        $this->assertSame("Veille de l'écosystème : 3 nouveautés cette semaine", $c['sujet']);
        $this->assertStringContainsString('Atelier données', $c['message']);
        $this->assertStringContainsString('Webinaire IA', $c['message']);
        $this->assertStringContainsString('Colloque santé', $c['message']);
        $this->assertStringContainsString('href="https://exemple.test/wp-admin/admin.php?page=rsn-veille"', $c['message']);
        foreach (['Vieille fiche', 'Fiche sans date de création', 'Fiche écartée'] as $absente) {
            $this->assertStringNotContainsString($absente, $c['message']);
        }
        // Version texte jointe (partie text/plain) par phpmailer_init.
        $this->assertStringContainsString('- Atelier données', (string) $c['texte']);

        $this->assertSame(
            ['date' => wp_date('Y-m-d H:i'), 'destinataires' => ['equipe@exemple.test', 'tess@exemple.test'], 'nb' => 3, 'ok' => true],
            get_option(Courriel::OPTION_DERNIER)
        );
        $this->assertSame(get_option(Courriel::OPTION_DERNIER), Courriel::dernier_envoi());
    }

    public function test_un_appel_perime_publie_cette_semaine_n_est_pas_annonce(): void {
        self::regler();
        $e = new EntrepotWordPress();
        $appel = ['type' => 'appel', 'statut' => 'publie', 'cree_le' => '2026-10-26', 'organisateur' => 'RSN'];
        $e->creer(Fiche::depuis_tableau($appel + ['titre' => 'Programme de juin', 'debut' => '2026-06-22', 'fin' => '2026-06-22', 'cle_source' => 'r:1', 'cles_source' => ['r:1']]));
        $e->creer(Fiche::depuis_tableau($appel + ['titre' => 'Programme d’octobre', 'debut' => '2026-10-23', 'fin' => '2026-10-23', 'cle_source' => 'r:2', 'cles_source' => ['r:2']]));

        $this->assertTrue(Courriel::envoyer());
        $c = self::courriels()[0];
        $this->assertSame("Veille de l'écosystème : 1 nouveauté cette semaine", $c['sujet']);
        $this->assertStringContainsString('Programme d’octobre', $c['message']);
        $this->assertStringNotContainsString('Programme de juin', $c['message']);
    }

    public function test_le_texte_alternatif_n_est_joint_qu_au_courriel_de_la_veille(): void {
        Courriel::accrocher();
        $autre = new stdClass();
        do_action('phpmailer_init', $autre);
        $this->assertFalse(isset($autre->AltBody));
        wp_mail('a@exemple.test', 'Autre courriel', 'Bonjour');
        $this->assertNull(self::courriels()[0]['texte']);
    }

    public function test_envoyer_n_envoie_rien_si_l_envoi_est_inactif_ou_sans_destinataire(): void {
        self::fiches_de_test();
        self::regler(['actif' => false]);
        $this->assertFalse(Courriel::envoyer());
        $this->assertSame('inactif', Courriel::tenter()['statut']);

        self::regler(['destinataires' => []]);
        $this->assertFalse(Courriel::envoyer());
        $this->assertSame('sans_destinataire', Courriel::tenter()['statut']);

        $this->assertSame([], self::courriels());
        $this->assertFalse(get_option(Courriel::OPTION_DERNIER));
        $this->assertNull(Courriel::dernier_envoi());
    }

    public function test_envoyer_n_envoie_rien_sans_nouveaute(): void {
        self::regler();
        $e = new EntrepotWordPress();
        $e->creer(Fiche::depuis_tableau(['type' => 'evenement', 'titre' => 'Ancienne', 'debut' => '2026-11-20', 'statut' => 'publie', 'cle_source' => 'o:9', 'cles_source' => ['o:9'], 'cree_le' => '2026-09-01']));
        $e->creer(Fiche::depuis_tableau(['type' => 'evenement', 'titre' => 'Écartée', 'debut' => '2026-11-20', 'statut' => 'ecarte', 'cle_source' => 'o:8', 'cles_source' => ['o:8']]));
        // Le radar ne suffit pas.
        set_transient(PageRadar::TRANSIENT, ['nouvelles' => [['titre' => 'N', 'source' => 'Mila', 'date' => '2026-10-25', 'url' => 'https://exemple.ca/n']], 'erreurs' => [], 'genere_le' => self::AUJOURDHUI]);
        $this->assertFalse(Courriel::envoyer());
        $this->assertSame(['statut' => 'rien', 'nb' => 0, 'destinataires' => ['equipe@exemple.test', 'tess@exemple.test']], Courriel::tenter());
        $this->assertSame([], self::courriels());
        $this->assertFalse(get_option(Courriel::OPTION_DERNIER));
    }

    public function test_echec_de_wp_mail_est_journalise(): void {
        self::regler();
        self::fiches_de_test();
        $GLOBALS['rsn_veille_wp']['wp_mail_reussit'] = false;
        $this->assertFalse(Courriel::envoyer());
        $this->assertCount(1, self::courriels());
        $this->assertFalse(Courriel::dernier_envoi()['ok']);
        $this->assertSame('echec', Courriel::tenter()['statut']);
    }

    public function test_nouvelles_du_radar_lues_dans_le_cache_sans_reseau(): void {
        self::regler();
        self::fiches_de_test();
        Collecte::$obtenir_pour_tests = static function (string $url): array {
            throw new LogicException('Aucune requête réseau attendue : ' . $url);
        };
        $nouvelles = [
            ['titre' => 'Mila lance un programme', 'source' => 'Mila', 'date' => '2026-10-24', 'url' => 'https://exemple.ca/mila', 'statut_radar' => 'reperee'],
            ['titre' => 'Nouvelle ignorée', 'source' => 'Mila', 'date' => '2026-10-24', 'url' => 'https://exemple.ca/ignoree', 'statut_radar' => 'reperee'],
            ['titre' => 'Nouvelle gardée', 'source' => 'Mila', 'date' => '2026-10-24', 'url' => 'https://exemple.ca/gardee', 'statut_radar' => 'reperee'],
            ['titre' => 'Nouvelle ancienne', 'source' => 'Mila', 'date' => '2026-09-01', 'url' => 'https://exemple.ca/ancienne', 'statut_radar' => 'reperee'],
            ['titre' => 'Nouvelle sans date', 'source' => 'Mila', 'date' => '', 'url' => 'https://exemple.ca/sans-date', 'statut_radar' => 'a_trier'],
        ];
        update_option(PageRadar::OPTION_IGNOREES, ['https://exemple.ca/ignoree']);
        update_option(PageRadar::OPTION_GARDEES, [['url' => 'https://exemple.ca/gardee', 'titre' => 'Nouvelle gardée']]);

        // Cache courant absent : la copie de 24 h sert.
        set_transient(PageRadar::TRANSIENT_DERNIER, ['nouvelles' => $nouvelles, 'erreurs' => [], 'genere_le' => self::AUJOURDHUI], 86400);
        $this->assertTrue(Courriel::envoyer());
        $message = self::courriels()[0]['message'];
        $this->assertStringContainsString('Nouvelles repérées (1)', $message);
        $this->assertStringContainsString('Mila lance un programme', $message);
        foreach (['Nouvelle ignorée', 'Nouvelle gardée', 'Nouvelle ancienne', 'Nouvelle sans date'] as $absente) {
            $this->assertStringNotContainsString($absente, $message);
        }

        // Aucun cache : la section est absente, l'envoi se fait quand même.
        rsn_veille_bouchons_reinitialiser();
        $GLOBALS['rsn_veille_test_aujourdhui'] = self::AUJOURDHUI;
        self::regler();
        self::fiches_de_test();
        $this->assertTrue(Courriel::envoyer());
        $this->assertStringNotContainsString('Nouvelles repérées', self::courriels()[0]['message']);
    }

    public function test_la_tache_hebdomadaire_envoie_le_courriel(): void {
        Courriel::accrocher();
        self::regler();
        self::fiches_de_test();
        Courriel::planifier();
        $this->assertTrue(rsn_veille_bouchons_executer_cron(Courriel::CROCHET));
        $this->assertCount(1, self::courriels());
    }

    // --- Page Réglages : essai ---

    public function test_essai_decrit_chaque_resultat(): void {
        self::fiches_de_test();

        self::regler(['actif' => false]);
        $this->assertSame('warning', PageReglages::lancer_essai_courriel()['niveau']);
        $this->assertStringContainsString('désactivé', PageReglages::lancer_essai_courriel()['texte']);

        self::regler(['destinataires' => []]);
        $this->assertStringContainsString('Aucun destinataire', PageReglages::lancer_essai_courriel()['texte']);
        $this->assertSame([], self::courriels());

        self::regler();
        $this->assertSame(
            ['niveau' => 'success', 'texte' => 'Courriel d’essai envoyé (3 nouveautés) à 2 destinataires.'],
            PageReglages::lancer_essai_courriel()
        );
        $this->assertCount(1, self::courriels());

        $GLOBALS['rsn_veille_wp']['wp_mail_reussit'] = false;
        $this->assertSame('error', PageReglages::lancer_essai_courriel()['niveau']);

        rsn_veille_bouchons_reinitialiser();
        self::regler(['destinataires' => ['seule@exemple.test']]);
        $this->assertSame(
            ['niveau' => 'warning', 'texte' => 'Aucune nouveauté cette semaine : le courriel n’est pas envoyé.'],
            PageReglages::lancer_essai_courriel()
        );
        $this->assertSame([], self::courriels());
    }

    public function test_essai_exige_la_capacite_et_le_jeton(): void {
        PageReglages::accrocher();
        self::regler();
        self::fiches_de_test();
        $this->assertNotEmpty($GLOBALS['rsn_veille_wp']['actions']['admin_post_' . PageReglages::ACTION_ESSAI_COURRIEL]);

        // Sans la capacité.
        try {
            PageReglages::traiter_essai_courriel();
            $this->fail('Refus attendu');
        } catch (RsnVeilleArretRequete $e) {
            $this->assertSame(403, $e->statut_http);
        }
        // Avec la capacité mais sans jeton valide.
        $GLOBALS['rsn_veille_wp']['capacites'] = [Menu::CAPACITE];
        $_REQUEST = ['_wpnonce' => 'faux'];
        try {
            PageReglages::traiter_essai_courriel();
            $this->fail('Refus attendu');
        } catch (RsnVeilleArretRequete $e) {
            $this->assertSame(403, $e->statut_http);
        } finally {
            $_REQUEST = [];
        }
        $this->assertSame([], self::courriels());
    }

    public function test_page_reglages_montre_l_essai_et_le_dernier_envoi(): void {
        self::regler();
        $html = PageReglages::page_html(PageReglages::contexte(Collecte::reglages()));
        $this->assertStringContainsString('Dernier envoi : <strong>aucun pour l’instant</strong>', $html);
        $this->assertStringContainsString('Prochain envoi planifié : <strong>aucun</strong>', $html);
        $this->assertStringContainsString('Envoyer un essai maintenant', $html);
        // Le bouton est rattaché à un formulaire à part (pas de formulaire dans le formulaire des réglages).
        $this->assertStringContainsString('<button type="submit" form="rsn-veille-formulaire-essai-courriel"', $html);
        $this->assertSame(1, preg_match('#<form id="rsn-veille-formulaire-essai-courriel" method="post" action="https://exemple.test/wp-admin/admin-post.php"><input type="hidden" name="action" value="rsn_veille_courriel_essai"><input type="hidden" name="_wpnonce" value="jeton-rsn_veille_actions"></form>#', $html));

        update_option(Courriel::OPTION_DERNIER, ['date' => '2026-10-26 08:00', 'destinataires' => ['a@exemple.test', 'b@exemple.test'], 'nb' => 1, 'ok' => false]);
        Courriel::planifier();
        $html = PageReglages::page_html(PageReglages::contexte(Collecte::reglages()));
        $this->assertStringContainsString('26 octobre 2026, 8 h : 1 nouveauté, 2 destinataires (échec de l’envoi)', $html);
        $this->assertStringNotContainsString('Prochain envoi planifié : <strong>aucun</strong>', $html);
    }
}

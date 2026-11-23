<?php
declare(strict_types=1);

require_once __DIR__ . '/bouchons-wordpress.php';

use PHPUnit\Framework\TestCase;
use RSN\Veille\Admin\PageInfolettre;
use RSN\Veille\Admin\PageRadar;
use RSN\Veille\Admin\Menu;
use RSN\Veille\EntrepotWordPress;
use RSN\Veille\Fiche;

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

    public function test_cinq_blocs_avec_apercu_et_boutons(): void {
        $html = PageInfolettre::page_html([self::evenement()], [self::gardee()], [], self::OPTIONS);
        // Ce que le RSN organise d'abord, puis l'écosystème, puis les nouvelles.
        $positions = [];
        foreach (['evenements-rsn', 'programmes-rsn', 'agenda', 'appels', 'nouvelles'] as $bloc) {
            $this->assertStringContainsString('data-bloc="' . $bloc . '"', $html);
            $positions[] = strpos($html, 'data-bloc="' . $bloc . '"');
        }
        $trie = $positions;
        sort($trie);
        $this->assertSame($trie, $positions);
        foreach (['Événements du RSN', 'Programmes de financement du RSN', 'Agenda de l&#039;écosystème', 'Appels de l&#039;écosystème', 'Nouvelles gardées'] as $titre) {
            $this->assertStringContainsString('<h2>' . $titre . ' <span', $html);
        }
        $this->assertStringContainsString('Cinq blocs prêts pour Mailchimp', $html);
        $this->assertSame(5, substr_count($html, '<iframe'));
        $this->assertSame(5, substr_count($html, '>Copier</button>'));
        $this->assertSame(5, substr_count($html, '>Copier le code HTML</button>'));
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
        $this->assertStringContainsString("12 NOV.\nA &amp; &lt;i&gt;B&lt;/i&gt;", $texte[1]);
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

    /** @return array<string,string> Le HTML de chaque bloc de la page, par clé. */
    private static function blocs(string $html): array {
        preg_match_all('/<section[^>]*data-bloc="([^"]+)".*?<\/section>/s', $html, $sections, PREG_SET_ORDER);
        return array_column($sections, 0, 1);
    }

    public function test_chaque_element_dans_son_bloc(): void {
        $appel = ['type' => 'appel', 'titre' => 'Bourse X', 'debut' => '2026-10-01', 'echeance' => '2026-11-20', 'organisateur' => 'FRQ', 'categorie' => 'Bourse', 'resume' => '', 'url' => 'https://frq.test/x'];
        $programme = ['type' => 'appel', 'titre' => 'Programme Y', 'debut' => '2026-10-02', 'echeance' => null, 'organisateur' => 'RSN', 'categorie' => 'Programme du RSN', 'resume' => '', 'url' => 'https://rsn.test/y'];
        $du_rsn = self::evenement(['titre' => 'Atelier du RSN', 'organisateur' => 'RSN', 'organise_par' => 'rsn', 'debut' => '2026-11-05']);
        $blocs = self::blocs(PageInfolettre::page_html([self::evenement(), $du_rsn, $appel], [], [$programme], self::OPTIONS));
        $contenu = ['evenements-rsn' => 'Atelier du RSN', 'programmes-rsn' => 'Programme Y', 'agenda' => 'Midi-conférence', 'appels' => 'Bourse X'];
        foreach ($contenu as $bloc => $titre) {
            foreach ($contenu as $autre => $titre_autre) {
                if ($autre === $bloc) {
                    $this->assertStringContainsString($titre, $blocs[$bloc]);
                } else {
                    $this->assertStringNotContainsString($titre_autre, $blocs[$bloc], "« $titre_autre » n'a rien à faire dans le bloc $bloc");
                }
            }
        }
        $this->assertStringContainsString('<span class="rsn-veille-compteur">1 événement</span>', $blocs['evenements-rsn']);
        $this->assertStringContainsString('<span class="rsn-veille-compteur">1 programme</span>', $blocs['programmes-rsn']);
        $this->assertStringContainsString('<span class="rsn-veille-compteur">1 événement</span>', $blocs['agenda']);
        $this->assertStringContainsString('<span class="rsn-veille-compteur">1 appel</span>', $blocs['appels']);
        // Sans rien du RSN : ses blocs sont vides, boutons désactivés.
        $vides = self::blocs(PageInfolettre::page_html([self::evenement(), $appel], [], [], self::OPTIONS));
        foreach (['evenements-rsn', 'programmes-rsn'] as $bloc) {
            $this->assertStringContainsString('Aucun élément', $vides[$bloc]);
            $this->assertStringContainsString('disabled', $vides[$bloc]);
        }
        $this->assertStringContainsString('<span class="rsn-veille-compteur">0 programmes</span>', $vides['programmes-rsn']);
    }

    public function test_images_des_billets_du_rsn_adaptees_au_courriel(): void {
        $du_rsn = self::evenement(['titre' => 'Atelier du RSN', 'organise_par' => 'rsn', 'image_url' => 'https://rsn.test/atelier.webp']);
        $passe = self::evenement(['titre' => 'Passé du RSN', 'organise_par' => 'rsn', 'debut' => '2026-10-01', 'image_url' => 'https://rsn.test/passe.webp']);
        $externe = self::evenement(['titre' => 'Colloque Mila', 'image_url' => 'https://mila.test/colloque.webp']);
        $programme = ['type' => 'appel', 'titre' => 'Programme Y', 'debut' => '2026-10-02', 'echeance' => null, 'organisateur' => 'RSN', 'organise_par' => 'rsn', 'programme' => true, 'resume' => '', 'url' => 'https://rsn.test/y', 'image_url' => 'https://rsn.test/y.webp'];
        $sans_image = ['titre' => 'Programme Z', 'url' => 'https://rsn.test/z', 'image_url' => ''] + $programme;
        $demandees = [];
        $image = static function (string $url) use (&$demandees): string {
            $demandees[] = $url;
            return (string) preg_replace('/\.webp$/', '.jpg', $url);
        };
        $html = PageInfolettre::page_html([$du_rsn, $passe, $externe], [], [$programme, $sans_image], ['image' => $image] + self::OPTIONS);
        // Seules les images de ce qui est repris dans un billet sont converties.
        sort($demandees);
        $this->assertSame(['https://rsn.test/atelier.webp', 'https://rsn.test/y.webp'], $demandees);
        $this->assertStringContainsString('src=&quot;https://rsn.test/atelier.jpg&quot;', $html);
        $this->assertStringContainsString('src=&quot;https://rsn.test/y.jpg&quot;', $html);
        $this->assertStringNotContainsString('.webp', $html);
        // Sans convertisseur (tests, autre usage) : les adresses d'origine.
        $this->assertStringContainsString('src=&quot;https://rsn.test/atelier.webp&quot;', PageInfolettre::page_html([$du_rsn], [], [], self::OPTIONS));
    }

    public function test_programme_converti_avec_les_renseignements_de_sa_case(): void {
        $id = wp_insert_post([
            'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Appel à projets 2027-2028 : 200 000 $ pour cinq projets',
            'post_excerpt' => 'Programme fondateur du RSN.', 'post_date' => '2026-10-23 09:00:00',
            'post_content' => '<h2>En bref</h2><ul><li><strong>Pour qui :</strong> équipes multi-axes</li><li><strong>Montant :</strong> 40 000 $ par projet</li></ul>'
                . '<p><a href="https://event.fourwaves.com/fr/appel-projets">Déposer une demande</a></p>',
        ]);
        update_post_meta($id, 'rsn_appel_titre', 'Appel à projets de recherche 2027-2028');
        update_post_meta($id, 'rsn_date_limite', '2026-12-13');
        update_post_meta($id, 'rsn_appel_public', 'chercheurs');
        $programme = PageInfolettre::programme_depuis_article(get_post($id), '2026-10-26');
        $this->assertSame('Appel à projets de recherche 2027-2028', $programme['titre'], 'le titre court de l’encadré, comme sur le site');
        $this->assertSame('2026-12-13', $programme['echeance']);
        $this->assertSame('Programme fondateur du RSN.', $programme['texte']);
        $this->assertSame('https://event.fourwaves.com/fr/appel-projets', $programme['lien'], 'sans champ rempli : le premier lien Fourwaves du texte');
        $this->assertSame('Chercheur·euses', $programme['pour_qui'], 'les cases de l’encadré priment sur le bloc En bref');
        $this->assertSame('40 000 $ par projet', $programme['montant']);
        $this->assertSame('rsn', $programme['organise_par']);
        $this->assertSame((string) get_permalink($id), $programme['url']);

        // Sans encadré rempli : titre de l'article, « Pour qui » du bloc En bref, lien du champ s'il existe.
        delete_post_meta($id, 'rsn_appel_titre');
        delete_post_meta($id, 'rsn_appel_public');
        delete_post_meta($id, 'rsn_date_limite');
        update_post_meta($id, 'rsn_appel_lien', 'https://formulaire.test/depot');
        update_post_meta($id, 'rsn_appel_periode', 'Date limite : 15 novembre 2026');
        $programme = PageInfolettre::programme_depuis_article(get_post($id), '2026-10-26');
        $this->assertSame('Appel à projets 2027-2028 : 200 000 $ pour cinq projets', $programme['titre']);
        $this->assertSame('Équipes multi-axes', $programme['pour_qui']);
        $this->assertSame('https://formulaire.test/depot', $programme['lien']);
        $this->assertSame('2026-11-15', $programme['echeance'], 'sans date limite dans l’encadré : celle de la période');
    }

    // --- Vider les gardées, accès, programmes ---

    // --- Ce que l'équipe tient hors de l'infolettre, ce qui est archivé (0.15.0) ---

    public function test_fiche_tenue_hors_de_l_infolettre(): void {
        $items = [
            self::evenement(['id' => 11, 'titre' => 'Colloque gardé']),
            self::evenement(['id' => 12, 'titre' => 'Midi écarté de l’infolettre', 'debut' => '2026-11-20', 'hors_infolettre' => true]),
            ['id' => 21, 'type' => 'appel', 'titre' => 'Appel gardé', 'debut' => '2026-10-20', 'echeance' => '2026-12-01', 'url' => 'https://frq.test/a', 'resume' => ''],
            ['id' => 22, 'type' => 'appel', 'titre' => 'Appel hors infolettre', 'debut' => '2026-10-20', 'echeance' => '2026-12-05', 'url' => 'https://frq.test/b', 'resume' => '', 'hors_infolettre' => true],
            // Événement déjà passé : il n'aurait pas été repris de toute façon, il ne compte pas dans la note.
            self::evenement(['id' => 13, 'titre' => 'Passé', 'debut' => '2026-10-01', 'hors_infolettre' => true]),
        ];
        // La marque `hors_infolettre` est posée par `Donnees` d'après le choix fait dans la file.
        $html = PageInfolettre::page_html($items, [], [], self::OPTIONS);
        $this->assertStringContainsString('Colloque gardé', $html);
        $this->assertStringNotContainsString('Midi écarté', $html);
        $this->assertStringContainsString('Appel gardé', $html);
        $this->assertStringNotContainsString('Appel hors infolettre', $html);
        $this->assertStringContainsString('<p class="description rsn-veille-infolettre-note">1 événement publié est tenu hors de l&#039;infolettre (File d&#039;approbation › Publiées).</p>', $html);
        $this->assertStringContainsString('1 appel publié est tenu hors de l&#039;infolettre', $html);
        $blocs = self::blocs($html);
        $this->assertStringContainsString('rsn-veille-infolettre-note', $blocs['agenda']);
        $this->assertStringContainsString('rsn-veille-infolettre-note', $blocs['appels']);
        $this->assertStringNotContainsString('rsn-veille-infolettre-note', $blocs['evenements-rsn']);
        $this->assertStringNotContainsString('rsn-veille-infolettre-note', $blocs['programmes-rsn']);
        // Sans choix de l'équipe : tout y est, aucune note.
        $sans = array_map(static fn(array $i): array => array_diff_key($i, ['hors_infolettre' => true]), $items);
        $html = PageInfolettre::page_html($sans, [], [], self::OPTIONS);
        $this->assertStringContainsString('Midi écarté', $html);
        $this->assertStringContainsString('Appel hors infolettre', $html);
        $this->assertStringNotContainsString('rsn-veille-infolettre-note', $html);
    }

    public function test_ce_que_le_rsn_organise_tenu_hors_de_l_infolettre(): void {
        $programme = ['type' => 'appel', 'titre' => 'Programme du site', 'debut' => '2026-10-02', 'echeance' => '2026-12-13', 'organisateur' => 'RSN', 'organise_par' => 'rsn', 'programme' => true, 'resume' => '', 'url' => 'https://rsn.test/programme/'];
        $autre = ['titre' => 'Autre programme', 'url' => 'https://rsn.test/autre/'] + $programme;
        $items = [
            self::evenement(['id' => 31, 'titre' => 'Atelier du RSN retiré', 'organise_par' => 'rsn', 'hors_infolettre' => true]),
            // La fiche publiée du même appel que le programme du site, tenue hors de l'infolettre.
            ['id' => 41, 'type' => 'appel', 'titre' => 'Fiche du programme', 'debut' => '2026-10-02', 'echeance' => '2026-12-13', 'organisateur' => 'RSN', 'organise_par' => 'rsn', 'url' => 'https://rsn.test/programme', 'resume' => '', 'hors_infolettre' => true],
        ];
        $blocs = self::blocs(PageInfolettre::page_html($items, [], [$programme, $autre], self::OPTIONS));
        $this->assertStringNotContainsString('Atelier du RSN retiré', $blocs['evenements-rsn']);
        $this->assertStringContainsString('1 événement publié est tenu hors de l&#039;infolettre', $blocs['evenements-rsn']);
        // Le choix fait sur la fiche vaut aussi pour le programme du site, qui l'aurait remplacée.
        $this->assertStringNotContainsString('Programme du site', $blocs['programmes-rsn']);
        $this->assertStringNotContainsString('Fiche du programme', $blocs['programmes-rsn']);
        $this->assertStringContainsString('Autre programme', $blocs['programmes-rsn']);
        $this->assertStringContainsString('1 appel publié est tenu hors de l&#039;infolettre', $blocs['programmes-rsn']);
        $this->assertStringNotContainsString('rsn-veille-infolettre-note', $blocs['agenda']);
        $this->assertStringNotContainsString('rsn-veille-infolettre-note', $blocs['appels']);
    }

    public function test_afficher_applique_le_choix_de_la_file(): void {
        $GLOBALS['rsn_veille_wp']['capacites'] = [Menu::CAPACITE];
        $GLOBALS['rsn_veille_test_aujourdhui'] = '2026-10-26';
        $e = new EntrepotWordPress();
        $base = ['type' => 'evenement', 'statut' => 'publie', 'debut' => '2026-11-12', 'organisateur' => 'Mila', 'source' => 'Mila : événements', 'niveau' => 'C', 'url' => 'https://mila.test/'];
        $dedans = $e->creer(Fiche::depuis_tableau(['titre' => 'Colloque gardé', 'cle_source' => 'mila:1', 'cles_source' => ['mila:1']] + $base));
        $hors = $e->creer(Fiche::depuis_tableau(['titre' => 'Midi tenu hors', 'cle_source' => 'mila:2', 'cles_source' => ['mila:2'], 'debut' => '2026-11-20'] + $base));
        update_option(\RSN\Veille\Admin\PageFile::OPTION_HORS_INFOLETTRE, [$hors]);
        ob_start();
        PageInfolettre::afficher();
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('Colloque gardé', $html);
        $this->assertStringNotContainsString('Midi tenu hors', $html);
        $this->assertStringContainsString('1 événement publié est tenu hors de l&#039;infolettre', $html);
        $GLOBALS['rsn_veille_test_aujourdhui'] = '2026-10-25';
    }

    public function test_nouvelle_gardee_trop_vieille_hors_de_l_infolettre(): void {
        $vieille = ['url' => 'https://inspq.test/vieille', 'titre' => 'Vieille nouvelle', 'date' => '2026-08-01', 'gardee_le' => '2026-08-02 09:00'] + self::gardee();
        $remise = ['url' => 'https://inspq.test/remise', 'titre' => 'Remise par l’équipe', 'date' => '2026-08-01', 'conservee' => '1'] + self::gardee();
        $html = PageInfolettre::page_html([], [self::gardee(), $vieille, $remise], [], self::OPTIONS);
        $this->assertStringContainsString('Une nouvelle', $html);
        $this->assertStringContainsString('Remise par l’équipe', $html);
        $this->assertStringNotContainsString('Vieille nouvelle', $html);
        $this->assertStringContainsString('1 nouvelle gardée, publiée depuis plus de 60 jours, est archivée (Radar › Archivées).', $html);
        $this->assertStringNotContainsString('archivée', PageInfolettre::page_html([], [self::gardee()], [], self::OPTIONS));
    }

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

    public function test_afficher_reste_en_francais_meme_si_la_langue_courante_est_l_anglais(): void {
        $GLOBALS['rsn_veille_wp']['capacites'] = [Menu::CAPACITE];
        $jour = \rsn_veille_aujourdhui();
        (new EntrepotWordPress())->creer(Fiche::depuis_tableau([
            'type' => 'appel', 'titre' => 'Appel de projets', 'debut' => $jour, 'echeance' => gmdate('Y-m-d', strtotime($jour . ' +40 days')),
            'organisateur' => 'RSN', 'source' => 'RSN : appels', 'niveau' => 'B', 'statut' => 'publie',
            'cle_source' => 'rsn_appels:abc', 'cles_source' => ['rsn_appels:abc'], 'url' => 'https://rsn.quebec/appel-de-projets/',
        ]));
        wp_insert_post(['ID' => 2749, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Call for projects']);
        $GLOBALS['rsn_veille_wp']['urls']['https://rsn.quebec/appel-de-projets/'] = 2710;
        add_filter('wpml_current_language', static fn($valeur) => 'en');
        add_filter('wpml_object_id', static fn($id) => 2749);
        ob_start();
        PageInfolettre::afficher();
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('Appel de projets', $html, "l'infolettre est française : la langue de l'administration ne la traduit pas");
        $this->assertStringNotContainsString('Call for projects', $html);
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

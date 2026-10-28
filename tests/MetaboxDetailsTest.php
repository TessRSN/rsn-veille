<?php
declare(strict_types=1);

require_once __DIR__ . '/bouchons-wordpress.php';

use PHPUnit\Framework\TestCase;
use RSN\Veille\Admin\MetaboxDetails;
use RSN\Veille\EntrepotWordPress;
use RSN\Veille\Fiche;
use RSN\Veille\TypeContenu;

final class MetaboxDetailsTest extends TestCase {
    protected function setUp(): void {
        rsn_veille_bouchons_reinitialiser();
        $_POST = $_REQUEST = [];
        $GLOBALS['rsn_veille_test_aujourdhui'] = '2026-10-28';
    }

    protected function tearDown(): void {
        $_POST = $_REQUEST = [];
        $GLOBALS['rsn_veille_test_aujourdhui'] = '2026-10-25';
    }

    /** Fiche collectée d'exemple (événement). */
    private static function fiche(array $champs = []): Fiche {
        return Fiche::depuis_tableau($champs + [
            'id' => 12,
            'type' => 'evenement',
            'titre' => 'Midi-conférence en santé numérique',
            'debut' => '2026-11-12',
            'fin' => '2026-11-12',
            'heure' => '12:00',
            'organisateur' => 'Mila',
            'format' => 'Présentiel',
            'lieu' => 'Mila',
            'ville' => 'Montréal',
            'region' => 'Québec',
            'langue' => 'fr',
            'url' => 'https://mila.quebec/fr/evenements/midi',
            'source' => 'Mila : événements',
            'niveau' => 'C',
            'cle_source' => 'mila:midi',
            'cles_source' => ['mila:midi'],
            'statut' => 'a_approuver',
        ]);
    }

    /** Envoi de l'encadré qui reprend exactement les valeurs de la fiche (rien de changé). */
    private static function envoi_identique(Fiche $f): array {
        return [
            'type' => $f->type,
            'organisateur' => $f->organisateur,
            'echeance' => (string) $f->echeance,
            'categorie' => $f->categorie,
            'publie' => (string) $f->debut,
            'debut' => (string) $f->debut,
            'fin' => (string) $f->fin,
            'heure' => $f->heure,
            'format' => $f->format,
            'lieu' => $f->lieu,
            'ville' => $f->ville,
            'langue' => $f->langue,
            'url' => $f->url,
        ];
    }

    // --- nettoyer ---

    /** Envoi que le navigateur ferait du HTML réel de l'encadré, sans rien toucher (radio cochée, options choisies). */
    private static function envoi_depuis_html(string $html): array {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><form>' . $html . '</form>');
        libxml_clear_errors();
        $envoi = [];
        $nom = static fn(DOMElement $e): ?string => preg_match('/^rsn_veille_details\[(\w+)\]$/', $e->getAttribute('name'), $m) === 1 ? $m[1] : null;
        foreach ($doc->getElementsByTagName('input') as $e) {
            $champ = $nom($e);
            if ($champ === null || ($e->getAttribute('type') === 'radio' && !$e->hasAttribute('checked'))) {
                continue;
            }
            $envoi[$champ] = $e->getAttribute('value');
        }
        foreach ($doc->getElementsByTagName('select') as $e) {
            $champ = $nom($e);
            if ($champ === null) {
                continue;
            }
            $options = $e->getElementsByTagName('option');
            $envoi[$champ] = $options->length > 0 ? $options->item(0)->getAttribute('value') : '';
            foreach ($options as $o) {
                if ($o->hasAttribute('selected')) {
                    $envoi[$champ] = $o->getAttribute('value');
                }
            }
        }
        return $envoi;
    }

    /** Appel collecté dont la date limite n'est que la fin (échéance vide, fin différente du début). */
    private static function appel_collecte(): Fiche {
        return self::fiche(['type' => 'appel', 'debut' => '2026-10-15', 'fin' => '2026-11-30', 'echeance' => null, 'heure' => '', 'format' => '', 'lieu' => '', 'ville' => '', 'region' => '', 'source' => 'FRQ : appels', 'niveau' => 'B', 'cle_source' => 'frq_appels:x', 'cles_source' => ['frq_appels:x']]);
    }


    public function test_rien_de_change_rien_a_ecrire_ni_a_proteger(): void {
        $f = self::fiche();
        $r = MetaboxDetails::nettoyer(self::envoi_identique($f), $f);
        $this->assertSame([], $r['champs']);
        $this->assertSame([], $r['erreurs']);
    }

    public function test_champs_absents_de_l_envoi_ne_changent_rien(): void {
        $r = MetaboxDetails::nettoyer([], self::fiche());
        $this->assertSame([], $r['champs']);
        $this->assertSame([], $r['erreurs']);
    }

    public function test_protection_posee_seulement_sur_les_champs_factuels_changes(): void {
        // Point de vigilance 3 : seule l'URL change parmi les champs protégeables ; l'organisateur change aussi,
        // mais la collecte ne le touche jamais : il n'est pas protégé.
        $f = self::fiche(['champs_manuels' => ['heure', 'titre']]);
        $envoi = ['url' => 'https://mila.quebec/fr/evenements/midi-2', 'organisateur' => 'Mila et IVADO'] + self::envoi_identique($f);
        $r = MetaboxDetails::nettoyer($envoi, $f);
        $this->assertSame('https://mila.quebec/fr/evenements/midi-2', $r['champs']['url']);
        $this->assertSame('Mila et IVADO', $r['champs']['organisateur']);
        // Union de l'existant (nom inconnu « titre » écarté) et du champ protégeable changé.
        $this->assertSame(['heure', 'url'], $r['champs']['champs_manuels']);
        $this->assertEqualsCanonicalizing(['url', 'organisateur', 'champs_manuels'], array_keys($r['champs']));
    }

    public function test_liste_de_protection_inchangee_pas_reecrite(): void {
        $f = self::fiche(['champs_manuels' => ['url']]);
        $r = MetaboxDetails::nettoyer(['url' => 'https://mila.quebec/autre'] + self::envoi_identique($f), $f);
        $this->assertSame('https://mila.quebec/autre', $r['champs']['url']);
        $this->assertArrayNotHasKey('champs_manuels', $r['champs']);
    }

    public function test_appel_fin_egale_debut(): void {
        $f = self::fiche(['type' => 'appel', 'debut' => '2026-10-15', 'fin' => '2026-10-15', 'echeance' => '2026-11-30']);
        $envoi = ['type' => 'appel', 'publie' => '2026-10-20', 'debut' => '2026-10-15', 'fin' => '2026-12-31'] + self::envoi_identique($f);
        $r = MetaboxDetails::nettoyer($envoi, $f);
        // « Publié le » donne le début de l'appel ; la fin le suit, quoi que porte le champ caché de l'événement.
        $this->assertSame('2026-10-20', $r['champs']['debut']);
        $this->assertSame('2026-10-20', $r['champs']['fin']);
        $this->assertSame(['debut', 'fin'], $r['champs']['champs_manuels']);
    }

    public function test_passage_en_appel_la_date_limite_reprend_la_fin(): void {
        // Point de vigilance 2 de la conception, côté serveur (sans le script) : fin différente du début.
        $f = self::fiche(['debut' => '2026-10-15', 'fin' => '2026-11-30', 'source' => 'proposition', 'niveau' => 'M']);
        $envoi = ['type' => 'appel', 'publie' => '2026-10-15', 'echeance' => ''] + self::envoi_identique($f);
        $r = MetaboxDetails::nettoyer($envoi, $f);
        $this->assertSame('appel', $r['champs']['type']);
        $this->assertSame('2026-11-30', $r['champs']['echeance']);
        $this->assertSame('2026-10-15', $r['champs']['fin']);
        $this->assertArrayNotHasKey('debut', $r['champs']);
    }

    public function test_date_limite_saisie_gardee_au_passage_en_appel(): void {
        $f = self::fiche(['debut' => '2026-10-15', 'fin' => '2026-11-30']);
        $envoi = ['type' => 'appel', 'publie' => '2026-10-15', 'echeance' => '2026-12-15'] + self::envoi_identique($f);
        $r = MetaboxDetails::nettoyer($envoi, $f);
        $this->assertSame('2026-12-15', $r['champs']['echeance']);
    }

    public function test_fin_avant_debut_devient_le_debut(): void {
        $f = self::fiche();
        $r = MetaboxDetails::nettoyer(['debut' => '2026-11-20', 'fin' => '2026-11-18'] + self::envoi_identique($f), $f);
        $this->assertSame('2026-11-20', $r['champs']['debut']);
        $this->assertSame('2026-11-20', $r['champs']['fin']);
    }

    public function test_fin_vide_devient_le_debut(): void {
        $f = self::fiche(['fin' => '2026-11-14']);
        $r = MetaboxDetails::nettoyer(['fin' => ''] + self::envoi_identique($f), $f);
        $this->assertSame('2026-11-12', $r['champs']['fin']);
    }

    public function test_evenement_sans_debut_valide_garde_l_ancien_et_signale(): void {
        $f = self::fiche();
        foreach (['', '2026-02-30', '12/11/2026', 'demain'] as $debut) {
            $r = MetaboxDetails::nettoyer(['debut' => $debut] + self::envoi_identique($f), $f);
            $this->assertArrayNotHasKey('debut', $r['champs'], $debut);
            $this->assertArrayNotHasKey('fin', $r['champs'], $debut);
            $this->assertCount(1, $r['erreurs'], $debut);
            $this->assertStringContainsString('date de début', $r['erreurs'][0]);
        }
    }

    public function test_lien_javascript_refuse(): void {
        $f = self::fiche();
        foreach (['javascript:alert(1)', 'data:text/html,x', 'ftp://exemple.test/a', 'https://', 'https://moi@exemple.test/'] as $url) {
            $r = MetaboxDetails::nettoyer(['url' => $url] + self::envoi_identique($f), $f);
            $this->assertArrayNotHasKey('url', $r['champs'], $url);
            $this->assertCount(1, $r['erreurs'], $url);
        }
        // Un lien vide est une demande explicite : la fiche n'a plus de lien.
        $r = MetaboxDetails::nettoyer(['url' => ''] + self::envoi_identique($f), $f);
        $this->assertSame('', $r['champs']['url']);
        $this->assertSame([], $r['erreurs']);
    }

    public function test_valeur_renvoyee_telle_quelle_n_est_pas_un_changement(): void {
        // Valeurs enregistrées avant la 0.6.0, sous une forme que le nettoyage écrirait autrement : renvoyées
        // sans y toucher, elles ne sont ni réécrites, ni protégées, ni signalées.
        $f = self::fiche(['heure' => '9:05', 'url' => 'https://exemple.test/a`b', 'organisateur' => 'Mila  &amp; IVADO']);
        $r = MetaboxDetails::nettoyer(self::envoi_identique($f), $f);
        $this->assertSame([], $r['champs']);
        $this->assertSame([], $r['erreurs']);
    }

    public function test_appel_collecte_enregistre_sans_rien_toucher_rien_n_est_ecrit(): void {
        // I1 : la date limite préremplie depuis la fin n'est pas un changement ; ni la fin ni l'échéance ne bougent.
        $f = self::appel_collecte();
        $envoi = self::envoi_depuis_html(MetaboxDetails::html($f, MetaboxDetails::origine($f)));
        $this->assertSame('2026-11-30', $envoi['echeance']);
        $r = MetaboxDetails::nettoyer($envoi, $f);
        $this->assertSame([], $r['champs']);
        $this->assertSame([], $r['erreurs']);
    }

    public function test_appel_collecte_seul_l_organisateur_change_aucune_date_ecrite(): void {
        $f = self::appel_collecte();
        $envoi = ['organisateur' => 'Fonds de recherche du Québec'] + self::envoi_depuis_html(MetaboxDetails::html($f, ''));
        $r = MetaboxDetails::nettoyer($envoi, $f);
        $this->assertSame(['organisateur' => 'Fonds de recherche du Québec'], $r['champs']);
    }

    public function test_appel_collecte_date_limite_changee_fin_suit_le_debut(): void {
        $f = self::appel_collecte();
        $envoi = ['echeance' => '2026-12-15'] + self::envoi_depuis_html(MetaboxDetails::html($f, ''));
        $r = MetaboxDetails::nettoyer($envoi, $f);
        $this->assertSame('2026-12-15', $r['champs']['echeance']);
        $this->assertSame('2026-10-15', $r['champs']['fin']);
        $this->assertSame(['fin', 'echeance'], $r['champs']['champs_manuels']);
    }

    public function test_appel_collecte_publication_changee_la_date_limite_reprend_la_fin(): void {
        $f = self::appel_collecte();
        $envoi = ['publie' => '2026-10-20'] + self::envoi_depuis_html(MetaboxDetails::html($f, ''));
        $r = MetaboxDetails::nettoyer($envoi, $f);
        $this->assertSame('2026-10-20', $r['champs']['debut']);
        $this->assertSame('2026-10-20', $r['champs']['fin']);
        $this->assertSame('2026-11-30', $r['champs']['echeance']);
    }

    public function test_appel_vers_evenement_sans_debut_valide_reste_un_appel(): void {
        // M1 : « Publié le » vide, donc pas de date de début ; le type n'est pas changé et l'avis le dit.
        $f = self::fiche(['type' => 'appel', 'debut' => null, 'fin' => null, 'echeance' => '2026-12-01']);
        $envoi = ['type' => 'evenement', 'debut' => '', 'publie' => '', 'fin' => ''] + self::envoi_identique($f);
        $r = MetaboxDetails::nettoyer($envoi, $f);
        $this->assertArrayNotHasKey('type', $r['champs']);
        $this->assertArrayNotHasKey('debut', $r['champs']);
        $this->assertCount(1, $r['erreurs']);
        $this->assertStringContainsString('reste un appel', $r['erreurs'][0]);
        // Avec une date de début valide, le passage se fait.
        $r = MetaboxDetails::nettoyer(['debut' => '2026-11-05'] + $envoi, $f);
        $this->assertSame('evenement', $r['champs']['type']);
        $this->assertSame('2026-11-05', $r['champs']['debut']);
        $this->assertSame([], $r['erreurs']);
    }

    public function test_format_et_langue_hors_liste_ignores(): void {
        $f = self::fiche();
        $r = MetaboxDetails::nettoyer(['format' => 'Autre', 'langue' => 'de', 'type' => 'nouvelle'] + self::envoi_identique($f), $f);
        $this->assertSame([], $r['champs']);
        $r = MetaboxDetails::nettoyer(['format' => 'Hybride', 'langue' => 'en'] + self::envoi_identique($f), $f);
        $this->assertSame('Hybride', $r['champs']['format']);
        $this->assertSame('en', $r['champs']['langue']);
        // Le format et la langue ne sont jamais changés par la collecte : pas de protection.
        $this->assertArrayNotHasKey('champs_manuels', $r['champs']);
    }

    public function test_heure_invalide_ignoree_et_heure_valide_normalisee(): void {
        $f = self::fiche();
        foreach (['25:00', '12h30', '7', '12:60'] as $heure) {
            $r = MetaboxDetails::nettoyer(['heure' => $heure] + self::envoi_identique($f), $f);
            $this->assertArrayNotHasKey('heure', $r['champs'], $heure);
        }
        $r = MetaboxDetails::nettoyer(['heure' => '9:05'] + self::envoi_identique($f), $f);
        $this->assertSame('09:05', $r['champs']['heure']);
        $r = MetaboxDetails::nettoyer(['heure' => ''] + self::envoi_identique($f), $f);
        $this->assertSame('', $r['champs']['heure']);
        $this->assertSame(['heure'], $r['champs']['champs_manuels']);
    }

    public function test_date_limite_invalide_ignoree_et_vide_effacee(): void {
        $f = self::fiche(['type' => 'appel', 'debut' => '2026-10-15', 'fin' => '2026-10-15', 'echeance' => '2026-11-30']);
        $r = MetaboxDetails::nettoyer(['echeance' => '2026-13-01'] + self::envoi_identique($f), $f);
        $this->assertArrayNotHasKey('echeance', $r['champs']);
        $r = MetaboxDetails::nettoyer(['echeance' => ''] + self::envoi_identique($f), $f);
        $this->assertArrayHasKey('echeance', $r['champs']);
        $this->assertNull($r['champs']['echeance']);
    }

    public function test_region_recalculee_si_ville_lieu_ou_format_changent(): void {
        $f = self::fiche();
        $r = MetaboxDetails::nettoyer(['ville' => 'Toronto', 'lieu' => 'MaRS'] + self::envoi_identique($f), $f);
        $this->assertSame('Hors Québec', $r['champs']['region']);
        $this->assertSame(['lieu', 'ville'], $r['champs']['champs_manuels']);
        $r = MetaboxDetails::nettoyer(['format' => 'Virtuel'] + self::envoi_identique($f), $f);
        $this->assertSame('En ligne', $r['champs']['region']);
        // Ville changée mais toujours au Québec : la région ne change pas, elle n'est pas écrite.
        $r = MetaboxDetails::nettoyer(['ville' => 'Québec'] + self::envoi_identique($f), $f);
        $this->assertArrayNotHasKey('region', $r['champs']);
    }

    public function test_textes_sans_balises(): void {
        $f = self::fiche();
        $r = MetaboxDetails::nettoyer(['organisateur' => '<b>IVADO</b> <script>x</script>', 'categorie' => ' Concours  ouvert '] + self::envoi_identique($f), $f);
        $this->assertSame('IVADO x', $r['champs']['organisateur']);
        $this->assertSame('Concours ouvert', $r['champs']['categorie']);
    }

    // --- html ---

    public function test_html_radios_cochees_selon_le_type(): void {
        $html = MetaboxDetails::html(self::fiche(['type' => 'appel']), 'Collectée : Mila : événements');
        $this->assertMatchesRegularExpression('#<input type="radio" name="rsn_veille_details\[type\]" value="appel" checked>#', $html);
        $this->assertMatchesRegularExpression('#<input type="radio" name="rsn_veille_details\[type\]" value="evenement">#', $html);
        $html = MetaboxDetails::html(self::fiche(), '');
        $this->assertMatchesRegularExpression('#name="rsn_veille_details\[type\]" value="evenement" checked>#', $html);
        // Fiche neuve, sans type : événement par défaut.
        $html = MetaboxDetails::html(new Fiche(), '');
        $this->assertMatchesRegularExpression('#name="rsn_veille_details\[type\]" value="evenement" checked>#', $html);
    }

    public function test_html_champs_du_type_et_data_pour(): void {
        $html = MetaboxDetails::html(self::fiche(['type' => 'appel', 'echeance' => '2026-11-30']), '');
        foreach (['organisateur', 'echeance', 'categorie', 'publie', 'debut', 'fin', 'heure', 'format', 'lieu', 'ville', 'langue', 'url'] as $nom) {
            $this->assertStringContainsString('name="rsn_veille_details[' . $nom . ']"', $html, $nom);
        }
        // Champs de l'autre type masqués dès le rendu ; ceux du type affichés.
        $this->assertMatchesRegularExpression('#<tr data-pour="evenement" hidden>#', $html);
        $this->assertMatchesRegularExpression('#<tr data-pour="appel">#', $html);
        $this->assertDoesNotMatchRegularExpression('#<tr data-pour="appel" hidden>#', $html);
        // Ordre de la maquette : organisateur, date limite, catégorie, publié le, puis les champs de l'événement, langue, lien.
        $ordre = array_map(static fn(string $n): int => (int) strpos($html, 'name="rsn_veille_details[' . $n . ']"'), ['organisateur', 'echeance', 'categorie', 'publie', 'debut', 'fin', 'heure', 'format', 'lieu', 'ville', 'langue', 'url']);
        $tri = $ordre;
        sort($tri);
        $this->assertSame($tri, $ordre);
        foreach (['Organisateur', 'Date limite', 'Catégorie', 'Publié le', 'Date de début', 'Date de fin', 'Heure', 'Format', 'Lieu', 'Ville', 'Langue', 'Lien d&#039;information', 'Événement', 'Appel'] as $libelle) {
            $this->assertStringContainsString($libelle, $html, $libelle);
        }
        $this->assertStringContainsString('value="2026-11-30"', $html);
        $this->assertStringContainsString('name="rsn_veille_details_jeton" value="jeton-rsn_veille_details"', $html);
    }

    public function test_html_appel_sans_echeance_montre_la_fin_comme_date_limite(): void {
        $html = MetaboxDetails::html(self::fiche(['type' => 'appel', 'debut' => '2026-10-15', 'fin' => '2026-11-30']), '');
        $this->assertMatchesRegularExpression('#name="rsn_veille_details\[echeance\]" value="2026-11-30"#', $html);
    }

    public function test_html_pastille_sur_les_champs_proteges(): void {
        $html = MetaboxDetails::html(self::fiche(['champs_manuels' => ['url', 'titre']]), '');
        $this->assertSame(1, substr_count($html, 'corrigé à la main</span>'));
        $this->assertMatchesRegularExpression('#name="rsn_veille_details\[url\]"[^>]*>\s*<span class="rsn-veille-corrige">#u', $html);
        // Note qui explique la règle.
        $this->assertStringContainsString('ne sont plus changés par la collecte de nuit', $html);
        $sans = MetaboxDetails::html(self::fiche(), '');
        $this->assertStringNotContainsString('corrigé à la main</span>', $sans);
        // Le début protégé : pastille sur la date de début et sur « Publié le ».
        $debut = MetaboxDetails::html(self::fiche(['champs_manuels' => ['debut']]), '');
        $this->assertSame(2, substr_count($debut, 'corrigé à la main</span>'));
    }

    public function test_html_valeurs_echappees(): void {
        $f = self::fiche(['organisateur' => '"><script>alert(1)</script>', 'lieu' => 'Salle <b>A</b> & B', 'url' => 'https://exemple.test/?a=1&b="2"']);
        $html = MetaboxDetails::html($f, 'Collectée : <i>Mila</i>');
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('<i>Mila</i>', $html);
        $this->assertStringContainsString('value="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"', $html);
        $this->assertStringContainsString('Salle &lt;b&gt;A&lt;/b&gt; &amp; B', $html);
        $this->assertStringContainsString('Collectée : &lt;i&gt;Mila&lt;/i&gt;', $html);
    }

    public function test_html_format_et_langue_selectionnes(): void {
        $html = MetaboxDetails::html(self::fiche(['format' => 'Hybride', 'langue' => 'en']), '');
        $this->assertStringContainsString('<option value="Hybride" selected>Hybride</option>', $html);
        $this->assertStringContainsString('<option value="en" selected>Anglais</option>', $html);
        $this->assertStringContainsString('<option value="fr">Français</option>', $html);
        // Format inconnu de la fiche (appel collecté) : choix vide, rien ne change à l'enregistrement.
        $vide = MetaboxDetails::html(self::fiche(['format' => '']), '');
        $this->assertStringContainsString('<option value="" selected>', $vide);
    }

    public function test_origine_de_la_fiche(): void {
        $this->assertSame('Proposée par le public', MetaboxDetails::origine(self::fiche(['source' => Fiche::SOURCE_PROPOSITION, 'niveau' => 'M'])));
        $this->assertSame('Saisie à la main', MetaboxDetails::origine(self::fiche(['source' => 'manuel', 'niveau' => 'M'])));
        $this->assertSame('Saisie à la main', MetaboxDetails::origine(self::fiche(['source' => '', 'niveau' => 'M'])));
        $this->assertSame('Saisie à la main', MetaboxDetails::origine(new Fiche()));
        $this->assertSame('Collectée : Mila : événements', MetaboxDetails::origine(self::fiche()));
        $this->assertSame('Collectée : infolettre', MetaboxDetails::origine(self::fiche(['source' => 'infolettre', 'niveau' => 'M'])));
    }

    // --- WordPress : accrochage, métabox, ressources ---

    public function test_accrochages(): void {
        MetaboxDetails::accrocher();
        $actions = $GLOBALS['rsn_veille_wp']['actions'];
        foreach (['add_meta_boxes', 'save_post_rsn_veille', 'admin_enqueue_scripts', 'admin_notices'] as $crochet) {
            $this->assertArrayHasKey($crochet, $actions, $crochet);
        }
    }

    public function test_enregistre_la_metabox_sous_le_texte_avec_l_origine(): void {
        $id = wp_insert_post(['post_type' => TypeContenu::TYPE, 'post_title' => 'Proposée', 'post_status' => 'pending']);
        update_post_meta($id, TypeContenu::PREFIXE . 'source', Fiche::SOURCE_PROPOSITION);
        MetaboxDetails::enregistrer(TypeContenu::TYPE, get_post($id));
        $boite = $GLOBALS['rsn_veille_wp']['metaboxes']['rsn_veille_details'] ?? null;
        $this->assertNotNull($boite);
        $this->assertStringStartsWith('Détails de la fiche', $boite['titre']);
        $this->assertStringContainsString('Proposée par le public · à approuver', $boite['titre']);
        $this->assertSame([MetaboxDetails::class, 'rendre'], $boite['rappel']);
        $this->assertSame('rsn_veille', $boite['ecran']);
        $this->assertSame('normal', $boite['contexte']);
        $this->assertSame('high', $boite['priorite']);
    }

    public function test_pas_de_metabox_pour_un_autre_type(): void {
        MetaboxDetails::enregistrer('post', null);
        $this->assertArrayNotHasKey('rsn_veille_details', $GLOBALS['rsn_veille_wp']['metaboxes']);
    }

    public function test_rendre_affiche_la_fiche_du_post(): void {
        $id = wp_insert_post(['post_type' => TypeContenu::TYPE, 'post_title' => 'Fiche', 'post_status' => 'publish']);
        update_post_meta($id, TypeContenu::PREFIXE . 'organisateur', 'Fonds de recherche du Québec');
        ob_start();
        MetaboxDetails::rendre(get_post($id));
        $sortie = (string) ob_get_clean();
        $this->assertStringContainsString('value="Fonds de recherche du Québec"', $sortie);
    }

    public function test_script_seulement_sur_l_editeur_des_fiches(): void {
        $GLOBALS['rsn_veille_wp']['ecran'] = (object) ['base' => 'post', 'post_type' => 'post'];
        MetaboxDetails::charger_ressources('post.php');
        $this->assertSame([], $GLOBALS['rsn_veille_wp']['scripts']);

        $GLOBALS['rsn_veille_wp']['ecran'] = (object) ['base' => 'edit', 'post_type' => TypeContenu::TYPE];
        MetaboxDetails::charger_ressources('edit.php');
        $this->assertSame([], $GLOBALS['rsn_veille_wp']['scripts']);

        $GLOBALS['rsn_veille_wp']['ecran'] = (object) ['base' => 'post', 'post_type' => TypeContenu::TYPE];
        MetaboxDetails::charger_ressources('post-new.php');
        $script = $GLOBALS['rsn_veille_wp']['scripts']['rsn-veille-details'];
        $this->assertStringEndsWith('assets/admin-details.js', $script['source']);
        $this->assertSame(RSN_VEILLE_VERSION, $script['version']);
        $this->assertSame([], $script['dependances']);
    }

    // --- enregistrer_envoi ---

    /** Envoi de l'encadré avec un jeton valide. */
    private static function poster(array $champs, string $jeton = 'jeton-rsn_veille_details'): void {
        $_POST = [MetaboxDetails::CHAMP => wp_slash($champs), 'rsn_veille_details_jeton' => $jeton];
    }

    private static function meta(int $id, string $nom): mixed {
        return get_post_meta($id, TypeContenu::PREFIXE . $nom, true);
    }

    /** Fiche collectée enregistrée dans le faux WordPress. */
    private static function fiche_enregistree(): int {
        $f = self::fiche();
        $f->id = null;
        return (new EntrepotWordPress())->creer($f);
    }

    public function test_sans_jeton_ou_jeton_faux_rien(): void {
        $GLOBALS['rsn_veille_wp']['capacites'] = ['edit_post'];
        $id = self::fiche_enregistree();
        $_POST = [MetaboxDetails::CHAMP => ['organisateur' => 'Autre']];
        MetaboxDetails::enregistrer_envoi($id);
        $this->assertSame('Mila', self::meta($id, 'organisateur'));
        self::poster(['organisateur' => 'Autre'], 'jeton-faux');
        MetaboxDetails::enregistrer_envoi($id);
        $this->assertSame('Mila', self::meta($id, 'organisateur'));
    }

    public function test_sans_capacite_rien(): void {
        $id = self::fiche_enregistree();
        self::poster(['organisateur' => 'Autre']);
        MetaboxDetails::enregistrer_envoi($id);
        $this->assertSame('Mila', self::meta($id, 'organisateur'));
    }

    public function test_revision_rien(): void {
        $GLOBALS['rsn_veille_wp']['capacites'] = ['edit_post'];
        $id = self::fiche_enregistree();
        $revision = wp_insert_post(['post_type' => 'revision', 'post_title' => 'Révision', 'post_parent' => $id, 'post_status' => 'inherit']);
        $avant = [$GLOBALS['rsn_veille_wp']['meta'], $GLOBALS['rsn_veille_wp']['posts']];
        self::poster(['organisateur' => 'Autre', 'url' => 'https://exemple.test/autre']);
        MetaboxDetails::enregistrer_envoi($revision);
        // Aucune écriture, ni sur la révision ni sur la fiche parente.
        $this->assertEquals($avant, [$GLOBALS['rsn_veille_wp']['meta'], $GLOBALS['rsn_veille_wp']['posts']]);
        $this->assertSame('Mila', self::meta($id, 'organisateur'));
        $this->assertSame('', self::meta($revision, 'organisateur'));
    }

    public function test_sauvegarde_automatique_wordpress_rien(): void {
        // Révision de sauvegarde automatique (nom « <parent>-autosave-v1 »), ni écrite ni reportée sur la fiche.
        $GLOBALS['rsn_veille_wp']['capacites'] = ['edit_post'];
        $id = self::fiche_enregistree();
        $auto = wp_insert_post(['post_type' => 'revision', 'post_title' => 'Auto', 'post_name' => $id . '-autosave-v1', 'post_parent' => $id, 'post_status' => 'inherit']);
        $avant = $GLOBALS['rsn_veille_wp']['meta'];
        self::poster(['organisateur' => 'Autre']);
        MetaboxDetails::enregistrer_envoi($auto);
        $this->assertEquals($avant, $GLOBALS['rsn_veille_wp']['meta']);
    }

    /**
     * Requête de sauvegarde automatique (DOING_AUTOSAVE) sur la fiche elle-même : rien n'est écrit. Processus à part :
     * la constante ne se retire pas.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_doing_autosave_rien(): void {
        require_once __DIR__ . '/bouchons-wordpress.php';
        rsn_veille_bouchons_reinitialiser();
        $GLOBALS['rsn_veille_wp']['capacites'] = ['edit_post'];
        $id = self::fiche_enregistree();
        $avant = $GLOBALS['rsn_veille_wp']['meta'];
        define('DOING_AUTOSAVE', true);
        self::poster(['organisateur' => 'Autre', 'debut' => '2026-12-01']);
        MetaboxDetails::enregistrer_envoi($id);
        $this->assertEquals($avant, $GLOBALS['rsn_veille_wp']['meta']);
        $this->assertSame('Mila', self::meta($id, 'organisateur'));
    }

    public function test_correction_d_une_fiche_collectee(): void {
        // Point de vigilance 3 par l'entrepôt : seule l'URL changée est protégée ; la source ne change pas.
        $GLOBALS['rsn_veille_wp']['capacites'] = ['edit_post'];
        $id = self::fiche_enregistree();
        $f = (new EntrepotWordPress())->obtenir($id);
        self::poster(['url' => 'https://mila.quebec/corrige'] + self::envoi_identique($f));
        MetaboxDetails::enregistrer_envoi($id);
        $apres = (new EntrepotWordPress())->obtenir($id);
        $this->assertSame('https://mila.quebec/corrige', $apres->url);
        $this->assertSame(['url'], $apres->champs_manuels);
        $this->assertSame('Mila : événements', $apres->source);
        $this->assertSame('mila:midi', $apres->cle_source);
        $this->assertSame([], get_transient('rsn_veille_details_avis_1') ?: []);
    }

    public function test_fiche_neuve_sans_rien_remplir(): void {
        // Point de vigilance 4 : « Ajouter » puis Enregistrer sans toucher à l'encadré. L'enregistrement de
        // WordPress déclenche save_post, qui déclenche l'encadré (et la mise à jour de la date du post le relance).
        $GLOBALS['rsn_veille_wp']['capacites'] = ['edit_post'];
        MetaboxDetails::accrocher();
        self::poster(['type' => 'evenement', 'organisateur' => '', 'echeance' => '', 'categorie' => '', 'publie' => '', 'debut' => '', 'fin' => '', 'heure' => '', 'format' => '', 'lieu' => '', 'ville' => '', 'langue' => 'fr', 'url' => '']);
        $id = wp_insert_post(['post_type' => TypeContenu::TYPE, 'post_title' => 'Atelier de la relève', 'post_status' => 'draft']);

        $f = (new EntrepotWordPress())->obtenir($id);
        $this->assertSame('manuel', $f->source);
        $this->assertSame('M', $f->niveau);
        $this->assertSame('manuel:' . $id, $f->cle_source);
        $this->assertSame(['manuel:' . $id], $f->cles_source);
        $this->assertSame(['manuel:' . $id], get_post_meta($id, TypeContenu::META_CLE));
        $this->assertTrue($f->manuel);
        $this->assertSame('2026-10-28', $f->cree_le);
        $this->assertSame('evenement', $f->type);
        $this->assertSame('2026-10-28', $f->debut);
        $this->assertSame('2026-10-28', $f->fin);
        $this->assertSame('Québec', $f->region);
        $this->assertSame('ecarte', $f->statut);
        // Date du post : le début ; clé de dédoublonnage à jour.
        $this->assertSame('2026-10-28 00:00:00', get_post($id)->post_date);
        $this->assertSame(Fiche::cle_dedoublonnage_de('2026-10-28', 'Atelier de la relève'), get_post_meta($id, TypeContenu::META_DEDOUBLONNAGE, true));
        // Le rappel est raccroché une seule fois après l'écriture (pas de récursion, pas de doublon).
        $rappels = array_filter($GLOBALS['rsn_veille_wp']['actions']['save_post_rsn_veille'], static fn(array $a): bool => $a['rappel'] === [MetaboxDetails::class, 'enregistrer_envoi']);
        $this->assertCount(1, $rappels);
    }

    public function test_deuxieme_enregistrement_garde_manuel(): void {
        $GLOBALS['rsn_veille_wp']['capacites'] = ['edit_post'];
        MetaboxDetails::accrocher();
        self::poster(['type' => 'appel', 'publie' => '', 'echeance' => '2026-12-01', 'organisateur' => 'RSN']);
        $id = wp_insert_post(['post_type' => TypeContenu::TYPE, 'post_title' => 'Appel à projets', 'post_status' => 'draft']);
        $premier = (new EntrepotWordPress())->obtenir($id);
        $this->assertSame('appel', $premier->type);
        $this->assertSame('2026-12-01', $premier->echeance);

        // Deuxième enregistrement, un autre jour : rien de ce que la création a posé ne change.
        $GLOBALS['rsn_veille_test_aujourdhui'] = '2026-10-30';
        self::poster(['type' => 'evenement', 'debut' => '2026-11-05', 'fin' => '', 'organisateur' => 'RSN']);
        wp_update_post(['ID' => $id, 'post_title' => 'Appel à projets', 'post_status' => 'publish']);
        $f = (new EntrepotWordPress())->obtenir($id);
        $this->assertSame('manuel:' . $id, $f->cle_source);
        $this->assertSame(['manuel:' . $id], $f->cles_source);
        $this->assertSame('manuel', $f->source);
        $this->assertSame('M', $f->niveau);
        $this->assertSame('2026-10-28', $f->cree_le);
        $this->assertSame('evenement', $f->type);
        $this->assertSame('2026-11-05', $f->debut);
        $this->assertSame('2026-11-05', $f->fin);
        $this->assertSame('publie', $f->statut);
    }

    public function test_debut_invalide_garde_l_ancien_et_avis(): void {
        $GLOBALS['rsn_veille_wp']['capacites'] = ['edit_post'];
        $id = self::fiche_enregistree();
        $f = (new EntrepotWordPress())->obtenir($id);
        self::poster(['debut' => '2026-02-30', 'organisateur' => 'Mila et IVADO'] + self::envoi_identique($f));
        MetaboxDetails::enregistrer_envoi($id);
        $apres = (new EntrepotWordPress())->obtenir($id);
        $this->assertSame('2026-11-12', $apres->debut);
        $this->assertSame('Mila et IVADO', $apres->organisateur);
        $avis = get_transient('rsn_veille_details_avis_1');
        $this->assertIsArray($avis);
        $this->assertCount(1, $avis);

        ob_start();
        MetaboxDetails::afficher_avis();
        $sortie = (string) ob_get_clean();
        $this->assertStringContainsString('notice notice-error', $sortie);
        $this->assertStringContainsString('date de début', $sortie);
        // Avis montré une seule fois.
        $this->assertFalse(get_transient('rsn_veille_details_avis_1'));
        ob_start();
        MetaboxDetails::afficher_avis();
        $this->assertSame('', (string) ob_get_clean());
    }

    public function test_avis_html_echappe(): void {
        $html = MetaboxDetails::avis_html(['Lien <b>refusé</b>']);
        $this->assertStringContainsString('Lien &lt;b&gt;refusé&lt;/b&gt;', $html);
        $this->assertSame('', MetaboxDetails::avis_html([]));
    }
}

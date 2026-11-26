<?php
declare(strict_types=1);

require_once __DIR__ . '/bouchons-wordpress.php';

use PHPUnit\Framework\TestCase;
use RSN\Veille\ACompleter;
use RSN\Veille\EntrepotWordPress;
use RSN\Veille\Fiche;

final class ACompleterTest extends TestCase {
    private const URL = 'https://www.exemple.org/evenements/atelier-ia-sante/';

    protected function setUp(): void {
        rsn_veille_bouchons_reinitialiser();
        // Pas de DNS dans les tests : tout hôte mène à une adresse publique, sauf indication contraire.
        ACompleter::$resolveur = static fn(string $hote): array => ['93.184.216.34'];
    }

    protected function tearDown(): void {
        ACompleter::$resolveur = null;
    }

    public function test_adresse_sure_seulement_vers_une_adresse_publique(): void {
        $publique = static fn(string $hote): array => ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'];
        $this->assertTrue(ACompleter::adresse_sure('https://www.exemple.org/page?x=1', $publique));
        $this->assertTrue(ACompleter::adresse_sure('http://exemple.org:80/', $publique));
        $this->assertTrue(ACompleter::adresse_sure('https://93.184.216.34/'));
        $this->assertTrue(ACompleter::adresse_sure('https://[2606:2800:220:1:248:1893:25c8:1946]/'));
        $this->assertTrue(ACompleter::ip_publique('8.8.8.8'));
        $this->assertFalse(ACompleter::ip_publique('pas une adresse'));
        foreach (['ftp://exemple.org/', 'https://moi:secret@exemple.org/', 'https://exemple.org:8080/', 'https://exemple.org:22/', 'https:///chemin', 'pas une adresse', ''] as $url) {
            $this->assertFalse(ACompleter::adresse_sure($url, $publique), $url);
        }
        // Adresses écrites en clair : locales, privées, de liaison locale, de métadonnées, réservées, IPv6 comprises.
        foreach (['127.0.0.1', '10.0.0.5', '172.16.3.4', '192.168.1.1', '169.254.169.254', '0.0.0.0', '100.64.0.1', '224.0.0.1', '198.18.0.1', '192.0.0.8', '203.0.113.9', '255.255.255.255',
            '[::1]', '[::]', '[fe80::1]', '[fd00::1]', '[::ffff:127.0.0.1]', '[::ffff:10.0.0.1]', '[::7f00:1]', '[2002:7f00:1::]', '[2001:0:53aa:64c::1]', '[2001:db8::1]', '[64:ff9b::7f00:1]', '[ff02::1]'] as $hote) {
            $this->assertFalse(ACompleter::adresse_sure('http://' . $hote . '/'), $hote);
        }
        // Un nom qui mène, même en partie, à une adresse interne ; un nom qui ne se résout pas.
        $this->assertFalse(ACompleter::adresse_sure('https://interne.exemple.org/', static fn(string $h): array => ['93.184.216.34', '10.0.0.5']));
        $this->assertFalse(ACompleter::adresse_sure('https://interne.exemple.org/', static fn(string $h): array => ['fe80::1']));
        $this->assertFalse(ACompleter::adresse_sure('https://inconnu.exemple.org/', static fn(string $h): array => []));
    }

    public function test_adresse_non_sure_jamais_demandee(): void {
        $e = new EntrepotWordPress();
        $f = self::enregistree();
        ACompleter::$resolveur = static fn(string $hote): array => ['169.254.169.254'];
        $this->assertSame(0, ACompleter::lire_titres($e, [$f]));
        $this->assertSame([], $GLOBALS['rsn_veille_wp']['requetes_http'] ?? [], 'aucune requête vers une adresse interne');
        $this->assertSame('0', get_post_meta($f->id, 'rsn_veille_titre_lu', true));
    }

    public function test_redirection_suivie_seulement_vers_une_adresse_sure(): void {
        $e = new EntrepotWordPress();
        // Redirection vers une adresse interne : la deuxième requête n'est jamais faite.
        $f = self::enregistree();
        ACompleter::$resolveur = static fn(string $hote): array => $hote === 'www.exemple.org' ? ['93.184.216.34'] : ['10.0.0.5'];
        $GLOBALS['rsn_veille_wp']['reponse_http'] = ['headers' => ['location' => 'http://interne.exemple.org/admin'], 'body' => '', 'response' => ['code' => 302]];
        $this->assertSame(0, ACompleter::lire_titres($e, [$f]));
        $this->assertCount(1, $GLOBALS['rsn_veille_wp']['requetes_http']);
        $this->assertSame(0, $GLOBALS['rsn_veille_wp']['requetes_http'][0]['arguments']['redirection'], 'WordPress ne suit rien de lui-même');
        // Redirections sans fin : une seule est suivie, puis l'abandon.
        $g = self::enregistree();
        ACompleter::$resolveur = static fn(string $hote): array => ['93.184.216.34'];
        $GLOBALS['rsn_veille_wp']['requetes_http'] = [];
        $GLOBALS['rsn_veille_wp']['reponse_http'] = ['headers' => ['location' => '/encore'], 'body' => '', 'response' => ['code' => 301]];
        $this->assertSame(0, ACompleter::lire_titres($e, [$g]));
        $this->assertSame(['https://www.exemple.org/evenements/atelier-ia-sante/', 'https://www.exemple.org/encore'], array_column($GLOBALS['rsn_veille_wp']['requetes_http'], 'url'));
    }

    private static function fiche(array $champs = []): Fiche {
        return Fiche::depuis_tableau($champs + [
            'type' => 'evenement', 'titre' => ACompleter::titre_provisoire(self::URL), 'debut' => null, 'fin' => null,
            'organisateur' => '', 'source' => Fiche::SOURCE_PROPOSITION, 'niveau' => 'M', 'statut' => 'a_approuver', 'url' => self::URL,
            'cle_source' => 'proposition:' . uniqid('', true),
        ]);
    }

    /** Fiche enregistrée et marquée « à compléter ». */
    private static function enregistree(array $champs = []): Fiche {
        $e = new EntrepotWordPress();
        $f = self::fiche($champs);
        $f->cles_source = [$f->cle_source];
        $id = $e->creer($f);
        ACompleter::marquer($id);
        return $e->obtenir($id);
    }

    public function test_titre_provisoire(): void {
        $this->assertSame('exemple.org/evenements/atelier-ia-sante', ACompleter::titre_provisoire(self::URL));
        $this->assertSame('exemple.org', ACompleter::titre_provisoire('HTTP://WWW.exemple.org/'));
        $this->assertSame('sous.exemple.org/a?b=1', ACompleter::titre_provisoire(' https://sous.exemple.org/a?b=1 '));
        $long = ACompleter::titre_provisoire('https://exemple.org/' . str_repeat('é', 200));
        $this->assertSame(80, mb_strlen($long));
        $this->assertStringEndsWith('…', $long);
    }

    public function test_ce_qui_manque(): void {
        $this->assertSame(['titre', 'organisateur', 'date'], ACompleter::manque(self::fiche()));
        $this->assertSame(['organisateur'], ACompleter::manque(self::fiche(['titre' => 'Atelier IA', 'debut' => '2027-01-21'])));
        $this->assertSame([], ACompleter::manque(self::fiche(['titre' => 'Atelier IA', 'organisateur' => 'MiCM', 'debut' => '2027-01-21'])));
        $this->assertSame(['date'], ACompleter::manque(self::fiche(['titre' => 'Atelier IA', 'organisateur' => 'MiCM'])));
        $this->assertSame(['titre'], ACompleter::manque(self::fiche(['titre' => '  ', 'organisateur' => 'MiCM', 'debut' => '2027-01-21'])));
        // La date limite d'un appel est facultative : pas de date exigée.
        $this->assertSame([], ACompleter::manque(self::fiche(['type' => 'appel', 'titre' => 'Bourses 2027', 'organisateur' => 'FRQ', 'debut' => '2026-11-26'])));
    }

    public function test_titre_de_la_page(): void {
        $html = '<html><head><title> Atelier &amp; IA | MiCM </title><meta content="Atelier : l&#8217;IA en santé" property="og:title"></head></html>';
        $this->assertSame('Atelier : l’IA en santé', ACompleter::titre_de_la_page($html));
        $this->assertSame('Atelier & IA | MiCM', ACompleter::titre_de_la_page('<TITLE lang="fr"> Atelier &amp; IA | MiCM </TITLE>'));
        $this->assertSame('Titre "cité"', ACompleter::titre_de_la_page("<meta property='og:title' content='Titre \"cité\"' />"));
        $this->assertSame('', ACompleter::titre_de_la_page('<p>Sans titre</p>'));
        $this->assertSame('', ACompleter::titre_de_la_page(''));
        $this->assertSame(200, mb_strlen(ACompleter::titre_de_la_page('<title>' . str_repeat('a', 500) . '</title>')));
        // Balise og:title vide : le titre de la page prend le relais.
        $this->assertSame('Repli', ACompleter::titre_de_la_page('<meta property="og:title" content=""><title>Repli</title>'));
    }

    public function test_titre_provisoire_enregistre_reconnu_meme_nettoye_ou_apres_changement_d_adresse(): void {
        // L'entrepôt nettoie le titre (octets encodés retirés) : il ne ressemble plus au titre calculé depuis l'adresse.
        $url = 'https://www.exemple.qc.ca/%C3%A9v%C3%A9nements/atelier-ia';
        $f = self::enregistree(['url' => $url, 'titre' => ACompleter::titre_provisoire($url)]);
        ACompleter::marquer($f->id, '', $f->titre);
        $this->assertNotSame(ACompleter::titre_provisoire($url), $f->titre, 'le cas que la méta existe pour couvrir');
        $this->assertContains('titre', ACompleter::manque_de($f->id, $f));
        // L'adresse corrigée dans l'encadré sans toucher au titre : le titre manque toujours.
        $f->url = 'https://www.exemple.qc.ca/autre-page';
        $this->assertContains('titre', ACompleter::manque_de($f->id, $f));
        $f->url = '';
        $this->assertContains('titre', ACompleter::manque_de($f->id, $f));
        // Un vrai titre : il ne manque plus.
        $f->titre = 'Atelier IA';
        $this->assertNotContains('titre', ACompleter::manque_de($f->id, $f));
        // Et la lecture du titre est bien essayée pour une telle adresse.
        $g = self::enregistree(['url' => $url, 'titre' => ACompleter::titre_provisoire($url)]);
        ACompleter::marquer($g->id, '', $g->titre);
        $this->assertSame(1, ACompleter::lire_titres(new EntrepotWordPress(), [$g], static fn(string $u): string => '<title>Atelier lu</title>'));
    }

    public function test_titre_de_page_de_refus_ignore(): void {
        foreach (['Just a moment...', 'Attention Required! | Cloudflare', '403 Forbidden', '404 Not Found', 'Access Denied', 'Erreur 500', 'Page introuvable | Organisme'] as $titre) {
            $this->assertSame('', ACompleter::titre_de_la_page('<title>' . $titre . '</title>'), $titre);
        }
        $this->assertSame('Forum 2027 sur la santé', ACompleter::titre_de_la_page('<title>Forum 2027 sur la santé</title>'));
    }

    public function test_adresse_comparable(): void {
        $this->assertSame('exemple.org/evenements/atelier', ACompleter::adresse_comparable(' HTTPS://www.Exemple.org/evenements/Atelier/#inscription '));
        $this->assertSame(ACompleter::adresse_comparable('http://exemple.org/a?x=1'), ACompleter::adresse_comparable('https://www.exemple.org/a?x=1'));
        $this->assertNotSame(ACompleter::adresse_comparable('https://exemple.org/a?x=1'), ACompleter::adresse_comparable('https://exemple.org/a?x=2'));
        $this->assertSame('', ACompleter::adresse_comparable(''));
    }

    public function test_une_fiche_a_completer_ne_passe_pas_a_publiee(): void {
        $f = self::enregistree();
        $donnees = ['post_type' => 'rsn_veille', 'post_status' => 'publish', 'post_title' => 'x'];
        // Bouton « Publier » de l'éditeur, modification rapide, actions groupées : la fiche reste en attente.
        $this->assertSame('pending', ACompleter::retenir_publication($donnees, ['ID' => $f->id])['post_status']);
        $this->assertSame('pending', ACompleter::retenir_publication(['post_status' => 'future'] + $donnees, ['ID' => $f->id])['post_status']);
        // Les autres statuts, les autres types et les fiches sans marque ne sont pas touchés.
        $this->assertSame('pending', ACompleter::retenir_publication(['post_status' => 'pending'] + $donnees, ['ID' => $f->id])['post_status']);
        $this->assertSame('trash', ACompleter::retenir_publication(['post_status' => 'trash'] + $donnees, ['ID' => $f->id])['post_status']);
        $this->assertSame('publish', ACompleter::retenir_publication(['post_type' => 'post'] + $donnees, ['ID' => $f->id])['post_status']);
        $this->assertSame('publish', ACompleter::retenir_publication($donnees, ['ID' => 0])['post_status']);
        $this->assertSame('publish', ACompleter::retenir_publication($donnees, 'pas un tableau')['post_status']);
        $this->assertSame('pas un tableau', ACompleter::retenir_publication('pas un tableau', []));
        ACompleter::lever($f->id);
        $this->assertSame('publish', ACompleter::retenir_publication($donnees, ['ID' => $f->id])['post_status']);
        // Le filtre est accroché.
        ACompleter::accrocher();
        $this->assertNotEmpty($GLOBALS['rsn_veille_wp']['filtres']['wp_insert_post_data'] ?? []);
    }

    public function test_lecture_notee_avant_la_requete_et_bornee_dans_le_temps(): void {
        $e = new EntrepotWordPress();
        $f = self::enregistree();
        // L'essai est noté avant la requête : une lecture interrompue n'est pas relancée.
        $vu = null;
        ACompleter::lire_titres($e, [$f], static function (string $url) use (&$vu, $f): string {
            $vu = get_post_meta($f->id, 'rsn_veille_titre_lu', true);
            return '';
        });
        $this->assertSame('0', $vu);
        // Budget de temps : après une lecture très lente, les suivantes attendent une autre ouverture de la file.
        $lot = [self::enregistree(), self::enregistree(), self::enregistree()];
        $temps = 100.0;
        $appels = 0;
        $lus = ACompleter::lire_titres($e, $lot, static function (string $url) use (&$temps, &$appels): string {
            $appels++;
            $temps += 9.0;
            return '<title>Lu</title>';
        }, static function () use (&$temps): float {
            return $temps;
        });
        $this->assertSame(1, $appels);
        $this->assertSame(1, $lus);
        $this->assertSame('', get_post_meta($lot[1]->id, 'rsn_veille_titre_lu', true), 'pas encore essayée : elle le sera à la prochaine ouverture');
    }

    public function test_marque_levee_et_mot(): void {
        $f = self::enregistree();
        $this->assertTrue(ACompleter::est($f->id));
        $this->assertSame('', ACompleter::mot($f->id));
        ACompleter::marquer($f->id, "Mot de l'équipe");
        $this->assertSame("Mot de l'équipe", ACompleter::mot($f->id));
        ACompleter::lever($f->id);
        $this->assertFalse(ACompleter::est($f->id));
        $this->assertFalse(ACompleter::est(0));
    }

    public function test_lecture_du_titre_une_seule_fois(): void {
        $e = new EntrepotWordPress();
        $f = self::enregistree();
        $appels = [];
        $obtenir = static function (string $url) use (&$appels): string {
            $appels[] = $url;
            return '<title>Atelier : introduction à l’IA en santé</title>';
        };
        $this->assertSame(1, ACompleter::lire_titres($e, [$f], $obtenir));
        $this->assertSame([self::URL], $appels);
        $this->assertSame('Atelier : introduction à l’IA en santé', $f->titre, 'la fiche passée est à jour pour l’affichage');
        $this->assertSame('Atelier : introduction à l’IA en santé', $e->obtenir($f->id)->titre);
        $this->assertTrue(ACompleter::titre_lu($f->id));
        $this->assertTrue(ACompleter::est($f->id), 'la fiche reste à compléter : le titre est à vérifier');
        // Deuxième ouverture de la file : rien n'est relu.
        $this->assertSame(0, ACompleter::lire_titres($e, [$e->obtenir($f->id)], $obtenir));
        $this->assertCount(1, $appels);
    }

    public function test_lecture_ratee_notee_et_jamais_reessayee(): void {
        $e = new EntrepotWordPress();
        $f = self::enregistree();
        $appels = 0;
        $obtenir = static function (string $url) use (&$appels): string {
            $appels++;
            return '';
        };
        $this->assertSame(0, ACompleter::lire_titres($e, [$f], $obtenir));
        $this->assertSame('0', get_post_meta($f->id, 'rsn_veille_titre_lu', true));
        $this->assertFalse(ACompleter::titre_lu($f->id));
        $this->assertSame(ACompleter::titre_provisoire(self::URL), $e->obtenir($f->id)->titre);
        ACompleter::lire_titres($e, [$f], $obtenir);
        $this->assertSame(1, $appels);
    }

    public function test_lecture_seulement_des_fiches_a_completer_au_titre_provisoire(): void {
        $e = new EntrepotWordPress();
        $deja_titree = self::enregistree(['titre' => 'Titre donné par l’équipe']);
        $non_marquee = self::enregistree();
        ACompleter::lever($non_marquee->id);
        $appels = 0;
        $obtenir = static function (string $url) use (&$appels): string {
            $appels++;
            return '<title>Lu</title>';
        };
        $this->assertSame(0, ACompleter::lire_titres($e, [$deja_titree, $non_marquee, 'pas une fiche'], $obtenir));
        $this->assertSame(0, $appels);
        // Trois essais au plus par ouverture de la file.
        $lot = [self::enregistree(), self::enregistree(), self::enregistree(), self::enregistree()];
        $this->assertSame(3, ACompleter::lire_titres($e, $lot, $obtenir));
        $this->assertSame(3, $appels);
    }

    public function test_lecture_par_defaut_refuse_les_adresses_non_sures_et_ce_qui_n_est_pas_du_html(): void {
        $e = new EntrepotWordPress();
        $f = self::enregistree();
        $GLOBALS['rsn_veille_wp']['reponse_http'] = ['headers' => ['content-type' => 'text/html; charset=UTF-8'], 'body' => '<title>Atelier lu</title>', 'response' => ['code' => 200, 'message' => 'OK']];
        $this->assertSame(1, ACompleter::lire_titres($e, [$f]));
        $requete = $GLOBALS['rsn_veille_wp']['requetes_http'][0];
        $this->assertSame(self::URL, $requete['url']);
        $this->assertTrue($requete['arguments']['reject_unsafe_urls']);
        $this->assertSame(0, $requete['arguments']['redirection']);
        $this->assertSame(4, $requete['arguments']['timeout']);
        $this->assertStringStartsWith('RSN-Veille/', $requete['arguments']['user-agent']);
        // Refus du site (403) ou autre chose que du HTML : pas de titre.
        foreach ([['headers' => ['content-type' => 'text/html'], 'body' => '<title>Just a moment…</title>', 'response' => ['code' => 403]], ['headers' => ['content-type' => 'application/pdf'], 'body' => '<title>PDF</title>', 'response' => ['code' => 200]]] as $reponse) {
            $autre = self::enregistree();
            $GLOBALS['rsn_veille_wp']['reponse_http'] = $reponse;
            $this->assertSame(0, ACompleter::lire_titres($e, [$autre]));
            $this->assertSame('0', get_post_meta($autre->id, 'rsn_veille_titre_lu', true));
        }
    }
}

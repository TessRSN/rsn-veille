<?php
declare(strict_types=1);

require_once __DIR__ . '/bouchons-wordpress.php';

use PHPUnit\Framework\TestCase;
use RSN\Veille\Infolettre;

final class InfolettreTest extends TestCase {
    private const AUJOURDHUI = '2026-10-26';

    private static function evenement(array $champs = []): array {
        return $champs + [
            'type' => 'evenement', 'titre' => 'Midi-conférence en santé numérique', 'debut' => '2026-11-12', 'fin' => null,
            'organisateur' => 'Mila', 'lieu' => 'Mila', 'ville' => 'Montréal', 'format' => 'Présentiel', 'prix' => 'Gratuit',
            'langue' => 'fr', 'themes' => ['IA', 'Santé'], 'resume' => 'Une présentation sur l’IA en santé.',
            'url' => 'https://mila.quebec/fr/evenements/midi',
        ];
    }

    private static function appel(array $champs = []): array {
        return $champs + [
            'type' => 'appel', 'titre' => 'Concours de bourses', 'debut' => '2026-10-01', 'echeance' => '2026-11-20',
            'organisateur' => 'FRQ', 'categorie' => 'Bourse', 'resume' => 'Bourses de recherche.',
            'url' => 'https://frq.test/' . md5((string) ($champs['titre'] ?? 'bourses')),
        ];
    }

    private static function gardee(array $champs = []): array {
        return $champs + [
            'url' => 'https://inspq.test/n1', 'titre' => 'Une nouvelle', 'date' => '2026-10-20', 'source' => 'INSPQ',
            'organisateur' => 'INSPQ', 'resume' => 'Résumé de la nouvelle.', 'gardee_le' => '2026-10-25 09:00',
        ];
    }

    // --- Agenda ---

    public function test_agenda_deux_fiches(): void {
        $html = Infolettre::bloc_agenda([
            self::evenement(),
            self::evenement(['titre' => 'Atelier IVADO', 'debut' => '2026-12-01', 'fin' => '2026-12-03', 'lieu' => '', 'ville' => '', 'format' => 'Virtuel', 'prix' => '', 'langue' => 'en', 'themes' => []]),
        ], 8, null, self::AUJOURDHUI);
        $this->assertStringContainsString('Midi-conférence en santé numérique', $html);
        $this->assertStringContainsString('Atelier IVADO', $html);
        $this->assertSame(2, substr_count($html, "Plus d'information →"));
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<style', $html);
        $this->assertStringStartsWith("<!-- Agenda", $html);
        $this->assertStringContainsString("généré par la veille de l'écosystème, 26 octobre 2026) -->", $html);
        $this->assertStringContainsString("<table style=\"border-collapse:collapse;font-family:'Plus Jakarta Sans',Arial,sans-serif;font-size:14px;color:#101828\">", $html);
    }

    public function test_agenda_format_de_reference(): void {
        $html = Infolettre::bloc_agenda([self::evenement()], 8, null, self::AUJOURDHUI);
        $this->assertStringContainsString('<td style="width:64px;text-align:center;vertical-align:top;color:#003469"><div style="font-size:28px;line-height:1">12</div><div style="font-size:11px;letter-spacing:.08em;text-transform:uppercase">nov.</div></td>', $html);
        $this->assertStringContainsString('<b style="font-size:16px">Midi-conférence en santé numérique</b>', $html);
        $this->assertStringContainsString('<span style="color:#6E7A84">Mila · Mila – Montréal</span>', $html);
        $this->assertStringContainsString('<span style="font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#3D6A99">IA · Santé</span>', $html);
        $this->assertStringContainsString('<a href="https://mila.quebec/fr/evenements/midi" style="color:#003469">Plus d\'information →</a>', $html);
        $this->assertStringContainsString('<span style="color:#6E7A84">Présentiel – Gratuit</span>', $html);
    }

    public function test_agenda_plusieurs_jours_et_langue_anglaise(): void {
        $meme_mois = Infolettre::bloc_agenda([self::evenement(['debut' => '2026-12-01', 'fin' => '2026-12-03', 'langue' => 'en'])], 8, null, self::AUJOURDHUI);
        $this->assertStringContainsString('line-height:1">1–3</div>', $meme_mois);
        $this->assertStringContainsString('déc.</div>', $meme_mois);
        $this->assertStringContainsString('Présentiel – Gratuit · En anglais', $meme_mois);
        $deux_mois = Infolettre::bloc_agenda([self::evenement(['debut' => '2026-11-28', 'fin' => '2026-12-02'])], 8, null, self::AUJOURDHUI);
        $this->assertStringContainsString('line-height:1">28 nov. – 2</div>', $deux_mois);
        $this->assertStringContainsString('déc.</div>', $deux_mois);
    }

    public function test_agenda_fin_avant_le_debut_est_ignoree(): void {
        $html = Infolettre::bloc_agenda([self::evenement(['debut' => '2026-11-12', 'fin' => '2026-11-10'])], 8, null, self::AUJOURDHUI);
        $this->assertStringContainsString('line-height:1">12</div>', $html);
    }

    public function test_agenda_echappe_tout(): void {
        $html = Infolettre::bloc_agenda([self::evenement([
            'titre' => 'Titre <b>gras</b> & "guillemets"', 'organisateur' => '<i>Org</i>', 'resume' => '<script>alert(1)</script>',
            'themes' => ['<u>x</u>'], 'url' => 'javascript:alert(1)',
        ])], 8, null, self::AUJOURDHUI);
        $this->assertStringContainsString('Titre &lt;b&gt;gras&lt;/b&gt; &amp; &quot;guillemets&quot;', $html);
        $this->assertStringNotContainsString('<b>gras</b>', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<i>', $html);
        $this->assertStringNotContainsString('<u>', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString("Plus d'information", $html, 'une adresse refusée ne donne aucun lien');
    }

    public function test_agenda_limite_par_nombre_et_tri_par_debut(): void {
        $fiches = [];
        foreach ([5, 3, 1, 4, 2] as $n) {
            $fiches[] = self::evenement(['titre' => 'Événement ' . $n, 'debut' => '2026-11-0' . $n]);
        }
        $html = Infolettre::bloc_agenda($fiches, 3, null, self::AUJOURDHUI);
        $this->assertSame(3, substr_count($html, "Plus d'information →"));
        $this->assertLessThan(strpos($html, 'Événement 2'), strpos($html, 'Événement 1'));
        $this->assertLessThan(strpos($html, 'Événement 3'), strpos($html, 'Événement 2'));
        $this->assertStringNotContainsString('Événement 4', $html);
        $this->assertStringNotContainsString('Événement 5', $html);
    }

    public function test_agenda_horizon_remplace_le_nombre(): void {
        $fiches = [];
        foreach ([1, 2, 3, 4, 5] as $n) {
            $fiches[] = self::evenement(['titre' => 'Événement ' . $n, 'debut' => '2026-11-0' . $n]);
        }
        $html = Infolettre::bloc_agenda($fiches, 2, '2026-11-04', self::AUJOURDHUI);
        $this->assertSame(4, substr_count($html, "Plus d'information →"));
        $this->assertStringNotContainsString('Événement 5', $html);
    }

    public function test_agenda_garde_seulement_les_evenements_a_venir(): void {
        $html = Infolettre::bloc_agenda([
            self::evenement(['titre' => 'Passé', 'debut' => '2026-10-20']),
            self::evenement(['titre' => 'Aujourd’hui', 'debut' => '2026-10-26']),
            self::evenement(['titre' => 'En cours', 'debut' => '2026-10-24', 'fin' => '2026-10-28']),
            self::evenement(['titre' => 'Un appel', 'type' => 'appel', 'debut' => '2026-11-01']),
            self::evenement(['titre' => 'Sans date', 'debut' => null]),
        ], 8, null, self::AUJOURDHUI);
        $this->assertStringNotContainsString('Passé', $html);
        $this->assertStringNotContainsString('Un appel', $html);
        $this->assertStringNotContainsString('Sans date', $html);
        $this->assertStringContainsString('Aujourd’hui', $html);
        $this->assertStringContainsString('En cours', $html);
    }

    public function test_agenda_vide(): void {
        $html = Infolettre::bloc_agenda([], 8, null, self::AUJOURDHUI);
        $this->assertStringContainsString('Aucun élément', $html);
        $this->assertStringStartsWith('<!-- Agenda', $html);
        $this->assertStringNotContainsString("Plus d'information", $html);
    }

    public function test_evenements_retenus_compte(): void {
        $this->assertCount(2, Infolettre::evenements_retenus([self::evenement(), self::evenement(['debut' => '2026-11-13']), self::evenement(['debut' => '2026-10-01'])], 8, null, self::AUJOURDHUI));
    }

    // --- Appels ---

    public function test_appels_programme_rsn_et_appel_externe(): void {
        $programme = self::appel(['titre' => 'Programme de démarrage', 'organisateur' => 'RSN', 'categorie' => 'Programme du RSN', 'echeance' => null, 'debut' => '2026-10-10', 'url' => 'https://rsn.quebec/programme']);
        $html = Infolettre::bloc_appels([self::appel(['url' => 'https://frq.test/bourses'])], [$programme], self::AUJOURDHUI);
        $this->assertSame(2, substr_count($html, "Consulter l'appel →"));
        $this->assertSame(2, substr_count($html, '<tr>'));
        $this->assertSame(1, substr_count($html, 'Date limite'));
        $this->assertSame(1, substr_count($html, 'Publié'));
        $this->assertStringContainsString('Programme de démarrage', $html);
        $this->assertStringContainsString('Concours de bourses', $html);
        $this->assertStringContainsString('<span style="color:#6E7A84">FRQ · Bourse</span>', $html);
        $this->assertStringContainsString('<span style="color:#6E7A84">RSN · Programme du RSN</span>', $html);
        $this->assertStringContainsString('<div style="font-size:24px;line-height:1">20</div><div style="font-size:11px;letter-spacing:.08em;text-transform:uppercase">nov.</div>', $html);
        $this->assertStringContainsString('<a href="https://frq.test/bourses" style="color:#003469">Consulter l\'appel →</a>', $html);
        $this->assertStringStartsWith('<!-- Appels', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    public function test_appels_tri_par_date_limite_puis_sans_echeance(): void {
        $html = Infolettre::bloc_appels([
            self::appel(['titre' => 'Sans limite', 'echeance' => null, 'debut' => '2026-10-05']),
            self::appel(['titre' => 'Tard', 'echeance' => '2026-12-01']),
            self::appel(['titre' => 'Tôt', 'echeance' => '2026-11-01']),
        ], [], self::AUJOURDHUI);
        $this->assertLessThan(strpos($html, 'Tard'), strpos($html, 'Tôt'));
        $this->assertLessThan(strpos($html, 'Sans limite'), strpos($html, 'Tard'));
    }

    public function test_appels_ecartes_apres_la_date_limite_et_hors_type(): void {
        $html = Infolettre::bloc_appels([
            self::appel(['titre' => 'Expiré', 'echeance' => '2026-10-25']),
            self::appel(['titre' => 'Dernier jour', 'echeance' => '2026-10-26']),
            self::evenement(['titre' => 'Pas un appel']),
        ], [], self::AUJOURDHUI);
        $this->assertStringNotContainsString('Expiré', $html);
        $this->assertStringNotContainsString('Pas un appel', $html);
        $this->assertStringContainsString('Dernier jour', $html);
    }

    public function test_appels_externes_sans_limite_retenus_moins_de_quatre_vingt_dix_jours(): void {
        $html = Infolettre::bloc_appels([
            self::appel(['titre' => 'Externe récent', 'echeance' => null, 'debut' => '2026-10-05', 'url' => 'https://a.test/1']),
            self::appel(['titre' => 'Externe de juin', 'echeance' => null, 'debut' => '2026-06-22', 'fin' => '2026-06-22', 'url' => 'https://a.test/2']),
            self::appel(['titre' => 'Externe fin passée', 'echeance' => null, 'debut' => '2026-10-01', 'fin' => '2026-10-20', 'url' => 'https://a.test/3']),
        ], [
            // Programme du RSN : son état « ouvert » vient du site (rsn_appel_etat), aucun âge maximal.
            self::appel(['titre' => 'Programme ancien ouvert', 'echeance' => null, 'debut' => '2026-01-10', 'url' => 'https://rsn.test/p']),
        ], self::AUJOURDHUI);
        $this->assertStringContainsString('Externe récent', $html);
        $this->assertStringContainsString('Programme ancien ouvert', $html);
        $this->assertStringNotContainsString('Externe de juin', $html);
        $this->assertStringNotContainsString('Externe fin passée', $html);
    }

    public function test_appels_une_meme_adresse_une_seule_fois_le_programme_gagne(): void {
        $programme = self::appel(['titre' => 'Version du site', 'url' => 'https://rsn.quebec/programme/', 'organisateur' => 'RSN']);
        $collecte = self::appel(['titre' => 'Version collectée', 'url' => 'https://rsn.quebec/programme']);
        $html = Infolettre::bloc_appels([$collecte, self::appel(['titre' => 'Sans adresse', 'url' => '']), self::appel(['titre' => 'Sans adresse bis', 'url' => ''])], [$programme], self::AUJOURDHUI);
        $this->assertStringContainsString('Version du site', $html);
        $this->assertStringNotContainsString('Version collectée', $html);
        $this->assertStringContainsString('Sans adresse</b>', $html);
        $this->assertStringContainsString('Sans adresse bis', $html);
        $this->assertSame(1, substr_count($html, "Consulter l'appel →"), 'seul le programme a une adresse');
    }

    public function test_appels_echappe_et_categorie_par_defaut(): void {
        $html = Infolettre::bloc_appels([self::appel(['titre' => '<b>Gras</b>', 'categorie' => '', 'resume' => '<script>x</script>'])], [], self::AUJOURDHUI);
        $this->assertStringContainsString('&lt;b&gt;Gras&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('FRQ · Appel</span>', $html);
    }

    public function test_appels_vide(): void {
        $this->assertStringContainsString('Aucun élément', Infolettre::bloc_appels([], [], self::AUJOURDHUI));
    }

    public function test_appel_programme_depuis_un_article(): void {
        $appel = Infolettre::appel_programme('Programme <Émergence>', 'https://rsn.quebec/programmes/emergence/', "<p>Un programme\npour la relève.</p>", 'Date limite : 15 novembre 2026', '2026-10-10 08:00:00', self::AUJOURDHUI);
        $this->assertSame('appel', $appel['type']);
        $this->assertSame('Programme <Émergence>', $appel['titre']);
        $this->assertSame('https://rsn.quebec/programmes/emergence/', $appel['url']);
        $this->assertSame('Un programme pour la relève.', $appel['resume']);
        $this->assertSame('2026-11-15', $appel['echeance']);
        $this->assertSame('2026-10-10', $appel['debut']);
        $this->assertSame('RSN', $appel['organisateur']);
        $this->assertSame('Programme du RSN', $appel['categorie']);
        $sans = Infolettre::appel_programme('P', 'https://x.test', '', 'Du 1er au 30 novembre', '2026-10-10 08:00:00', self::AUJOURDHUI);
        $this->assertNull($sans['echeance'], 'une période sans date limite lisible ne donne pas d’échéance');
    }

    // --- Nouvelles ---

    public function test_nouvelles_gardees(): void {
        $html = Infolettre::bloc_nouvelles([
            self::gardee(),
            self::gardee(['url' => 'https://frq.test/n2', 'titre' => 'Plus récente <i>x</i>', 'date' => '2026-10-24', 'source' => 'FRQ']),
        ], self::AUJOURDHUI);
        $this->assertStringStartsWith('<!-- Nouvelles', $html);
        $this->assertSame(2, substr_count($html, 'Lire la suite →'));
        $this->assertLessThan(strpos($html, 'Une nouvelle'), strpos($html, 'Plus récente'));
        $this->assertStringContainsString('Plus récente &lt;i&gt;x&lt;/i&gt;', $html);
        $this->assertStringContainsString('<span style="font-size:12px;color:#6E7A84">24 oct. 2026 · FRQ</span>', $html);
        $this->assertStringContainsString('<b style="font-size:16px">Une nouvelle</b>', $html);
        $this->assertStringContainsString('<a href="https://inspq.test/n1" style="color:#003469">Lire la suite →</a>', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    public function test_nouvelles_sans_date_ni_source_et_adresse_refusee(): void {
        $html = Infolettre::bloc_nouvelles([self::gardee(['date' => '', 'source' => '']), self::gardee(['url' => 'javascript:alert(1)', 'titre' => 'Piégée', 'date' => '', 'source' => ''])], self::AUJOURDHUI);
        $this->assertStringNotContainsString('<span style="font-size:12px', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertSame(1, substr_count($html, 'Lire la suite →'));
    }

    public function test_nouvelles_vide(): void {
        $this->assertStringContainsString('Aucun élément', Infolettre::bloc_nouvelles([], self::AUJOURDHUI));
    }

    // --- Texte brut ---

    public function test_texte_brut(): void {
        $html = Infolettre::bloc_agenda([self::evenement(['titre' => 'A & B'])], 8, null, self::AUJOURDHUI);
        $texte = Infolettre::texte_brut($html);
        $this->assertStringNotContainsString('<', $texte);
        $this->assertStringNotContainsString('&amp;', $texte);
        $this->assertStringContainsString('A & B', $texte);
        $this->assertStringContainsString("Plus d'information → https://mila.quebec/fr/evenements/midi", $texte);
        $this->assertStringStartsWith("12 nov.\nA & B\nMila · Mila – Montréal\nIA · Santé\nUne présentation sur l’IA en santé.\n", $texte);
        $this->assertStringContainsString("Présentiel – Gratuit", $texte);
        $this->assertStringNotContainsString("\n\n\n", $texte);
        $this->assertSame($texte, trim($texte));
        $this->assertSame('Aucun élément à inclure pour le moment.', Infolettre::texte_brut(Infolettre::bloc_nouvelles([], self::AUJOURDHUI)));
    }
}

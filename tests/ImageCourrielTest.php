<?php
declare(strict_types=1);

require_once __DIR__ . '/bouchons-wordpress.php';

use PHPUnit\Framework\TestCase;
use RSN\Veille\Admin\ImageCourriel;

final class ImageCourrielTest extends TestCase {
    private const BASE = 'https://rsn.quebec/wp-content/uploads';

    private string $dossier = '';

    protected function setUp(): void {
        rsn_veille_bouchons_reinitialiser();
        $this->dossier = sys_get_temp_dir() . '/rsn-veille-images-' . bin2hex(random_bytes(6));
        mkdir($this->dossier . '/2026/04', 0777, true);
    }

    protected function tearDown(): void {
        $fichiers = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->dossier, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($fichiers as $fichier) {
            $fichier->isDir() ? rmdir($fichier->getPathname()) : unlink($fichier->getPathname());
        }
        rmdir($this->dossier);
    }

    /** En-tête d'un fichier WebP au format étendu (`VP8X`), de la largeur donnée, transparent ou non. */
    private static function webp(int $largeur, bool $transparent = false): string {
        $taille = static fn(int $n): string => substr(pack('V', $n - 1), 0, 3);
        return 'RIFF' . pack('V', 22) . 'WEBP' . 'VP8X' . pack('V', 10) . chr($transparent ? 0x10 : 0) . "\0\0\0" . $taille($largeur) . $taille(300);
    }

    /** En-tête d'un fichier PNG de la largeur donnée. */
    private static function png(int $largeur): string {
        return "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('N', $largeur) . pack('N', 300) . "\x08\x06\0\0\0" . pack('N', 0);
    }

    /** Pose une image dans le dossier des téléversements simulé et rend son adresse. */
    private function image(string $relatif, string $contenu): string {
        $GLOBALS['rsn_veille_wp']['televersements'] = ['basedir' => $this->dossier, 'baseurl' => self::BASE];
        file_put_contents($this->dossier . '/' . $relatif, $contenu);
        return self::BASE . '/' . $relatif;
    }

    /** @return string[] Noms des copies faites. */
    private function copies(): array {
        return array_map('basename', (array) glob($this->dossier . '/' . ImageCourriel::DOSSIER . '/*'));
    }

    public function test_chemin_relatif_d_une_image_du_site(): void {
        $this->assertSame('2026/07/cocktail-1024x683.jpg', ImageCourriel::chemin_relatif(self::BASE . '/2026/07/cocktail-1024x683.jpg', self::BASE));
        // Le protocole ne compte pas, ni la casse du domaine, ni une barre finale à la base, ni les paramètres.
        $this->assertSame('2026/04/photo.webp', ImageCourriel::chemin_relatif('http://RSN.quebec/wp-content/uploads/2026/04/photo.webp?ver=3#x', self::BASE . '/'));
        $this->assertSame('2026/04/photo été.webp', ImageCourriel::chemin_relatif(self::BASE . '/2026/04/photo%20%C3%A9t%C3%A9.webp', self::BASE));
    }

    public function test_chemin_relatif_refuse_ce_qui_n_est_pas_dans_les_televersements(): void {
        $this->assertNull(ImageCourriel::chemin_relatif('https://mila.quebec/sites/default/files/image.jpg', self::BASE));
        $this->assertNull(ImageCourriel::chemin_relatif('https://rsn.quebec/wp-content/themes/vault/logo.png', self::BASE));
        $this->assertNull(ImageCourriel::chemin_relatif('https://rsn.quebec.pirate.test/wp-content/uploads/a.jpg', self::BASE));
        $this->assertNull(ImageCourriel::chemin_relatif(self::BASE . 'x/a.jpg', self::BASE), 'un dossier qui commence pareil n’est pas le dossier');
        // Rien qui sorte du dossier.
        $this->assertNull(ImageCourriel::chemin_relatif(self::BASE . '/../../wp-config.php', self::BASE));
        $this->assertNull(ImageCourriel::chemin_relatif(self::BASE . '/2026/%2e%2e/%2e%2e/secret.jpg', self::BASE));
        $this->assertNull(ImageCourriel::chemin_relatif(self::BASE . '/2026//a.jpg', self::BASE));
        $this->assertNull(ImageCourriel::chemin_relatif(self::BASE . '/a%00.jpg', self::BASE));
        $this->assertNull(ImageCourriel::chemin_relatif(self::BASE . '/2026\\..\\a.jpg', self::BASE));
        $this->assertNull(ImageCourriel::chemin_relatif(self::BASE . '/', self::BASE));
        $this->assertNull(ImageCourriel::chemin_relatif(self::BASE . '/a.jpg', ''));
        $this->assertNull(ImageCourriel::chemin_relatif('', self::BASE));
        // Une copie déjà faite n'est pas recopiée.
        $this->assertNull(ImageCourriel::chemin_relatif(self::BASE . '/' . ImageCourriel::DOSSIER . '/cocktail-3f2a9c1e.jpg', self::BASE));
    }

    public function test_extension_de_la_copie(): void {
        foreach (['a.jpg', 'a.JPEG', 'a.webp'] as $photo) {
            $this->assertSame('jpg', ImageCourriel::extension_copie($photo), $photo);
        }
        $this->assertSame('png', ImageCourriel::extension_copie('logo.PNG'), 'un PNG reste un PNG : il garde sa transparence');
        // Un fond transparent deviendrait noir en JPEG : un WebP transparent, ou un AVIF, est copié en PNG.
        $this->assertSame('jpg', ImageCourriel::extension_copie('photo.webp', self::webp(1024)));
        $this->assertSame('png', ImageCourriel::extension_copie('affiche.webp', self::webp(1024, true)));
        $this->assertSame('png', ImageCourriel::extension_copie('2026/04/b.avif'));
        foreach (['anime.gif', 'logo.svg', 'document.pdf', 'sans-extension'] as $autre) {
            $this->assertNull(ImageCourriel::extension_copie($autre), $autre);
        }
        $this->assertTrue(ImageCourriel::lisible_partout('a.jpg'));
        $this->assertTrue(ImageCourriel::lisible_partout('a.PNG'));
        $this->assertFalse(ImageCourriel::lisible_partout('a.webp'), 'Outlook sur ordinateur n’affiche pas le WebP');
    }

    public function test_transparence_lue_dans_l_en_tete_d_un_webp(): void {
        $this->assertTrue(ImageCourriel::webp_transparent(self::webp(500, true)));
        $this->assertFalse(ImageCourriel::webp_transparent(self::webp(500)));
        // Format sans perte : le drapeau est le cinquième bit du dernier octet de l'en-tête.
        $sans_perte = static fn(int $dernier): string => 'RIFF' . pack('V', 30) . 'WEBP' . 'VP8L' . pack('V', 10) . "\x2f\0\0\0" . chr($dernier);
        $this->assertTrue(ImageCourriel::webp_transparent($sans_perte(0x10)));
        $this->assertFalse(ImageCourriel::webp_transparent($sans_perte(0x0f)));
        // Format simple avec perte : jamais de transparence. Tout ce qui n'est pas un WebP : non plus.
        $this->assertFalse(ImageCourriel::webp_transparent('RIFF' . pack('V', 30) . 'WEBP' . 'VP8 ' . str_repeat("\xff", 20)));
        $this->assertFalse(ImageCourriel::webp_transparent(self::png(500)));
        $this->assertFalse(ImageCourriel::webp_transparent('RIFF'));
        $this->assertFalse(ImageCourriel::webp_transparent(''));
    }

    public function test_nom_de_la_copie(): void {
        $nom = ImageCourriel::nom_copie('2026/04/2026_RSN ULAVAL-140-1024x683.webp', 1700000000, 126616, 'jpg');
        $this->assertMatchesRegularExpression('/^2026-rsn-ulaval-140-1024x683-[0-9a-f]{8}\.jpg$/', $nom);
        $this->assertSame($nom, ImageCourriel::nom_copie('2026/04/2026_RSN ULAVAL-140-1024x683.webp', 1700000000, 126616, 'jpg'));
        // Une image remplacée (date ou taille différente) ou du même nom dans un autre dossier : une autre copie.
        $this->assertNotSame($nom, ImageCourriel::nom_copie('2026/04/2026_RSN ULAVAL-140-1024x683.webp', 1700000001, 126616, 'jpg'));
        $this->assertNotSame($nom, ImageCourriel::nom_copie('2026/04/2026_RSN ULAVAL-140-1024x683.webp', 1700000000, 126617, 'jpg'));
        $this->assertNotSame($nom, ImageCourriel::nom_copie('2026/05/2026_RSN ULAVAL-140-1024x683.webp', 1700000000, 126616, 'jpg'));
        $this->assertMatchesRegularExpression('/^image-[0-9a-f]{8}\.png$/', ImageCourriel::nom_copie('2026/04/éàü.png', 1, 2, 'png'), 'un nom sans lettre ni chiffre simples');
        $this->assertLessThanOrEqual(60 + 1 + 8 + 4, strlen(ImageCourriel::nom_copie(str_repeat('a', 200) . '.jpg', 1, 2, 'jpg')));
    }

    // --- Copie dans le dossier des téléversements (éditeur d'images simulé) ---

    public function test_photo_webp_copiee_en_jpeg_une_seule_fois(): void {
        $url = $this->image('2026/04/photo-1024x683.webp', self::webp(1024));
        $copie = ImageCourriel::adresse($url . '?ver=2');
        $this->assertMatchesRegularExpression('#^https://rsn\.quebec/wp-content/uploads/rsn-veille-infolettre/photo-1024x683-[0-9a-f]{8}\.jpg$#', $copie);
        $this->assertSame([basename($copie)], $this->copies());
        $editeur = $GLOBALS['rsn_veille_wp']['editeurs'][0];
        $this->assertSame([
            ['resize', 400, null, false],
            ['set_quality', 82],
            ['save', $this->dossier . '/rsn-veille-infolettre/' . basename($copie), 'image/jpeg'],
        ], $editeur->appels);
        // Deuxième affichage de la page : la copie existe, l'image n'est pas rouverte.
        $this->assertSame($copie, ImageCourriel::adresse($url));
        $this->assertCount(1, $GLOBALS['rsn_veille_wp']['editeurs']);
        // L'image est remplacée : une nouvelle copie, l'ancienne reste (les infolettres envoyées l'affichent).
        file_put_contents($this->dossier . '/2026/04/photo-1024x683.webp', self::webp(1024) . 'autre contenu');
        $nouvelle = ImageCourriel::adresse($url);
        $this->assertNotSame($copie, $nouvelle);
        $this->assertCount(2, $this->copies());
    }

    public function test_image_transparente_copiee_en_png_et_petite_image_non_agrandie(): void {
        $copie = ImageCourriel::adresse($this->image('2026/04/affiche.webp', self::webp(1200, true)));
        $this->assertStringEndsWith('.png', $copie);
        $this->assertSame(['resize', 'save'], array_column($GLOBALS['rsn_veille_wp']['editeurs'][0]->appels, 0), 'pas de qualité JPEG pour un PNG');
        $this->assertSame('image/png', $GLOBALS['rsn_veille_wp']['editeurs'][0]->appels[1][2]);
        // Un WebP déjà étroit est converti sans être agrandi.
        $etroit = ImageCourriel::adresse($this->image('2026/04/vignette.webp', self::webp(300)));
        $this->assertStringEndsWith('.jpg', $etroit);
        $this->assertSame(['set_quality', 'save'], array_column($GLOBALS['rsn_veille_wp']['editeurs'][1]->appels, 0));
        // Un grand PNG est réduit et reste un PNG.
        $this->assertStringEndsWith('.png', ImageCourriel::adresse($this->image('2026/04/logo.png', self::png(1920))));
    }

    public function test_image_deja_lisible_et_assez_petite_laissee_telle_quelle(): void {
        $url = $this->image('2026/04/petit-logo.png', self::png(400));
        $this->assertSame($url, ImageCourriel::adresse($url));
        $this->assertSame([], $GLOBALS['rsn_veille_wp']['editeurs'], "la largeur est lue dans l'en-tête, sans ouvrir l'image");
        $this->assertSame([], $this->copies());
    }

    public function test_copie_impossible_l_image_d_origine_reste_et_l_essai_n_est_pas_repete(): void {
        $url = $this->image('2026/04/photo.webp', self::webp(1024));
        // Une extension du site impose le WebP en sortie : la copie ne servirait à rien, le fichier écrit est retiré.
        $GLOBALS['rsn_veille_wp']['editeur'] = ['type' => 'image/webp'];
        $this->assertSame($url, ImageCourriel::adresse($url));
        $this->assertSame([], $this->copies());
        $this->assertCount(1, $GLOBALS['rsn_veille_wp']['editeurs']);
        // Au prochain affichage, l'image n'est pas rouverte pour rien.
        $this->assertSame($url, ImageCourriel::adresse($url));
        $this->assertCount(1, $GLOBALS['rsn_veille_wp']['editeurs']);

        // Autres échecs : éditeur qui ne lit pas le format, redimensionnement ou écriture en erreur.
        foreach (['erreur', 'resize_echoue', 'save_echoue'] as $n => $echec) {
            $GLOBALS['rsn_veille_wp']['editeur'] = [$echec => true];
            $autre = $this->image("2026/04/echec-$n.webp", self::webp(1024));
            $this->assertSame($autre, ImageCourriel::adresse($autre), $echec);
        }
        $this->assertSame([], $this->copies());
    }

    public function test_adresse_rend_l_adresse_recue_quand_rien_n_est_a_copier(): void {
        // Aucun dossier de téléversement.
        $this->assertSame('https://rsn.quebec/wp-content/uploads/a.webp', ImageCourriel::adresse(' https://rsn.quebec/wp-content/uploads/a.webp '));
        $this->assertSame('', ImageCourriel::adresse(''));
        $this->image('2026/04/photo.webp', self::webp(1024));
        // Image d'un autre site, fichier absent, format non copié, fichier qui n'est pas une image.
        $this->assertSame('https://mila.quebec/image.webp', ImageCourriel::adresse('https://mila.quebec/image.webp'));
        $this->assertSame(self::BASE . '/2026/04/absente.webp', ImageCourriel::adresse(self::BASE . '/2026/04/absente.webp'));
        $gif = $this->image('2026/04/anime.gif', 'GIF89a');
        $this->assertSame($gif, ImageCourriel::adresse($gif));
        $faux = $this->image('2026/04/faux.webp', 'pas une image');
        $this->assertSame($faux, ImageCourriel::adresse($faux));
        $this->assertSame([], $GLOBALS['rsn_veille_wp']['editeurs']);
        $this->assertSame([], $this->copies());
    }
}

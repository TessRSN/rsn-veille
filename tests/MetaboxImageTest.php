<?php
declare(strict_types=1);

require_once __DIR__ . '/bouchons-wordpress.php';

use PHPUnit\Framework\TestCase;
use RSN\Veille\Admin\MetaboxImage;
use RSN\Veille\TypeContenu;

final class MetaboxImageTest extends TestCase {
    protected function setUp(): void {
        rsn_veille_bouchons_reinitialiser();
    }

    public function test_html_avec_image_de_la_source(): void {
        $html = MetaboxImage::html('https://exemple.test/affiche.jpg');
        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString('src="https://exemple.test/affiche.jpg"', $html);
        $this->assertStringNotContainsString('Aucune image de la source', $html);
        $this->assertStringContainsString(esc_html("Pour la remplacer, choisissez une image mise en avant : elle prime et la collecte ne l'écrase jamais."), $html);
    }

    public function test_html_sans_image(): void {
        $html = MetaboxImage::html('');
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('Aucune image de la source', $html);
        $this->assertStringContainsString('Pour la remplacer, choisissez une image mise en avant', $html);
    }

    public function test_html_refuse_une_adresse_javascript(): void {
        foreach (['javascript:alert(1)', 'data:text/html;base64,AAAA', 'mailto:a@exemple.test', '//exemple.test/a.jpg'] as $adresse) {
            $html = MetaboxImage::html($adresse);
            $this->assertStringNotContainsString('<img', $html, $adresse);
            $this->assertStringContainsString('Aucune image de la source', $html, $adresse);
        }
    }

    public function test_enregistre_la_metabox_sur_le_type(): void {
        MetaboxImage::accrocher();
        $this->assertArrayHasKey('add_meta_boxes', $GLOBALS['rsn_veille_wp']['actions']);
        MetaboxImage::enregistrer();
        $boite = $GLOBALS['rsn_veille_wp']['metaboxes']['rsn_veille_image'] ?? null;
        $this->assertNotNull($boite);
        $this->assertSame('Image de la fiche', $boite['titre']);
        $this->assertSame([MetaboxImage::class, 'rendre'], $boite['rappel']);
        $this->assertSame(TypeContenu::TYPE, $boite['ecran']);
        $this->assertSame('rsn_veille', $boite['ecran']);
        $this->assertSame('side', $boite['contexte']);
        $this->assertSame('default', $boite['priorite']);
    }

    public function test_rendre_affiche_l_image_de_la_meta(): void {
        $id = wp_insert_post(['post_type' => TypeContenu::TYPE, 'post_title' => 'Fiche', 'post_status' => 'publish']);
        update_post_meta($id, TypeContenu::PREFIXE . 'image_url', 'https://exemple.test/a.png');
        ob_start();
        MetaboxImage::rendre(get_post($id));
        $sortie = (string) ob_get_clean();
        $this->assertStringContainsString('src="https://exemple.test/a.png"', $sortie);
    }
}

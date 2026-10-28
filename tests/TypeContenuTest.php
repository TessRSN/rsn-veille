<?php
declare(strict_types=1);

require_once __DIR__ . '/bouchons-wordpress.php';

use PHPUnit\Framework\TestCase;
use RSN\Veille\TypeContenu;

final class TypeContenuTest extends TestCase {
    protected function setUp(): void {
        rsn_veille_bouchons_reinitialiser();
    }

    public function test_correspondance_des_statuts(): void {
        $this->assertSame('publish', TypeContenu::statut_wp('publie'));
        $this->assertSame('pending', TypeContenu::statut_wp('a_approuver'));
        $this->assertSame('draft', TypeContenu::statut_wp('ecarte'));
        $this->assertSame('publie', TypeContenu::statut_fiche('publish'));
        $this->assertSame('a_approuver', TypeContenu::statut_fiche('pending'));
        $this->assertSame('ecarte', TypeContenu::statut_fiche('draft'));
        $this->assertSame('publie', TypeContenu::statut_fiche('future'));
        $this->assertSame('ecarte', TypeContenu::statut_fiche('trash'));
        $this->expectException(\InvalidArgumentException::class);
        TypeContenu::statut_wp('publiee');
    }

    public function test_une_fiche_n_est_jamais_planifiee(): void {
        $this->assertSame('publish', TypeContenu::jamais_planifiee(['post_type' => 'rsn_veille', 'post_status' => 'future'])['post_status']);
        $this->assertSame('pending', TypeContenu::jamais_planifiee(['post_type' => 'rsn_veille', 'post_status' => 'pending'])['post_status']);
        $this->assertSame('future', TypeContenu::jamais_planifiee(['post_type' => 'post', 'post_status' => 'future'])['post_status']);
    }

    public function test_nettoyage_des_metas(): void {
        $this->assertSame('Montréal', TypeContenu::nettoyer_meta('ville', " <b>Montréal</b>\n"));
        $this->assertSame('https://exemple.ca/a%20b', TypeContenu::nettoyer_meta('url', 'https://exemple.ca/a b'));
        $this->assertSame(3, TypeContenu::nettoyer_meta('absences', '3'));
        $this->assertSame('1', TypeContenu::nettoyer_meta('manuel', true));
        $this->assertSame('0', TypeContenu::nettoyer_meta('manuel', false));
        $this->assertSame('a@exemple.ca', TypeContenu::nettoyer_meta('courriel_proposant', 'a@exemple.ca'));
    }

    public function test_url_en_est_nettoyee_comme_url(): void {
        $this->assertSame('https://exemple.ca/en/a%20b', TypeContenu::nettoyer_meta('url_en', 'https://exemple.ca/en/a b'));
        foreach (["  https://exemple.ca/en/a b ", 'https://exemple.ca/en/<script>x</script>?q="1"', 'javascript:alert(1)', ''] as $adresse) {
            $this->assertSame(TypeContenu::nettoyer_meta('url', $adresse), TypeContenu::nettoyer_meta('url_en', $adresse), $adresse);
        }
        $this->assertStringNotContainsString('<', (string) TypeContenu::nettoyer_meta('url_en', 'https://exemple.ca/<b>'));
    }

    public function test_cles_et_titres_source_gardes_tels_quels(): void {
        $this->assertSame('rss:https://x.ca/?p=1&caf%C3%A9', TypeContenu::nettoyer_meta('cle_source', 'rss:https://x.ca/?p=1&caf%C3%A9'));
        $this->assertSame(['a:1', 'b:%20'], TypeContenu::nettoyer_meta('cles_source', [2 => 'a:1', 5 => 'b:%20', 6 => '', 7 => 'a:1']));
        $this->assertSame(['a:1' => 'IA < 5 ans %20'], TypeContenu::nettoyer_meta('titres_source', ['a:1' => 'IA < 5 ans %20']));
        $this->assertSame([''], array_values(TypeContenu::nettoyer_meta('titres_source', ['a:1' => "\xC3\x28"])));
    }

    public function test_metas_enregistrees_avec_autorisation(): void {
        $metas = $GLOBALS['rsn_veille_wp']['metas_enregistrees'];
        $this->assertArrayHasKey('rsn_veille_cles_source', $metas);
        $this->assertArrayHasKey('rsn_veille_cle_dedoublonnage', $metas);
        $this->assertTrue($metas['rsn_veille_cle']['single'] === false);
        $this->assertFalse($metas['rsn_veille_url']['show_in_rest']);
        $this->assertFalse(TypeContenu::peut_modifier(true, 'rsn_veille_url', 1, 0));
        $this->assertTrue(TypeContenu::peut_modifier(false, 'rsn_veille_url', 1, 7));
        $type = $GLOBALS['rsn_veille_wp']['types']['rsn_veille'];
        $this->assertTrue($type['show_ui']);
        $this->assertFalse($type['show_in_menu']);
        $this->assertFalse($type['public']);
    }
}

<?php
declare(strict_types=1);

use RSN\Veille\Http;

final class HttpTest extends \PHPUnit\Framework\TestCase {
    /** Réponse fabriquée au format de `Http::obtenir`. */
    private static function reponse(string $type, string $corps, int $code = 200, ?string $erreur = null): array {
        return ['corps' => $corps, 'type' => $type, 'code' => $code, 'url_finale' => 'https://exemple.ca/', 'erreur' => $erreur];
    }

    public function test_flux_reconnu_par_son_type(): void {
        $this->assertTrue(Http::est_flux(self::reponse('application/rss+xml; charset=UTF-8', '<rss version="2.0"></rss>')));
        $this->assertTrue(Http::est_flux(self::reponse('application/atom+xml', '<feed xmlns="http://www.w3.org/2005/Atom"></feed>')));
        $this->assertTrue(Http::est_flux(self::reponse('text/xml', '<?xml version="1.0"?><rss></rss>')));
    }

    public function test_flux_reconnu_par_le_debut_du_corps_malgre_bom_et_espaces(): void {
        $this->assertTrue(Http::est_flux(self::reponse('', "\xEF\xBB\xBF \n\t<?xml version=\"1.0\" encoding=\"UTF-8\"?><rss></rss>")));
        $this->assertTrue(Http::est_flux(self::reponse('application/octet-stream', '<rss version="2.0"><channel></channel></rss>')));
        $this->assertTrue(Http::est_flux(self::reponse('text/plain', "  <feed xmlns=\"http://www.w3.org/2005/Atom\">")));
    }

    public function test_piege_html_en_200_a_la_place_d_un_flux(): void {
        $page = self::reponse('text/html; charset=UTF-8', "<!DOCTYPE html>\n<html lang=\"fr\"><head><title>Accueil</title></head></html>");
        $this->assertFalse(Http::est_flux($page));
        $this->assertFalse(Http::est_json($page));
        $this->assertFalse(Http::est_ical($page));
        $this->assertTrue(Http::est_html($page));
    }

    public function test_page_html_sans_type_ni_doctype_n_est_pas_un_flux(): void {
        $page = self::reponse('', '<html><body><p>Le flux a déménagé.</p></body></html>');
        $this->assertFalse(Http::est_flux($page));
        $this->assertTrue(Http::est_html($page));
    }

    public function test_page_xhtml_avec_declaration_xml_n_est_pas_un_flux(): void {
        $corps = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.0 Strict//EN\" \"x.dtd\">\n<html xmlns=\"http://www.w3.org/1999/xhtml\"></html>";
        $this->assertFalse(Http::est_flux(self::reponse('application/xhtml+xml', $corps)));
        $this->assertFalse(Http::est_flux(self::reponse('text/xml', $corps)));
        $this->assertTrue(Http::est_html(self::reponse('application/xhtml+xml', $corps)));
    }

    public function test_reponse_en_erreur_n_est_jamais_exploitable(): void {
        $refus = self::reponse('application/rss+xml', '<rss></rss>', 403, 'Réponse HTTP 403');
        $this->assertFalse(Http::est_flux($refus));
        $this->assertFalse(Http::est_json(self::reponse('application/json', '{"a":1}', 500, 'Réponse HTTP 500')));
        $this->assertFalse(Http::est_ical(self::reponse('text/calendar', 'BEGIN:VCALENDAR', 404, 'Réponse HTTP 404')));
        $this->assertFalse(Http::est_html(self::reponse('text/html', '<html></html>', 0, 'cURL error 28')));
    }

    public function test_json_reconnu_par_son_type_ou_par_son_corps(): void {
        $this->assertTrue(Http::est_json(self::reponse('application/json; charset=UTF-8', '{"events":[]}')));
        $this->assertTrue(Http::est_json(self::reponse('text/plain', "\xEF\xBB\xBF  [{\"id\": 1}]")));
        $this->assertFalse(Http::est_json(self::reponse('text/plain', '{pas du json')));
        $this->assertFalse(Http::est_json(self::reponse('application/rss+xml', '<rss></rss>')));
    }

    public function test_ical_reconnu_par_son_type_ou_par_son_corps(): void {
        $this->assertTrue(Http::est_ical(self::reponse('text/calendar; charset=utf-8', "BEGIN:VCALENDAR\r\nVERSION:2.0")));
        $this->assertTrue(Http::est_ical(self::reponse('application/octet-stream', "\r\nBEGIN:VCALENDAR\r\nVERSION:2.0")));
        $this->assertFalse(Http::est_ical(self::reponse('text/plain', 'BEGIN:VEVENT')));
        $this->assertFalse(Http::est_flux(self::reponse('text/calendar', "BEGIN:VCALENDAR\r\n")));
    }

    public function test_flux_et_json_ne_sont_pas_du_html(): void {
        $this->assertFalse(Http::est_html(self::reponse('application/rss+xml', '<?xml version="1.0"?><rss></rss>')));
        $this->assertFalse(Http::est_html(self::reponse('application/json', '{"html": "<html>"}')));
    }

    public function test_reponse_vide_ou_incomplete(): void {
        $this->assertFalse(Http::est_flux([]));
        $this->assertFalse(Http::est_json([]));
        $this->assertFalse(Http::est_html([]));
        $this->assertFalse(Http::est_ical([]));
        $this->assertFalse(Http::est_flux(self::reponse('', '')));
    }
}

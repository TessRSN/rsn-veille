<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RSN\Veille\Lecteurs\DecoupageMailchimp;

require_once __DIR__ . '/GabaritMailchimp.php';

/**
 * Découpage d'une infolettre Mailchimp (gabarit standard : blocs `mcn…Block`) en encadrés et en lignes d'agenda.
 * Le gabarit de test reprend celui de l'infolettre de l'Unité de soutien SSA Québec (2026).
 */
final class DecoupageMailchimpTest extends TestCase {
    public function test_cartes_titre_lien_et_resume(): void {
        $html = GabaritMailchimp::numero([
            GabaritMailchimp::section('Développer des savoirs'),
            GabaritMailchimp::carte('Rapport d’activités 2025-2026', 'Voici le rapport qui fait état de notre vision.', 'https://ssa.test/rapport/?utm_source=mailchimp&utm_medium=email'),
            GabaritMailchimp::carte('Boîte à outils SSA', 'Outils et formations.', 'https://ssa.test/boite-a-outils/'),
            GabaritMailchimp::agenda(['<a href="https://ssa.test/e1">Webinaire</a> &gt; 24 novembre en ligne']),
        ]);
        $cartes = DecoupageMailchimp::cartes($html);
        $this->assertCount(2, $cartes);
        $this->assertSame(['titre', 'url', 'resume'], array_keys($cartes[0]));
        $this->assertSame(['Rapport d’activités 2025-2026', 'https://ssa.test/rapport/'], [$cartes[0]['titre'], $cartes[0]['url']]);
        $this->assertSame('Voici le rapport qui fait état de notre vision.', $cartes[0]['resume']);
        $this->assertSame('Boîte à outils SSA', $cartes[1]['titre']);
    }

    public function test_carte_sans_gras_prend_son_debut_et_carte_sans_lien_est_laissee(): void {
        $sans_gras = '<table class="mcnImageCardBlock"><tr><td class="mcnTextContent">Un projet pilote au Québec. Il a démarré en mai. <a href="https://ssa.test/pilote">Lire</a></td></tr></table>';
        $sans_lien = '<table class="mcnImageCardBlock"><tr><td class="mcnTextContent"><strong>Sans lien</strong> Texte.</td></tr></table>';
        $cartes = DecoupageMailchimp::cartes(GabaritMailchimp::numero([$sans_gras, $sans_lien]));
        $this->assertCount(1, $cartes);
        $this->assertSame('https://ssa.test/pilote', $cartes[0]['url']);
        $this->assertStringStartsWith('Un projet pilote au Québec', $cartes[0]['titre']);
    }

    public function test_autre_gabarit_aucune_carte(): void {
        $this->assertSame([], DecoupageMailchimp::cartes('<table class="mcnTextBlock"><tr><td>Un envoi spécial <a href="https://ssa.test/a">ici</a>.</td></tr></table>'));
        $this->assertSame([], DecoupageMailchimp::cartes(''));
        $this->assertSame([], DecoupageMailchimp::agenda('<p>Rien</p>', '2026-11-20'));
    }

    public function test_nettoyer_url(): void {
        $this->assertSame('https://x.test/a?id=3', DecoupageMailchimp::nettoyer_url('https://x.test/a?id=3&utm_source=mc&gclid=abc&gad_source=1&mc_eid=secret&fbclid=z'));
        // L'ancre est gardée (elle mène à la bonne section de la page).
        $this->assertSame('https://x.test/a#section', DecoupageMailchimp::nettoyer_url('https://x.test/a?utm_campaign=x#section'));
        // Identifiant d'abonné de Mailchimp : jamais gardé.
        $this->assertSame('https://mailchi.mp/x/nov', DecoupageMailchimp::nettoyer_url('https://mailchi.mp/x/nov?e=265de85461'));
        $this->assertSame('https://x.test/a?e=3', DecoupageMailchimp::nettoyer_url('https://x.test/a?e=3'));
        // Lien « Safe Links » d'Outlook : la vraie adresse est dans le paramètre url.
        $this->assertSame('https://www.eventzilla.net/e/journee-2026', DecoupageMailchimp::nettoyer_url('https://can01.safelinks.protection.outlook.com/?url=https%3A%2F%2Fwww.eventzilla.net%2Fe%2Fjournee-2026&data=05%7C02%7Cnom%40ex.ca&reserved=0'));
        $this->assertSame('', DecoupageMailchimp::nettoyer_url('javascript:alert(1)'));
        $this->assertSame('', DecoupageMailchimp::nettoyer_url('mailto:a@b.ca'));
    }

    public function test_agenda_lignes_dates_lieux(): void {
        $html = GabaritMailchimp::numero([GabaritMailchimp::agenda([
            '<a href="https://teams.test/e/1" target="_blank">Les laboratoires vivants en ruralité</a>&nbsp;&gt;<strong>&nbsp;</strong>24 novembre en ligne',
            '<a href="https://qi.test/fisq?gclid=abc">Forum de l\'industrie de la santé de Québec</a> &gt; 24 et 25 novembre à Québec',
            '<a href="https://ccna.test/conf">Conférence annuelle du </a><a href="https://ccna.test/conf">Consortium 2026</a> &gt; <strong>26 au 28 octobre à Vancouver</strong>',
            'Congrès IBNT: <a href="https://ibt.test/conf">Sciences comportementales</a> &gt; 1er mai à Montréal',
            '<a href="https://zoom.test/x">Qu’est-ce que l’excellence en recherche ?</a> &gt; en ligne avec traduction simultanée',
            '<a href="https://sym.test/x">Symposium</a> « Gouvernance de proximité » &gt; 15 janvier à Québec et en ligne',
        ])]);
        $lignes = DecoupageMailchimp::agenda($html, '2026-11-20');
        $this->assertSame(['titre', 'url', 'debut', 'fin', 'lieu'], array_keys($lignes[0]));
        $this->assertSame(['Les laboratoires vivants en ruralité', 'https://teams.test/e/1', '2026-11-24', '2026-11-24', 'en ligne'], array_values($lignes[0]));
        $this->assertSame(['Forum de l\'industrie de la santé de Québec', 'https://qi.test/fisq', '2026-11-24', '2026-11-25', 'à Québec'], array_values($lignes[1]));
        // Octobre, annoncé le 20 novembre : déjà passé de moins de trois mois, donc la même année.
        $this->assertSame(['Conférence annuelle du Consortium 2026', '2026-10-26', '2026-10-28', 'à Vancouver'], [$lignes[2]['titre'], $lignes[2]['debut'], $lignes[2]['fin'], $lignes[2]['lieu']]);
        // Mai et janvier, annoncés en novembre : l'année suivante.
        $this->assertSame(['Congrès IBNT: Sciences comportementales', 'https://ibt.test/conf', '2027-05-01'], [$lignes[3]['titre'], $lignes[3]['url'], $lignes[3]['debut']]);
        $this->assertSame(['Symposium « Gouvernance de proximité »', '2027-01-15', 'à Québec et en ligne'], [$lignes[4]['titre'], $lignes[4]['debut'], $lignes[4]['lieu']]);
        // La ligne sans date n'est pas un événement datable : elle est laissée.
        $this->assertCount(5, $lignes);
    }

    public function test_agenda_en_lignes_br_et_annee_ecrite(): void {
        $bloc = '<table class="mcnBoxedTextBlock"><tr><td class="mcnTextContent"><div><strong><span>À L\'AGENDA</span></strong><br>&nbsp;</div>'
            . '<div><a href="https://a.test/1">Symposium A</a>&nbsp;&gt;<strong>&nbsp;26 et 27 octobre à Québec</strong><br>'
            . '<a href="https://a.test/2">Forum B</a> &gt; <strong>16 novembre 2027 à Toronto</strong></div></td></tr></table>';
        $lignes = DecoupageMailchimp::agenda($bloc, '2026-09-28');
        $this->assertSame([['Symposium A', '2026-10-26', '2026-10-27'], ['Forum B', '2027-11-16', '2027-11-16']], array_map(static fn(array $l): array => [$l['titre'], $l['debut'], $l['fin']], $lignes));
    }

    public function test_numeros_du_flux(): void {
        $flux = GabaritMailchimp::flux([
            ['Infolettre - Novembre 2026', 'https://mailchi.mp/x/nov', 'Fri, 20 Nov 2026 15:00:00 +0000', GabaritMailchimp::numero([GabaritMailchimp::carte('A', 'a', 'https://ssa.test/a')])],
            ['Infolettre - Octobre 2026', 'https://mailchi.mp/x/oct', 'Wed, 28 Oct 2026 15:00:00 +0000', '<p>x</p>'],
        ]);
        $numeros = DecoupageMailchimp::numeros($flux);
        $this->assertCount(2, $numeros);
        $this->assertSame(['titre', 'url', 'date', 'html'], array_keys($numeros[0]));
        $this->assertSame(['Infolettre - Novembre 2026', 'https://mailchi.mp/x/nov', '2026-11-20'], [$numeros[0]['titre'], $numeros[0]['url'], $numeros[0]['date']]);
        $this->assertStringContainsString('mcnImageCardBlock', $numeros[0]['html']);
        $this->assertSame('2026-10-28', $numeros[1]['date']);
        // Flux tronqué en route : les numéros complets restent lisibles.
        $this->assertCount(1, DecoupageMailchimp::numeros(substr($flux, 0, strrpos($flux, '<pubDate>'))));
        $this->assertSame([], DecoupageMailchimp::numeros('pas du xml'));
        $this->assertSame([], DecoupageMailchimp::numeros(''));
    }
}

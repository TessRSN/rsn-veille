<?php
declare(strict_types=1);

require_once __DIR__ . '/bouchons-wordpress.php';
require_once __DIR__ . '/GabaritMailchimp.php';

use PHPUnit\Framework\TestCase;
use RSN\Veille\Collecte;
use RSN\Veille\Depot;
use RSN\Veille\EntrepotMemoire;
use RSN\Veille\Lecteurs\LecteurInfolettre;
use RSN\Veille\Pertinence;
use RSN\Veille\Sources;

/** Bloc « À l'agenda » d'une infolettre Mailchimp : chaque ligne datée devient un événement à approuver. */
final class LecteurInfolettreTest extends TestCase {
    private const SOURCE = [
        'id' => 'ssa_agenda', 'nom' => 'SSA : agenda de l’infolettre', 'url' => 'https://us16.campaign-archive.com/feed?u=a&id=b',
        'type' => 'infolettre_mailchimp', 'niveau' => 'B', 'type_source' => 'infolettre', 'organisateur' => 'Unité SSA',
        'langue' => 'fr', 'actif' => true, 'sans_absences' => true, 'sans_doublon_par_url' => true, 'hotes_organisateur' => ['ssa.test'],
    ];

    protected function setUp(): void {
        rsn_veille_bouchons_reinitialiser();
        $GLOBALS['rsn_veille_test_aujourdhui'] = '2026-11-21';
        Collecte::$obtenir_pour_tests = null;
    }

    protected function tearDown(): void {
        $GLOBALS['rsn_veille_test_aujourdhui'] = '2026-10-25';
        Collecte::$obtenir_pour_tests = null;
    }

    private static function flux(array $premiers = []): string {
        $carte = [['Article', 'https://ssa.test/article']];
        return GabaritMailchimp::flux(array_merge($premiers, [
            ['Infolettre - Novembre 2026', 'https://mailchi.mp/x/nov', 'Fri, 20 Nov 2026 15:00:00 +0000', GabaritMailchimp::numero_de($carte, [
                '<a href="https://teams.test/e/1?utm_source=x">Les laboratoires vivants en ruralité</a> &gt; 24 novembre en ligne',
                '<a href="https://qi.test/fisq">Forum de l\'industrie de la santé</a> &gt; 24 et 25 novembre à Québec',
                '<a href="https://sub.ssa.test/pas">Webinaire PAS</a> &gt; 27 novembre en ligne',
                '<a href="https://yukon.test/x">Rencontre SRAP du Yukon</a> &gt; 3 et 4 décembre à Whitehorse et en ligne en anglais',
                'Journée sans lien &gt; 12 janvier à Montréal',
            ])],
            ['Infolettre - Octobre 2026', 'https://mailchi.mp/x/oct', 'Wed, 28 Oct 2026 12:00:00 +0000', GabaritMailchimp::numero_de($carte, [
                // Déjà annoncé en octobre, avec un lien suivi : le même événement, une seule fiche.
                '<a href="https://qi.test/fisq?gclid=abc">Forum de l\'industrie de la santé</a> &gt; 24 et 25 novembre à Québec',
                '<a href="https://ices.test/forum">ICES forum</a> &gt; 16 novembre à Toronto',
            ])],
            ['Infolettre - Septembre 2026', 'https://mailchi.mp/x/sep', 'Mon, 28 Sep 2026 12:00:00 +0000', GabaritMailchimp::numero_de($carte, [
                '<a href="https://ccna.test/conf">Conférence annuelle</a> &gt; 26 au 28 octobre à Vancouver',
            ])],
            ['Infolettre - Août 2026', 'https://mailchi.mp/x/aou', 'Thu, 27 Aug 2026 12:00:00 +0000', GabaritMailchimp::numero_de($carte, [
                '<a href="https://ccna.test/conf">Conférence annuelle</a> &gt; 26 au 28 octobre à Vancouver',
            ])],
        ]));
    }

    public function test_chaque_ligne_a_venir_devient_un_evenement(): void {
        $r = (new LecteurInfolettre())->lire(self::flux(), self::SOURCE);
        $this->assertSame([], $r->erreurs);
        $this->assertSame([], $r->alertes);
        // Passés : ICES (16 novembre) et la conférence de Vancouver, comptés une fois chacun.
        $this->assertSame(2, $r->ignores);
        $this->assertSame(
            ['Les laboratoires vivants en ruralité', 'Forum de l\'industrie de la santé', 'Webinaire PAS', 'Rencontre SRAP du Yukon', 'Journée sans lien'],
            array_map(static fn($f): string => $f->titre, $r->fiches)
        );
        [$labos, $forum, $pas, $yukon, $journee] = $r->fiches;
        $this->assertSame(['evenement', 'https://teams.test/e/1', '2026-11-24', '2026-11-24', 'Virtuel', '', 'En ligne'], [$labos->type, $labos->url, $labos->debut, $labos->fin, $labos->format, $labos->ville, $labos->region]);
        $this->assertSame(['2026-11-24', '2026-11-25', 'Présentiel', 'Québec', 'Québec', 'https://qi.test/fisq'], [$forum->debut, $forum->fin, $forum->format, $forum->ville, $forum->region, $forum->url]);
        $this->assertSame(['Hybride', 'Whitehorse', 'Hors Québec', 'en', '2026-12-03', '2026-12-04'], [$yukon->format, $yukon->ville, $yukon->region, $yukon->langue, $yukon->debut, $yukon->fin]);
        // Sans lien sur la ligne : celui du numéro. Janvier, annoncé en novembre : l'an prochain.
        $this->assertSame(['https://mailchi.mp/x/nov', '2027-01-12', 'Montréal', 'fr'], [$journee->url, $journee->debut, $journee->ville, $journee->langue]);
        // L'organisateur n'est connu que si le lien mène chez l'éditeur de l'infolettre.
        $this->assertSame(['', 'Unité SSA', ''], [$labos->organisateur, $pas->organisateur, $forum->organisateur]);
        $this->assertSame(['SSA : agenda de l’infolettre', 'B', ''], [$labos->source, $labos->niveau, $labos->lieu]);
        $this->assertSame('Annoncé dans l’infolettre du 20 novembre 2026 (Unité SSA) : en ligne.', $labos->resume);
        $this->assertStringEndsWith('(Unité SSA) : à Whitehorse et en ligne en anglais.', $yukon->resume);
        $this->assertStringStartsWith('ssa_agenda:2026-11-24|', $forum->cle_source);
        $this->assertSame([$forum->cle_source], $forum->cles_source);
        $this->assertCount(5, array_unique(array_map(static fn($f): string => $f->cle_source, $r->fiches)));
    }

    public function test_mentions_nombre_de_numeros_qui_annoncent_l_evenement(): void {
        $r = (new LecteurInfolettre())->lire(self::flux(), self::SOURCE);
        $par_titre = [];
        foreach ($r->fiches as $fiche) {
            $par_titre[$fiche->titre] = $r->mentions[$fiche->cle_source] ?? null;
        }
        // Le forum est annoncé en novembre et en octobre ; les autres, dans le seul numéro de novembre.
        $this->assertSame(2, $par_titre['Forum de l\'industrie de la santé']);
        $this->assertSame(1, $par_titre['Webinaire PAS']);
        $this->assertCount(5, $r->mentions);

        // Deux envois du même numéro (mêmes encadrés, même agenda) comptent pour un.
        $numero = GabaritMailchimp::numero_de([['A', 'https://ssa.test/a'], ['B', 'https://ssa.test/b']], ['<a href="https://z.test/z">Colloque Z</a> &gt; 15 décembre à Québec']);
        $autre = GabaritMailchimp::numero_de([['C', 'https://ssa.test/c'], ['D', 'https://ssa.test/d']], ['<a href="https://z.test/z">Colloque Z</a> &gt; 15 décembre à Québec', '<a href="https://y.test/y">Atelier Y</a> &gt; 30 novembre à Laval']);
        $r = (new LecteurInfolettre())->lire(GabaritMailchimp::flux([
            ['Infolettre - Novembre 2026', 'https://mailchi.mp/x/nov-b', 'Fri, 20 Nov 2026 15:00:00 +0000', $numero],
            ['Infolettre - Novembre 2026', 'https://mailchi.mp/x/nov-a', 'Fri, 13 Nov 2026 15:00:00 +0000', $numero],
            ['Infolettre - Octobre 2026', 'https://mailchi.mp/x/oct', 'Wed, 28 Oct 2026 12:00:00 +0000', $autre],
        ]), self::SOURCE);
        $this->assertSame([2, 1], array_values($r->mentions));
    }

    public function test_la_collecte_garde_les_mentions_par_source(): void {
        self::servir(self::flux());
        $entrepot = new EntrepotMemoire();
        Collecte::collecter_source(self::SOURCE, new Depot($entrepot), new Pertinence());
        $mentions = Collecte::mentions();
        $this->assertCount(5, $mentions);
        $forum = array_values(array_filter($entrepot->lister(''), static fn($f): bool => str_starts_with($f->titre, 'Forum')))[0];
        $this->assertSame(2, $mentions[$forum->cle_source]);
        // Un flux illisible ne vide pas les comptes de la collecte précédente.
        self::servir('<rss><channel></channel></rss>');
        Collecte::collecter_source(self::SOURCE, new Depot($entrepot), new Pertinence());
        $this->assertCount(5, Collecte::mentions());
        // Une autre source active garde les siennes ; une source qui n'est plus au registre (ou désactivée) n'est plus montrée.
        update_option(Collecte::OPTION_MENTIONS, get_option(Collecte::OPTION_MENTIONS) + ['dhc' => ['dhc:x' => 4, 'dhc:y' => 'illisible'], 'ancienne' => ['ancienne:z' => 3]], false);
        // La collecte suivante de la source remplace ses comptes.
        self::servir(GabaritMailchimp::flux([['Infolettre - Décembre 2026', 'https://mailchi.mp/x/dec', 'Sat, 21 Nov 2026 12:00:00 +0000', GabaritMailchimp::numero_de([['A', 'https://ssa.test/a']], ['<a href="https://z.test/z">Autre chose</a> &gt; 15 décembre à Québec'])]]));
        Collecte::collecter_source(self::SOURCE, new Depot($entrepot), new Pertinence());
        $mentions = Collecte::mentions();
        $this->assertSame(4, $mentions['dhc:x']);
        $this->assertArrayNotHasKey('dhc:y', $mentions);
        $this->assertArrayNotHasKey('ancienne:z', $mentions);
        $this->assertCount(2, $mentions);
        // Option illisible : rien, sans erreur.
        update_option(Collecte::OPTION_MENTIONS, 'x', false);
        $this->assertSame([], Collecte::mentions());
    }

    public function test_flux_illisible(): void {
        $r = (new LecteurInfolettre())->lire('<rss><channel></channel></rss>', self::SOURCE);
        $this->assertSame([], $r->fiches);
        $this->assertSame(['Flux vide ou invalide'], $r->erreurs);
    }

    public function test_alerte_quand_le_dernier_numero_n_a_plus_d_agenda(): void {
        $sans_agenda = GabaritMailchimp::numero_de([['Article', 'https://ssa.test/dec']]);
        $r = (new LecteurInfolettre())->lire(self::flux([['Infolettre - Décembre 2026', 'https://mailchi.mp/x/dec', 'Sat, 21 Nov 2026 12:00:00 +0000', $sans_agenda]]), self::SOURCE);
        $this->assertCount(1, $r->alertes);
        $this->assertStringContainsString('« Infolettre - Décembre 2026 »', $r->alertes[0]);
        $this->assertStringContainsString('À l’agenda', $r->alertes[0]);
        // Les numéros précédents restent lus.
        $this->assertCount(5, $r->fiches);
        // Un envoi spécial (sans encadré) n'est pas un numéro : rien à signaler.
        $special = '<table class="mcnTextBlock"><tr><td class="mcnTextContent">Nouveaux outils</td></tr></table>';
        $r = (new LecteurInfolettre())->lire(self::flux([['Nouveaux outils', 'https://mailchi.mp/x/sp', 'Sat, 21 Nov 2026 12:00:00 +0000', $special]]), self::SOURCE);
        $this->assertSame([], $r->alertes);
    }

    public function test_collecte_a_approuver_sans_doublon_ni_absence(): void {
        $flux = self::flux();
        Collecte::$obtenir_pour_tests = static fn(string $url, array $options = []): array => ['corps' => $flux, 'type' => 'application/rss+xml', 'code' => 200, 'url_finale' => $url, 'erreur' => null];
        $entrepot = new EntrepotMemoire();
        $ligne = Collecte::collecter_source(self::SOURCE, new Depot($entrepot), new Pertinence());
        $this->assertSame([], $ligne['erreurs']);
        $this->assertSame([5, 5, 2], [$ligne['lus'], $ligne['crees'], $ligne['ignores']]);
        $this->assertArrayNotHasKey('alerte', $ligne);
        // Toujours à approuver, même si le texte parle de santé et de numérique : c'est une mention dans une infolettre.
        $this->assertCount(5, $entrepot->lister('a_approuver'));
        $this->assertCount(0, $entrepot->lister('publie'));

        $seconde = Collecte::collecter_source(self::SOURCE, new Depot($entrepot), new Pertinence());
        $this->assertSame([0, 5], [$seconde['crees'], $seconde['inchanges']]);

        // Un événement publié qui sort de l'agenda n'est pas « absent de sa source » : l'infolettre ne l'annonce qu'un temps.
        foreach ($entrepot->lister('a_approuver') as $fiche) {
            $entrepot->mettre_a_jour($fiche->id, ['statut' => 'publie']);
        }
        $reduit = GabaritMailchimp::flux([['Infolettre - Décembre 2026', 'https://mailchi.mp/x/dec', 'Sat, 21 Nov 2026 12:00:00 +0000', GabaritMailchimp::numero_de([['A', 'https://ssa.test/a']], ['<a href="https://z.test/z">Autre chose</a> &gt; 15 décembre à Québec'])]]);
        Collecte::$obtenir_pour_tests = static fn(string $url, array $options = []): array => ['corps' => $reduit, 'type' => 'application/rss+xml', 'code' => 200, 'url_finale' => $url, 'erreur' => null];
        Collecte::collecter_source(self::SOURCE, new Depot($entrepot), new Pertinence());
        Collecte::collecter_source(self::SOURCE, new Depot($entrepot), new Pertinence());
        $this->assertSame([], $entrepot->lister_absentes());
        foreach ($entrepot->lister('publie') as $fiche) {
            $this->assertSame(0, $fiche->absences);
        }
    }

    private static function servir(string $flux): void {
        Collecte::$obtenir_pour_tests = static fn(string $url, array $options = []): array => ['corps' => $flux, 'type' => 'application/rss+xml', 'code' => 200, 'url_finale' => $url, 'erreur' => null];
    }

    public function test_lignes_sans_lien_ou_au_lien_commun_restent_des_fiches_distinctes(): void {
        // Trois lignes sans lien (elles reçoivent l'adresse du numéro) et deux qui pointent vers la même page générale.
        self::servir(GabaritMailchimp::flux([['Infolettre - Décembre 2026', 'https://mailchi.mp/x/dec', 'Sat, 21 Nov 2026 12:00:00 +0000', GabaritMailchimp::numero_de([['A', 'https://ssa.test/a']], [
            'Journée A &gt; 12 décembre à Québec',
            'Journée B &gt; 15 décembre à Montréal',
            'Journée C &gt; 20 janvier à Laval',
            '<a href="https://ssa.test/formations/">Formation D</a> &gt; 2 décembre en ligne',
            '<a href="https://ssa.test/formations/">Formation E</a> &gt; 9 décembre en ligne',
        ])]]));
        $entrepot = new EntrepotMemoire();
        $ligne = Collecte::collecter_source(self::SOURCE, new Depot($entrepot), new Pertinence());
        $this->assertSame([5, 0, 0], [$ligne['crees'], $ligne['fusionnes'], $ligne['mis_a_jour']]);
        $attendu = ['Formation D' => '2026-12-02', 'Formation E' => '2026-12-09', 'Journée A' => '2026-12-12', 'Journée B' => '2026-12-15', 'Journée C' => '2027-01-20'];
        $lire = static function () use ($entrepot): array {
            $dates = [];
            foreach ($entrepot->lister('') as $fiche) {
                $dates[$fiche->titre] = $fiche->debut;
            }
            ksort($dates);
            return $dates;
        };
        $this->assertSame($attendu, $lire());
        // La collecte suivante ne déplace rien.
        $seconde = Collecte::collecter_source(self::SOURCE, new Depot($entrepot), new Pertinence());
        $this->assertSame([0, 0, 0, 5], [$seconde['crees'], $seconde['fusionnes'], $seconde['mis_a_jour'], $seconde['inchanges']]);
        $this->assertSame($attendu, $lire());
    }

    public function test_une_mention_ne_reecrit_jamais_une_fiche_d_une_autre_source(): void {
        $entrepot = new EntrepotMemoire();
        foreach (['B', 'M', 'C', 'A'] as $rang => $niveau) {
            $entrepot->creer(\RSN\Veille\Fiche::depuis_tableau([
                'type' => 'evenement', 'titre' => 'Forum ' . $niveau . ' de la santé', 'debut' => '2026-12-0' . ($rang + 1), 'fin' => '2026-12-0' . ($rang + 1),
                'url' => 'https://organisateur.test/forum-' . $niveau, 'ville' => 'Québec', 'organisateur' => 'Organisateur', 'source' => 'Source ' . $niveau, 'niveau' => $niveau,
                'statut' => 'publie', 'cle_source' => 'x:' . $niveau, 'cles_source' => ['x:' . $niveau],
            ]));
        }
        $lignes = [];
        foreach (['B', 'M', 'C', 'A'] as $rang => $niveau) {
            // Même jour et même titre (fusion), mais une fin, un lien et une ville différents.
            $lignes[] = '<a href="https://lnkd.in/p/' . $niveau . '">Forum ' . $niveau . ' de la santé</a> &gt; ' . ($rang + 1) . ' au ' . ($rang + 3) . ' décembre à Lévis';
        }
        self::servir(GabaritMailchimp::flux([['Infolettre - Décembre 2026', 'https://mailchi.mp/x/dec', 'Sat, 21 Nov 2026 12:00:00 +0000', GabaritMailchimp::numero_de([['A', 'https://ssa.test/a']], $lignes)]]));
        $ligne = Collecte::collecter_source(self::SOURCE, new Depot($entrepot), new Pertinence());
        $this->assertSame([0, 4], [$ligne['crees'], $ligne['fusionnes']]);
        $this->assertCount(4, $entrepot->lister(''));
        foreach ($entrepot->lister('') as $fiche) {
            $n = $fiche->niveau;
            $this->assertSame(['publie', $fiche->debut, 'https://organisateur.test/forum-' . $n, 'Québec', 'Source ' . $n, 'Organisateur'], [$fiche->statut, $fiche->fin, $fiche->url, $fiche->ville, $fiche->source, $fiche->organisateur], $n);
            $this->assertCount(2, $fiche->cles_source, $n);
        }
        // Même adresse qu'une fiche connue, mais autre événement : jamais fusionné par l'adresse.
        self::servir(GabaritMailchimp::flux([['Infolettre - Décembre 2026', 'https://mailchi.mp/x/dec', 'Sat, 21 Nov 2026 12:00:00 +0000', GabaritMailchimp::numero_de([['A', 'https://ssa.test/a']], ['<a href="https://organisateur.test/forum-A">Tout autre chose</a> &gt; 18 décembre à Lévis'])]]));
        $ligne = Collecte::collecter_source(self::SOURCE, new Depot($entrepot), new Pertinence());
        $this->assertSame([1, 0], [$ligne['crees'], $ligne['fusionnes']]);
        $this->assertCount(1, $entrepot->lister('a_approuver'));
    }

    public function test_alerte_quand_tout_le_gabarit_change(): void {
        // Le dernier numéro n'a plus aucun bloc reconnu, mais se présente comme les autres : l'agenda est signalé aussi.
        $r = (new LecteurInfolettre())->lire(self::flux([['Infolettre - Décembre 2026', 'https://mailchi.mp/x/dec', 'Sat, 21 Nov 2026 12:00:00 +0000', '<div class="neuf"><h2>À l\'agenda</h2><p>Forum &gt; 3 décembre</p></div>']]), self::SOURCE);
        $this->assertCount(1, $r->alertes);
        $this->assertStringContainsString('« Infolettre - Décembre 2026 »', $r->alertes[0]);
    }

    public function test_alerte_du_lecteur_consignee_dans_la_ligne(): void {
        $flux = self::flux([['Infolettre - Décembre 2026', 'https://mailchi.mp/x/dec', 'Sat, 21 Nov 2026 12:00:00 +0000', GabaritMailchimp::numero_de([['Article', 'https://ssa.test/dec']])]]);
        Collecte::$obtenir_pour_tests = static fn(string $url, array $options = []): array => ['corps' => $flux, 'type' => 'application/rss+xml', 'code' => 200, 'url_finale' => $url, 'erreur' => null];
        $ligne = Collecte::collecter_source(self::SOURCE, new Depot(new EntrepotMemoire()), new Pertinence());
        $this->assertStringContainsString('« Infolettre - Décembre 2026 »', $ligne['alerte']);
    }

    public function test_sources_du_registre(): void {
        $sources = Sources::par_defaut();
        $agenda = $sources['ssa_agenda'];
        // Niveau B, le moins fiable : une mention ne l'emporte jamais sur une autre source.
        $this->assertSame(['infolettre_mailchimp', 'B', 'infolettre', true, true], [$agenda['type'], $agenda['niveau'], $agenda['type_source'], $agenda['sans_absences'], $agenda['sans_doublon_par_url']]);
        $this->assertArrayNotHasKey('categorie_nouvelles', $agenda);
        $this->assertInstanceOf(LecteurInfolettre::class, Collecte::lecteur_pour($agenda));
        $radar = $sources['ssa_infolettre'];
        $this->assertSame(['mailchimp', true, 'sante', $agenda['url']], [$radar['decoupage'], $radar['categorie_nouvelles'], $radar['type_source'], $radar['url']]);
        $this->assertStringStartsWith('https://us16.campaign-archive.com/feed?u=', $agenda['url']);
        // Le site de l'Unité reste lu à part (ses propres nouvelles).
        $this->assertSame('https://ssaquebec.ca/feed/', $sources['ssa']['url']);
    }
}

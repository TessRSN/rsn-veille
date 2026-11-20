<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RSN\Veille\Fiche;
use RSN\Veille\Ressemblance;

/** Deux fiches annoncent-elles sans doute la même chose, sous deux titres ou depuis deux sources ? */
final class RessemblanceTest extends TestCase {
    private static function fiche(array $champs = []): Fiche {
        return Fiche::depuis_tableau($champs + [
            'id' => 1, 'type' => 'evenement', 'titre' => 'Forum de l\'industrie de la santé de Québec', 'debut' => '2026-11-24', 'fin' => '2026-11-25',
            'url' => 'https://qi.test/fisq', 'statut' => 'a_approuver', 'source' => 'SSA : agenda', 'niveau' => 'B',
        ]);
    }

    public function test_titres_proches(): void {
        $proches = [
            ['Forum de l\'industrie de la santé de Québec', '20e Forum de l\'industrie de la santé de Québec (FISQ) 2026'],
            ['Journée d\'étude : IA & santé', 'JOURNEE D ETUDE – IA et Sante'],
            ['Conférence canadienne sur la science ouverte', '2e Conférence canadienne sur la science ouverte – Ottawa'],
            ['Colloque annuel du RSN', 'Colloque annuel du RSN'],
            // Deux mots, mais les mêmes de part et d'autre.
            ['Colloque sur le sommeil', 'Colloque du sommeil'],
        ];
        foreach ($proches as [$a, $b]) {
            $this->assertTrue(Ressemblance::titres_proches($a, $b), "$a / $b");
            $this->assertTrue(Ressemblance::titres_proches($b, $a), "$b / $a");
        }
        $differents = [
            // Un seul mot en commun, ou un titre d'un seul mot : pas assez pour conclure.
            ['Webinaire', 'Webinaire sur l\'intelligence artificielle'],
            ['Colloque sur la santé numérique', 'Colloque sur le sommeil'],
            // Un titre de deux mots contenu dans un titre plus long : trop peu pour conclure.
            ['Webinaire IA', 'Webinaire : l\'IA générative en soins infirmiers'],
            ['Appel à communications', 'Appel de communications – Congrès ACFAS'],
            ['Forum santé numérique', 'Digital Health Forum'],
            ['', 'Forum'],
            ['2026', '20e édition 2026'],
        ];
        foreach ($differents as [$a, $b]) {
            $this->assertFalse(Ressemblance::titres_proches($a, $b), "$a / $b");
        }
    }

    public function test_meme_evenement_sous_deux_titres(): void {
        $publiee = self::fiche(['id' => 2, 'titre' => '20e Forum de l\'industrie de la santé de Québec (FISQ)', 'url' => 'https://www.quebecinternational.ca/fr/fisq', 'statut' => 'publie']);
        $this->assertTrue(Ressemblance::memes(self::fiche(), $publiee));
        // Dates qui se recoupent (un jour commun suffit) ; sans date de fin, le début seul.
        $this->assertTrue(Ressemblance::memes(self::fiche(['debut' => '2026-11-25', 'fin' => null]), $publiee));
        // Même titre un autre jour : une autre date d'une série, pas un doublon.
        $this->assertFalse(Ressemblance::memes(self::fiche(['debut' => '2026-12-01', 'fin' => '2026-12-01']), $publiee));
        // Jamais elle-même, jamais un autre type.
        $this->assertFalse(Ressemblance::memes(self::fiche(), self::fiche()));
        $this->assertFalse(Ressemblance::memes(self::fiche(['type' => 'appel']), $publiee));
    }

    public function test_meme_adresse(): void {
        $publiee = self::fiche(['id' => 2, 'titre' => 'FISQ 2026', 'url' => 'https://www.qi.test/fisq/?utm_source=x', 'statut' => 'publie']);
        // Même page (au suivi, au « www » et à la barre finale près) et dates qui se recoupent : le même événement, même sous un autre titre.
        $this->assertTrue(Ressemblance::memes(self::fiche(), $publiee));
        // Même page mais d'autres dates : une page générale, ou une série.
        $this->assertFalse(Ressemblance::memes(self::fiche(['debut' => '2027-01-10', 'fin' => '2027-01-10']), $publiee));
        $this->assertFalse(Ressemblance::memes(self::fiche(['url' => '']), self::fiche(['id' => 2, 'titre' => 'FISQ 2026', 'url' => ''])));
    }

    public function test_adresse_partagee_ne_prouve_rien(): void {
        $jour = ['debut' => '2026-06-08', 'fin' => '2026-06-10', 'statut' => 'publie'];
        // Événements importés d'un même numéro d'infolettre : ils en portent tous l'adresse.
        $a = self::fiche(['id' => 1, 'titre' => 'Colloque francophone en gestion', 'url' => 'https://mailchi.mp/2131bce05a84/b67j7bewg8-63129'] + $jour);
        $b = self::fiche(['id' => 2, 'titre' => 'Institut d\'été en santé mondiale', 'url' => 'https://mailchi.mp/2131bce05a84/b67j7bewg8-63129'] + $jour);
        $this->assertFalse(Ressemblance::memes($a, $b));
        // Page générale d'un site, partagée par trois fiches ou plus : écartée par `pour_la_file`.
        $page = 'https://organisme.test/formations/';
        $attente = [
            self::fiche(['id' => 10, 'titre' => 'Atelier sur les données ouvertes', 'url' => $page, 'statut' => 'a_approuver'] + $jour),
            self::fiche(['id' => 11, 'titre' => 'Séminaire de gouvernance clinique', 'url' => $page, 'statut' => 'a_approuver'] + $jour),
        ];
        $publiees = [self::fiche(['id' => 3, 'titre' => 'Formation en éthique appliquée', 'url' => $page] + $jour)];
        $this->assertSame([], Ressemblance::pour_la_file($attente, $publiees));
        // À deux seulement, la même page reste un bon indice.
        $this->assertSame([10 => 3], array_map(static fn(Fiche $f): int => (int) $f->id, Ressemblance::pour_la_file([$attente[0]], $publiees)));
    }

    public function test_organisateurs_differents_demandent_plus(): void {
        $jour = ['debut' => '2026-12-03', 'fin' => '2026-12-03', 'url' => ''];
        $a = self::fiche(['id' => 1, 'titre' => 'Assemblée générale annuelle', 'organisateur' => 'CRCHUM'] + $jour);
        // Même titre banal, le même jour, chez deux organisateurs sans rapport : deux assemblées.
        $this->assertFalse(Ressemblance::memes($a, self::fiche(['id' => 2, 'titre' => 'Assemblée générale annuelle 2026', 'organisateur' => 'IVADO'] + $jour)));
        // Organisateur inconnu d'un côté (agenda d'une infolettre), ou écrit autrement : le titre suffit.
        $this->assertTrue(Ressemblance::memes($a, self::fiche(['id' => 2, 'titre' => 'Assemblée générale annuelle 2026', 'organisateur' => ''] + $jour)));
        $this->assertTrue(Ressemblance::memes($a, self::fiche(['id' => 2, 'titre' => 'Assemblée générale annuelle 2026', 'organisateur' => 'Centre de recherche du CHUM (CRCHUM)'] + $jour)));
        // Organisateurs différents mais un long titre commun : un événement conjoint.
        $long = 'Colloque québécois sur les données de santé et l\'intelligence artificielle';
        $this->assertTrue(Ressemblance::memes(self::fiche(['id' => 1, 'titre' => $long, 'organisateur' => 'Obvia'] + $jour), self::fiche(['id' => 2, 'titre' => '3e ' . $long, 'organisateur' => 'IVADO'] + $jour)));
    }

    public function test_page_d_accueil_et_fiches_sans_identifiant(): void {
        $jour = ['debut' => '2026-12-03', 'fin' => '2026-12-03'];
        // La page d'accueil d'un organisme ne désigne aucun événement.
        $this->assertFalse(Ressemblance::memes(self::fiche(['id' => 1, 'titre' => 'Atelier sur les données', 'url' => 'https://org.test/'] + $jour), self::fiche(['id' => 2, 'titre' => 'Séminaire de gouvernance', 'url' => 'https://www.org.test'] + $jour)));
        // Une fiche sans identifiant n'a pas de ligne dans la file : jamais signalée.
        $sans_id = self::fiche(['id' => null] + $jour);
        $this->assertSame([], Ressemblance::pour_la_file([$sans_id], [self::fiche(['id' => 2, 'statut' => 'publie'] + $jour)]));
    }

    public function test_une_fiche_publiee_passe_avant_une_fiche_en_attente(): void {
        $jour = ['debut' => '2026-12-03', 'fin' => '2026-12-03', 'url' => ''];
        $attente = [
            self::fiche(['id' => 11, 'titre' => 'Colloque sur le sommeil et la santé'] + $jour),
            self::fiche(['id' => 10, 'titre' => 'Colloque du sommeil et de la santé'] + $jour),
        ];
        $publiee = self::fiche(['id' => 2, 'titre' => '4e Colloque sur le sommeil et la santé', 'statut' => 'publie'] + $jour);
        $this->assertSame([11 => 2, 10 => 2], array_map(static fn(Fiche $f): int => (int) $f->id, Ressemblance::pour_la_file($attente, [$publiee])));
    }

    public function test_appels_echus_jamais_compares(): void {
        $appel = static fn(array $c): Fiche => self::fiche($c + ['type' => 'appel', 'titre' => 'Bourses de formation postdoctorale', 'debut' => '2026-11-01', 'fin' => '2026-11-01', 'echeance' => null, 'url' => 'https://frq.test/bourses']);
        $nouveau = $appel(['id' => 10, 'titre' => 'Bourses de formation postdoctorale 2027-2028']);
        // L'édition de l'an passé, encore publiée mais échue : ce n'est pas « déjà dans l'agenda ».
        $echu = $appel(['id' => 2, 'statut' => 'publie', 'debut' => '2025-08-01', 'fin' => '2025-10-01', 'echeance' => '2025-10-01']);
        $this->assertSame([], Ressemblance::pour_la_file([$nouveau], [$echu], '2026-11-20'));
        // Le même concours en cours : signalé.
        $en_cours = $appel(['id' => 3, 'statut' => 'publie', 'echeance' => '2027-01-15', 'fin' => '2027-01-15']);
        $this->assertSame([10 => 3], array_map(static fn(Fiche $f): int => (int) $f->id, Ressemblance::pour_la_file([$nouveau], [$echu, $en_cours], '2026-11-20')));
        // Même page de programme, deux dates limites différentes : deux concours.
        $this->assertFalse(Ressemblance::memes($appel(['echeance' => '2026-12-01']), $appel(['id' => 2, 'titre' => 'Autre volet', 'echeance' => '2027-04-01'])));
    }

    public function test_grosse_file_reste_rapide(): void {
        $attente = [];
        $publiees = [];
        for ($i = 0; $i < 300; $i++) {
            $attente[] = self::fiche(['id' => 10000 + $i, 'titre' => 'Atelier a' . $i . 'a b' . $i . 'b c' . $i . 'c', 'debut' => '2026-12-' . sprintf('%02d', 1 + $i % 28), 'fin' => null, 'url' => 'https://a.test/e/' . $i . '?utm_source=x']);
        }
        for ($i = 0; $i < 2000; $i++) {
            $publiees[] = self::fiche(['id' => 1 + $i, 'statut' => 'publie', 'titre' => 'Conférence d' . $i . 'd e' . $i . 'e f' . $i . 'f', 'debut' => '2026-12-' . sprintf('%02d', 1 + $i % 28), 'fin' => null, 'url' => 'https://p.test/e/' . $i]);
        }
        $debut = microtime(true);
        $this->assertSame([], Ressemblance::pour_la_file($attente, $publiees, '2026-11-20'));
        $this->assertLessThan(0.5, microtime(true) - $debut);
    }

    public function test_appels(): void {
        $appel = static fn(array $c): Fiche => self::fiche($c + ['type' => 'appel', 'titre' => 'Appel à projets en santé numérique', 'debut' => '2026-11-01', 'fin' => '2026-11-01', 'echeance' => '2027-01-15', 'url' => 'https://frq.test/appel']);
        // Même titre et même date limite, publiés à des jours différents.
        $this->assertTrue(Ressemblance::memes($appel([]), $appel(['id' => 2, 'debut' => '2026-11-10', 'url' => 'https://autre.test/x'])));
        // Date limite inconnue d'un côté : le titre décide.
        $this->assertTrue(Ressemblance::memes($appel(['echeance' => null]), $appel(['id' => 2, 'url' => 'https://autre.test/x'])));
        // Même titre, autre date limite : l'édition suivante du même concours.
        $this->assertFalse(Ressemblance::memes($appel([]), $appel(['id' => 2, 'echeance' => '2028-01-15', 'url' => 'https://autre.test/x'])));
        // Même page et autre titre : la préannonce et le lancement.
        $this->assertTrue(Ressemblance::memes($appel([]), $appel(['id' => 2, 'titre' => 'Préannonce du concours'])));
    }

    public function test_pour_la_file(): void {
        $attente = [
            self::fiche(['id' => 10]),
            self::fiche(['id' => 11, 'titre' => 'Colloque sur le sommeil', 'debut' => '2026-12-02', 'fin' => '2026-12-02', 'url' => 'https://s.test/c']),
            self::fiche(['id' => 12, 'titre' => 'Colloque du sommeil', 'debut' => '2026-12-02', 'fin' => '2026-12-02', 'url' => 'https://autre.test/c', 'source' => 'proposition', 'niveau' => 'M']),
            self::fiche(['id' => 13, 'titre' => 'Rien de pareil', 'debut' => '2026-12-09', 'fin' => '2026-12-09', 'url' => 'https://r.test/']),
        ];
        $publiees = [
            self::fiche(['id' => 2, 'titre' => '20e Forum de l\'industrie de la santé de Québec (FISQ)', 'statut' => 'publie', 'url' => 'https://q.test/fisq']),
            self::fiche(['id' => 3, 'titre' => 'Autre chose', 'statut' => 'publie', 'debut' => '2026-12-02', 'url' => 'https://a.test/']),
        ];
        $r = Ressemblance::pour_la_file($attente, $publiees);
        // Une fiche publiée d'abord ; sinon une autre fiche en attente (les deux se signalent l'une l'autre).
        $this->assertSame([10 => 2, 11 => 12, 12 => 11], array_map(static fn(Fiche $f): int => (int) $f->id, $r));
        $this->assertSame([], Ressemblance::pour_la_file([], $publiees));
        $this->assertSame([], Ressemblance::pour_la_file([self::fiche(['id' => 13, 'titre' => 'Rien de pareil'])], []));
    }
}

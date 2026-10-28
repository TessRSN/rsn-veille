<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use RSN\Veille\Fiche;

final class FicheTest extends TestCase {
    public function test_nettoyage_entites_et_emojis(): void {
        $f = new Fiche();
        $f->type = 'evenement';
        $f->debut = '2027-04-14';
        $f->titre = Fiche::nettoyer_texte('✨Journées scientifiques 2027 du RQRV✨');
        $this->assertSame('✨Journées scientifiques 2027 du RQRV✨', $f->titre);
        $this->assertSame('2027-04-14|journeesscientifiques2', $f->cle_dedoublonnage());
        $this->assertSame('Colloque – ACFAS 2026', Fiche::nettoyer_texte('Colloque &#8211; ACFAS 2026'));
    }

    public function test_court_coupe_au_mot(): void {
        $this->assertSame(
            'Le Réseau québécois de recherche sur le sommeil vous…',
            Fiche::court('Le Réseau québécois de recherche sur le sommeil vous invite', 55)
        );
    }

    public function test_format_et_region(): void {
        $this->assertSame('Virtuel', Fiche::deviner_format('Conférence en ligne par Zoom'));
        $this->assertSame('Hybride', Fiche::deviner_format('Journée hybride'));
        $this->assertSame('Présentiel', Fiche::deviner_format('HEC Montréal'));
        $this->assertSame('En ligne', Fiche::deviner_region('', 'Online', 'Virtuel'));
        $this->assertSame('Québec', Fiche::deviner_region('Montreal', 'Plaza Centre-Ville', 'Présentiel'));
        $this->assertSame('Hors Québec', Fiche::deviner_region('Calgary', '', 'Présentiel'));
    }

    public function test_region_liste_elargie_et_mentions(): void {
        $this->assertSame('Québec', Fiche::deviner_region('Lévis, QC', '', 'Présentiel'));
        $this->assertSame('Québec', Fiche::deviner_region('Trois Rivières', '', 'Présentiel'));
        $this->assertSame('Québec', Fiche::deviner_region('', 'Hôtel Sept-Îles', 'Présentiel'));
        $this->assertSame('Québec', Fiche::deviner_region('Val-d\'Or', '', 'Présentiel'));
        $this->assertSame('Québec', Fiche::deviner_region('Granby', '', 'Présentiel'));
        $this->assertSame('Québec', Fiche::deviner_region('Ailleurs', 'Salle X, Quebec', 'Présentiel'));
        $this->assertSame('Hors Québec', Fiche::deviner_region('Ottawa', 'Centre Shaw', 'Présentiel'));
        $this->assertSame('Hors Québec', Fiche::deviner_region('Paris', 'Rue Qcx', 'Présentiel'));
    }

    public function test_nettoyage_separe_les_blocs(): void {
        $this->assertSame(
            'Lieu : En virtuel Titre : X',
            Fiche::nettoyer_texte('<p>Lieu : En virtuel</p><p>Titre : X</p>')
        );
        $this->assertSame('Date Lieu', Fiche::nettoyer_texte('Date<br>Lieu'));
        $this->assertSame('mot entier', Fiche::nettoyer_texte('<strong>mot</strong> entier'));
    }

    public function test_aller_retour_tableau(): void {
        $f = new Fiche();
        $f->type = 'appel';
        $f->titre = 'Appel à projets – IA en santé';
        $f->debut = '2027-01-10';
        $f->fin = '2027-01-12';
        $f->heure = '09:30';
        $f->organisateur = 'IVADO';
        $f->categorie_rsn = 'Financement';
        $f->lieu = 'Palais des congrès';
        $f->ville = 'Montréal';
        $f->region = 'Québec';
        $f->format = 'Hybride';
        $f->prix = 'Gratuit';
        $f->langue = 'en';
        $f->themes = ['IA', 'Santé numérique'];
        $f->resume = 'Un résumé court.';
        $f->url = 'https://exemple.org/appel';
        $f->image_url = 'https://exemple.org/image.jpg';
        $f->source = 'ivado';
        $f->niveau = 'A';
        $f->statut = 'approuve';
        $f->cle_source = 'ivado:123';
        $f->titres_source = ['ivado:123' => 'Appel à projets – IA en santé (source)', 'crm:456' => 'Appel IA santé'];
        $f->cles_source = ['ivado:123', 'crm:456'];
        $f->categorie = 'Subvention';
        $f->echeance = '2027-03-01';
        $f->titre_en = 'AI in health call for projects';
        $f->resume_en = 'A short summary.';
        $f->url_en = 'https://exemple.org/en/call';
        $f->id = 42;
        $f->absent_depuis = '2026-10-20';
        $f->absences = 2;
        $f->manuel = true;

        $copie = Fiche::depuis_tableau($f->vers_tableau());
        $this->assertEquals($f, $copie);
        $this->assertSame($f->vers_tableau(), $copie->vers_tableau());

        $defaut = Fiche::depuis_tableau(['titre' => 'Seul', 'inconnue' => 'ignorée']);
        $this->assertSame('Seul', $defaut->titre);
        $this->assertSame('fr', $defaut->langue);
        $this->assertSame('a_approuver', $defaut->statut);
        $this->assertNull($defaut->id);
        $this->assertSame(['', '', ''], [$defaut->titre_en, $defaut->resume_en, $defaut->url_en]);
    }

    public function test_champs_anglais_dans_le_tableau(): void {
        $copie = Fiche::depuis_tableau(['titre_en' => 'Colloquium', 'resume_en' => 'Summary', 'url_en' => 'https://exemple.org/en']);
        $this->assertSame(['Colloquium', 'Summary', 'https://exemple.org/en'], [$copie->titre_en, $copie->resume_en, $copie->url_en]);
        $tableau = (new Fiche())->vers_tableau();
        $this->assertSame('', $tableau['titre_en']);
        $this->assertSame('', $tableau['resume_en']);
        $this->assertSame('', $tableau['url_en']);
    }

    public function test_vers_tableau_expose_image_manuelle(): void {
        $f = new Fiche();
        $f->titre = 'X';
        $f->debut = '2026-11-01';
        $f->image_manuelle = 'https://rsn.quebec/wp-content/uploads/a.jpg';
        $this->assertSame('https://rsn.quebec/wp-content/uploads/a.jpg', $f->vers_tableau()['image_manuelle']);
        $this->assertSame('', (new Fiche())->image_manuelle);
    }

    public function test_depuis_tableau_valeurs_vides_et_types(): void {
        $f = Fiche::depuis_tableau([
            'id' => '', 'debut' => '', 'fin' => '', 'echeance' => '', 'absent_depuis' => '',
            'manuel' => 'false',
            'themes' => ['IA', 3, null, ['x']], 'cles_source' => [7],
            'titres_source' => ['dhc:1' => 2026, 'rsn:2' => 'Atelier', 'x:3' => ['non'], 'x:4' => null],
        ]);
        $this->assertNull($f->id);
        $this->assertNull($f->debut);
        $this->assertNull($f->fin);
        $this->assertNull($f->echeance);
        $this->assertNull($f->absent_depuis);
        $this->assertFalse($f->manuel);
        $this->assertSame(['IA', '3'], $f->themes);
        $this->assertSame(['7'], $f->cles_source);
        // Tableau associatif : les clés sont gardées, les valeurs converties en chaînes.
        $this->assertSame(['dhc:1' => '2026', 'rsn:2' => 'Atelier'], $f->titres_source);

        foreach (['0', '', 'false', false, 0] as $faux) {
            $this->assertFalse(Fiche::depuis_tableau(['manuel' => $faux])->manuel);
        }
        foreach (['1', 'true', true, 1] as $vrai) {
            $this->assertTrue(Fiche::depuis_tableau(['manuel' => $vrai])->manuel);
        }
        $this->assertSame(42, Fiche::depuis_tableau(['id' => '42'])->id);
        $this->assertSame('2027-01-01', Fiche::depuis_tableau(['debut' => '2027-01-01'])->debut);
    }
    public function test_appel_en_cours_par_echeance_puis_fin_puis_age(): void {
        $appel = static fn(array $c): Fiche => Fiche::depuis_tableau($c + ['type' => 'appel', 'titre' => 'Appel']);
        $jour = '2026-10-25';
        // Échéance : elle décide seule, même si la fiche est récente ou la fin lointaine.
        $this->assertTrue($appel(['echeance' => '2026-10-25', 'debut' => '2020-01-01'])->appel_en_cours($jour));
        $this->assertFalse($appel(['echeance' => '2026-10-24', 'debut' => '2026-10-20', 'fin' => '2027-01-01'])->appel_en_cours($jour));
        // Sans échéance : une fin distincte du début sert de date limite.
        $this->assertTrue($appel(['debut' => '2026-01-01', 'fin' => '2026-11-01'])->appel_en_cours($jour));
        $this->assertFalse($appel(['debut' => '2026-10-20', 'fin' => '2026-10-24'])->appel_en_cours($jour));
        // Ni échéance ni fin distincte : moins de 30 jours depuis la publication (début), sinon depuis la création.
        $this->assertTrue($appel(['debut' => '2026-09-26', 'fin' => '2026-09-26'])->appel_en_cours($jour), '29 jours');
        $this->assertFalse($appel(['debut' => '2026-09-25', 'fin' => '2026-09-25'])->appel_en_cours($jour), '30 jours');
        $this->assertFalse($appel(['debut' => '2026-06-22', 'cree_le' => '2026-10-25'])->appel_en_cours($jour), 'publié en juin, collecté aujourd\'hui');
        $this->assertTrue($appel(['cree_le' => '2026-10-01'])->appel_en_cours($jour));
        $this->assertFalse($appel(['cree_le' => '2026-07-01'])->appel_en_cours($jour));
        // Aucune date du tout : rien ne permet de le dire en cours.
        $this->assertFalse($appel([])->appel_en_cours($jour));
        $this->assertSame('2026-11-01', $appel(['debut' => '2026-01-01', 'fin' => '2026-11-01'])->limite_appel());
        $this->assertNull($appel(['debut' => '2026-01-01', 'fin' => '2026-01-01'])->limite_appel());
    }

    public function test_champs_manuels_aller_retour_vers_et_depuis_tableau(): void {
        $this->assertSame([], (new Fiche())->champs_manuels);
        $f = Fiche::depuis_tableau(['champs_manuels' => ['debut', 'url', 7]]);
        $this->assertSame(['debut', 'url', '7'], $f->champs_manuels);
        $this->assertSame(['debut', 'url', '7'], $f->vers_tableau()['champs_manuels']);
        $this->assertSame(['debut', 'url', '7'], Fiche::depuis_tableau($f->vers_tableau())->champs_manuels);
        $this->assertSame([], Fiche::depuis_tableau(['champs_manuels' => 'debut'])->champs_manuels);
    }
}

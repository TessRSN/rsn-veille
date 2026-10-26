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
        $f->cles_source = ['ivado:123', 'crm:456'];
        $f->categorie = 'Subvention';
        $f->echeance = '2027-03-01';
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
    }
}

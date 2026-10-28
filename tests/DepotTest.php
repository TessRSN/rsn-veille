<?php
declare(strict_types=1);

require_once __DIR__ . '/bouchons-wordpress.php';

use PHPUnit\Framework\TestCase;
use RSN\Veille\Depot;
use RSN\Veille\EntrepotMemoire;
use RSN\Veille\EntrepotWordPress;
use RSN\Veille\Fiche;

final class DepotTest extends TestCase {
    /** Fiche d'événement construite depuis quelques champs ; `cles_source` reprend `cle_source` si absent. */
    private function fiche(array $champs): Fiche {
        $f = Fiche::depuis_tableau($champs + ['type' => 'evenement']);
        if ($f->cles_source === [] && $f->cle_source !== '') {
            $f->cles_source = [$f->cle_source];
        }
        return $f;
    }

    public function test_creation_puis_mise_a_jour_factuelle_seulement(): void {
        $e = new EntrepotMemoire(); $d = new Depot($e);
        $f = $this->fiche(['titre' => 'FHIR North 2026', 'debut' => '2026-10-27', 'fin' => '2026-10-28', 'cle_source' => 'dhc:1', 'niveau' => 'A', 'statut' => 'publie']);
        $this->assertSame('cree', $d->integrer($f));
        $e->mettre_a_jour(1, ['titre' => 'FHIR North (titre corrigé par l’équipe)', 'statut' => 'ecarte']);
        $f2 = clone $f; $f2->fin = '2026-10-29'; $f2->titre = 'FHIR North 2026 (nouveau titre source)';
        $this->assertSame('mis_a_jour', $d->integrer($f2));
        $x = $e->trouver_par_cle_source('dhc:1');
        $this->assertSame(['FHIR North (titre corrigé par l’équipe)', '2026-10-29', 'ecarte'], [$x->titre, $x->fin, $x->statut]);
        $this->assertSame(1, $x->id);
        $this->assertCount(1, $e->lister(''));
    }

    public function test_fiche_connue_recoit_les_champs_anglais_de_la_source(): void {
        $e = new EntrepotMemoire(); $d = new Depot($e);
        $f = $this->fiche(['titre' => 'Colloque', 'debut' => '2026-11-12', 'cle_source' => 'x:1', 'niveau' => 'C', 'statut' => 'publie']);
        $this->assertSame('cree', $d->integrer($f));
        $f2 = clone $f; $f2->titre_en = 'Colloquium'; $f2->resume_en = 'Summary'; $f2->url_en = 'https://exemple.ca/en';
        $this->assertSame('mis_a_jour', $d->integrer($f2));
        $x = $e->trouver_par_cle_source('x:1');
        $this->assertSame(['Colloquium', 'Summary', 'https://exemple.ca/en'], [$x->titre_en, $x->resume_en, $x->url_en]);

        // Une valeur anglaise vide n'écrase jamais l'existante.
        $this->assertSame('inchange', $d->integrer(clone $f));
        $this->assertSame('Colloquium', $e->trouver_par_cle_source('x:1')->titre_en);
    }

    public function test_source_moins_fiable_ne_change_pas_un_champ_anglais_deja_rempli_mais_le_comble(): void {
        $e = new EntrepotMemoire(); $d = new Depot($e);
        $d->integrer($this->fiche(['titre' => 'Colloque', 'debut' => '2026-11-12', 'cle_source' => 'x:1', 'niveau' => 'A', 'statut' => 'publie', 'titre_en' => 'Colloquium']));
        $b = $this->fiche(['titre' => 'Colloque', 'debut' => '2026-11-12', 'cle_source' => 'x:1', 'niveau' => 'B', 'titre_en' => 'Other', 'resume_en' => 'Summary']);
        $this->assertSame('mis_a_jour', $d->integrer($b));
        $x = $e->trouver_par_cle_source('x:1');
        $this->assertSame(['Colloquium', 'Summary'], [$x->titre_en, $x->resume_en]);
    }

    public function test_doublon_d_une_autre_source_passe_les_champs_anglais_a_la_fiche_gardee(): void {
        $e = new EntrepotMemoire(); $d = new Depot($e);
        $d->integrer($this->fiche(['titre' => 'Hackathon', 'debut' => '2026-11-13', 'cle_source' => 'infolettre:3', 'niveau' => 'M', 'statut' => 'publie']));
        // Source plus fiable.
        $a = $this->fiche(['titre' => 'Hackathon', 'debut' => '2026-11-13', 'cle_source' => 'rsn:42', 'niveau' => 'A', 'titre_en' => 'Hackathon EN', 'resume_en' => 'Summary', 'url_en' => 'https://rsn.quebec/en/hackathon/']);
        $this->assertSame('fusionne', $d->integrer($a));
        $x = $e->lister('')[0];
        $this->assertSame(['Hackathon EN', 'Summary', 'https://rsn.quebec/en/hackathon/'], [$x->titre_en, $x->resume_en, $x->url_en]);

        // Source moins fiable : comble seulement ce que la fiche n'a pas.
        $b = $this->fiche(['titre' => 'Hackathon', 'debut' => '2026-11-13', 'cle_source' => 'veille:9', 'niveau' => 'B', 'titre_en' => 'Autre titre', 'resume_en' => 'Autre résumé']);
        $this->assertSame('fusionne', $d->integrer($b));
        $x = $e->lister('')[0];
        $this->assertSame(['Hackathon EN', 'Summary'], [$x->titre_en, $x->resume_en]);

        $e2 = new EntrepotMemoire(); $d2 = new Depot($e2);
        $d2->integrer($this->fiche(['titre' => 'Atelier', 'debut' => '2026-11-14', 'cle_source' => 'a:1', 'niveau' => 'A', 'statut' => 'publie']));
        $d2->integrer($this->fiche(['titre' => 'Atelier', 'debut' => '2026-11-14', 'cle_source' => 'b:1', 'niveau' => 'C', 'titre_en' => 'Workshop']));
        $this->assertSame('Workshop', $e2->lister('')[0]->titre_en);
    }

    public function test_completer_anglais_ecrit_les_champs_non_vides_et_differents(): void {
        $e = new EntrepotMemoire(); $d = new Depot($e);
        $d->integrer($this->fiche(['titre' => 'Colloque', 'debut' => '2026-11-12', 'cle_source' => 'x:1', 'niveau' => 'C', 'statut' => 'publie']));
        $connue = $e->trouver_par_cle_source('x:1');

        $this->assertTrue($d->completer_anglais($connue, 'Colloquium', '', 'https://exemple.ca/en'));
        $x = $e->trouver_par_cle_source('x:1');
        $this->assertSame(['Colloquium', '', 'https://exemple.ca/en'], [$x->titre_en, $x->resume_en, $x->url_en]);

        // Rien de nouveau : rien d'écrit.
        $this->assertFalse($d->completer_anglais($x, 'Colloquium', '', 'https://exemple.ca/en'));
        // Valeurs vides : rien d'écrit, rien d'écrasé.
        $this->assertFalse($d->completer_anglais($x, '', '', ''));
        $this->assertSame('Colloquium', $e->trouver_par_cle_source('x:1')->titre_en);
        // Un champ change, l'autre non.
        $this->assertTrue($d->completer_anglais($x, 'Colloquium', 'Summary', 'https://exemple.ca/en'));
        $x = $e->trouver_par_cle_source('x:1');
        $this->assertSame(['Colloquium', 'Summary'], [$x->titre_en, $x->resume_en]);
    }

    public function test_mise_a_jour_ignore_les_valeurs_vides_et_ne_touche_pas_le_reste(): void {
        $e = new EntrepotMemoire(); $d = new Depot($e);
        $f = $this->fiche([
            'titre' => 'Atelier', 'debut' => '2026-11-12', 'heure' => '09:00', 'cle_source' => 'rsn:7', 'niveau' => 'A',
            'statut' => 'publie', 'image_url' => 'https://x.test/a.jpg', 'resume' => 'Résumé', 'themes' => ['IA'],
            'organisateur' => 'RSN', 'ville' => 'Montréal', 'format' => 'Présentiel', 'region' => 'Québec',
        ]);
        $d->integrer($f);
        $this->assertSame('inchange', $d->integrer(clone $f));

        $f2 = clone $f;
        $f2->image_url = ''; $f2->heure = ''; $f2->resume = 'Autre'; $f2->themes = []; $f2->organisateur = 'Autre';
        $f2->statut = 'a_approuver'; $f2->niveau = 'B'; $f2->source = 'autre';
        $this->assertSame('inchange', $d->integrer($f2));

        $f3 = clone $f; $f3->ville = 'Calgary'; $f3->prix = '50 $';
        $this->assertSame('mis_a_jour', $d->integrer($f3));
        $x = $e->trouver_par_cle_source('rsn:7');
        $this->assertSame(
            ['https://x.test/a.jpg', '09:00', 'Résumé', ['IA'], 'RSN', 'publie', 'A', 'Calgary', '50 $', 'Hors Québec'],
            [$x->image_url, $x->heure, $x->resume, $x->themes, $x->organisateur, $x->statut, $x->niveau, $x->ville, $x->prix, $x->region]
        );
    }

    public function test_fusion_niveau_superieur_fournit_image_et_dates(): void {
        $e = new EntrepotMemoire(); $d = new Depot($e);
        $m = $this->fiche([
            'titre' => 'Hackathon en santé numérique 2025', 'debut' => '2025-11-13', 'cle_source' => 'infolettre:3',
            'niveau' => 'M', 'source' => 'infolettre', 'statut' => 'publie', 'resume' => 'Résumé de l’infolettre',
            'organisateur' => 'RSN', 'url' => 'https://infolettre.test/hackathon',
        ]);
        $this->assertSame('cree', $d->integrer($m));
        $a = $this->fiche([
            'titre' => 'Hackathon en santé numérique 2025 — Montréal', 'debut' => '2025-11-13', 'fin' => '2025-11-14',
            'heure' => '08:30', 'cle_source' => 'rsn:42', 'niveau' => 'A', 'source' => 'rsn', 'statut' => 'a_approuver',
            'image_url' => 'https://rsn.quebec/hackathon.jpg', 'url' => 'https://rsn.quebec/evenement/hackathon/',
            'resume' => 'Résumé du site', 'organisateur' => 'Autre',
        ]);
        $this->assertSame('fusionne', $d->integrer($a));

        $toutes = $e->lister('');
        $this->assertCount(1, $toutes);
        $x = $toutes[0];
        $this->assertSame('https://rsn.quebec/hackathon.jpg', $x->image_url);
        $this->assertSame('https://rsn.quebec/evenement/hackathon/', $x->url);
        $this->assertSame(['2025-11-13', '2025-11-14', '08:30'], [$x->debut, $x->fin, $x->heure]);
        $this->assertSame(['A', 'rsn'], [$x->niveau, $x->source]);
        $this->assertSame(['infolettre:3', 'rsn:42'], $x->cles_source);
        $this->assertSame(['Hackathon en santé numérique 2025', 'Résumé de l’infolettre', 'RSN', 'publie'], [$x->titre, $x->resume, $x->organisateur, $x->statut]);
        // La nouvelle clé est enregistrée : la collecte suivante de rsn met à jour au lieu de fusionner.
        $this->assertSame('inchange', $d->integrer(clone $a));
    }

    public function test_fusion_niveau_inferieur_complete_seulement_image_et_url_vides(): void {
        $e = new EntrepotMemoire(); $d = new Depot($e);
        $d->integrer($this->fiche([
            'titre' => 'FHIR North 2026', 'debut' => '2026-10-27', 'fin' => '2026-10-28', 'cle_source' => 'dhc:1',
            'niveau' => 'A', 'source' => 'dhc', 'statut' => 'publie',
        ]));
        $b = $this->fiche([
            'titre' => 'FHIR north, 2026 !', 'debut' => '2026-10-27', 'fin' => '2026-10-30', 'heure' => '10:00',
            'cle_source' => 'frq:9', 'niveau' => 'B', 'source' => 'frq', 'image_url' => 'https://frq.test/i.png',
            'url' => 'https://frq.test/fhir', 'prix' => 'Gratuit',
        ]);
        $this->assertSame('fusionne', $d->integrer($b));
        $x = $e->trouver_par_cle_source('frq:9');
        $this->assertSame(1, $x->id);
        $this->assertSame(['2026-10-28', '', ''], [$x->fin, $x->heure, $x->prix]);
        $this->assertSame(['https://frq.test/i.png', 'https://frq.test/fhir'], [$x->image_url, $x->url]);
        $this->assertSame(['A', 'dhc', 'FHIR North 2026'], [$x->niveau, $x->source, $x->titre]);
        $this->assertSame(['dhc:1', 'frq:9'], $x->cles_source);

        // Image et URL déjà présentes : une autre source de niveau inférieur ne les remplace pas.
        $b2 = clone $b; $b2->cle_source = 'crm:5'; $b2->cles_source = ['crm:5']; $b2->image_url = 'https://crm.test/j.png'; $b2->url = 'https://crm.test/f';
        $this->assertSame('fusionne', $d->integrer($b2));
        $x = $e->trouver_par_cle_source('crm:5');
        $this->assertSame(['https://frq.test/i.png', 'https://frq.test/fhir'], [$x->image_url, $x->url]);
        $this->assertSame(['dhc:1', 'frq:9', 'crm:5'], $x->cles_source);
        $this->assertCount(1, $e->lister(''));

        // La collecte suivante de frq retrouve sa clé : elle ne réécrit pas les champs de dhc.
        $this->assertSame('inchange', $d->integrer(clone $b));
        $x = $e->trouver_par_cle_source('frq:9');
        $this->assertSame(['2026-10-28', '', '', 'A', 'dhc'], [$x->fin, $x->heure, $x->prix, $x->niveau, $x->source]);
    }

    public function test_cle_source_moins_fiable_comble_seulement_les_vides(): void {
        $e = new EntrepotMemoire(); $d = new Depot($e);
        $d->integrer($this->fiche(['titre' => 'Colloque IA', 'debut' => '2026-12-01', 'fin' => '2026-12-02', 'cle_source' => 'obvia:1', 'niveau' => 'C']));
        $d->integrer($this->fiche(['titre' => 'Colloque IA', 'debut' => '2026-12-01', 'cle_source' => 'frq:1', 'niveau' => 'B']));
        $b = $this->fiche(['titre' => 'Colloque IA', 'debut' => '2026-12-01', 'fin' => '2026-12-05', 'cle_source' => 'frq:1', 'niveau' => 'B', 'image_url' => 'https://frq.test/c.png']);
        $this->assertSame('mis_a_jour', $d->integrer($b));
        $x = $e->trouver_par_cle_source('frq:1');
        $this->assertSame(['2026-12-02', 'https://frq.test/c.png', 'C'], [$x->fin, $x->image_url, $x->niveau]);
        // Une clé de même niveau que la fiche, elle, fait suivre les champs factuels.
        $c = $this->fiche(['titre' => 'Colloque IA', 'debut' => '2026-12-01', 'fin' => '2026-12-03', 'cle_source' => 'obvia:1', 'niveau' => 'C']);
        $this->assertSame('mis_a_jour', $d->integrer($c));
        $this->assertSame('2026-12-03', $e->trouver_par_cle_source('frq:1')->fin);
    }

    public function test_echeance_suit_la_source(): void {
        $e = new EntrepotMemoire(); $d = new Depot($e);
        $a = $this->fiche(['type' => 'appel', 'titre' => 'Appel à projets', 'echeance' => '2026-11-30', 'cle_source' => 'frq_appels:4', 'niveau' => 'B', 'statut' => 'publie']);
        $d->integrer($a);
        $a2 = clone $a; $a2->echeance = '2026-12-15';
        $this->assertSame('mis_a_jour', $d->integrer($a2));
        $this->assertSame('2026-12-15', $e->trouver_par_cle_source('frq_appels:4')->echeance);
        $a3 = clone $a; $a3->echeance = null;
        $this->assertSame('inchange', $d->integrer($a3));
        $this->assertSame('2026-12-15', $e->trouver_par_cle_source('frq_appels:4')->echeance);
    }

    public function test_titres_source_par_cle(): void {
        $e = new EntrepotMemoire(); $d = new Depot($e);
        $f = $this->fiche(['titre' => 'FHIR North 2026', 'debut' => '2026-10-27', 'cle_source' => 'dhc:1', 'niveau' => 'A']);
        $d->integrer($f);
        $this->assertSame(['dhc:1' => 'FHIR North 2026'], $e->trouver_par_cle_source('dhc:1')->titres_source);
        // Même depuis une clé moins fiable, le titre vu par cette clé est noté ; le titre affiché ne bouge pas.
        $d->integrer($this->fiche(['titre' => 'FHIR North 2026', 'debut' => '2026-10-27', 'cle_source' => 'frq:2', 'niveau' => 'B']));
        $b = $this->fiche(['titre' => 'FHIR North 2026 (complet)', 'debut' => '2026-10-27', 'cle_source' => 'frq:2', 'niveau' => 'B']);
        $this->assertSame('mis_a_jour', $d->integrer($b));
        $x = $e->trouver_par_cle_source('dhc:1');
        $this->assertSame(['dhc:1' => 'FHIR North 2026', 'frq:2' => 'FHIR North 2026 (complet)'], $x->titres_source);
        $this->assertSame('FHIR North 2026', $x->titre);
    }

    public function test_deux_sources_aux_titres_differents_restent_inchangees(): void {
        $e = new EntrepotMemoire(); $d = new Depot($e);
        $rsn = $this->fiche(['titre' => 'Atelier données', 'debut' => '2026-11-16', 'url' => 'https://rsn.quebec/atelier/', 'cle_source' => 'rsn:1', 'niveau' => 'A', 'statut' => 'publie']);
        $dhc = $this->fiche(['titre' => 'Data Workshop (Montréal)', 'debut' => '2026-11-16', 'url' => 'https://rsn.quebec/atelier/', 'cle_source' => 'dhc:1', 'niveau' => 'A']);
        $this->assertSame('cree', $d->integrer($rsn));
        $this->assertSame('fusionne', $d->integrer($dhc));
        for ($nuit = 0; $nuit < 3; $nuit++) {
            $this->assertSame('inchange', $d->integrer(clone $rsn));
            $this->assertSame('inchange', $d->integrer(clone $dhc));
        }
        $this->assertSame(['rsn:1' => 'Atelier données', 'dhc:1' => 'Data Workshop (Montréal)'], $e->lister()[0]->titres_source);
    }

    public function test_source_reportee_deja_connue_ne_rebascule_pas_une_fiche_republiee(): void {
        $e = new EntrepotMemoire(); $d = new Depot($e);
        $rsn = $this->fiche(['titre' => 'Atelier données', 'debut' => '2026-11-16', 'url' => 'https://rsn.quebec/atelier/', 'cle_source' => 'rsn:1', 'niveau' => 'A', 'statut' => 'publie']);
        $dhc = $this->fiche(['titre' => 'Atelier données — reporté', 'debut' => '2026-11-16', 'url' => 'https://rsn.quebec/atelier/', 'cle_source' => 'dhc:1', 'niveau' => 'A']);
        $d->integrer($rsn);
        $d->integrer($dhc);
        $this->assertTrue($d->annuler_si_necessaire($dhc));
        $e->mettre_a_jour(1, ['statut' => 'publie', 'manuel' => true]);
        // Collectes alternées : ni rsn ni dhc ne renvoient la fiche à l'approbation.
        for ($nuit = 0; $nuit < 2; $nuit++) {
            $this->assertSame('inchange', $d->integrer(clone $dhc));
            $this->assertFalse($d->annuler_si_necessaire($dhc));
            $this->assertSame('inchange', $d->integrer(clone $rsn));
            $this->assertFalse($d->annuler_si_necessaire($rsn));
        }
        $this->assertSame('publie', $e->trouver_par_cle_source('dhc:1')->statut);
        // Avec une nouvelle instance (exécution suivante), le titre stocké de la clé sert de référence.
        $d2 = new Depot($e);
        $this->assertSame('inchange', $d2->integrer(clone $dhc));
        $this->assertFalse($d2->annuler_si_necessaire($dhc));
        $this->assertSame('publie', $e->trouver_par_cle_source('dhc:1')->statut);
    }

    public function test_nouvelle_cle_annoncant_une_annulation(): void {
        $e = new EntrepotMemoire(); $d = new Depot($e);
        $d->integrer($this->fiche(['titre' => 'Atelier données', 'debut' => '2026-11-16', 'url' => 'https://rsn.quebec/atelier/', 'cle_source' => 'rsn:1', 'niveau' => 'A', 'statut' => 'publie']));
        $dhc = $this->fiche(['titre' => 'Atelier données annulé', 'debut' => '2026-11-16', 'url' => 'https://rsn.quebec/atelier/', 'cle_source' => 'dhc:1', 'niveau' => 'A']);
        $this->assertSame('fusionne', $d->integrer($dhc));
        $this->assertTrue($d->annuler_si_necessaire($dhc));
        $this->assertSame('a_approuver', $e->trouver_par_cle_source('rsn:1')->statut);
    }

    public function test_fusion_par_url(): void {
        $e = new EntrepotMemoire(); $d = new Depot($e);
        $d->integrer($this->fiche(['titre' => 'Colloque', 'debut' => '2026-12-01', 'url' => 'https://x.test/colloque', 'cle_source' => 'obvia:c', 'niveau' => 'C']));
        $autre = $this->fiche(['titre' => 'Un titre très différent', 'debut' => '2026-12-02', 'url' => 'https://x.test/colloque', 'cle_source' => 'ivado:c', 'niveau' => 'C']);
        $this->assertSame('fusionne', $d->integrer($autre));
        $this->assertCount(1, $e->lister(''));
        $this->assertSame('2026-12-01', $e->trouver_par_cle_source('ivado:c')->debut);

        // Sans date ni URL commune, aucune fusion.
        $appel = $this->fiche(['type' => 'appel', 'titre' => 'Colloque', 'cle_source' => 'crm:a', 'niveau' => 'B']);
        $this->assertSame('cree', $d->integrer($appel));
        $this->assertSame('cree', $d->integrer($this->fiche(['type' => 'appel', 'titre' => 'Colloque', 'cle_source' => 'frq:a', 'niveau' => 'B'])));
        $this->assertCount(3, $e->lister(''));
    }

    public function test_absence_apres_deux_collectes_reussies_seulement(): void {
        $e = new EntrepotMemoire(); $d = new Depot($e);
        $d->integrer($this->fiche(['titre' => 'FHIR North 2026', 'debut' => '2026-11-27', 'cle_source' => 'dhc:1', 'niveau' => 'A', 'statut' => 'publie']));
        $d->integrer($this->fiche(['titre' => 'À approuver', 'debut' => '2026-11-28', 'cle_source' => 'dhc:2', 'niveau' => 'A', 'statut' => 'a_approuver']));
        $d->integrer($this->fiche(['titre' => 'Autre source', 'debut' => '2026-11-29', 'cle_source' => 'rqrv:1', 'niveau' => 'A', 'statut' => 'publie']));

        $d->signaler_absents('dhc', [], false, '2026-10-25');
        $x = $e->trouver_par_cle_source('dhc:1');
        $this->assertSame([0, null], [$x->absences, $x->absent_depuis]);

        $d->signaler_absents('dhc', [], true, '2026-10-25');
        $x = $e->trouver_par_cle_source('dhc:1');
        $this->assertSame([1, null], [$x->absences, $x->absent_depuis]);

        $d->signaler_absents('dhc', [], true, '2026-10-26');
        $x = $e->trouver_par_cle_source('dhc:1');
        $this->assertSame([2, '2026-10-26'], [$x->absences, $x->absent_depuis]);
        $this->assertSame('publie', $x->statut);

        // Une troisième absence garde la date du drapeau.
        $d->signaler_absents('dhc', [], true, '2026-10-27');
        $this->assertSame('2026-10-26', $e->trouver_par_cle_source('dhc:1')->absent_depuis);

        // Fiches non publiées ou d'une autre source : jamais touchées.
        $this->assertSame([0, null], [$e->trouver_par_cle_source('dhc:2')->absences, $e->trouver_par_cle_source('dhc:2')->absent_depuis]);
        $this->assertSame([0, null], [$e->trouver_par_cle_source('rqrv:1')->absences, $e->trouver_par_cle_source('rqrv:1')->absent_depuis]);

        $d->signaler_absents('dhc', ['dhc:1'], true, '2026-10-28');
        $x = $e->trouver_par_cle_source('dhc:1');
        $this->assertSame([0, null], [$x->absences, $x->absent_depuis]);
    }

    public function test_absences_chargent_seulement_les_fiches_de_la_source(): void {
        rsn_veille_bouchons_reinitialiser();
        $e = new EntrepotWordPress(); $d = new Depot($e);
        $d->integrer($this->fiche(['titre' => 'FHIR North 2026', 'debut' => '2026-11-27', 'cle_source' => 'dhc:1', 'niveau' => 'A', 'statut' => 'publie']));
        for ($i = 1; $i <= 5; $i++) {
            $d->integrer($this->fiche(['titre' => 'Autre ' . $i, 'debut' => '2026-11-2' . $i, 'cle_source' => 'rqrv:' . $i, 'niveau' => 'A', 'statut' => 'publie']));
        }
        $GLOBALS['rsn_veille_wp']['requetes'] = [];

        $d->signaler_absents('dhc', [], true, '2026-10-25');
        $this->assertSame(1, $e->trouver_par_cle_source('dhc:1')->absences);
        foreach ($GLOBALS['rsn_veille_wp']['requetes'] as $requete) {
            $this->assertFalse(($requete['posts_per_page'] ?? 0) === -1 && empty($requete['meta_query']) && empty($requete['post__in']), 'aucune requête sans borne');
        }
        $this->assertContains([1], array_column($GLOBALS['rsn_veille_wp']['requetes'], 'post__in'));
    }

    public function test_absence_ignore_evenements_passes_et_appels(): void {
        $e = new EntrepotMemoire(); $d = new Depot($e);
        $d->integrer($this->fiche(['titre' => 'Fini', 'debut' => '2026-08-30', 'fin' => '2026-09-01', 'cle_source' => 'dhc:1', 'niveau' => 'A', 'statut' => 'publie']));
        $d->integrer($this->fiche(['type' => 'appel', 'titre' => 'Appel', 'echeance' => '2026-12-01', 'cle_source' => 'dhc:2', 'niveau' => 'A', 'statut' => 'publie']));
        $d->integrer($this->fiche(['titre' => 'Commencé, pas fini', 'debut' => '2026-10-20', 'fin' => '2026-10-30', 'cle_source' => 'dhc:3', 'niveau' => 'A', 'statut' => 'publie']));
        $d->integrer($this->fiche(['titre' => 'Aujourd’hui', 'debut' => '2026-10-25', 'cle_source' => 'dhc:4', 'niveau' => 'A', 'statut' => 'publie']));
        $e->creer($this->fiche(['titre' => 'Fin vide', 'debut' => '2026-11-02', 'cle_source' => 'dhc:5', 'niveau' => 'A', 'statut' => 'publie']));
        $e->mettre_a_jour(5, ['fin' => '']);
        $d->signaler_absents('dhc', [], true, '2026-10-25');
        $d->signaler_absents('dhc', [], true, '2026-10-25');
        foreach (['dhc:1' => [0, null], 'dhc:2' => [0, null], 'dhc:3' => [2, '2026-10-25'], 'dhc:4' => [2, '2026-10-25'], 'dhc:5' => [2, '2026-10-25']] as $cle => $attendu) {
            $x = $e->trouver_par_cle_source($cle);
            $this->assertSame($attendu, [$x->absences, $x->absent_depuis], $cle);
        }
    }

    public function test_absence_une_seule_fois_puis_revue_remet_le_compteur(): void {
        $e = new EntrepotMemoire(); $d = new Depot($e);
        // Fiche fusionnée : la clé de la source dhc n'est pas la première.
        $d->integrer($this->fiche(['titre' => 'Déjeuner', 'debut' => '2026-11-27', 'cle_source' => 'rsn:5', 'niveau' => 'A', 'statut' => 'publie']));
        $d->integrer($this->fiche(['titre' => 'Déjeuner', 'debut' => '2026-11-27', 'cle_source' => 'dhc:8', 'niveau' => 'A']));
        $d->signaler_absents('dhc', ['dhc:99'], true, '2026-10-25');
        $this->assertSame(1, $e->trouver_par_cle_source('dhc:8')->absences);
        $d->signaler_absents('dhc', ['dhc:8'], true, '2026-10-26');
        $d->signaler_absents('dhc', [], true, '2026-10-27');
        $x = $e->trouver_par_cle_source('dhc:8');
        $this->assertSame([1, null], [$x->absences, $x->absent_depuis]);
        // La source rsn la voit encore : elle n'est pas absente de rsn.
        $this->assertSame([1], $e->ids_publies_de_source('rsn'));
        $this->assertSame([], $e->ids_publies_de_source('rs'));
    }

    public function test_annulation_repasse_a_approuver(): void {
        $e = new EntrepotMemoire(); $d = new Depot($e);
        $f = $this->fiche(['titre' => 'Atelier données', 'debut' => '2026-11-16', 'cle_source' => 'rsn:3', 'niveau' => 'A', 'statut' => 'publie']);
        $d->integrer($f);
        $this->assertFalse($d->annuler_si_necessaire($f));
        $this->assertSame('publie', $e->trouver_par_cle_source('rsn:3')->statut);

        $annule = clone $f; $annule->titre = 'Atelier ANNULÉ : données de santé';
        $d->integrer($annule);
        $this->assertTrue($d->annuler_si_necessaire($annule));
        $this->assertSame('a_approuver', $e->trouver_par_cle_source('rsn:3')->statut);
        // Déjà à approuver : rien ne change.
        $this->assertFalse($d->annuler_si_necessaire($annule));

        // L'équipe republie la fiche à la main : le même titre annulé ne la renvoie plus à l'approbation.
        $e->mettre_a_jour(1, ['statut' => 'publie', 'manuel' => true]);
        $this->assertSame(['rsn:3' => 'Atelier ANNULÉ : données de santé'], $e->trouver_par_cle_source('rsn:3')->titres_source);
        $this->assertSame('inchange', $d->integrer(clone $annule));
        $this->assertFalse($d->annuler_si_necessaire($annule));
        $this->assertSame('publie', $e->trouver_par_cle_source('rsn:3')->statut);
    }

    public function test_annulation_des_la_creation(): void {
        $e = new EntrepotMemoire(); $d = new Depot($e);
        $f = $this->fiche(['titre' => 'Webinaire reporté', 'debut' => '2026-11-16', 'cle_source' => 'rsn:4', 'niveau' => 'A', 'statut' => 'publie']);
        $d->integrer($f);
        $this->assertTrue($d->annuler_si_necessaire($f));
        $this->assertSame('a_approuver', $e->trouver_par_cle_source('rsn:4')->statut);
    }

    public function test_mots_d_annulation(): void {
        foreach (['Atelier annulé', 'Colloque reporté', 'Webinar CANCELLED', 'Event canceled', 'Summit postponed', 'Atelier annule', 'Journée Reportée', 'Atelier : annulation'] as $titre) {
            $e = new EntrepotMemoire(); $d = new Depot($e);
            $d->integrer($this->fiche(['titre' => 'X', 'debut' => '2026-11-16', 'cle_source' => 's:1', 'statut' => 'publie']));
            $f = $this->fiche(['titre' => $titre, 'debut' => '2026-11-16', 'cle_source' => 's:1']);
            $this->assertTrue($d->annuler_si_necessaire($f), $titre);
        }
        foreach (['Comment reporter un incident', 'Atelier sur les données'] as $titre) {
            $e = new EntrepotMemoire(); $d = new Depot($e);
            $d->integrer($this->fiche(['titre' => 'X', 'debut' => '2026-11-16', 'cle_source' => 's:1', 'statut' => 'publie']));
            $this->assertFalse($d->annuler_si_necessaire($this->fiche(['titre' => $titre, 'cle_source' => 's:1'])), $titre);
        }
        // Fiche inconnue de l'entrepôt : rien à annuler.
        $d = new Depot(new EntrepotMemoire());
        $this->assertFalse($d->annuler_si_necessaire($this->fiche(['titre' => 'Atelier annulé', 'cle_source' => 's:2'])));
    }

    public function test_rang(): void {
        $this->assertSame([0, 1, 2, 3, 9], [Depot::rang('A'), Depot::rang('C'), Depot::rang('M'), Depot::rang('B'), Depot::rang('')]);
    }

    public function test_entrepot_memoire_rend_des_copies(): void {
        $e = new EntrepotMemoire();
        $f = $this->fiche(['titre' => 'Original', 'cle_source' => 's:1', 'statut' => 'publie', 'url' => 'https://x.test/a']);
        $id = $e->creer($f);
        $this->assertSame(1, $id);
        $this->assertNull($f->id);
        $f->titre = 'Modifié dehors';
        $lu = $e->trouver_par_url('https://x.test/a');
        $lu->titre = 'Modifié en lecture';
        $this->assertSame('Original', $e->lister()[0]->titre);
        $this->assertSame(2, $e->creer($this->fiche(['type' => 'appel', 'titre' => 'Appel', 'statut' => 'a_approuver'])));
        $this->assertCount(1, $e->lister('publie', 'evenement'));
        $this->assertCount(0, $e->lister('publie', 'appel'));
        $this->assertCount(1, $e->lister('', 'appel'));
        $this->assertNull($e->trouver_par_cle_source('s:9'));
        $this->assertNull($e->trouver_par_url(''));
    }

    public function test_existe_par_cle_doublon_ou_url_sans_rien_ecrire(): void {
        $e = new EntrepotMemoire(); $d = new Depot($e);
        $f = $this->fiche(['titre' => 'Atelier données', 'debut' => '2026-11-20', 'url' => 'https://x.test/atelier', 'cle_source' => 'rsn:1', 'niveau' => 'A', 'statut' => 'publie']);
        $this->assertFalse($d->existe($f));
        $this->assertNull($d->fiche_de_cle($f));
        $this->assertSame([], $e->lister(''));
        $d->integrer($f);

        $this->assertTrue($d->existe($this->fiche(['titre' => 'Autre titre', 'cle_source' => 'rsn:1'])));
        $doublon = $this->fiche(['titre' => 'Atelier données', 'debut' => '2026-11-20', 'cle_source' => 'mila:9']);
        $this->assertTrue($d->existe($doublon));
        $this->assertNull($d->fiche_de_cle($doublon));
        $this->assertTrue($d->existe($this->fiche(['titre' => 'Sans date', 'url' => 'https://x.test/atelier', 'cle_source' => 'obvia:3'])));
        $this->assertFalse($d->existe($this->fiche(['titre' => 'Inconnue', 'debut' => '2026-11-21', 'cle_source' => 'obvia:4'])));
        $this->assertSame('rsn:1', $d->fiche_de_cle($this->fiche(['cle_source' => 'rsn:1']))->cle_source);
        $this->assertCount(1, $e->lister(''));
    }

    public function test_sans_doublon_par_url_les_evenements_d_une_meme_page_restent_distincts(): void {
        // Une infolettre annonce plusieurs événements avec la même adresse (celle du numéro).
        $e = new EntrepotMemoire(); $d = new Depot($e);
        $a = $this->fiche(['titre' => 'Colloque A', 'debut' => '2026-11-03', 'url' => 'https://mailchi.mp/x/numero', 'cle_source' => 'infolettre:a', 'niveau' => 'M']);
        $b = $this->fiche(['titre' => 'Symposium B', 'debut' => '2026-11-04', 'url' => 'https://mailchi.mp/x/numero', 'cle_source' => 'infolettre:b', 'niveau' => 'M']);
        $this->assertSame('cree', $d->integrer($a, false));
        $this->assertSame('cree', $d->integrer($b, false));
        $this->assertCount(2, $e->lister(''));
        // Même date et même titre : toujours un doublon.
        $c = $this->fiche(['titre' => 'Symposium B', 'debut' => '2026-11-04', 'cle_source' => 'rsn:9', 'niveau' => 'A']);
        $this->assertSame('fusionne', $d->integrer($c, false));
        // Par défaut, la même adresse suffit à fusionner.
        $f = $this->fiche(['titre' => 'Atelier C', 'debut' => '2026-11-05', 'url' => 'https://mailchi.mp/x/numero', 'cle_source' => 'frq:1', 'niveau' => 'B']);
        $this->assertSame('fusionne', $d->integrer($f));
        $this->assertCount(2, $e->lister(''));
    }

    // --- Propositions du public : jamais cibles d'une fusion ---

    private function proposition(string $statut): Fiche {
        return $this->fiche([
            'titre' => 'Colloque santé numérique', 'debut' => '2026-11-20', 'url' => 'https://proposant.example/page',
            'source' => 'proposition', 'niveau' => 'M', 'statut' => $statut, 'cle_source' => 'proposition:abc',
        ]);
    }

    public function test_fiche_collectee_apres_une_proposition_en_attente_creee_a_part(): void {
        $e = new EntrepotMemoire(); $d = new Depot($e);
        $e->creer($this->proposition('a_approuver'));
        $b = $this->fiche(['titre' => 'Colloque santé numérique', 'debut' => '2026-11-20', 'url' => 'https://proposant.example/page',
            'source' => 'Obvia', 'niveau' => 'B', 'statut' => 'publie', 'cle_source' => 'obvia:1']);
        $this->assertFalse($d->existe($b));
        $this->assertSame('cree', $d->integrer($b));
        $this->assertCount(2, $e->lister(''));
        $proposition = $e->trouver_par_cle_source('proposition:abc');
        $this->assertSame(['proposition', 'M', ['proposition:abc'], 'https://proposant.example/page'], [$proposition->source, $proposition->niveau, $proposition->cles_source, $proposition->url]);
        $this->assertSame('Obvia', $e->trouver_par_cle_source('obvia:1')->source);
    }

    public function test_fiche_collectee_apres_une_proposition_ecartee_creee_et_publiee(): void {
        $e = new EntrepotMemoire(); $d = new Depot($e);
        $e->creer($this->proposition('ecarte'));
        $a = $this->fiche(['titre' => 'Colloque santé numérique', 'debut' => '2026-11-20', 'url' => 'https://rsn.quebec/colloque',
            'source' => 'RSN', 'niveau' => 'A', 'statut' => 'publie', 'cle_source' => 'rsn:1']);
        $this->assertSame('cree', $d->integrer($a));
        $this->assertSame('publie', $e->trouver_par_cle_source('rsn:1')->statut);
        $this->assertSame('ecarte', $e->trouver_par_cle_source('proposition:abc')->statut);
        $this->assertCount(1, $e->lister('publie'));
    }

    public function test_une_fiche_collectee_fusionne_encore_avec_une_autre_fiche_collectee(): void {
        $e = new EntrepotMemoire(); $d = new Depot($e);
        $e->creer($this->proposition('a_approuver'));
        $d->integrer($this->fiche(['titre' => 'Colloque santé numérique', 'debut' => '2026-11-20', 'source' => 'Obvia', 'niveau' => 'B', 'statut' => 'publie', 'cle_source' => 'obvia:1']));
        $this->assertSame('fusionne', $d->integrer($this->fiche(['titre' => 'Colloque santé numérique', 'debut' => '2026-11-20', 'source' => 'RSN', 'niveau' => 'A', 'statut' => 'publie', 'cle_source' => 'rsn:1'])));
        $this->assertCount(2, $e->lister(''));
    }

    public function test_champs_proteges_ne_changent_plus_par_la_meme_source(): void {
        $e = new EntrepotMemoire(); $d = new Depot($e);
        $f = $this->fiche(['titre' => 'Atelier', 'debut' => '2026-11-12', 'fin' => '2026-11-12', 'url' => 'https://a.test/x', 'heure' => '09:00', 'cle_source' => 'dhc:1', 'niveau' => 'A', 'statut' => 'publie']);
        $this->assertSame('cree', $d->integrer($f));
        $e->mettre_a_jour(1, ['champs_manuels' => ['debut', 'url']]);
        // Même source, autre début, autre URL : rien ne change.
        $f2 = clone $f; $f2->debut = '2026-12-01'; $f2->url = 'https://a.test/y';
        $this->assertSame('inchange', $d->integrer($f2));
        $x = $e->trouver_par_cle_source('dhc:1');
        $this->assertSame(['2026-11-12', 'https://a.test/x'], [$x->debut, $x->url]);
        // Les champs non protégés suivent toujours la source.
        $f3 = clone $f2; $f3->heure = '10:30';
        $this->assertSame('mis_a_jour', $d->integrer($f3));
        $x = $e->trouver_par_cle_source('dhc:1');
        $this->assertSame(['2026-11-12', 'https://a.test/x', '10:30'], [$x->debut, $x->url, $x->heure]);
        $this->assertSame(['debut', 'url'], $x->champs_manuels);
    }

    public function test_champs_proteges_gardes_face_a_un_doublon_d_une_source_plus_fiable(): void {
        $e = new EntrepotMemoire(); $d = new Depot($e);
        $b = $this->fiche(['titre' => 'Colloque annuel', 'debut' => '2026-11-12', 'url' => 'https://b.test/c', 'cle_source' => 'frq:9', 'niveau' => 'B', 'source' => 'FRQ', 'statut' => 'publie']);
        $this->assertSame('cree', $d->integrer($b));
        $e->mettre_a_jour(1, ['champs_manuels' => ['debut', 'url']]);
        $a = $this->fiche(['titre' => 'Colloque annuel', 'debut' => '2026-11-12', 'fin' => '2026-11-13', 'heure' => '08:30', 'url' => 'https://a.test/c', 'cle_source' => 'dhc:1', 'niveau' => 'A', 'source' => 'DHC']);
        $this->assertSame('fusionne', $d->integrer($a));
        $x = $e->trouver_par_cle_source('dhc:1');
        // Début et URL corrigés à la main gardés ; fin et heure, non protégées, viennent de la source plus fiable.
        $this->assertSame(['2026-11-12', 'https://b.test/c', '2026-11-13', '08:30', 'A'], [$x->debut, $x->url, $x->fin, $x->heure, $x->niveau]);
        $this->assertCount(1, $e->lister(''));
    }

    public function test_url_protegee_n_est_pas_comblee_par_une_source_moins_fiable(): void {
        $e = new EntrepotMemoire(); $d = new Depot($e);
        $a = $this->fiche(['titre' => 'Forum', 'debut' => '2026-11-12', 'url' => '', 'cle_source' => 'dhc:1', 'niveau' => 'A', 'statut' => 'publie']);
        $this->assertSame('cree', $d->integrer($a));
        // L'équipe a volontairement vidé l'URL : la source moins fiable ne la remet pas.
        $e->mettre_a_jour(1, ['champs_manuels' => ['url']]);
        $b = $this->fiche(['titre' => 'Forum', 'debut' => '2026-11-12', 'url' => 'https://b.test/f', 'image_url' => 'https://b.test/f.png', 'cle_source' => 'frq:2', 'niveau' => 'B']);
        $this->assertSame('fusionne', $d->integrer($b));
        $x = $e->trouver_par_cle_source('dhc:1');
        $this->assertSame(['', 'https://b.test/f.png'], [$x->url, $x->image_url]);
    }

    public function test_ville_protegee_ne_change_ni_la_ville_ni_la_region(): void {
        $e = new EntrepotMemoire(); $d = new Depot($e);
        $f = $this->fiche(['titre' => 'Congrès', 'debut' => '2026-11-12', 'ville' => 'Montréal', 'region' => 'Québec', 'cle_source' => 'dhc:1', 'niveau' => 'A', 'statut' => 'publie']);
        $this->assertSame('cree', $d->integrer($f));
        $e->mettre_a_jour(1, ['champs_manuels' => ['ville']]);
        $f2 = clone $f; $f2->ville = 'Toronto';
        $this->assertSame('inchange', $d->integrer($f2));
        $x = $e->trouver_par_cle_source('dhc:1');
        $this->assertSame(['Montréal', 'Québec'], [$x->ville, $x->region]);
    }

    public function test_region_refaite_seulement_des_champs_ecrits_quand_le_lieu_est_protege(): void {
        $e = new EntrepotMemoire(); $d = new Depot($e);
        $f = $this->fiche(['titre' => 'Congrès', 'debut' => '2026-11-12', 'ville' => 'Montréal', 'lieu' => 'Palais des congrès', 'region' => 'Québec', 'cle_source' => 'dhc:1', 'niveau' => 'A', 'statut' => 'publie']);
        $this->assertSame('cree', $d->integrer($f));
        $e->mettre_a_jour(1, ['champs_manuels' => ['lieu']]);
        // Le lieu protégé reste celui de la fiche ; la ville écrite sert au calcul avec ce lieu.
        $f2 = clone $f; $f2->lieu = 'Convention Center'; $f2->ville = 'Toronto';
        $this->assertSame('mis_a_jour', $d->integrer($f2));
        $x = $e->trouver_par_cle_source('dhc:1');
        $this->assertSame(['Toronto', 'Palais des congrès'], [$x->ville, $x->lieu]);
        $this->assertSame(Fiche::deviner_region('Toronto', 'Palais des congrès', ''), $x->region);
    }

    public function test_sans_champs_manuels_la_collecte_change_toujours_les_champs_factuels(): void {
        $e = new EntrepotMemoire(); $d = new Depot($e);
        $f = $this->fiche(['titre' => 'Atelier', 'debut' => '2026-11-12', 'url' => 'https://a.test/x', 'cle_source' => 'dhc:1', 'niveau' => 'A', 'statut' => 'publie']);
        $d->integrer($f);
        $f2 = clone $f; $f2->debut = '2026-12-01'; $f2->url = 'https://a.test/y';
        $this->assertSame('mis_a_jour', $d->integrer($f2));
        $x = $e->trouver_par_cle_source('dhc:1');
        $this->assertSame(['2026-12-01', 'https://a.test/y', []], [$x->debut, $x->url, $x->champs_manuels]);
    }

    public function test_liste_de_champs_proteges_publique(): void {
        $this->assertSame(['debut', 'fin', 'heure', 'echeance', 'url', 'lieu', 'ville'], Depot::CHAMPS_PROTEGEABLES);
    }
}

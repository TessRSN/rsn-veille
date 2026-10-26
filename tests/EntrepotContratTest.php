<?php
declare(strict_types=1);

require_once __DIR__ . '/bouchons-wordpress.php';

use PHPUnit\Framework\TestCase;
use RSN\Veille\Depot;
use RSN\Veille\Entrepot;
use RSN\Veille\EntrepotMemoire;
use RSN\Veille\EntrepotWordPress;
use RSN\Veille\Fiche;

/**
 * Contrat commun des entrepôts : les mêmes cas sur EntrepotMemoire et sur EntrepotWordPress
 * (celui-ci sur l'imitation de WordPress de tests/bouchons-wordpress.php, pas sur un vrai WordPress).
 */
final class EntrepotContratTest extends TestCase {
    protected function setUp(): void {
        rsn_veille_bouchons_reinitialiser();
    }

    public function entrepots(): array {
        return ['mémoire' => ['memoire'], 'WordPress' => ['wordpress']];
    }

    private static function entrepot(string $nom): Entrepot {
        return $nom === 'memoire' ? new EntrepotMemoire() : new EntrepotWordPress();
    }

    private static function fiche(array $champs = []): Fiche {
        return Fiche::depuis_tableau($champs + [
            'type' => 'evenement',
            'titre' => 'Atelier données',
            'debut' => '2026-11-20',
            'heure' => '13:30',
            'url' => 'https://exemple.ca/atelier?id=1',
            'source' => 'Exemple',
            'niveau' => 'A',
            'statut' => 'publie',
            'cle_source' => 'ex:1',
            'cles_source' => ['ex:1', 'autre:%C3%A9-1'],
            'titres_source' => ['ex:1' => 'Atelier données'],
            'themes' => ['Sommeil', 'IA'],
        ]);
    }

    /** @dataProvider entrepots */
    public function test_creer_puis_trouver_par_cle_source(string $nom): void {
        $e = self::entrepot($nom);
        $id = $e->creer(self::fiche());
        $trouvee = $e->trouver_par_cle_source('autre:%C3%A9-1');
        $this->assertNotNull($trouvee);
        $this->assertSame($id, $trouvee->id);
        $this->assertSame('Atelier données', $trouvee->titre);
        $this->assertSame('publie', $trouvee->statut);
        $this->assertSame(['ex:1', 'autre:%C3%A9-1'], $trouvee->cles_source);
        $this->assertSame(['Sommeil', 'IA'], $trouvee->themes);
        $this->assertSame('13:30', $trouvee->heure);
        $this->assertSame($id, $e->trouver_par_cle_source('ex:1')->id);
        $this->assertNull($e->trouver_par_cle_source('EX:1'));
        $this->assertNull($e->trouver_par_cle_source('ex:2'));
        $this->assertNull($e->trouver_par_cle_source(''));
    }

    /** @dataProvider entrepots */
    public function test_trouver_par_dedoublonnage_et_par_url(string $nom): void {
        $e = self::entrepot($nom);
        $f = self::fiche();
        $id = $e->creer($f);
        $this->assertSame($id, $e->trouver_par_dedoublonnage($f->cle_dedoublonnage())->id);
        $this->assertNull($e->trouver_par_dedoublonnage('2026-11-21|atelierdonnees'));
        $this->assertSame($id, $e->trouver_par_url('https://exemple.ca/atelier?id=1')->id);
        $this->assertNull($e->trouver_par_url('https://exemple.ca/atelier?id=2'));
        $this->assertNull($e->trouver_par_url(''));
    }

    /** @dataProvider entrepots */
    public function test_la_plus_ancienne_fiche_est_rendue_et_c_est_une_copie(string $nom): void {
        $e = self::entrepot($nom);
        $premiere = $e->creer(self::fiche());
        $e->creer(self::fiche(['cle_source' => 'ex:2', 'cles_source' => ['ex:2']]));
        $trouvee = $e->trouver_par_url('https://exemple.ca/atelier?id=1');
        $this->assertSame($premiere, $trouvee->id);
        $trouvee->titre = 'Modifiée hors entrepôt';
        $this->assertSame('Atelier données', $e->trouver_par_cle_source('ex:1')->titre);
    }

    /** @dataProvider entrepots */
    public function test_mise_a_jour_partielle(string $nom): void {
        $e = self::entrepot($nom);
        $id = $e->creer(self::fiche());
        $e->mettre_a_jour($id, [
            'titre' => 'Atelier données ouvertes',
            'statut' => 'a_approuver',
            'fin' => '2026-11-21',
            'cles_source' => ['ex:1', 'nouvelle:9'],
            'themes' => ['Données'],
        ]);
        $f = $e->trouver_par_cle_source('nouvelle:9');
        $this->assertSame($id, $f->id);
        $this->assertSame('Atelier données ouvertes', $f->titre);
        $this->assertSame('a_approuver', $f->statut);
        $this->assertSame('2026-11-21', $f->fin);
        $this->assertSame('2026-11-20', $f->debut);
        $this->assertSame(['Données'], $f->themes);
        $this->assertSame('https://exemple.ca/atelier?id=1', $f->url);
        $this->assertNull($e->trouver_par_cle_source('autre:%C3%A9-1'));
        // Clé de dédoublonnage suivie après le changement de titre.
        $this->assertSame($id, $e->trouver_par_dedoublonnage($f->cle_dedoublonnage())->id);
        $this->assertNull($e->trouver_par_dedoublonnage(self::fiche()->cle_dedoublonnage()));
        $this->assertSame([], $e->lister('publie'));
    }

    /** @dataProvider entrepots */
    public function test_champ_inconnu_ou_id_refuses(string $nom): void {
        $e = self::entrepot($nom);
        $id = $e->creer(self::fiche());
        foreach (['inconnu' => 'x', 'id' => 99] as $champ => $valeur) {
            try {
                $e->mettre_a_jour($id, [$champ => $valeur]);
                $this->fail('Champ accepté : ' . $champ);
            } catch (\InvalidArgumentException $ex) {
                $this->assertStringContainsString($champ, $ex->getMessage());
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $e->mettre_a_jour($id + 100, ['fin' => '2026-12-01']);
    }

    /** @dataProvider entrepots */
    public function test_lister_par_statut_et_type_avec_id(string $nom): void {
        $e = self::entrepot($nom);
        $a = $e->creer(self::fiche());
        $b = $e->creer(self::fiche(['type' => 'appel', 'cle_source' => 'ex:2', 'cles_source' => ['ex:2'], 'themes' => ['Financement']]));
        $c = $e->creer(self::fiche(['statut' => 'ecarte', 'cle_source' => 'ex:3', 'cles_source' => ['ex:3']]));

        $this->assertSame([$a, $b], array_map(static fn(Fiche $f): ?int => $f->id, $e->lister()));
        $this->assertSame([$a, $b, $c], array_map(static fn(Fiche $f): ?int => $f->id, $e->lister('')));
        $this->assertSame([$c], array_map(static fn(Fiche $f): ?int => $f->id, $e->lister('ecarte')));
        $appels = $e->lister('publie', 'appel');
        $this->assertCount(1, $appels);
        $this->assertSame($b, $appels[0]->id);
        $this->assertSame(['ex:2'], $appels[0]->cles_source);
        $this->assertSame(['Financement'], $appels[0]->themes);
        $this->assertSame(['ex:1' => 'Atelier données'], $appels[0]->titres_source);
        $this->assertSame([], $e->lister('inconnu'));
    }

    /** @dataProvider entrepots */
    public function test_ids_publies_de_source_par_prefixe_exact(string $nom): void {
        $e = self::entrepot($nom);
        $a = $e->creer(self::fiche(['cle_source' => 'verif:1', 'cles_source' => ['verif:1']]));
        $e->creer(self::fiche(['statut' => 'a_approuver', 'cle_source' => 'verif:2', 'cles_source' => ['verif:2']]));
        $c = $e->creer(self::fiche(['cle_source' => 'x:1', 'cles_source' => ['x:1', 'verif:3']]));
        $this->assertSame([$a, $c], $e->ids_publies_de_source('verif'));
        // « erif: » est contenu dans « verif:… » sans en être le début.
        $this->assertSame([], $e->ids_publies_de_source('erif'));
    }

    /** @dataProvider entrepots */
    public function test_marquer_et_lever_absent(string $nom): void {
        $e = self::entrepot($nom);
        $id = $e->creer(self::fiche());
        $e->marquer_absent($id, '2026-10-25');
        $this->assertSame('2026-10-25', $e->trouver_par_cle_source('ex:1')->absent_depuis);
        $e->lever_absent($id);
        $this->assertNull($e->trouver_par_cle_source('ex:1')->absent_depuis);
    }

    /** @dataProvider entrepots */
    public function test_titre_source_avec_chevron_et_pourcent_survit_a_l_aller_retour(string $nom): void {
        $e = self::entrepot($nom);
        $titre = 'IA < 5 ans : 100%20 en ligne';
        $f = self::fiche(['titre' => $titre, 'titres_source' => ['ex:1' => $titre]]);
        $e->creer($f);
        $this->assertSame(['ex:1' => $titre], $e->trouver_par_cle_source('ex:1')->titres_source);

        // Collecte suivante identique : rien à écrire.
        $entrante = clone $f;
        $entrante->titres_source = [];
        $this->assertSame('inchange', (new Depot($e))->integrer($entrante));
    }

    public function test_wordpress_listes_renumerotees_et_cles_de_titres_gardees_a_la_lecture(): void {
        $e = new EntrepotWordPress();
        $id = $e->creer(self::fiche(['cles_source' => [3 => 'ex:2'], 'themes' => [5 => 'Financement']]));
        // Méta écrite à trous, comme pourrait le faire une autre extension.
        $GLOBALS['rsn_veille_wp']['meta'][$id]['rsn_veille_cles_source'] = [serialize([3 => 'ex:2', 8 => 'ex:9'])];
        $f = $e->lister()[0];
        $this->assertSame($id, $f->id);
        $this->assertSame(['ex:2', 'ex:9'], $f->cles_source);
        $this->assertSame(['Financement'], $f->themes);
        $this->assertSame(['ex:1' => 'Atelier données'], $f->titres_source);
        $this->assertSame($id, $e->trouver_par_cle_source('ex:2')->id);
    }

    public function test_wordpress_echec_des_themes_laisse_une_fiche_retrouvable(): void {
        $e = new EntrepotWordPress();
        unset($GLOBALS['rsn_veille_wp']['taxonomies']['rsn_theme']);
        try {
            $e->creer(self::fiche());
            $this->fail('Échec des thèmes non signalé.');
        } catch (\RuntimeException $ex) {
            $this->assertStringContainsString('Thèmes', $ex->getMessage());
        }
        $f = self::fiche();
        $this->assertNotNull($e->trouver_par_cle_source('ex:1'));
        $this->assertNotNull($e->trouver_par_dedoublonnage($f->cle_dedoublonnage()));
    }

    public function test_wordpress_post_sans_meta_garde_les_valeurs_par_defaut(): void {
        $id = wp_insert_post(['post_type' => 'rsn_veille', 'post_status' => 'pending', 'post_title' => 'Créée dans l’administration']);
        $f = (new EntrepotWordPress())->obtenir($id);
        $this->assertSame($id, $f->id);
        $this->assertSame('a_approuver', $f->statut);
        $this->assertSame('fr', $f->langue);
        $this->assertSame(0, $f->absences);
        $this->assertFalse($f->manuel);
        $this->assertNull($f->debut);
        $this->assertSame([], $f->cles_source);
        $this->assertSame([], $f->titres_source);
        $this->assertSame([], $f->themes);
    }

    public function test_wordpress_fiche_publiee_a_date_future_reste_publiee(): void {
        $id = (new EntrepotWordPress())->creer(self::fiche(['debut' => '2099-01-15', 'heure' => '9:05']));
        $this->assertSame('publish', get_post_status($id));
        $this->assertSame('2099-01-15 09:05:00', get_post_field('post_date', $id));
    }

    public function test_wordpress_approbation_garde_la_date_de_debut(): void {
        $e = new EntrepotWordPress();
        $id = $e->creer(self::fiche(['statut' => 'a_approuver']));
        $e->mettre_a_jour($id, ['statut' => 'publie']);
        $this->assertSame('2026-11-20 13:30:00', get_post_field('post_date', $id));
        $this->assertSame('publie', $e->obtenir($id)->statut);
    }

    public function test_wordpress_texte_brut_malgre_les_entites_de_kses(): void {
        $e = new EntrepotWordPress();
        $id = $e->creer(self::fiche(['titre' => 'Santé & IA', 'resume' => 'Données & modèles', 'themes' => ['Santé & IA']]));
        $this->assertSame('Santé &amp; IA', get_post_field('post_title', $id));
        $f = $e->obtenir($id);
        $this->assertSame('Santé & IA', $f->titre);
        $this->assertSame('Données & modèles', $f->resume);
        $this->assertSame(['Santé & IA'], $f->themes);
    }
}

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
    public function test_creer_pose_le_jour_de_creation_sans_l_ecraser(string $nom): void {
        $GLOBALS['rsn_veille_test_aujourdhui'] = '2026-10-26';
        $e = self::entrepot($nom);
        $e->creer(self::fiche());
        $e->creer(self::fiche(['cle_source' => 'ex:2', 'cles_source' => ['ex:2'], 'titre' => 'Autre atelier', 'cree_le' => '2026-01-02']));
        [$nouvelle, $ancienne] = $e->lister('');
        $this->assertSame('2026-10-26', $nouvelle->cree_le);
        $this->assertSame('2026-01-02', $ancienne->cree_le);

        // Une mise à jour des champs factuels ne change pas le jour de création.
        $GLOBALS['rsn_veille_test_aujourdhui'] = '2026-11-02';
        $e->mettre_a_jour((int) $nouvelle->id, ['debut' => '2026-11-21']);
        $this->assertSame('2026-10-26', $e->lister('')[0]->cree_le);
        $GLOBALS['rsn_veille_test_aujourdhui'] = '2026-10-25';
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
        // Sans jour de création enregistré : le jour du post (âge d'un appel sans date) ; la méta reste absente.
        $this->assertSame(substr(get_post($id)->post_date, 0, 10), $f->cree_le);
        $this->assertFalse(metadata_exists('post', $id, 'rsn_veille_cree_le'));
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

    /** @dataProvider entrepots */
    public function test_une_proposition_n_est_jamais_rendue_par_le_dedoublonnage(string $nom): void {
        $e = self::entrepot($nom);
        $proposition = self::fiche(['source' => 'proposition', 'niveau' => 'M', 'statut' => 'a_approuver', 'cle_source' => 'proposition:1', 'cles_source' => ['proposition:1']]);
        $id = $e->creer($proposition);
        $this->assertNull($e->trouver_par_dedoublonnage($proposition->cle_dedoublonnage()));
        $this->assertNull($e->trouver_par_url('https://exemple.ca/atelier?id=1'));
        // La fiche reste retrouvable par sa clé de source.
        $this->assertSame($id, $e->trouver_par_cle_source('proposition:1')->id);
        // Une fiche collectée ensuite (identifiant plus grand) est, elle, rendue, par date-titre et par URL.
        $collectee = $e->creer(self::fiche(['cle_source' => 'ex:2', 'cles_source' => ['ex:2']]));
        $this->assertSame($collectee, $e->trouver_par_dedoublonnage($proposition->cle_dedoublonnage())->id);
        $this->assertSame($collectee, $e->trouver_par_url('https://exemple.ca/atelier?id=1')->id);
    }

    /** @dataProvider entrepots */
    public function test_cinquante_propositions_a_la_meme_url_n_empechent_pas_de_trouver_la_fiche_collectee(string $nom): void {
        $e = self::entrepot($nom);
        for ($i = 1; $i <= 50; $i++) {
            $e->creer(self::fiche(['titre' => 'Proposition ' . $i, 'source' => 'proposition', 'niveau' => 'M', 'statut' => $i % 2 ? 'a_approuver' : 'ecarte',
                'cle_source' => 'proposition:' . $i, 'cles_source' => ['proposition:' . $i]]));
        }
        $this->assertNull($e->trouver_par_url('https://exemple.ca/atelier?id=1'));
        $collectee = $e->creer(self::fiche(['cle_source' => 'ex:9', 'cles_source' => ['ex:9']]));
        $this->assertSame($collectee, $e->trouver_par_url('https://exemple.ca/atelier?id=1')->id);
        if ($nom === 'wordpress') {
            // Une seule requête bornée à une fiche, URL et « source ≠ proposition » dans le même meta_query.
            $requete = end($GLOBALS['rsn_veille_wp']['requetes']);
            $this->assertSame(1, $requete['posts_per_page']);
            $this->assertSame('AND', $requete['meta_query']['relation']);
            $this->assertSame('OR', $requete['meta_query'][1]['relation']);
        }
    }

    public function test_wordpress_fiche_sans_meta_source_trouvee_par_url(): void {
        $e = new EntrepotWordPress();
        $id = $e->creer(self::fiche());
        delete_post_meta($id, 'rsn_veille_source');
        $this->assertSame($id, $e->trouver_par_url('https://exemple.ca/atelier?id=1')->id);
    }
    /** Identifiants des fiches, dans l'ordre rendu. */
    private static function ids(array $fiches): array {
        return array_map(static fn(Fiche $f): ?int => $f->id, $fiches);
    }

    /** @dataProvider entrepots */
    public function test_obtenir_et_lister_par_ids(string $nom): void {
        $e = self::entrepot($nom);
        $a = $e->creer(self::fiche());
        $b = $e->creer(self::fiche(['statut' => 'ecarte', 'cle_source' => 'ex:2', 'cles_source' => ['ex:2'], 'themes' => ['Financement']]));
        $c = $e->creer(self::fiche(['cle_source' => 'ex:3', 'cles_source' => ['ex:3']]));

        $this->assertSame($b, $e->obtenir($b)->id);
        $this->assertSame('ecarte', $e->obtenir($b)->statut);
        $this->assertNull($e->obtenir(999));
        // Ordre des identifiants (croissant), tous statuts, sans les inconnus ; liste vide : aucune fiche.
        $this->assertSame([$a, $c], self::ids($e->lister_par_ids([$c, 999, $a])));
        $par_ids = $e->lister_par_ids([$b]);
        $this->assertSame(['Financement'], $par_ids[0]->themes);
        $this->assertSame(['ex:2'], $par_ids[0]->cles_source);
        $this->assertSame([], $e->lister_par_ids([]));
        if ($nom === 'wordpress') {
            $requete = end($GLOBALS['rsn_veille_wp']['requetes']);
            $this->assertSame([$b], $requete['post__in']);
            $this->assertSame(1, $requete['posts_per_page']);
        }
    }

    /** @dataProvider entrepots */
    public function test_compter_par_onglet_sans_charger_les_fiches(string $nom): void {
        $e = self::entrepot($nom);
        $e->creer(self::fiche());
        $absente = $e->creer(self::fiche(['cle_source' => 'ex:2', 'cles_source' => ['ex:2']]));
        $e->marquer_absent($absente, '2026-10-20');
        $e->creer(self::fiche(['statut' => 'a_approuver', 'cle_source' => 'ex:3', 'cles_source' => ['ex:3']]));
        $e->creer(self::fiche(['statut' => 'ecarte', 'cle_source' => 'ex:4', 'cles_source' => ['ex:4']]));
        $e->creer(self::fiche(['statut' => 'ecarte', 'cle_source' => 'ex:5', 'cles_source' => ['ex:5'], 'absent_depuis' => '2026-10-01']));

        $this->assertSame(['a_approuver' => 1, 'publie' => 2, 'ecarte' => 2, 'a_verifier' => 1], $e->compter());
        $this->assertSame([$absente], self::ids($e->lister_absentes()));
    }

    /** @dataProvider entrepots */
    public function test_lister_crees_depuis_par_meta_sans_les_fiches_sans_jour_de_creation(string $nom): void {
        $e = self::entrepot($nom);
        $recente = $e->creer(self::fiche(['cree_le' => '2026-10-20']));
        $e->creer(self::fiche(['cle_source' => 'ex:2', 'cles_source' => ['ex:2'], 'cree_le' => '2026-10-18']));
        $attente = $e->creer(self::fiche(['statut' => 'a_approuver', 'cle_source' => 'ex:3', 'cles_source' => ['ex:3'], 'cree_le' => '2026-10-19']));
        $limite = $e->creer(self::fiche(['cle_source' => 'ex:4', 'cles_source' => ['ex:4'], 'cree_le' => '2026-10-19']));

        $this->assertSame([$recente, $limite], self::ids($e->lister_crees_depuis('2026-10-19', 'publie')));
        $this->assertSame([$attente], self::ids($e->lister_crees_depuis('2026-10-19', 'a_approuver')));
        $this->assertSame([$recente, $attente, $limite], self::ids($e->lister_crees_depuis('2026-10-19')));
        if ($nom === 'wordpress') {
            delete_post_meta($recente, 'rsn_veille_cree_le');
            $this->assertSame([$limite], self::ids($e->lister_crees_depuis('2026-10-19', 'publie')));
            $requete = end($GLOBALS['rsn_veille_wp']['requetes']);
            $this->assertSame(['key' => 'rsn_veille_cree_le', 'value' => '2026-10-19', 'compare' => '>=', 'type' => 'CHAR'], $requete['meta_query'][0]);
        }
    }

    /** @dataProvider entrepots */
    public function test_lister_recentes_plafonne_les_plus_recentes_d_abord(string $nom): void {
        $e = self::entrepot($nom);
        $ids = [];
        for ($i = 1; $i <= 4; $i++) {
            $ids[] = $e->creer(self::fiche(['statut' => 'ecarte', 'type' => $i === 2 ? 'appel' : 'evenement', 'cle_source' => 'ex:' . $i, 'cles_source' => ['ex:' . $i]]));
        }
        $e->creer(self::fiche(['cle_source' => 'ex:9', 'cles_source' => ['ex:9']]));

        $this->assertSame([$ids[3], $ids[2]], self::ids($e->lister_recentes('ecarte', 2)));
        $this->assertSame([$ids[3], $ids[2], $ids[0]], self::ids($e->lister_recentes('ecarte', 5, 'evenement')));
        $this->assertSame([], $e->lister_recentes('inconnu', 5));
    }

    /** @dataProvider entrepots */
    public function test_lister_pour_page_borne_les_evenements_anciens_et_les_appels_perimes(string $nom): void {
        $e = self::entrepot($nom);
        $ev = static fn(string $cle, array $c): Fiche => self::fiche($c + ['cle_source' => $cle, 'cles_source' => [$cle]]);
        $ap = static fn(string $cle, array $c): Fiche => self::fiche($c + ['type' => 'appel', 'debut' => null, 'fin' => null, 'cle_source' => $cle, 'cles_source' => [$cle]]);
        $garde = [
            $e->creer($ev('e:1', ['debut' => '2025-11-01'])),
            $e->creer($ev('e:2', ['debut' => '2024-01-01', 'fin' => '2025-12-01'])),
            $e->creer($ap('a:1', ['echeance' => '2026-10-25', 'cree_le' => '2026-01-01'])),
            $e->creer($ap('a:2', ['debut' => '2026-01-01', 'fin' => '2026-11-01', 'cree_le' => '2026-01-01'])),
            $e->creer($ap('a:3', ['debut' => '2026-08-01', 'cree_le' => '2026-01-01'])),
            $e->creer($ap('a:4', ['cree_le' => '2026-08-01'])),
        ];
        $e->creer($ev('e:3', ['debut' => '2025-09-01', 'fin' => '2025-09-02']));
        $e->creer($ev('e:4', ['debut' => '2026-11-01', 'statut' => 'a_approuver']));
        $e->creer($ap('a:5', ['debut' => '2026-01-01', 'echeance' => '2026-03-01', 'fin' => '2026-03-01', 'cree_le' => '2026-01-01']));
        $e->creer($ap('a:6', ['debut' => '2026-05-01', 'fin' => '2026-05-01', 'cree_le' => '2026-05-01']));
        $e->creer(self::fiche(['type' => 'nouvelle', 'cle_source' => 'n:1', 'cles_source' => ['n:1']]));

        // Sur-ensemble : la règle exacte des appels est appliquée ensuite par Donnees.
        $this->assertSame($garde, self::ids($e->lister_pour_page('2025-10-25', '2026-10-25', '2026-07-27')));
    }
    public function test_wordpress_fiche_a_la_corbeille_trouvee_par_sa_cle_et_jamais_recreee(): void {
        $e = new EntrepotWordPress();
        $id = $e->creer(self::fiche());
        // « Déplacer dans la corbeille » depuis l'éditeur de WordPress.
        wp_update_post(['ID' => $id, 'post_status' => 'trash']);

        $trouvee = $e->trouver_par_cle_source('ex:1');
        $this->assertSame($id, $trouvee->id);
        $this->assertSame('ecarte', $trouvee->statut, 'une fiche à la corbeille compte comme écartée');
        $this->assertSame($id, $e->trouver_par_dedoublonnage(self::fiche()->cle_dedoublonnage())->id);
        $this->assertSame($id, $e->trouver_par_url('https://exemple.ca/atelier?id=1')->id);

        // Collecte suivante : la fiche n'est ni recréée ni republiée.
        $d = new Depot($e);
        $this->assertTrue($d->existe(self::fiche()));
        $this->assertContains($d->integrer(self::fiche(['debut' => '2026-11-21'])), ['mis_a_jour', 'inchange']);
        $this->assertSame('trash', get_post_status($id));
        $this->assertCount(1, $GLOBALS['rsn_veille_wp']['posts']);
        // Ni listée ni comptée.
        $this->assertSame([], $e->lister(''));
        $this->assertSame(['a_approuver' => 0, 'publie' => 0, 'ecarte' => 0, 'a_verifier' => 0], $e->compter());
    }
}

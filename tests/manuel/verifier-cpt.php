<?php
/**
 * Vérification manuelle du type de contenu et de l'entrepôt WordPress, dans un WordPress réel :
 *
 *   npx wp-env run cli wp eval-file wp-content/plugins/rsn-veille/tests/manuel/verifier-cpt.php
 *
 * Crée une fiche de test, la retrouve, la modifie, la marque absente, puis la supprime.
 * Attendu : cinq lignes « OK ».
 */

use RSN\Veille\EntrepotWordPress;
use RSN\Veille\Fiche;
use RSN\Veille\TypeContenu;

if (!defined('ABSPATH')) {
    exit;
}

/** Affiche « OK », ou lève une exception (la fiche de test est supprimée avant l'arrêt). */
function rsn_veille_verifier(bool $condition, string $etape): void {
    if (!$condition) {
        throw new RuntimeException($etape);
    }
    echo 'OK : ' . $etape . PHP_EOL;
}

if (!post_type_exists(TypeContenu::TYPE) || !taxonomy_exists(TypeContenu::TAXONOMIE)) {
    echo 'ÉCHEC : type de contenu ou taxonomie non enregistrés (extension active ?)' . PHP_EOL;
    exit(1);
}

$entrepot = new EntrepotWordPress();
$jeton = 'verif-' . wp_generate_password(8, false);

$f = new Fiche();
$f->type = 'evenement';
$f->titre = 'Atelier de vérification : santé & IA ' . $jeton;
$f->debut = gmdate('Y-m-d', time() + 30 * DAY_IN_SECONDS);
$f->heure = '13:30';
$f->resume = 'Fiche créée par tests/manuel/verifier-cpt.php.';
$f->url = 'https://exemple.rsn.quebec/' . $jeton . '/Page?ID=42';
$f->source = 'Vérification';
$f->niveau = 'A';
$f->statut = 'publie';
$f->cle_source = 'verif:' . $jeton;
$f->cles_source = ['verif:' . $jeton, 'autre:%C3%A9-' . $jeton];
$f->titres_source = ['verif:' . $jeton => $f->titre];
$f->themes = ['Sommeil', 'IA'];
$f->manuel = true;

$id = $entrepot->creer($f);
$echec = null;

try {
    $par_cle = $entrepot->trouver_par_cle_source('autre:%C3%A9-' . $jeton);
    rsn_veille_verifier(
        $par_cle !== null && $par_cle->id === $id
            && get_post_status($id) === 'publish'
            && $par_cle->statut === 'publie'
            && $par_cle->themes === ['Sommeil', 'IA']
            && $par_cle->cles_source === $f->cles_source
            && $par_cle->titres_source === $f->titres_source
            && $par_cle->manuel === true
            && $par_cle->titre === $f->titre
            && get_post_field('post_date', $id) === $f->debut . ' 13:30:00'
            && $entrepot->trouver_par_cle_source('AUTRE:%c3%a9-' . $jeton) === null,
        'trouvée par clé de source (publiée malgré une date future, thèmes ordonnés, tableaux intacts)'
    );

    $par_cle_dedoublonnage = $entrepot->trouver_par_dedoublonnage($f->cle_dedoublonnage());
    rsn_veille_verifier($par_cle_dedoublonnage !== null && $par_cle_dedoublonnage->id === $id, 'trouvée par clé de dédoublonnage');

    $par_url = $entrepot->trouver_par_url($f->url);
    rsn_veille_verifier(
        $par_url !== null && $par_url->id === $id && $entrepot->trouver_par_url('') === null,
        'trouvée par URL'
    );

    $fin = gmdate('Y-m-d', time() + 31 * DAY_IN_SECONDS);
    $entrepot->mettre_a_jour($id, ['fin' => $fin, 'absences' => 1]);
    $modifiee = $entrepot->obtenir($id);
    rsn_veille_verifier(
        $modifiee !== null && $modifiee->fin === $fin && $modifiee->absences === 1
            && in_array($id, $entrepot->ids_publies_de_source('verif'), true)
            && !in_array($id, $entrepot->ids_publies_de_source('erif'), true),
        'fin mise à jour'
    );

    $entrepot->marquer_absent($id, wp_date('Y-m-d'));
    $absente = $entrepot->obtenir($id);
    $entrepot->lever_absent($id);
    $revue = $entrepot->obtenir($id);
    rsn_veille_verifier(
        $absente !== null && $absente->absent_depuis === wp_date('Y-m-d')
            && $revue !== null && $revue->absent_depuis === null,
        'marquée absente (puis drapeau levé)'
    );
} catch (Throwable $e) {
    $echec = $e->getMessage();
} finally {
    wp_delete_post($id, true);
}

if ($echec !== null) {
    if (class_exists('WP_CLI')) {
        WP_CLI::error('ÉCHEC : ' . $echec);
    }
    echo 'ÉCHEC : ' . $echec . PHP_EOL;
    exit(1);
}

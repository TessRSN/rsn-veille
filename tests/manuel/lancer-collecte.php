<?php
/**
 * Collecte complète manuelle, dans un WordPress réel (vrais téléchargements, vraies fiches) :
 *
 *   npx wp-env run cli wp eval-file wp-content/plugins/rsn-veille/tests/manuel/lancer-collecte.php
 *
 * Met les sources en file puis les traite toutes en boucle (sans attendre WP-Cron), et affiche la
 * dernière entrée du journal de chaque source. Les événements `rsn_veille_source` sont retirés avant
 * la boucle (WP-Cron ne traite rien en parallèle) et après (pas de collecte relancée derrière).
 * Un verrou tenu par une autre exécution arrête la boucle (il se périme seul après 10 minutes).
 *
 * Attendu : une ligne par source (lus, créés, fusionnés, mis à jour, inchangés, écartés, annulés, ignorés,
 * en attente, durée), les erreurs en dessous ; puis le nombre de fiches en attente et publiées (> 60).
 */

use RSN\Veille\Collecte;
use RSN\Veille\Journal;
use RSN\Veille\TypeContenu;

if (!defined('ABSPATH')) {
    exit;
}

Collecte::mettre_en_file();
$file = get_option(Collecte::OPTION_FILE, []);
echo 'Sources en file : ' . count($file) . ' (' . implode(', ', $file) . ')' . PHP_EOL . PHP_EOL;

// Les sources sont traitées ici : WP-Cron ne doit pas en traiter en parallèle.
wp_clear_scheduled_hook(Collecte::CROCHET_SOURCE);
$tours = 0;
while (get_option(Collecte::OPTION_FILE, []) !== [] && $tours++ < 50) {
    $avant = get_option(Collecte::OPTION_FILE, []);
    Collecte::traiter_une_source();
    // traiter_une_source() planifie la suivante : on la retire pour que WP-Cron ne la lance pas en parallèle.
    wp_clear_scheduled_hook(Collecte::CROCHET_SOURCE);
    if (get_option(Collecte::OPTION_FILE, []) === $avant) {
        echo 'Verrou tenu par une autre exécution (option ' . Collecte::VERROU . ') : arrêt.' . PHP_EOL;
        break;
    }
}
wp_clear_scheduled_hook(Collecte::CROCHET_SOURCE);

foreach ($file as $id) {
    $l = Journal::derniere($id);
    if ($l === null) {
        echo str_pad($id, 20) . 'aucune entrée de journal' . PHP_EOL;
        continue;
    }
    printf(
        "%s lus %3d | créés %3d | fusionnés %2d | mis à jour %2d | inchangés %3d | écartés %2d | annulés %d | ignorés %3d | en attente %2d | %5d ms%s",
        str_pad($id, 20),
        $l['lus'], $l['crees'], $l['fusionnes'], $l['mis_a_jour'], $l['inchanges'], $l['ecartes'], $l['annules'], $l['ignores'], $l['en_attente'], $l['duree_ms'],
        PHP_EOL
    );
    foreach ($l['erreurs'] as $erreur) {
        echo str_repeat(' ', 20) . '! ' . $erreur . PHP_EOL;
    }
}

$compte = wp_count_posts(TypeContenu::TYPE);
echo PHP_EOL . 'Fiches : ' . (int) ($compte->pending ?? 0) . ' à approuver, ' . (int) ($compte->publish ?? 0) . ' publiées.' . PHP_EOL;
echo 'Dernière collecte : ' . wp_date('Y-m-d H:i', (int) get_option(Collecte::OPTION_DERNIERE)) . PHP_EOL;

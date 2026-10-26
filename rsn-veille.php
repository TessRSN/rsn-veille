<?php
/**
 * Plugin Name: RSN : veille de l'écosystème
 * Description: Collecte et présente la veille de l'écosystème de la santé numérique au Québec.
 * Version: 0.1.0
 * Author: Réseau santé numérique
 * Requires PHP: 8.0
 * Text Domain: rsn-veille
 */

if (!defined('ABSPATH')) {
    exit;
}

define('RSN_VEILLE_VERSION', '0.1.0');
define('RSN_VEILLE_DOSSIER', __DIR__);
define('RSN_VEILLE_URL', function_exists('plugin_dir_url') ? plugin_dir_url(__FILE__) : '');

// Autochargeur PSR-4 maison : RSN\Veille\Lecteurs\LecteurTec -> includes/Lecteurs/LecteurTec.php
spl_autoload_register(static function (string $classe): void {
    $prefixe = 'RSN\\Veille\\';
    if (strncmp($classe, $prefixe, strlen($prefixe)) !== 0) {
        return;
    }
    $relatif = substr($classe, strlen($prefixe));
    $fichier = RSN_VEILLE_DOSSIER . '/includes/' . str_replace('\\', '/', $relatif) . '.php';
    if (is_file($fichier)) {
        require $fichier;
    }
});

if (function_exists('add_action')) {
    add_action('init', [\RSN\Veille\TypeContenu::class, 'enregistrer']);
}

/**
 * Date du jour (Y-m-d), en heure de Montréal selon le fuseau du site.
 */
function rsn_veille_aujourdhui(): string {
    return wp_date('Y-m-d');
}

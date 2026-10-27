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
    // Collecte nocturne (rsn_veille_nuit, rsn_veille_source) et purge de la page Veille à chaque écriture de fiche.
    \RSN\Veille\Collecte::accrocher();
    // Courriel hebdomadaire optionnel (rsn_veille_hebdo) : envoi, texte alternatif et planification selon les réglages.
    \RSN\Veille\Courriel::accrocher();
    // Menu Veille de wp-admin, capacité rsn_veille_gerer, ressources et action AJAX de la file d'approbation.
    \RSN\Veille\Admin\Menu::accrocher();
    // Page publique : raccourci [rsn_veille], ressources seulement si la page le contient, cache du JSON.
    \RSN\Veille\Public\Raccourci::accrocher();
    // Formulaire « Proposer un événement » : actions AJAX rsn_veille_proposer et rsn_veille_jeton, avec et sans connexion.
    \RSN\Veille\Public\Formulaire::accrocher();
    // Sorties publiques : /wp-json/rsn/v1/veille et /veille.ics.
    \RSN\Veille\Public\Rest::accrocher();
}

if (function_exists('register_activation_hook')) {
    // Activation : collecte quotidienne à l'heure des réglages (03:00 à Montréal par défaut) ; désactivation : tout déplanifier.
    register_activation_hook(__FILE__, [\RSN\Veille\Collecte::class, 'planifier_nuit']);
    // Activation : capacité rsn_veille_gerer donnée aux rôles des réglages (administrateur et éditeur par défaut).
    register_activation_hook(__FILE__, [\RSN\Veille\Admin\Menu::class, 'synchroniser_roles']);
    // Activation : règle de réécriture de /veille.ics ajoutée puis règles régénérées ; désactivation : retirée.
    register_activation_hook(__FILE__, [\RSN\Veille\Public\Rest::class, 'activer']);
    // Activation : courriel hebdomadaire planifié s'il est actif dans les réglages ; désactivation : retiré.
    register_activation_hook(__FILE__, [\RSN\Veille\Courriel::class, 'planifier']);
    register_deactivation_hook(__FILE__, [\RSN\Veille\Collecte::class, 'deplanifier']);
    register_deactivation_hook(__FILE__, [\RSN\Veille\Courriel::class, 'deplanifier']);
    register_deactivation_hook(__FILE__, [\RSN\Veille\Public\Rest::class, 'desactiver']);
}

/**
 * Date du jour (Y-m-d), en heure de Montréal selon le fuseau du site.
 */
function rsn_veille_aujourdhui(): string {
    return wp_date('Y-m-d');
}

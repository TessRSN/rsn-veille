<?php
/**
 * Désinstallation de l'extension « RSN : veille de l'écosystème » (suppression depuis wp-admin).
 *
 * Supprime les options et les transients `rsn_veille_*`, déplanifie les trois tâches et retire la
 * capacité `rsn_veille_gerer` de tous les rôles.
 *
 * Ne supprime PAS les fiches `rsn_veille` ni la taxonomie « Thème » : ce sont des données de l'équipe.
 * Pour les supprimer, voir « Désinstallation » dans docs/installation-et-bascule.md.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/** Nettoie le site courant (appelé une fois, ou une fois par site d'un réseau). */
function rsn_veille_desinstaller_site(): void {
    global $wpdb;

    // Tâches planifiées (les classes de l'extension ne sont pas chargées ici : noms en dur).
    foreach (['rsn_veille_nuit', 'rsn_veille_source', 'rsn_veille_hebdo'] as $crochet) {
        wp_clear_scheduled_hook($crochet);
    }

    // Capacité de gestion de la veille, retirée de tous les rôles.
    foreach (wp_roles()->role_objects as $role) {
        if ($role->has_cap('rsn_veille_gerer')) {
            $role->remove_cap('rsn_veille_gerer');
        }
    }

    // Options (réglages, file, journal, nouvelles gardées et ignorées, verrous, dernier courriel…) et
    // transients (JSON et iCal par langue, radar, débit du formulaire…) : tous préfixés rsn_veille_.
    // Les transients sont des options dans la base (`_transient_…` et `_transient_timeout_…`).
    $motifs = ['rsn\_veille\_%', '\_transient\_rsn\_veille\_%', '\_transient\_timeout\_rsn\_veille\_%'];
    foreach ($motifs as $motif) {
        $noms = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $motif));
        foreach ((array) $noms as $nom) {
            delete_option((string) $nom);
        }
    }
}

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $id_site) {
        switch_to_blog((int) $id_site);
        rsn_veille_desinstaller_site();
        restore_current_blog();
    }
} else {
    rsn_veille_desinstaller_site();
}

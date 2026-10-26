<?php
declare(strict_types=1);

namespace RSN\Veille\Admin;

use RSN\Veille\Collecte;
use RSN\Veille\Depot;
use RSN\Veille\EntrepotWordPress;
use RSN\Veille\Pertinence;
use RSN\Veille\Sources;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Page Réglages : option unique `rsn_veille_reglages` (API Settings, enregistrée par options.php),
 * état de la collecte, « Lancer la collecte maintenant » et import d'un fichier JSON d'événements
 * (formulaires séparés vers admin-post.php, jeton `rsn_veille_actions`).
 *
 * `nettoyer`, `lancer_collecte` et le rendu sont testés sans WordPress réel ; `afficher` et les
 * rappels d'admin-post lisent la requête et appellent WordPress.
 */
final class PageReglages {
    public const GROUPE = 'rsn_veille_reglages_groupe';
    public const NONCE_ACTIONS = 'rsn_veille_actions';
    public const ACTION_COLLECTER = 'rsn_veille_collecter';
    public const ACTION_IMPORTER = 'rsn_veille_importer';

    /** Taille maximale du fichier importé (2 Mo). */
    public const TAILLE_MAX = 2 * 1024 * 1024;

    /** Durée de vie du message affiché après une action, en secondes. */
    private const DUREE_MESSAGE = 300;

    // --- WordPress : crochets, page, actions ---

    /** Accroche le réglage, ses suites et les actions de la page (chargement de l'extension). */
    public static function accrocher(): void {
        add_action('admin_init', [self::class, 'enregistrer_reglage']);
        // options.php exige manage_options par défaut : l'équipe de la veille doit pouvoir enregistrer.
        add_filter('option_page_capability_' . self::GROUPE, [self::class, 'capacite']);
        add_action('add_option_' . Collecte::OPTION_REGLAGES, [self::class, 'apres_ajout'], 10, 2);
        add_action('update_option_' . Collecte::OPTION_REGLAGES, [self::class, 'apres_mise_a_jour'], 10, 2);
        add_action('admin_post_' . self::ACTION_COLLECTER, [self::class, 'traiter_collecte']);
        add_action('admin_post_' . self::ACTION_IMPORTER, [self::class, 'traiter_import']);
    }

    public static function enregistrer_reglage(): void {
        register_setting(self::GROUPE, Collecte::OPTION_REGLAGES, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'nettoyer'],
            'show_in_rest' => false,
        ]);
    }

    public static function capacite(): string {
        return Menu::CAPACITE;
    }

    /** Rappel de `add_option_rsn_veille_reglages` ($nom, $valeur) : premier enregistrement. */
    public static function apres_ajout(mixed $nom = '', mixed $valeur = null): void {
        self::apres_mise_a_jour([], $valeur);
    }

    /** Après enregistrement : rôles, collecte quotidienne si l'heure change, caches de la page Veille. */
    public static function apres_mise_a_jour(mixed $ancienne = null, mixed $nouvelle = null): void {
        Menu::synchroniser_roles();
        $heure_avant = NettoyageReglages::heure(is_array($ancienne) ? ($ancienne['heure_collecte'] ?? '') : '');
        if ($heure_avant !== NettoyageReglages::heure(is_array($nouvelle) ? ($nouvelle['heure_collecte'] ?? '') : '')) {
            Collecte::replanifier_nuit();
        }
        Collecte::purger_caches();
    }

    /** Affiche la page (rappel du menu). */
    public static function afficher(): void {
        if (!current_user_can(Menu::CAPACITE)) {
            wp_die(esc_html__("Vous n'avez pas accès à cette page.", 'rsn-veille'), '', 403);
        }
        $contexte = self::contexte(Collecte::reglages());
        // Messages d'options.php (« Réglages enregistrés », champs corrigés) : produits et échappés par WordPress.
        ob_start();
        settings_errors();
        $contexte['notices'] = (string) ob_get_clean();
        $cle = self::cle_message();
        $message = get_transient($cle);
        if (is_array($message)) {
            delete_transient($cle);
            $contexte['message'] = $message;
        }
        // Sortie entièrement échappée par les méthodes de rendu.
        echo self::page_html($contexte);
    }

    /** admin-post.php : « Lancer la collecte maintenant ». */
    public static function traiter_collecte(): void {
        self::verifier_requete();
        $n = self::lancer_collecte();
        if ($n === 0) {
            self::noter_message('warning', __('Aucune source active à collecter.', 'rsn-veille'));
        } else {
            /* translators: %d : nombre de sources mises en file. */
            $texte = sprintf(_n('Collecte lancée : %d source en file, traitée en arrière-plan.', 'Collecte lancée : %d sources en file, traitées une à une en arrière-plan.', $n, 'rsn-veille'), $n);
            if (self::cron_desactive()) {
                $texte .= ' ' . __('WP-Cron est désactivé : elle commencera au prochain appel de wp-cron.php par le serveur.', 'rsn-veille');
            }
            self::noter_message('success', $texte, [__('Le Journal montre chaque source à mesure.', 'rsn-veille')]);
        }
        self::rediriger();
    }

    /** admin-post.php : « Importer un fichier JSON » (champ `fichier`). */
    public static function traiter_import(): void {
        self::verifier_requete();
        $fichier = $_FILES['fichier'] ?? null;
        $erreur = self::erreur_fichier($fichier);
        $json = false;
        if ($erreur === null) {
            $chemin = (string) $fichier['tmp_name'];
            $json = (!function_exists('is_uploaded_file') || is_uploaded_file($chemin)) ? file_get_contents($chemin) : false;
            if ($json === false) {
                $erreur = __('Le fichier téléversé est illisible.', 'rsn-veille');
            }
        }
        if ($erreur !== null || !is_string($json)) {
            self::noter_message('error', (string) $erreur);
            self::rediriger();
            return;
        }
        $r = Import::importer($json, new Depot(new EntrepotWordPress()), Collecte::pertinence(), \rsn_veille_aujourdhui());
        if ($r['crees'] + $r['fusionnes'] + $r['mis_a_jour'] > 0) {
            Collecte::purger_caches();
        }
        self::noter_message($r['erreurs'] === [] ? 'success' : 'warning', self::resume_import($r), $r['erreurs']);
        self::rediriger();
    }

    // --- Logique ---

    /**
     * Met en file les sources actives (`Collecte::mettre_en_file`), avance la première source à
     * maintenant et lance WP-Cron (`spawn_cron`). La file attend sinon 60 secondes, et WP-Cron ne
     * lance que les tâches échues ; un second événement à maintenant serait refusé (moins de 10 minutes
     * du premier) : le premier est donc déplacé. Renvoie le nombre de sources en file.
     */
    public static function lancer_collecte(): int {
        Collecte::mettre_en_file();
        $file = get_option(Collecte::OPTION_FILE, []);
        $n = is_array($file) ? count($file) : 0;
        if ($n === 0) {
            return 0;
        }
        $maintenant = time();
        $prevue = wp_next_scheduled(Collecte::CROCHET_SOURCE);
        if ($prevue !== false && $prevue > $maintenant) {
            wp_unschedule_event($prevue, Collecte::CROCHET_SOURCE);
            if (!wp_schedule_single_event($maintenant, Collecte::CROCHET_SOURCE)) {
                // Jamais de chaîne cassée : la tâche reprend sa place.
                wp_schedule_single_event($prevue, Collecte::CROCHET_SOURCE);
            }
        }
        spawn_cron();
        return $n;
    }

    /** Motif du refus d'un fichier téléversé (`$_FILES['fichier']`), ou null s'il est recevable. */
    public static function erreur_fichier(mixed $fichier): ?string {
        if (!is_array($fichier) || !isset($fichier['tmp_name'], $fichier['name']) || ($fichier['error'] ?? null) === UPLOAD_ERR_NO_FILE) {
            return __('Aucun fichier reçu : choisissez un fichier JSON.', 'rsn-veille');
        }
        $code = (int) ($fichier['error'] ?? UPLOAD_ERR_OK);
        if (in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || (int) ($fichier['size'] ?? 0) > self::TAILLE_MAX) {
            return __('Fichier trop gros : 2 Mo au plus.', 'rsn-veille');
        }
        if ($code !== UPLOAD_ERR_OK) {
            return __('Le téléversement a échoué. Réessayez.', 'rsn-veille');
        }
        if (strtolower(pathinfo((string) $fichier['name'], PATHINFO_EXTENSION)) !== 'json') {
            return __('Le fichier doit porter l’extension .json.', 'rsn-veille');
        }
        return null;
    }

    /** « 24 lus, 23 créés, 1 fusionné, 0 mis à jour, 0 inchangé, 0 erreur ». */
    public static function resume_import(array $r): string {
        $erreurs = count($r['erreurs'] ?? []);
        $parties = [
            /* translators: %d : nombre d'entrées lues. */
            sprintf(_n('%d lu', '%d lus', (int) $r['lus'], 'rsn-veille'), (int) $r['lus']),
            /* translators: %d : nombre de fiches créées. */
            sprintf(_n('%d créé', '%d créés', (int) $r['crees'], 'rsn-veille'), (int) $r['crees']),
            /* translators: %d : nombre d'entrées fusionnées avec une fiche existante. */
            sprintf(_n('%d fusionné', '%d fusionnés', (int) $r['fusionnes'], 'rsn-veille'), (int) $r['fusionnes']),
            /* translators: %d : nombre de fiches mises à jour. */
            sprintf(_n('%d mis à jour', '%d mis à jour', (int) $r['mis_a_jour'], 'rsn-veille'), (int) $r['mis_a_jour']),
            /* translators: %d : nombre d'entrées déjà importées, sans changement. */
            sprintf(_n('%d inchangé', '%d inchangés', (int) $r['inchanges'], 'rsn-veille'), (int) $r['inchanges']),
            /* translators: %d : nombre d'entrées refusées. */
            sprintf(_n('%d erreur', '%d erreurs', $erreurs, 'rsn-veille'), $erreurs),
        ];
        /* translators: %s : décompte de l'import. */
        return sprintf(__('Import terminé : %s.', 'rsn-veille'), implode(', ', $parties));
    }

    /** Rappel `sanitize_callback` du réglage : voir `NettoyageReglages::nettoyer`. */
    public static function nettoyer(mixed $brut): array {
        return NettoyageReglages::nettoyer($brut);
    }

    // --- Rendu ---

    /**
     * Données de la page lues dans WordPress : réglages effectifs, sources, rôles, pages, état de la
     * collecte, champs cachés d'options.php. `message` et `notices` sont remplis par `afficher`.
     *
     * @param array<string,mixed> $reglages Option enregistrée (éventuellement vide).
     * @return array<string,mixed>
     */
    public static function contexte(array $reglages): array {
        $propres = NettoyageReglages::relire($reglages);
        $defaut = Pertinence::config_par_defaut();
        $roles = [];
        foreach (wp_roles()->roles as $nom => $role) {
            $libelle = (string) ($role['name'] ?? $nom);
            $roles[(string) $nom] = function_exists('translate_user_role') ? translate_user_role($libelle) : $libelle;
        }
        $pages = [];
        foreach (get_pages(['post_status' => 'publish,draft', 'sort_column' => 'post_title']) ?: [] as $page) {
            $pages[(int) $page->ID] = $page->post_title;
        }
        ob_start();
        settings_fields(self::GROUPE);
        $derniere = get_option(Collecte::OPTION_DERNIERE, 0);
        return [
            'reglages' => $propres,
            // Listes vides : la pertinence prend les valeurs par défaut, montrées telles quelles.
            'mots_sante' => $propres['mots_sante'] !== [] ? $propres['mots_sante'] : $defaut['mots_sante'],
            'mots_numerique' => $propres['mots_numerique'] !== [] ? $propres['mots_numerique'] : $defaut['mots_numerique'],
            'themes' => $propres['themes'] !== [] ? $propres['themes'] : $defaut['themes'],
            'sources' => Sources::fusionner(Sources::par_defaut(), $propres['sources']),
            'roles' => $roles,
            'pages' => $pages,
            'derniere' => is_numeric($derniere) ? (int) $derniere : 0,
            'prochaine' => wp_next_scheduled(Collecte::CROCHET_NUIT),
            'cron_desactive' => self::cron_desactive(),
            'champs_caches' => (string) ob_get_clean(),
            'notices' => '',
            'message' => null,
            'url_options' => admin_url('options.php'),
            'url_actions' => admin_url('admin-post.php'),
        ];
    }

    /** Page complète. `champs_caches` et `notices` viennent de WordPress (déjà échappés) ; tout le reste est échappé ici. */
    public static function page_html(array $c): string {
        return '<div class="wrap rsn-veille-admin rsn-veille-reglages">'
            . '<h1>' . esc_html__('Réglages de la veille', 'rsn-veille') . '</h1>'
            . $c['notices']
            . self::message_html($c['message'])
            . RenduReglages::collecte_html($c)
            . RenduReglages::import_html($c)
            . RenduReglages::formulaire_html($c)
            . '</div>';
    }

    /** @param array{niveau?: string, texte?: string, details?: string[]}|null $message */
    private static function message_html(?array $message): string {
        if ($message === null || (string) ($message['texte'] ?? '') === '') {
            return '';
        }
        $niveau = in_array($message['niveau'] ?? '', ['success', 'warning', 'error'], true) ? $message['niveau'] : 'info';
        $html = '<div class="notice notice-' . esc_attr($niveau) . ' is-dismissible"><p>' . esc_html((string) $message['texte']) . '</p>';
        $details = array_slice(array_filter((array) ($message['details'] ?? []), 'is_string'), 0, 30);
        if ($details !== []) {
            $html .= '<ul class="rsn-veille-details">';
            foreach ($details as $detail) {
                $html .= '<li>' . esc_html($detail) . '</li>';
            }
            $html .= '</ul>';
        }
        return $html . '</div>';
    }

    // --- Outils ---

    public static function cron_desactive(): bool {
        return defined('DISABLE_WP_CRON') && (bool) constant('DISABLE_WP_CRON');
    }

    /** Capacité et jeton exigés : sinon la requête s'arrête (403). */
    private static function verifier_requete(): void {
        if (!current_user_can(Menu::CAPACITE)) {
            wp_die(esc_html__("Vous n'avez pas le droit de modifier la veille.", 'rsn-veille'), '', 403);
        }
        check_admin_referer(self::NONCE_ACTIONS);
    }

    private static function cle_message(): string {
        return 'rsn_veille_message_' . get_current_user_id();
    }

    /** @param string[] $details */
    private static function noter_message(string $niveau, string $texte, array $details = []): void {
        set_transient(self::cle_message(), ['niveau' => $niveau, 'texte' => $texte, 'details' => array_values($details)], self::DUREE_MESSAGE);
    }

    private static function rediriger(): void {
        wp_safe_redirect(admin_url('admin.php?page=' . Menu::SLUG_REGLAGES));
        exit;
    }
}

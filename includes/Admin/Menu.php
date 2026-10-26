<?php
declare(strict_types=1);

namespace RSN\Veille\Admin;

use RSN\Veille\Collecte;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Menu « Veille » de wp-admin, capacité `rsn_veille_gerer` et ressources (CSS, JS) de ses pages.
 */
final class Menu {
    public const CAPACITE = 'rsn_veille_gerer';
    public const SLUG = 'rsn-veille';
    public const SLUG_RADAR = 'rsn-veille-radar';
    public const SLUG_INFOLETTRE = 'rsn-veille-infolettre';
    public const SLUG_JOURNAL = 'rsn-veille-journal';
    public const SLUG_REGLAGES = 'rsn-veille-reglages';

    /** Rôles qui reçoivent la capacité si le réglage `roles` est absent ou illisible. */
    public const ROLES_PAR_DEFAUT = ['administrator', 'editor'];

    /** Nom des ressources CSS et JS, et de l'objet JS de configuration. */
    private const RESSOURCE = 'rsn-veille-admin';
    private const OBJET_JS = 'rsnVeilleAdmin';

    /** @var string[] Crochets (`hook_suffix`) des pages du menu, connus après `admin_menu`. */
    private static array $crochets = [];

    /** Accroche le menu, la capacité, les ressources et l'action AJAX (chargement de l'extension). */
    public static function accrocher(): void {
        add_action('admin_menu', [self::class, 'enregistrer']);
        // Extension mise à jour sans réactivation, ou rôles changés : la capacité suit le réglage.
        add_action('admin_init', [self::class, 'synchroniser_roles']);
        add_action('admin_enqueue_scripts', [self::class, 'charger_ressources']);
        // Connectés seulement : pas de wp_ajax_nopriv_.
        add_action('wp_ajax_' . PageFile::ACTION_AJAX, [PageFile::class, 'ajax_changer_statut']);
        // Réglages : option rsn_veille_reglages (options.php), lancement de la collecte et import (admin-post.php).
        PageReglages::accrocher();
    }

    /**
     * Rôles qui doivent avoir la capacité : ceux du réglage `rsn_veille_reglages['roles']` (par défaut
     * administrateur et éditeur), plus toujours l'administrateur, pour que personne ne perde l'accès.
     *
     * @return string[]
     */
    public static function roles(): array {
        $reglage = Collecte::reglages()['roles'] ?? null;
        $roles = is_array($reglage) ? array_filter($reglage, static fn($r): bool => is_string($r) && $r !== '') : [];
        if ($roles === []) {
            $roles = self::ROLES_PAR_DEFAUT;
        }
        return array_values(array_unique(array_merge(['administrator'], $roles)));
    }

    /**
     * Donne la capacité aux rôles voulus et la retire aux autres (activation, `admin_init`, et la page
     * des réglages quand les rôles changent). N'écrit que ce qui change : `add_cap` réécrit l'option des rôles.
     */
    public static function synchroniser_roles(): void {
        $voulus = self::roles();
        foreach (wp_roles()->role_objects as $nom => $role) {
            $doit = in_array((string) $nom, $voulus, true);
            if ($doit && !$role->has_cap(self::CAPACITE)) {
                $role->add_cap(self::CAPACITE);
            } elseif (!$doit && $role->has_cap(self::CAPACITE)) {
                $role->remove_cap(self::CAPACITE);
            }
        }
    }

    /** Menu « Veille » et ses sous-pages (sur `admin_menu`). */
    public static function enregistrer(): void {
        $crochets = [
            add_menu_page(__('Veille', 'rsn-veille'), __('Veille', 'rsn-veille'), self::CAPACITE, self::SLUG, [PageFile::class, 'afficher'], 'dashicons-rss', 26),
        ];
        $pages = [
            // Même slug que le menu : la file d'approbation est la page par défaut.
            self::SLUG => [__("File d'approbation", 'rsn-veille'), [PageFile::class, 'afficher']],
            self::SLUG_RADAR => [__('Radar', 'rsn-veille'), [self::class, 'afficher_radar']],
            self::SLUG_INFOLETTRE => [__('Infolettre', 'rsn-veille'), [self::class, 'afficher_infolettre']],
            self::SLUG_JOURNAL => [__('Journal', 'rsn-veille'), [PageJournal::class, 'afficher']],
            self::SLUG_REGLAGES => [__('Réglages', 'rsn-veille'), [PageReglages::class, 'afficher']],
        ];
        foreach ($pages as $slug => [$titre, $rappel]) {
            $crochets[] = add_submenu_page(self::SLUG, $titre, $titre, self::CAPACITE, $slug, $rappel);
        }
        self::$crochets = array_values(array_filter($crochets, 'is_string'));
    }

    /** CSS et JS de l'administration, seulement sur les pages du menu Veille (`admin_enqueue_scripts`). */
    public static function charger_ressources(mixed $crochet = ''): void {
        if (!is_string($crochet) || !in_array($crochet, self::$crochets, true)) {
            return;
        }
        wp_enqueue_style(self::RESSOURCE, RSN_VEILLE_URL . 'assets/admin.css', [], RSN_VEILLE_VERSION);
        wp_enqueue_script(self::RESSOURCE, RSN_VEILLE_URL . 'assets/admin.js', [], RSN_VEILLE_VERSION, true);
        wp_localize_script(self::RESSOURCE, self::OBJET_JS, [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'action' => PageFile::ACTION_AJAX,
            'nonce' => wp_create_nonce(PageFile::NONCE),
            'textes' => [
                'erreur' => __('Le changement de statut a échoué. Réessayez ou rechargez la page.', 'rsn-veille'),
                'vide' => __('Aucune fiche.', 'rsn-veille'),
            ],
        ]);
    }

    /** Page Radar (tâche 15). */
    public static function afficher_radar(): void {
        self::afficher_a_venir(__('Radar', 'rsn-veille'));
    }

    /** Page Infolettre (tâche 16). */
    public static function afficher_infolettre(): void {
        self::afficher_a_venir(__('Infolettre', 'rsn-veille'));
    }

    /** Page provisoire d'une fonction pas encore livrée. */
    private static function afficher_a_venir(string $titre): void {
        if (!current_user_can(self::CAPACITE)) {
            wp_die(esc_html__("Vous n'avez pas accès à cette page.", 'rsn-veille'), '', 403);
        }
        echo '<div class="wrap rsn-veille-admin"><h1>' . esc_html($titre) . '</h1><p>'
            . esc_html__('Cette page est à venir.', 'rsn-veille') . '</p></div>';
    }
}

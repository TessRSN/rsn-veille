<?php
declare(strict_types=1);

namespace RSN\Veille\Public;

use RSN\Veille\EntrepotWordPress;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sorties publiques pour d'autres usages : `GET /wp-json/rsn/v1/veille` (le JSON de la page) et
 * `GET /veille.ics` (calendrier iCal des événements publiés, par règle de réécriture).
 */
final class Rest {
    public const ESPACE = 'rsn/v1';
    public const ROUTE = '/veille';
    public const VARIABLE_ICS = 'rsn_veille_ics';
    public const REGLE_ICS = '^veille\.ics$';

    /** Accroche la route REST et le calendrier iCal. */
    public static function accrocher(): void {
        add_action('rest_api_init', [self::class, 'enregistrer']);
        add_action('init', [self::class, 'ajouter_regle']);
        add_filter('query_vars', [self::class, 'variables']);
        add_action('template_redirect', [self::class, 'servir_ics']);
    }

    /** Route `GET rsn/v1/veille`, publique. */
    public static function enregistrer(): void {
        register_rest_route(self::ESPACE, self::ROUTE, [
            'methods' => 'GET',
            'callback' => [self::class, 'repondre'],
            'permission_callback' => '__return_true',
        ]);
    }

    /** Le JSON de la page publique (`Donnees`, sans niveau ni source), en cache une heure. */
    public static function repondre(mixed $requete = null): mixed {
        do_action('litespeed_control_set_nocache');
        $reponse = rest_ensure_response(Donnees::en_cache());
        if (is_object($reponse) && method_exists($reponse, 'header')) {
            $reponse->header('Cache-Control', 'public, max-age=3600');
        }
        return $reponse;
    }

    /** Règle de réécriture `/veille.ics` (sur `init`, et à l'activation avant `flush_rewrite_rules`). */
    public static function ajouter_regle(): void {
        add_rewrite_rule(self::REGLE_ICS, 'index.php?' . self::VARIABLE_ICS . '=1', 'top');
    }

    /**
     * @param string[] $variables
     * @return string[]
     */
    public static function variables(mixed $variables = []): array {
        $variables = is_array($variables) ? $variables : [];
        $variables[] = self::VARIABLE_ICS;
        return $variables;
    }

    /** Sert `/veille.ics` puis arrête la requête (`template_redirect`). */
    public static function servir_ics(): void {
        if ((string) get_query_var(self::VARIABLE_ICS) !== '1') {
            return;
        }
        do_action('litespeed_control_set_nocache');
        if (!headers_sent()) {
            status_header(200);
            header('Content-Type: text/calendar; charset=utf-8');
            header('Content-Disposition: inline; filename="veille.ics"');
            header('Cache-Control: public, max-age=3600');
            header('X-Robots-Tag: noindex');
        }
        echo self::ics(); // phpcs:ignore WordPress.Security.EscapeOutput -- calendrier iCal, échappé par Ical::generer.
        exit;
    }

    /** Le calendrier iCal, depuis le transient d'une heure ou régénéré (vidé avec le JSON public). */
    public static function ics(): string {
        $ics = get_transient(Ical::TRANSIENT);
        if (is_string($ics) && $ics !== '') {
            return $ics;
        }
        $ics = Ical::generer(Donnees::elements(new EntrepotWordPress(), EvenementsRsn::lire()), \rsn_veille_aujourdhui());
        set_transient(Ical::TRANSIENT, $ics, HOUR_IN_SECONDS);
        return $ics;
    }

    /** Activation : règle ajoutée puis règles régénérées. */
    public static function activer(): void {
        self::ajouter_regle();
        flush_rewrite_rules();
    }

    /** Désactivation : règle retirée puis règles régénérées (elle a été ajoutée sur `init` de cette requête). */
    public static function desactiver(): void {
        global $wp_rewrite;
        if (is_object($wp_rewrite) && isset($wp_rewrite->extra_rules_top) && is_array($wp_rewrite->extra_rules_top)) {
            unset($wp_rewrite->extra_rules_top[self::REGLE_ICS]);
        }
        flush_rewrite_rules();
    }
}

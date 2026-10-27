<?php
declare(strict_types=1);

namespace RSN\Veille;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Client HTTP des collectes et reconnaissance du contenu reçu.
 *
 * `obtenir` passe par `wp_remote_get` ; les méthodes `est_*` sont pures (aucune fonction WordPress)
 * et ne regardent que la réponse : une réponse en erreur n'est jamais exploitable, et une page HTML
 * renvoyée en code 200 à la place d'un flux n'est pas un flux.
 */
final class Http {
    /** Délai d'attente par requête, en secondes (maximum aussi : une option `timeout` ne le dépasse pas). */
    private const DELAI = 15;

    /** Valeurs de l'en-tête Accept selon le contenu attendu. */
    private const ACCEPT = [
        'json' => 'application/json, text/plain;q=0.5, */*;q=0.1',
        'xml' => 'application/rss+xml, application/atom+xml, application/xml;q=0.9, text/xml;q=0.9, */*;q=0.1',
        'html' => 'text/html, application/xhtml+xml;q=0.9, */*;q=0.1',
        'ical' => 'text/calendar, */*;q=0.1',
        'any' => '*/*',
    ];

    /** Taille maximale d'une réponse lue, en octets : au-delà, le corps est tronqué (pas de chargement sans fin en mémoire). */
    private const TAILLE_MAX = 5 * 1024 * 1024;

    /** Nombre de caractères du début du corps examinés pour reconnaître le contenu. */
    private const DEBUT = 1024;

    /**
     * Télécharge une adresse. `$options['accept']` : json, xml, html, ical ou any (par défaut).
     * `$options['timeout']` : délai d'attente en secondes, de 1 à 15 (15 par défaut).
     * `erreur` est renseignée si la requête échoue ou si le code HTTP est 400 ou plus ; un 403 reste
     * une erreur (aucun contournement des protections anti-robots).
     *
     * @param array{accept?: string, timeout?: int} $options
     * @return array{corps: string, type: string, code: int, url_finale: string, erreur: ?string}
     */
    public static function obtenir(string $url, array $options = []): array {
        $accept = self::ACCEPT[$options['accept'] ?? 'any'] ?? self::ACCEPT['any'];
        $reponse = wp_remote_get($url, [
            'timeout' => self::delai($options),
            'redirection' => 5,
            'limit_response_size' => self::TAILLE_MAX,
            'user-agent' => 'RSN-Veille/' . RSN_VEILLE_VERSION . ' (+https://rsn.quebec)',
            'headers' => ['Accept' => $accept],
        ]);

        if (is_wp_error($reponse)) {
            return ['corps' => '', 'type' => '', 'code' => 0, 'url_finale' => $url, 'erreur' => $reponse->get_error_message()];
        }

        $code = (int) wp_remote_retrieve_response_code($reponse);
        $type = wp_remote_retrieve_header($reponse, 'content-type');
        if (is_array($type)) {
            // En-tête répété : le dernier l'emporte.
            $type = (string) end($type);
        }

        $url_finale = $url;
        $brute = $reponse['http_response'] ?? null;
        if (is_object($brute) && method_exists($brute, 'get_response_object')) {
            $objet = $brute->get_response_object();
            if (is_object($objet) && isset($objet->url) && is_string($objet->url) && $objet->url !== '') {
                $url_finale = $objet->url;
            }
        }

        $erreur = null;
        if ($code >= 400 || $code === 0) {
            /* translators: %d : code de la réponse HTTP. */
            $erreur = sprintf(__('Réponse HTTP %d', 'rsn-veille'), $code);
        }

        return [
            'corps' => (string) wp_remote_retrieve_body($reponse),
            'type' => (string) $type,
            'code' => $code,
            'url_finale' => $url_finale,
            'erreur' => $erreur,
        ];
    }

    /** Délai d'attente demandé par `$options['timeout']`, borné de 1 à 15 s ; 15 s si absent ou illisible. */
    public static function delai(array $options): int {
        $demande = $options['timeout'] ?? null;
        return is_int($demande) || (is_string($demande) && ctype_digit($demande))
            ? max(1, min(self::DELAI, (int) $demande))
            : self::DELAI;
    }

    /** Flux RSS ou Atom : type xml, rss ou atom (hors XHTML), ou corps commençant par `<?xml`, `<rss`, `<feed`. */
    public static function est_flux(array $reponse): bool {
        if (!self::exploitable($reponse) || self::corps_html($reponse)) {
            return false;
        }
        $type = self::type($reponse);
        if (!str_contains($type, 'html') && (str_contains($type, 'xml') || str_contains($type, 'rss') || str_contains($type, 'atom'))) {
            return true;
        }
        $debut = self::debut($reponse);
        foreach (['<?xml', '<rss', '<feed', '<rdf:rdf'] as $balise) {
            if (str_starts_with($debut, $balise)) {
                return true;
            }
        }
        return false;
    }

    /** JSON : type json, ou corps commençant par `{` ou `[` et décodable. */
    public static function est_json(array $reponse): bool {
        if (!self::exploitable($reponse) || self::corps_html($reponse)) {
            return false;
        }
        if (str_contains(self::type($reponse), 'json')) {
            return true;
        }
        $debut = self::debut($reponse);
        if (!str_starts_with($debut, '{') && !str_starts_with($debut, '[')) {
            return false;
        }
        json_decode(self::sans_bom((string) ($reponse['corps'] ?? '')));
        return json_last_error() === JSON_ERROR_NONE;
    }

    /** Page HTML : type html (text/html, XHTML) ou corps commençant par `<!doctype html` ou `<html`. */
    public static function est_html(array $reponse): bool {
        if (!self::exploitable($reponse)) {
            return false;
        }
        return str_contains(self::type($reponse), 'html') || self::corps_html($reponse);
    }

    /** Calendrier iCal : type text/calendar ou corps commençant par `BEGIN:VCALENDAR`. */
    public static function est_ical(array $reponse): bool {
        if (!self::exploitable($reponse)) {
            return false;
        }
        return str_contains(self::type($reponse), 'text/calendar') || str_starts_with(self::debut($reponse), 'begin:vcalendar');
    }

    /** Réponse sans erreur et au corps non vide. */
    private static function exploitable(array $reponse): bool {
        return ($reponse['erreur'] ?? null) === null && trim((string) ($reponse['corps'] ?? '')) !== '';
    }

    private static function type(array $reponse): string {
        return strtolower((string) ($reponse['type'] ?? ''));
    }

    /** Début du corps en minuscules, sans BOM ni espaces de tête. */
    private static function debut(array $reponse): string {
        return strtolower(substr(ltrim(self::sans_bom((string) ($reponse['corps'] ?? ''))), 0, self::DEBUT));
    }

    private static function sans_bom(string $corps): string {
        return str_starts_with($corps, "\xEF\xBB\xBF") ? substr($corps, 3) : $corps;
    }

    /**
     * Le document est une page HTML : après une éventuelle déclaration XML, des commentaires et un
     * DOCTYPE, la première balise est `<html`, ou le DOCTYPE est celui du HTML.
     */
    private static function corps_html(array $reponse): bool {
        $debut = self::debut($reponse);
        $debut = preg_replace('/^<\?xml[^>]*\?>\s*/', '', $debut) ?? $debut;
        $debut = preg_replace('/^(?:<!--.*?-->\s*)+/s', '', $debut) ?? $debut;
        if (str_starts_with($debut, '<!doctype html')) {
            return true;
        }
        $debut = preg_replace('/^<!doctype[^>]*>\s*/', '', $debut) ?? $debut;
        return str_starts_with($debut, '<html');
    }
}

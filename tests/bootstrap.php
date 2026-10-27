<?php
// Bouchons minimaux pour tester les classes pures sans WordPress.

define('ABSPATH', __DIR__ . '/');
define('FIXTURES', __DIR__ . '/fixtures');

// Date du jour simulée, modifiable par les tests.
$GLOBALS['rsn_veille_test_aujourdhui'] = '2026-10-25';

function esc_html(string $texte): string {
    return htmlspecialchars($texte, ENT_QUOTES, 'UTF-8');
}

function wp_strip_all_tags(string $texte): string {
    return trim(strip_tags($texte));
}

/**
 * Proche de _sanitize_text_fields de WordPress : un `<` qui n'ouvre pas de balise devient `&lt;`,
 * balises retirées, espaces réduites, octets `%xx` retirés.
 */
function sanitize_text_field(string $texte): string {
    if (str_contains($texte, '<')) {
        $texte = strip_tags((string) preg_replace('/<(?![a-zA-Z\/!?])/', '&lt;', $texte));
    }
    $texte = trim((string) preg_replace('/[\r\n\t ]+/', ' ', $texte));
    $sans_octets = (string) preg_replace('/%[a-f0-9]{2}/i', '', $texte);
    return $sans_octets === $texte ? $texte : trim((string) preg_replace('/ +/', ' ', $sans_octets));
}

function __(string $texte, string $domaine = 'default'): string {
    return $texte;
}

function wp_date(string $format, ?int $horodatage = null): string {
    if ($format === 'Y-m-d') {
        return $GLOBALS['rsn_veille_test_aujourdhui'];
    }
    return date($format, $horodatage ?? time());
}

require dirname(__DIR__) . '/rsn-veille.php';

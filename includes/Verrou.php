<?php
declare(strict_types=1);

namespace RSN\Veille;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Verrou simple par option : `add_option` échoue si l'option existe déjà. Un verrou plus vieux que
 * le délai de péremption est tenu pour abandonné et repris ; seul son détenteur (jeton) le libère.
 */
final class Verrou {
    /** Prend le verrou $nom ; rend le jeton, ou null s'il est tenu (et pas périmé). */
    public static function prendre(string $nom, int $perime): ?string {
        $jeton = uniqid('', true);
        for ($essai = 0; $essai < 2; $essai++) {
            if (add_option($nom, ['jeton' => $jeton, 'depuis' => time()], '', 'no')) {
                return $jeton;
            }
            $actuel = get_option($nom);
            $depuis = is_array($actuel) ? (int) ($actuel['depuis'] ?? 0) : 0;
            if (time() - $depuis < $perime) {
                return null;
            }
            delete_option($nom);
        }
        return null;
    }

    /** Libère le verrou $nom s'il porte encore ce jeton. */
    public static function liberer(string $nom, string $jeton): void {
        $actuel = get_option($nom);
        if (is_array($actuel) && ($actuel['jeton'] ?? null) === $jeton) {
            delete_option($nom);
        }
    }
}

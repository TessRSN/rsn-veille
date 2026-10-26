<?php
declare(strict_types=1);

namespace RSN\Veille;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Journal des collectes : pour chaque source, ses dernières entrées (la plus récente en premier),
 * dans l'option `rsn_veille_journal` (non chargée automatiquement).
 */
final class Journal {
    public const OPTION = 'rsn_veille_journal';

    /** Entrées gardées par source. */
    public const MAX = 60;

    /**
     * Ajoute une entrée en tête de la liste de la source et ne garde que les 60 plus récentes.
     *
     * @param array<string,mixed> $ligne
     */
    public static function consigner(string $id_source, array $ligne): void {
        $tout = self::tout();
        $entrees = $tout[$id_source] ?? [];
        array_unshift($entrees, $ligne);
        $tout[$id_source] = array_slice($entrees, 0, self::MAX);
        update_option(self::OPTION, $tout, false);
    }

    /**
     * Toutes les entrées, par source.
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    public static function tout(): array {
        $tout = get_option(self::OPTION, []);
        return is_array($tout) ? $tout : [];
    }

    /**
     * La plus récente entrée de la source, sinon null.
     *
     * @return array<string,mixed>|null
     */
    public static function derniere(string $id_source): ?array {
        $entree = self::tout()[$id_source][0] ?? null;
        return is_array($entree) ? $entree : null;
    }
}

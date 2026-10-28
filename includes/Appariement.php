<?php
declare(strict_types=1);

namespace RSN\Veille;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Appariement des fiches d'une source lue dans ses deux langues (Mila, IVADO) : les deux pages ne partagent
 * aucun identifiant sûr, seulement les dates. Chaque française, dans l'ordre, prend la première anglaise
 * encore libre de mêmes début et fin (une fin absente vaut le début) ; deux événements le même jour sont
 * donc appariés dans l'ordre des pages.
 */
final class Appariement {
    /**
     * @param Fiche[] $francaises
     * @param Fiche[] $anglaises
     * @return array{paires: array<int, array{0: Fiche, 1: Fiche}>, orphelines: Fiche[]} Paires [française,
     *         anglaise] dans l'ordre des françaises ; orphelines : anglaises sans équivalent, dans leur ordre.
     */
    public static function apparier(array $francaises, array $anglaises): array {
        $libres = array_values($anglaises);
        $paires = [];
        foreach ($francaises as $francaise) {
            foreach ($libres as $position => $anglaise) {
                if (self::memes_dates($francaise, $anglaise)) {
                    $paires[] = [$francaise, $anglaise];
                    unset($libres[$position]);
                    break;
                }
            }
        }
        return ['paires' => $paires, 'orphelines' => array_values($libres)];
    }

    private static function memes_dates(Fiche $a, Fiche $b): bool {
        return $a->debut === $b->debut && ($a->fin ?? $a->debut) === ($b->fin ?? $b->debut);
    }
}

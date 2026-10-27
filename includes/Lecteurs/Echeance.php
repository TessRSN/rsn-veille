<?php
declare(strict_types=1);

namespace RSN\Veille\Lecteurs;

use RSN\Veille\Fiche;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Extraction d'une date limite écrite en français (« date limite : 20 octobre 2026 »).
 */
final class Echeance {
    /** Mois en toutes lettres (minuscules, avec et sans accent) vers leur numéro. */
    private const MOIS = [
        'janvier' => 1, 'février' => 2, 'fevrier' => 2, 'mars' => 3, 'avril' => 4, 'mai' => 5,
        'juin' => 6, 'juillet' => 7, 'août' => 8, 'aout' => 8, 'septembre' => 9,
        'octobre' => 10, 'novembre' => 11, 'décembre' => 12, 'decembre' => 12,
    ];

    /** Expressions qui annoncent une échéance (minuscules). */
    private const DECLENCHEURS = [
        'date limite', 'échéance', 'avant le', "jusqu'au", 'jusqu’au', "d'ici le", 'd’ici le', 'deadline',
    ];

    /**
     * Première date limite trouvée dans le texte, au format Y-m-d, ou null.
     * Sans année, on prend l'année de $aujourdhui (jour de référence : aujourd'hui, ou la publication du
     * texte) si le mois n'est pas déjà passé, sinon la suivante.
     */
    public static function trouver(string $texte, string $aujourdhui): ?string {
        $texte = mb_strtolower(Fiche::nettoyer_texte($texte));
        $declencheurs = implode('|', array_map(static fn(string $d): string => preg_quote($d, '/'), self::DECLENCHEURS));
        $mois = implode('|', array_keys(self::MOIS));
        $motif = '/(?:' . $declencheurs . ').{0,60}?(?<!\d)(\d{1,2})(?:er)?\s+(' . $mois . ')(?!\p{L})(?:\s+(\d{4}))?(?!\d)/u';
        if (!preg_match($motif, $texte, $m)) {
            return null;
        }

        $jour = (int) $m[1];
        $numero_mois = self::MOIS[$m[2]];
        if (isset($m[3]) && $m[3] !== '') {
            $annee = (int) $m[3];
        } else {
            $annee = (int) substr($aujourdhui, 0, 4);
            if ($numero_mois < (int) substr($aujourdhui, 5, 2)) {
                $annee++;
            }
        }
        return checkdate($numero_mois, $jour, $annee) ? sprintf('%04d-%02d-%02d', $annee, $numero_mois, $jour) : null;
    }
}

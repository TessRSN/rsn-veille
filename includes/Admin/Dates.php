<?php
declare(strict_types=1);

namespace RSN\Veille\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Dates lisibles en français pour l'administration (« 1er janvier 2026 », « Du 3 au 5 mai 2026 »).
 *
 * Classe pure : seulement `__()` pour la traduction des mois et des tournures.
 */
final class Dates {
    /** « 1er janvier 2026 », ou vide si la date n'est pas une date `Y-m-d` valide. */
    public static function date_longue(?string $date): string {
        $p = self::parties($date);
        if ($p === null) {
            return '';
        }
        return self::jour($p[2]) . ' ' . self::mois($p[1]) . ' ' . $p[0];
    }

    /**
     * Période d'une fiche : « 12 novembre 2026, 13 h 30 », « Du 1er au 3 décembre 2026 »,
     * « Du 28 novembre au 2 décembre 2026 », « Du 30 décembre 2026 au 2 janvier 2027 ».
     * Vide sans début lisible ; une fin illisible est ignorée.
     */
    public static function periode(?string $debut, ?string $fin, string $heure = ''): string {
        $a = self::parties($debut);
        if ($a === null) {
            return '';
        }
        $b = self::parties($fin);
        if ($b === null || $b === $a) {
            $h = self::heure($heure);
            /* translators: 1 : date ; 2 : heure. */
            return $h === '' ? self::date_longue($debut) : sprintf(__('%1$s, %2$s', 'rsn-veille'), self::date_longue($debut), $h);
        }
        if ($a[0] === $b[0] && $a[1] === $b[1]) {
            /* translators: 1 : premier jour ; 2 : dernier jour ; 3 : mois ; 4 : année. */
            return sprintf(__('Du %1$s au %2$s %3$s %4$d', 'rsn-veille'), self::jour($a[2]), self::jour($b[2]), self::mois($a[1]), $a[0]);
        }
        $depart = self::jour($a[2]) . ' ' . self::mois($a[1]) . ($a[0] !== $b[0] ? ' ' . $a[0] : '');
        /* translators: 1 : date de début (sans l'année si c'est la même) ; 2 : date de fin. */
        return sprintf(__('Du %1$s au %2$s', 'rsn-veille'), $depart, self::date_longue($fin));
    }

    /** « 26 octobre 2026, 3 h 05 » depuis « Y-m-d H:i » (ou « Y-m-d ») ; vide si illisible. */
    public static function date_heure(string $moment): string {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:[ T](\d{1,2}:\d{2}))?/', $moment, $m) !== 1) {
            return '';
        }
        $date = self::date_longue($m[1]);
        $heure = self::heure($m[2] ?? '');
        if ($date === '' || $heure === '') {
            return $date;
        }
        /* translators: 1 : date ; 2 : heure. */
        return sprintf(__('%1$s, %2$s', 'rsn-veille'), $date, $heure);
    }

    /** « 13 h 30 », « 9 h » ; vide si l'heure n'est pas au format H:i. */
    public static function heure(string $heure): string {
        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $heure, $m) !== 1) {
            return '';
        }
        $h = (int) $m[1];
        if ($m[2] === '00') {
            /* translators: %d : heure. */
            return sprintf(__('%d h', 'rsn-veille'), $h);
        }
        /* translators: 1 : heure ; 2 : minutes. */
        return sprintf(__('%1$d h %2$s', 'rsn-veille'), $h, $m[2]);
    }

    /** @return array{0:int,1:int,2:int}|null [année, mois, jour] d'une date `Y-m-d` valide. */
    private static function parties(?string $date): ?array {
        if ($date === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1) {
            return null;
        }
        [$a, $mo, $j] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        return checkdate($mo, $j, $a) ? [$a, $mo, $j] : null;
    }

    private static function jour(int $jour): string {
        return $jour === 1 ? __('1er', 'rsn-veille') : (string) $jour;
    }

    private static function mois(int $mois): string {
        $noms = [
            1 => __('janvier', 'rsn-veille'), 2 => __('février', 'rsn-veille'), 3 => __('mars', 'rsn-veille'),
            4 => __('avril', 'rsn-veille'), 5 => __('mai', 'rsn-veille'), 6 => __('juin', 'rsn-veille'),
            7 => __('juillet', 'rsn-veille'), 8 => __('août', 'rsn-veille'), 9 => __('septembre', 'rsn-veille'),
            10 => __('octobre', 'rsn-veille'), 11 => __('novembre', 'rsn-veille'), 12 => __('décembre', 'rsn-veille'),
        ];
        return $noms[$mois];
    }
}

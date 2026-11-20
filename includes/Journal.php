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

    /** Messages d'erreur gardés par entrée. */
    public const MAX_ERREURS = 10;

    /** Entrées précédentes regardées pour connaître l'habitude d'une source (deux semaines de collectes). */
    private const FENETRE = 14;

    /** Lectures saines qu'il faut dans la fenêtre pour parler d'une habitude. */
    private const LECTURES_MIN = 3;

    /** Habitude à partir de laquelle une lecture vide est signalée, puis une lecture réduite à moins du tiers. */
    private const HABITUDE_VIDE = 3;
    private const HABITUDE_CHUTE = 9;

    /**
     * Ajoute une entrée en tête de la liste de la source et ne garde que les 60 plus récentes. Au-delà
     * de 10 erreurs, les suivantes sont résumées par « … et N autres ». Une lecture qui rompt avec l'habitude
     * de la source reçoit une `alerte` (`alerte_gabarit`).
     *
     * @param array<string,mixed> $ligne
     */
    public static function consigner(string $id_source, array $ligne): void {
        if (isset($ligne['erreurs']) && is_array($ligne['erreurs']) && count($ligne['erreurs']) > self::MAX_ERREURS) {
            $reste = count($ligne['erreurs']) - self::MAX_ERREURS;
            $ligne['erreurs'] = array_slice(array_values($ligne['erreurs']), 0, self::MAX_ERREURS);
            /* translators: %d : nombre d'erreurs non affichées. */
            $ligne['erreurs'][] = sprintf(_n('… et %d autre', '… et %d autres', $reste, 'rsn-veille'), $reste);
        }
        $tout = self::tout();
        $entrees = is_array($tout[$id_source] ?? null) ? $tout[$id_source] : [];
        // Une alerte posée par le lecteur (infolettre) est gardée ; sinon, celle que donne l'habitude de la source.
        if (!is_string($ligne['alerte'] ?? null) || $ligne['alerte'] === '') {
            unset($ligne['alerte']);
            $alerte = self::alerte_gabarit($ligne, $entrees);
            if ($alerte !== '') {
                $ligne['alerte'] = $alerte;
            }
        }
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

    /**
     * Phrase d'alerte quand une lecture sans erreur rompt avec l'habitude de la source : rien de lu (ni fiche ni
     * élément ignoré) alors qu'elle donne d'ordinaire au moins trois éléments, ou moins du tiers pour une source qui en
     * donne au moins neuf. C'est le signe d'une page qui a changé de gabarit sans cesser de répondre. Vide sinon.
     *
     * L'habitude est la médiane des lectures saines (sans erreur ni alerte) parmi les quatorze précédentes ; il en faut
     * trois. Une source qui reste vide deux semaines est donc tenue pour vide, et l'alerte s'éteint d'elle-même.
     *
     * @param array<string,mixed> $ligne Entrée à consigner.
     * @param array<int,mixed> $precedentes Entrées déjà consignées de la source, la plus récente d'abord.
     */
    public static function alerte_gabarit(array $ligne, array $precedentes): string {
        if (!self::sans_erreur($ligne)) {
            return '';
        }
        $totaux = [];
        foreach (array_slice($precedentes, 0, self::FENETRE) as $entree) {
            if (is_array($entree) && self::sans_erreur($entree) && (string) ($entree['alerte'] ?? '') === '') {
                $totaux[] = self::total($entree);
            }
        }
        if (count($totaux) < self::LECTURES_MIN) {
            return '';
        }
        sort($totaux);
        $habitude = $totaux[intdiv(count($totaux), 2)];
        $total = self::total($ligne);
        if ($total === 0 && $habitude >= self::HABITUDE_VIDE) {
            /* translators: %d : nombre d'éléments que la source donne d'habitude. */
            return sprintf(__('Rien lu, alors que la source donne d’habitude environ %d éléments. Sa page a peut-être changé de gabarit : à vérifier.', 'rsn-veille'), $habitude);
        }
        if ($total > 0 && $habitude >= self::HABITUDE_CHUTE && $total * 3 < $habitude) {
            /* translators: 1 : nombre d'éléments lus ; 2 : nombre d'éléments que la source donne d'habitude. */
            return sprintf(_n(
                '%1$d élément lu, alors que la source en donne d’habitude environ %2$d. Sa page a peut-être changé de gabarit : à vérifier.',
                '%1$d éléments lus, alors que la source en donne d’habitude environ %2$d. Sa page a peut-être changé de gabarit : à vérifier.',
                $total,
                'rsn-veille'
            ), $total, $habitude);
        }
        return '';
    }

    /**
     * Alerte de la plus récente entrée de chaque source, par identifiant de source (seulement celles qui en ont une).
     *
     * @param array<string,mixed> $tout `tout()`
     * @return array<string,string>
     */
    public static function alertes(array $tout): array {
        $alertes = [];
        foreach ($tout as $id => $entrees) {
            $alerte = is_array($entrees) && is_array($entrees[0] ?? null) ? ($entrees[0]['alerte'] ?? '') : '';
            if (is_string($alerte) && $alerte !== '') {
                $alertes[(string) $id] = $alerte;
            }
        }
        return $alertes;
    }

    private static function sans_erreur(array $entree): bool {
        return !isset($entree['erreurs']) || $entree['erreurs'] === [] || !is_array($entree['erreurs']);
    }

    /** Éléments compris par le lecteur : fiches lues et éléments ignorés (événements terminés). */
    private static function total(array $entree): int {
        return (is_numeric($entree['lus'] ?? null) ? (int) $entree['lus'] : 0) + (is_numeric($entree['ignores'] ?? null) ? (int) $entree['ignores'] : 0);
    }
}

<?php
declare(strict_types=1);

namespace RSN\Veille\Lecteurs;

use RSN\Veille\Fiche;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ce que rapporte la lecture d'une source : fiches, erreurs, éléments ignorés et alertes de gabarit.
 */
final class Resultat {
    /** @var Fiche[] */
    public array $fiches = [];
    /** @var string[] */
    public array $erreurs = [];
    /** Éléments écartés (par exemple événements déjà terminés). */
    public int $ignores = 0;
    /**
     * Signes que la source a changé de gabarit sans cesser d'être lisible (une infolettre dont le dernier numéro n'a
     * plus son bloc d'agenda) : consignés au journal, sans faire échouer la lecture.
     *
     * @var string[]
     */
    public array $alertes = [];
    /**
     * Pour une infolettre : nombre de numéros qui annoncent chaque événement rendu, par clé de source.
     *
     * @var array<string,int>
     */
    public array $mentions = [];
}

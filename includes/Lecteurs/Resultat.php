<?php
declare(strict_types=1);

namespace RSN\Veille\Lecteurs;

use RSN\Veille\Fiche;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ce que rapporte la lecture d'une source : fiches, erreurs et éléments ignorés.
 */
final class Resultat {
    /** @var Fiche[] */
    public array $fiches = [];
    /** @var string[] */
    public array $erreurs = [];
    /** Éléments écartés (par exemple événements déjà terminés). */
    public int $ignores = 0;
}

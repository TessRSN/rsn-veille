<?php
declare(strict_types=1);

namespace RSN\Veille\Lecteurs;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Un lecteur transforme le contenu brut d'une source en fiches.
 *
 * Aucun accès réseau : le contenu est déjà récupéré par la collecte.
 */
interface Lecteur {
    /**
     * @param string $contenu Corps brut renvoyé par la source.
     * @param array  $source  Entrée du registre des sources (nom, url, type, niveau, etc.).
     */
    public function lire(string $contenu, array $source): Resultat;
}

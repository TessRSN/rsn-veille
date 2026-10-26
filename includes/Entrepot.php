<?php
declare(strict_types=1);

namespace RSN\Veille;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Persistance des fiches de veille. Les fiches rendues portent leur `id`.
 */
interface Entrepot {
    /** La fiche dont `cles_source` contient cette clé, sinon null. */
    public function trouver_par_cle_source(string $cle): ?Fiche;

    /** La fiche dont la clé de dédoublonnage (`Fiche::cle_dedoublonnage()`) vaut cette clé, sinon null. */
    public function trouver_par_dedoublonnage(string $cle): ?Fiche;

    /** La fiche qui a exactement cette URL d'information, sinon null (null aussi pour une URL vide). */
    public function trouver_par_url(string $url): ?Fiche;

    /** Enregistre une nouvelle fiche et renvoie son identifiant. */
    public function creer(Fiche $f): int;

    /**
     * Modifie les champs donnés (noms des propriétés de `Fiche`) de la fiche $id.
     *
     * @param array<string,mixed> $champs
     */
    public function mettre_a_jour(int $id, array $champs): void;

    /**
     * Fiches de ce statut (tous les statuts si vide) et de ce type (tous si vide).
     *
     * @return Fiche[]
     */
    public function lister(string $statut = 'publie', string $type = ''): array;

    /**
     * Identifiants des fiches publiées dont une clé de source commence par « {id_source}: ».
     *
     * @return int[]
     */
    public function ids_publies_de_source(string $id_source): array;

    /** Pose le drapeau « absent de la source depuis le … ». */
    public function marquer_absent(int $id, string $depuis): void;

    /** Lève le drapeau « absent de la source ». */
    public function lever_absent(int $id): void;
}

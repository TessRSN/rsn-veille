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

    /**
     * La fiche dont la clé de dédoublonnage (`Fiche::cle_dedoublonnage()`) vaut cette clé, sinon null.
     * Les propositions du public (`Fiche::est_proposition()`) ne sont jamais rendues.
     */
    public function trouver_par_dedoublonnage(string $cle): ?Fiche;

    /**
     * La fiche qui a exactement cette URL d'information, sinon null (null aussi pour une URL vide).
     * Les propositions du public ne sont jamais rendues.
     */
    public function trouver_par_url(string $url): ?Fiche;

    /**
     * Enregistre une nouvelle fiche et renvoie son identifiant. Sans `cree_le`, la fiche reçoit la date
     * du jour (`rsn_veille_aujourdhui()`) : le courriel hebdomadaire s'en sert pour repérer les nouveautés.
     */
    public function creer(Fiche $f): int;

    /**
     * Modifie les champs donnés (noms des propriétés de `Fiche`) de la fiche $id.
     *
     * @param array<string,mixed> $champs
     */
    public function mettre_a_jour(int $id, array $champs): void;

    /**
     * Fiches de ce statut (tous les statuts si vide) et de ce type (tous si vide), sans limite : à réserver
     * à la file « À approuver » et aux tests (voir les variantes bornées ci-dessous).
     *
     * @return Fiche[]
     */
    public function lister(string $statut = 'publie', string $type = ''): array;

    /** La fiche $id (tous statuts), ou null si ce n'est pas une fiche de veille. */
    public function obtenir(int $id): ?Fiche;

    /**
     * Les fiches de ces identifiants (tous statuts), par identifiant croissant ; les inconnus sont ignorés.
     *
     * @param int[] $ids
     * @return Fiche[]
     */
    public function lister_par_ids(array $ids): array;

    /**
     * Nombre de fiches par onglet de la file, sans charger les fiches : `a_approuver`, `publie`, `ecarte`
     * et `a_verifier` (publiées signalées absentes de leur source).
     *
     * @return array<string,int>
     */
    public function compter(): array;

    /**
     * Fiches publiées signalées absentes de leur source (onglet « À vérifier »), par identifiant croissant.
     *
     * @return Fiche[]
     */
    public function lister_absentes(): array;

    /**
     * Fiches de ce statut (tous si vide) créées le $date (Y-m-d) ou après, d'après leur jour de création
     * enregistré (`cree_le`) ; une fiche sans jour de création enregistré n'est pas rendue.
     *
     * @return Fiche[]
     */
    public function lister_crees_depuis(string $date, string $statut = ''): array;

    /**
     * Les $max fiches les plus récentes (identifiant décroissant) de ce statut, et de ce type si non vide.
     *
     * @return Fiche[]
     */
    public function lister_recentes(string $statut, int $max, string $type = ''): array;

    /**
     * Fiches publiées utiles à la page publique, par identifiant croissant : événements dont le début ou la
     * fin est au moins $limite_evenements (ou sans début) ; appels dont l'échéance ou la fin est au moins
     * $aujourdhui, ou dont le début ou le jour de création est au moins $limite_appels (ou qui n'ont ni l'un
     * ni l'autre). C'est un sur-ensemble : la règle exacte des appels (`Fiche::appel_en_cours`) s'applique ensuite.
     *
     * @return Fiche[]
     */
    public function lister_pour_page(string $limite_evenements, string $aujourdhui, string $limite_appels): array;

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

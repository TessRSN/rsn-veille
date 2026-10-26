<?php
declare(strict_types=1);

namespace RSN\Veille;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Intègre les fiches collectées dans l'entrepôt : création, mise à jour des champs factuels,
 * fusion des doublons selon le niveau des sources, annulations et absences (devis § 4.3).
 *
 * Usage : pour chaque fiche collectée, appeler `integrer()` puis `annuler_si_necessaire()` sur la
 * même instance, fiche par fiche : l'annulation compare le titre entrant au titre que la même clé de
 * source avait avant cette intégration.
 *
 * Classe pure : aucune fonction WordPress.
 */
final class Depot {
    /** Seuls champs qu'une collecte peut modifier sur une fiche existante. */
    private const CHAMPS_FACTUELS = ['debut', 'fin', 'heure', 'echeance', 'url', 'image_url', 'prix', 'lieu', 'ville'];

    /** Champs qu'une source moins fiable que la fiche peut seulement combler s'ils sont vides. */
    private const CHAMPS_A_COMBLER = ['image_url', 'url'];

    /** Priorité des niveaux : A > C > M > B (plus petit = plus fiable). */
    private const RANGS = ['A' => 0, 'C' => 1, 'M' => 2, 'B' => 3];

    /** Mots (entiers) d'un titre qui signalent une annulation ou un report. */
    private const MOTIF_ANNULATION = '/(?<!\p{L})(?:annul[ée]e?s?|annulations?|report[ée]e?s?|cancell?ed|postponed)(?!\p{L})/iu';

    /** Collectes réussies consécutives sans la fiche avant de poser le drapeau « absent ». */
    private const ABSENCES_AVANT_DRAPEAU = 2;

    /**
     * Titre vu par chaque clé de source avant sa dernière intégration par ce dépôt
     * (id => [clé => titre], '' pour une clé nouvelle), pour qu'`annuler_si_necessaire` voie la
     * transition même appelé après `integrer`.
     *
     * @var array<int,array<string,string>>
     */
    private array $titres_precedents = [];

    public function __construct(private Entrepot $entrepot) {
    }

    /** Rang d'un niveau : A=0, C=1, M=2, B=3, inconnu=9. */
    public static function rang(string $niveau): int {
        return self::RANGS[$niveau] ?? 9;
    }

    /**
     * Intègre une fiche collectée : cree, fusionne, mis_a_jour ou inchange.
     */
    public function integrer(Fiche $f): string {
        $cles = $this->cles($f);

        // 1. Déjà connue de cette source : seuls les champs factuels suivent la source, et
        //    seulement si elle est au moins aussi fiable que la fiche (sinon elle comble les vides).
        $cle = self::cle_entrante($f);
        $existante = $this->trouver_par_cles($cles);
        if ($existante !== null) {
            $this->noter_titre_precedent($existante, $cle);
            $champs = self::rang($f->niveau) <= self::rang($existante->niveau)
                ? $this->champs_factuels($existante, $f)
                : $this->champs_a_combler($existante, $f);
            $champs += $this->champ_titres_source($existante, $f, $cle);
            if ($champs === []) {
                return 'inchange';
            }
            $this->entrepot->mettre_a_jour($existante->id, $champs);
            return 'mis_a_jour';
        }

        // 2. Doublon venu d'une autre source : on fusionne, jamais de seconde fiche.
        $existante = $this->trouver_doublon($f);
        if ($existante !== null) {
            $this->noter_titre_precedent($existante, $cle);
            $champs = ['cles_source' => array_values(array_unique(array_merge($existante->cles_source, $cles)))];
            if (self::rang($f->niveau) < self::rang($existante->niveau)) {
                // Source plus fiable : elle fournit dates, heure, URL, image, prix et lieu.
                $champs += $this->champs_factuels($existante, $f);
                $champs['niveau'] = $f->niveau;
                $champs['source'] = $f->source;
            } else {
                // Source égale ou moins fiable : elle ne fait que combler une image ou une URL manquante.
                $champs += $this->champs_a_combler($existante, $f);
            }
            $champs += $this->champ_titres_source($existante, $f, $cle);
            $this->entrepot->mettre_a_jour($existante->id, $champs);
            return 'fusionne';
        }

        // 3. Nouvelle fiche.
        $nouvelle = clone $f;
        $nouvelle->id = null;
        $nouvelle->cles_source = $cles;
        $nouvelle->titres_source = $cle !== '' ? [$cle => $f->titre] : [];
        $id = $this->entrepot->creer($nouvelle);
        if ($cle !== '') {
            $this->titres_precedents[$id][$cle] = '';
        }
        return 'cree';
    }

    /**
     * La fiche est déjà connue (même clé de source, doublon ou même URL) : `integrer()` ne créerait
     * pas de nouvelle fiche. N'écrit rien.
     */
    public function existe(Fiche $f): bool {
        return $this->trouver_par_cles($this->cles($f)) !== null || $this->trouver_doublon($f) !== null;
    }

    /** La fiche enregistrée qui porte une clé de source de $f (sans recherche de doublon), sinon null. N'écrit rien. */
    public function fiche_de_cle(Fiche $f): ?Fiche {
        return $this->trouver_par_cles($this->cles($f));
    }

    /**
     * Repasse « à approuver » une fiche publiée quand le titre de la clé de source entrante se met à
     * annoncer une annulation ou un report (transition sur cette clé seulement, '' si elle est
     * nouvelle : une fiche republiée par l'équipe avec un titre déjà annulé reste publiée).
     * Renvoie vrai si le statut a changé.
     */
    public function annuler_si_necessaire(Fiche $f): bool {
        if (!self::annonce_annulation($f->titre)) {
            return false;
        }
        $existante = $this->trouver_par_cles($this->cles($f)) ?? $this->trouver_doublon($f);
        if ($existante === null || $existante->statut !== 'publie') {
            return false;
        }
        $cle = self::cle_entrante($f);
        $precedent = $this->titres_precedents[$existante->id][$cle] ?? $existante->titres_source[$cle] ?? '';
        if (self::annonce_annulation($precedent)) {
            return false;
        }
        $this->entrepot->mettre_a_jour($existante->id, ['statut' => 'a_approuver']);
        return true;
    }

    /**
     * Après la collecte d'une source : compte les absences des fiches publiées de cette source
     * qui n'ont pas été vues, pose le drapeau à la deuxième collecte réussie consécutive,
     * et le lève pour celles qui sont revues. Une collecte échouée ne compte pas.
     *
     * @param string[] $cles_vues Clés de source lues pendant cette collecte.
     */
    public function signaler_absents(string $id_source, array $cles_vues, bool $collecte_reussie, string $aujourdhui): void {
        if (!$collecte_reussie) {
            return;
        }
        $ids = $this->entrepot->ids_publies_de_source($id_source);
        if ($ids === []) {
            return;
        }
        $prefixe = $id_source . ':';
        $vues = array_flip($cles_vues);
        $publiees = [];
        foreach ($this->entrepot->lister('publie') as $fiche) {
            $publiees[$fiche->id] = $fiche;
        }
        foreach ($ids as $id) {
            $fiche = $publiees[$id] ?? null;
            if ($fiche === null) {
                continue;
            }
            $revue = false;
            foreach ($fiche->cles_source as $cle) {
                if (str_starts_with($cle, $prefixe) && isset($vues[$cle])) {
                    $revue = true;
                    break;
                }
            }
            if (!$revue && !self::evenement_a_venir($fiche, $aujourdhui)) {
                // Les lecteurs ne rendent plus les événements finis, et les appels expirent par leur échéance.
                continue;
            }
            if ($revue) {
                if ($fiche->absences !== 0) {
                    $this->entrepot->mettre_a_jour($id, ['absences' => 0]);
                }
                if ($fiche->absent_depuis !== null) {
                    $this->entrepot->lever_absent($id);
                }
                continue;
            }
            $absences = $fiche->absences + 1;
            $this->entrepot->mettre_a_jour($id, ['absences' => $absences]);
            if ($absences >= self::ABSENCES_AVANT_DRAPEAU && $fiche->absent_depuis === null) {
                $this->entrepot->marquer_absent($id, $aujourdhui);
            }
        }
    }

    private static function annonce_annulation(string $titre): bool {
        return preg_match(self::MOTIF_ANNULATION, $titre) === 1;
    }

    /** Événement pas encore fini (fin, sinon début, aujourd'hui ou plus tard). */
    private static function evenement_a_venir(Fiche $f, string $aujourdhui): bool {
        $date = ($f->fin ?? '') !== '' ? $f->fin : ($f->debut ?? '');
        return $f->type === 'evenement' && $date !== '' && $date >= $aujourdhui;
    }

    /**
     * Image et URL de l'entrant là où la fiche n'en a pas.
     *
     * @return array<string,string>
     */
    private function champs_a_combler(Fiche $existante, Fiche $entrante): array {
        $champs = [];
        foreach (self::CHAMPS_A_COMBLER as $nom) {
            if ($existante->$nom === '' && $entrante->$nom !== '') {
                $champs[$nom] = $entrante->$nom;
            }
        }
        return $champs;
    }

    /** Clé de source de la fiche entrante : `cle_source`, sinon la première de `cles_source`. */
    private static function cle_entrante(Fiche $f): string {
        return $f->cle_source !== '' ? $f->cle_source : (string) ($f->cles_source[0] ?? '');
    }

    private function noter_titre_precedent(Fiche $existante, string $cle): void {
        if ($cle !== '') {
            $this->titres_precedents[$existante->id][$cle] = $existante->titres_source[$cle] ?? '';
        }
    }

    /**
     * Titre vu par la clé entrante, quel que soit le niveau de la source. Seule l'entrée de cette clé
     * est modifiée : une autre source au titre différent ne change rien.
     *
     * @return array<string,array<string,string>>
     */
    private function champ_titres_source(Fiche $existante, Fiche $entrante, string $cle): array {
        if ($cle === '' || $entrante->titre === '' || ($existante->titres_source[$cle] ?? null) === $entrante->titre) {
            return [];
        }
        $titres = $existante->titres_source;
        $titres[$cle] = $entrante->titre;
        return ['titres_source' => $titres];
    }

    /** @return string[] Clés de source de la fiche (`cles_source` et `cle_source`), sans doublon ni vide. */
    private function cles(Fiche $f): array {
        $cles = array_merge($f->cles_source, [$f->cle_source]);
        return array_values(array_unique(array_filter($cles, static fn(string $c): bool => $c !== '')));
    }

    private function trouver_par_cles(array $cles): ?Fiche {
        foreach ($cles as $cle) {
            $fiche = $this->entrepot->trouver_par_cle_source($cle);
            if ($fiche !== null) {
                return $fiche;
            }
        }
        return null;
    }

    /** Même date de début et même début de titre (si la fiche a une date), sinon même URL. */
    private function trouver_doublon(Fiche $f): ?Fiche {
        $fiche = null;
        if (($f->debut ?? '') !== '') {
            $fiche = $this->entrepot->trouver_par_dedoublonnage($f->cle_dedoublonnage());
        }
        if ($fiche === null && $f->url !== '') {
            $fiche = $this->entrepot->trouver_par_url($f->url);
        }
        return $fiche;
    }

    /**
     * Champs factuels de l'entrant non vides et différents de l'existant ; la région est refaite
     * si la ville ou le lieu changent.
     *
     * @return array<string,mixed>
     */
    private function champs_factuels(Fiche $existante, Fiche $entrante): array {
        $champs = [];
        foreach (self::CHAMPS_FACTUELS as $nom) {
            $valeur = $entrante->$nom;
            if ($valeur !== null && $valeur !== '' && $valeur !== $existante->$nom) {
                $champs[$nom] = $valeur;
            }
        }
        if (isset($champs['ville']) || isset($champs['lieu'])) {
            $region = Fiche::deviner_region(
                $champs['ville'] ?? $existante->ville,
                $champs['lieu'] ?? $existante->lieu,
                $existante->format
            );
            if ($region !== $existante->region) {
                $champs['region'] = $region;
            }
        }
        return $champs;
    }
}

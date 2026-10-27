<?php
declare(strict_types=1);

namespace RSN\Veille;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Entrepôt en mémoire, pour les tests. Les fiches sont copiées à l'entrée et à la sortie :
 * modifier une fiche rendue ne modifie pas l'entrepôt.
 */
final class EntrepotMemoire implements Entrepot {
    /** @var array<int,Fiche> */
    private array $fiches = [];
    private int $prochain_id = 1;

    public function trouver_par_cle_source(string $cle): ?Fiche {
        return $this->premiere(static fn(Fiche $f): bool => in_array($cle, $f->cles_source, true));
    }

    public function trouver_par_dedoublonnage(string $cle): ?Fiche {
        return $this->premiere(static fn(Fiche $f): bool => !$f->est_proposition() && $f->cle_dedoublonnage() === $cle);
    }

    public function trouver_par_url(string $url): ?Fiche {
        if ($url === '') {
            return null;
        }
        return $this->premiere(static fn(Fiche $f): bool => !$f->est_proposition() && $f->url === $url);
    }

    public function creer(Fiche $f): int {
        $copie = clone $f;
        $copie->id = $this->prochain_id++;
        $copie->cree_le ??= \rsn_veille_aujourdhui();
        $this->fiches[$copie->id] = $copie;
        return $copie->id;
    }

    public function mettre_a_jour(int $id, array $champs): void {
        $fiche = $this->fiche($id);
        foreach ($champs as $nom => $valeur) {
            if ($nom === 'id' || !property_exists($fiche, $nom)) {
                throw new \InvalidArgumentException('Champ inconnu ou non modifiable : ' . $nom);
            }
            $fiche->$nom = $valeur;
        }
    }

    public function lister(string $statut = 'publie', string $type = ''): array {
        $resultat = [];
        foreach ($this->fiches as $fiche) {
            if (($statut === '' || $fiche->statut === $statut) && ($type === '' || $fiche->type === $type)) {
                $resultat[] = clone $fiche;
            }
        }
        return $resultat;
    }

    public function ids_publies_de_source(string $id_source): array {
        $prefixe = $id_source . ':';
        $ids = [];
        foreach ($this->fiches as $id => $fiche) {
            if ($fiche->statut !== 'publie') {
                continue;
            }
            foreach ($fiche->cles_source as $cle) {
                if (str_starts_with($cle, $prefixe)) {
                    $ids[] = $id;
                    break;
                }
            }
        }
        return $ids;
    }

    public function marquer_absent(int $id, string $depuis): void {
        $this->fiche($id)->absent_depuis = $depuis;
    }

    public function lever_absent(int $id): void {
        $this->fiche($id)->absent_depuis = null;
    }

    /** Copie de la première fiche qui satisfait le critère, sinon null. */
    private function premiere(callable $critere): ?Fiche {
        foreach ($this->fiches as $fiche) {
            if ($critere($fiche)) {
                return clone $fiche;
            }
        }
        return null;
    }

    /** La fiche stockée elle-même (pas une copie). */
    private function fiche(int $id): Fiche {
        if (!isset($this->fiches[$id])) {
            throw new \InvalidArgumentException('Fiche inconnue : ' . $id);
        }
        return $this->fiches[$id];
    }
}

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

    public function obtenir(int $id): ?Fiche {
        return isset($this->fiches[$id]) ? clone $this->fiches[$id] : null;
    }

    public function lister_par_ids(array $ids): array {
        $voulus = array_flip(array_map('intval', $ids));
        return $this->filtrer(static fn(Fiche $f): bool => isset($voulus[$f->id]));
    }

    public function compter(): array {
        $compteurs = ['a_approuver' => 0, 'publie' => 0, 'ecarte' => 0, 'a_verifier' => 0];
        foreach ($this->fiches as $fiche) {
            if (isset($compteurs[$fiche->statut])) {
                $compteurs[$fiche->statut]++;
            }
            if (self::absente($fiche)) {
                $compteurs['a_verifier']++;
            }
        }
        return $compteurs;
    }

    public function lister_absentes(): array {
        return $this->filtrer(static fn(Fiche $f): bool => self::absente($f));
    }

    public function lister_crees_depuis(string $date, string $statut = ''): array {
        return $this->filtrer(static fn(Fiche $f): bool => ($statut === '' || $f->statut === $statut)
            && $f->cree_le !== null && $f->cree_le >= $date);
    }

    public function lister_recentes(string $statut, int $max, string $type = ''): array {
        $fiches = array_reverse($this->filtrer(static fn(Fiche $f): bool => $f->statut === $statut && ($type === '' || $f->type === $type)));
        return array_slice($fiches, 0, max(0, $max));
    }

    public function lister_pour_page(string $limite_evenements, string $aujourdhui, string $limite_appels): array {
        $au_moins = static fn(?string $date, string $limite): bool => ($date ?? '') !== '' && $date >= $limite;
        return $this->filtrer(static function (Fiche $f) use ($au_moins, $limite_evenements, $aujourdhui, $limite_appels): bool {
            if ($f->statut !== 'publie') {
                return false;
            }
            if ($f->type === 'evenement') {
                return ($f->debut ?? '') === '' || $au_moins($f->debut, $limite_evenements) || $au_moins($f->fin, $limite_evenements);
            }
            if ($f->type === 'appel') {
                return $au_moins($f->echeance, $aujourdhui) || $au_moins($f->fin, $aujourdhui)
                    || $au_moins($f->debut, $limite_appels) || $au_moins($f->cree_le, $limite_appels)
                    || (($f->debut ?? '') === '' && ($f->cree_le ?? '') === '');
            }
            return false;
        });
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

    /** @return Fiche[] Copies des fiches qui satisfont le critère, par identifiant croissant. */
    private function filtrer(callable $critere): array {
        $resultat = [];
        foreach ($this->fiches as $fiche) {
            if ($critere($fiche)) {
                $resultat[] = clone $fiche;
            }
        }
        return $resultat;
    }

    /** Publiée et signalée absente de sa source. */
    private static function absente(Fiche $f): bool {
        return $f->statut === 'publie' && ($f->absent_depuis ?? '') !== '';
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

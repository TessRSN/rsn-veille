<?php
declare(strict_types=1);

namespace RSN\Veille\Admin;

use RSN\Veille\Collecte;
use RSN\Veille\Sources;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Nettoyage de l'option `rsn_veille_reglages` (rappel `sanitize_callback` de l'API Settings, via
 * `PageReglages::nettoyer`). Seuls WordPress `wp_roles`, `get_post`, `is_email`, `sanitize_*` et
 * `add_settings_error` sont appelés ; aucune écriture.
 */
final class NettoyageReglages {
    public const HEURE_PAR_DEFAUT = '03:00';
    /** Jour du courriel hebdomadaire par défaut : lundi (0 = dimanche, comme `date('w')`). */
    public const JOUR_PAR_DEFAUT = 1;
    private const NIVEAUX = ['A', 'B', 'C', 'M'];

    /** Vrai pendant la relecture de l'option pour l'affichage : aucun message de champ. */
    private static bool $silencieux = false;

    /**
     * Réglage nettoyé, champ par champ. Accepte les valeurs du formulaire (zones de texte) comme celles
     * déjà enregistrées (listes), et rend toujours la forme enregistrée : nettoyer son résultat ne
     * change rien. Une valeur invalide reprend sa valeur par défaut et `add_settings_error` le signale.
     * Les clés inconnues sont retirées.
     *
     * @return array{sources: array<string,array{actif: bool, niveau: string}>, mots_sante: string[], mots_numerique: string[],
     *               themes: array<int,array{nom: string, mots: string[]}>, roles: string[],
     *               courriel: array{actif: bool, destinataires: string[], jour: int}, heure_collecte: string, page_veille_id: int}
     */
    public static function nettoyer(mixed $brut): array {
        $brut = is_array($brut) ? $brut : [];
        return [
            'sources' => self::nettoyer_sources($brut['sources'] ?? null),
            'mots_sante' => self::mots($brut['mots_sante'] ?? []),
            'mots_numerique' => self::mots($brut['mots_numerique'] ?? []),
            'themes' => self::nettoyer_themes($brut['themes'] ?? []),
            'roles' => self::nettoyer_roles($brut),
            'courriel' => self::nettoyer_courriel($brut['courriel'] ?? null),
            'heure_collecte' => self::nettoyer_heure($brut['heure_collecte'] ?? ''),
            'page_veille_id' => self::nettoyer_page($brut['page_veille_id'] ?? 0),
        ];
    }

    /**
     * Mots-clés : un par ligne (ou une liste), en minuscules, lignes vides retirées, sans doublon.
     * Les espaces de bordure sont gardées : une espace au début ou à la fin veut dire « mot entier de
     * ce côté » pour la pertinence (« ia » ne doit pas toucher « mai »).
     *
     * @return string[]
     */
    public static function mots(mixed $valeur): array {
        $lignes = is_string($valeur) ? preg_split('/\r\n|\r|\n/', $valeur) : (is_array($valeur) ? $valeur : []);
        $mots = [];
        foreach ($lignes as $ligne) {
            $mot = is_scalar($ligne) ? self::mot((string) $ligne) : '';
            if ($mot !== '') {
                $mots[] = $mot;
            }
        }
        return array_values(array_unique($mots));
    }

    /**
     * Thèmes en texte, une ligne par thème : « Nom : mot1, mot2 ». La première espace après les deux
     * points et après chaque virgule sert de séparation ; une deuxième espace appartient au mot.
     *
     * @param array<int,array{nom: string, mots: string[]}> $themes
     */
    public static function themes_en_texte(array $themes): string {
        $lignes = [];
        foreach ($themes as $theme) {
            $lignes[] = $theme['nom'] . ' : ' . implode(', ', $theme['mots']);
        }
        return implode("\n", $lignes);
    }

    /** @return array<string,array{actif: bool, niveau: string}> Toutes les sources connues, et elles seules. */
    private static function nettoyer_sources(mixed $valeur): array {
        $reglages = is_array($valeur) ? $valeur : [];
        $sources = [];
        foreach (Sources::par_defaut() as $id => $defaut) {
            $reglage = $reglages[$id] ?? null;
            if (!is_array($reglage)) {
                $sources[$id] = ['actif' => (bool) $defaut['actif'], 'niveau' => (string) $defaut['niveau']];
                continue;
            }
            // Case décochée : le navigateur n'envoie rien, la source est désactivée.
            $actif = filter_var($reglage['actif'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $niveau = $reglage['niveau'] ?? $defaut['niveau'];
            if (!in_array($niveau, self::NIVEAUX, true)) {
                /* translators: %s : nom de la source. */
                self::signaler('sources', sprintf(__('Niveau inconnu pour « %s » : niveau par défaut repris.', 'rsn-veille'), (string) $defaut['nom']));
                $niveau = $defaut['niveau'];
            }
            $sources[$id] = ['actif' => $actif, 'niveau' => (string) $niveau];
        }
        return $sources;
    }

    /** @return array<int,array{nom: string, mots: string[]}> */
    private static function nettoyer_themes(mixed $valeur): array {
        $themes = [];
        if (is_array($valeur)) {
            // Forme enregistrée.
            foreach ($valeur as $theme) {
                $nom = is_array($theme) && is_string($theme['nom'] ?? null) ? sanitize_text_field($theme['nom']) : '';
                $mots = is_array($theme) ? self::mots($theme['mots'] ?? []) : [];
                if ($nom !== '' && $mots !== []) {
                    $themes[] = ['nom' => $nom, 'mots' => $mots];
                }
            }
            return $themes;
        }
        $lignes = is_string($valeur) ? preg_split('/\r\n|\r|\n/', $valeur) : [];
        foreach ($lignes as $numero => $ligne) {
            if (trim($ligne) === '') {
                continue;
            }
            $position = strpos($ligne, ':');
            $nom = $position === false ? '' : sanitize_text_field(substr($ligne, 0, $position));
            $mots = [];
            if ($position !== false) {
                foreach (explode(',', substr($ligne, $position + 1)) as $morceau) {
                    // Une espace de séparation après « : » et après « , » ; le reste appartient au mot.
                    $mots[] = str_starts_with($morceau, ' ') ? substr($morceau, 1) : $morceau;
                }
                $mots = self::mots($mots);
            }
            if ($nom === '' || $mots === []) {
                /* translators: %d : numéro de la ligne. */
                self::signaler('themes', sprintf(__('Thème ignoré (ligne %d) : le format attendu est « Nom : mot1, mot2 ».', 'rsn-veille'), $numero + 1));
                continue;
            }
            $themes[] = ['nom' => $nom, 'mots' => $mots];
        }
        return $themes;
    }

    /** @return string[] Rôles existants cochés, l'administrateur toujours en premier ; réglage absent : rôles par défaut. */
    private static function nettoyer_roles(array $brut): array {
        if (!array_key_exists('roles', $brut)) {
            return Menu::ROLES_PAR_DEFAUT;
        }
        $existants = array_keys(wp_roles()->roles);
        $roles = ['administrator'];
        foreach (is_array($brut['roles']) ? $brut['roles'] : [] as $role) {
            if (is_string($role) && in_array($role, $existants, true)) {
                $roles[] = $role;
            }
        }
        return array_values(array_unique($roles));
    }

    /** @return array{actif: bool, destinataires: string[], jour: int} */
    private static function nettoyer_courriel(mixed $valeur): array {
        $valeur = is_array($valeur) ? $valeur : [];
        $adresses = $valeur['destinataires'] ?? [];
        $adresses = is_string($adresses) ? preg_split('/[\r\n]+/', $adresses) : (is_array($adresses) ? $adresses : []);
        $destinataires = [];
        foreach ($adresses as $adresse) {
            $adresse = is_string($adresse) ? trim($adresse) : '';
            if ($adresse === '') {
                continue;
            }
            if (is_email($adresse) === false) {
                /* translators: %s : adresse refusée. */
                self::signaler('courriel', sprintf(__('Adresse de courriel invalide retirée : %s', 'rsn-veille'), sanitize_text_field($adresse)));
                continue;
            }
            $destinataires[] = sanitize_email($adresse);
        }
        $jour = $valeur['jour'] ?? self::JOUR_PAR_DEFAUT;
        if (filter_var($jour, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 6]]) === false) {
            self::signaler('courriel_jour', __('Jour du courriel invalide : lundi repris.', 'rsn-veille'));
            $jour = self::JOUR_PAR_DEFAUT;
        }
        return [
            'actif' => filter_var($valeur['actif'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'destinataires' => array_values(array_unique($destinataires)),
            'jour' => (int) $jour,
        ];
    }

    private static function nettoyer_heure(mixed $valeur): string {
        $valeur = is_string($valeur) ? trim($valeur) : '';
        if ($valeur !== '' && self::heure($valeur, '') === '') {
            self::signaler('heure_collecte', __('Heure de collecte invalide (format HH:MM) : 03:00 reprise.', 'rsn-veille'));
        }
        return self::heure($valeur);
    }

    /** Une page (publiée ou non, hors corbeille), sinon 0. */
    private static function nettoyer_page(mixed $valeur): int {
        $id = is_scalar($valeur) ? absint($valeur) : 0;
        if ($id === 0) {
            return 0;
        }
        $page = get_post($id);
        if ($page === null || $page->post_type !== 'page' || $page->post_status === 'trash') {
            self::signaler('page_veille_id', __('La page Veille choisie est introuvable : aucune page retenue.', 'rsn-veille'));
            return 0;
        }
        return $id;
    }

    /** Heure H:i normalisée (« 7:05 » → « 07:05 »), sinon $defaut. */
    public static function heure(mixed $valeur, string $defaut = self::HEURE_PAR_DEFAUT): string {
        if (!is_string($valeur) || preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($valeur), $m) !== 1) {
            return $defaut;
        }
        return sprintf('%02d:%s', (int) $m[1], $m[2]);
    }

    /** Un mot-clé : caractères de contrôle retirés, minuscules ; les espaces de bordure restent. Vide s'il n'y a que des espaces. */
    private static function mot(string $texte): string {
        if (!mb_check_encoding($texte, 'UTF-8')) {
            return '';
        }
        $texte = mb_strtolower((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $texte), 'UTF-8');
        return trim($texte) === '' ? '' : $texte;
    }

    private static function signaler(string $champ, string $message): void {
        if (self::$silencieux) {
            return;
        }
        add_settings_error(Collecte::OPTION_REGLAGES, 'rsn_veille_' . $champ, $message, 'error');
    }

    /** `nettoyer` sans laisser de message : relit l'option enregistrée pour l'affichage. */
    public static function relire(array $reglages): array {
        self::$silencieux = true;
        try {
            return self::nettoyer($reglages);
        } finally {
            self::$silencieux = false;
        }
    }
}

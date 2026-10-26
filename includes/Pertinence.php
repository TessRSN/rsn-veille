<?php
declare(strict_types=1);

namespace RSN\Veille;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Règles de pertinence : repérage des mots-clés santé et numérique, thèmes
 * d'une fiche et statut initial selon le type de source (devis § 4.2).
 *
 * Classe pure : aucune fonction WordPress n'est appelée.
 */
final class Pertinence {
    /** @var string[] */
    private array $mots_sante;
    /** @var string[] */
    private array $mots_numerique;
    /** @var array<int, array{nom: string, mots: string[]}> */
    private array $themes;

    /**
     * @param array $config Clés `mots_sante`, `mots_numerique` et `themes` ;
     *                      une clé absente prend sa valeur par défaut.
     */
    public function __construct(array $config = []) {
        $config = $config + self::config_par_defaut();
        $this->mots_sante = array_values($config['mots_sante']);
        $this->mots_numerique = array_values($config['mots_numerique']);
        $this->themes = array_values($config['themes']);
    }

    /**
     * Listes validées sur les données réelles. La correspondance se fait en
     * minuscules, sans pliage des accents : les listes portent les deux formes
     * quand c'est utile. Une entrée qui commence ou finit par une espace est
     * cherchée comme mot entier de ce côté (« ia » ne doit pas toucher « mai »).
     */
    public static function config_par_defaut(): array {
        return [
            'mots_sante' => [
                'santé', 'sante', 'health', 'cliniq', 'clinic', 'médic', 'medic', 'patient', 'soins',
                'care ', 'hospital', 'hôpital', 'biomédic', 'biomedic', 'épidémio', 'epidemio',
                'pharma', 'génom', 'genom', 'neuro', 'cancer', 'sommeil', 'sleep', 'vieillissement',
                'aging', 'réadaptation', 'rehabilit', 'douleur', 'pain', 'santé mentale', 'mental health', 'psychiatr', 'cardio', 'diabèt',
                'télésanté', 'telehealth', 'maladie', 'disease', 'infirm', 'nursing', 'physio',
                'radiolog', 'imagerie', 'cohorte', 'biobanque', 'dsn', 'dossier', 'handicap',
                'autisme', 'obésité',
            ],
            'mots_numerique' => [
                'numérique', 'numerique', 'digital', 'intelligence artificielle', ' ia ',
                "l'ia", 'l’ia', ' ai ', ' ai-', 'données', 'donnees', 'data', 'algorithm',
                'apprentissage', 'machine learning', 'deep learning', 'interopérab', 'cyber',
                'informati', 'technolog', 'plateforme', 'platform', 'logiciel', 'software', 'modèle',
                'model', 'statisti', 'quantique', 'robot', 'capteur', 'sensor', 'agent', 'chatbot',
                'hackathon', 'web', 'cloud', 'infonuag', 'ehealth', 'e-health', 'virtuel',
            ],
            // L'ordre est la priorité d'affichage.
            'themes' => [
                ['nom' => 'IA', 'mots' => ['intelligence artificielle', ' ia ', "l'ia", 'l’ia', ' ai ', 'apprentissage', 'machine learning', 'agent', 'algorithm', 'chatbot', 'modèle']],
                ['nom' => 'Données', 'mots' => ['données', 'data', 'cohorte', 'biobanque', 'interopérab', 'dossier']],
                ['nom' => 'Santé publique', 'mots' => ['santé publique', 'public health', 'population', 'épidémio']],
                ['nom' => 'Vieillissement', 'mots' => ['vieillissement', 'aging', 'âgé', 'aîné']],
                ['nom' => 'Sommeil', 'mots' => ['sommeil', 'sleep', 'somno']],
                ['nom' => 'Gouvernance', 'mots' => ['gouvernance', 'souveraineté', 'réglement', 'règlement', 'regulat', 'politique', 'policy']],
                ['nom' => 'Éthique', 'mots' => ['éthique', 'ethic', 'responsab', 'confiance', 'trust']],
                ['nom' => 'Formation', 'mots' => [' formation', "école d'été", 'cours', 'atelier', 'workshop', 'training', 'tutoriel']],
                ['nom' => 'Réseautage', 'mots' => ['réseautage', 'networking', 'petit-déjeuner', 'breakfast', '5 à 7', 'communauté de pratique']],
                ['nom' => 'Financement', 'mots' => ['financement', 'bourse', 'subvention', 'concours', 'funding', 'grant']],
                ['nom' => 'Appel à communications', 'mots' => ['appel à communications', 'appel à contributions', 'call for', 'soumettre']],
                ['nom' => 'Innovation', 'mots' => ['innovation', 'entrepreneur', 'start-up', 'startup', 'commercialis']],
                ['nom' => 'Science ouverte', 'mots' => ['science ouverte', 'open science', 'libre accès']],
                ['nom' => 'Interdisciplinarité', 'mots' => ['interdisciplin', 'intersectoriel']],
            ],
        ];
    }

    /**
     * @return array{sante: bool, numerique: bool}
     */
    public function touche(string $texte): array {
        $texte = self::normaliser($texte);
        return [
            'sante' => self::contient_un($texte, $this->mots_sante),
            'numerique' => self::contient_un($texte, $this->mots_numerique),
        ];
    }

    /**
     * Noms des thèmes touchés, dans l'ordre de la configuration, au plus $max.
     *
     * @return string[]
     */
    public function themes(string $texte, int $max = 2): array {
        $texte = self::normaliser($texte);
        $touches = [];
        foreach ($this->themes as $theme) {
            if (count($touches) >= $max) {
                break;
            }
            if (self::contient_un($texte, $theme['mots'])) {
                $touches[] = $theme['nom'];
            }
        }
        return $touches;
    }

    /**
     * Statut initial d'une fiche : `publie`, `a_approuver` ou `ecarte`.
     */
    public function statut(string $type_source, string $texte): string {
        $touche = $this->touche($texte);
        switch ($type_source) {
            case 'sante_numerique':
                return 'publie';
            case 'sante':
                return $touche['numerique'] ? 'publie' : 'a_approuver';
            case 'numerique':
                return $touche['sante'] ? 'publie' : 'a_approuver';
            case 'generaliste':
                if ($touche['sante'] && $touche['numerique']) {
                    return 'publie';
                }
                return ($touche['sante'] || $touche['numerique']) ? 'a_approuver' : 'ecarte';
            default: // proposition et tout type inconnu
                return 'a_approuver';
        }
    }

    /** Minuscules, encadré d'une espace de chaque côté. */
    private static function normaliser(string $texte): string {
        return ' ' . mb_strtolower($texte, 'UTF-8') . ' ';
    }

    /**
     * Une entrée qui commence ou finit par une espace est un mot entier de ce
     * côté : l'espace de bordure est retirée et le mot ne doit pas être collé à
     * une lettre ou à un chiffre. Les autres entrées sont des sous-chaînes.
     *
     * @param string[] $mots
     */
    private static function contient_un(string $texte, array $mots): bool {
        foreach ($mots as $mot) {
            $nu = trim($mot);
            if ($nu === '') {
                continue;
            }
            $debut = $mot[0] === ' ';
            $fin = substr($mot, -1) === ' ';
            if (!$debut && !$fin) {
                if (str_contains($texte, $mot)) {
                    return true;
                }
                continue;
            }
            $motif = ($debut ? '(?<![\p{L}\p{N}])' : '') . preg_quote($nu, '/') . ($fin ? '(?![\p{L}\p{N}])' : '');
            if (preg_match('/' . $motif . '/u', $texte) === 1) {
                return true;
            }
        }
        return false;
    }
}

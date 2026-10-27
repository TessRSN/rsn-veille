<?php
declare(strict_types=1);

namespace RSN\Veille;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Une fiche de veille (événement, appel ou nouvelle) et ses règles de normalisation.
 *
 * Classe pure : aucune fonction WordPress, seulement du PHP standard.
 */
final class Fiche {
    /** Villes du Québec reconnues pour deviner la région (sans accents, en minuscules). */
    private const VILLES_QUEBEC = [
        'montreal', 'quebec', 'laval', 'sherbrooke', 'trois-rivieres', 'drummondville',
        'gatineau', 'longueuil', 'brossard', 'rimouski', 'saguenay', 'chicoutimi',
        'levis', 'granby', 'shawinigan', 'saint-hyacinthe', 'saint-jean-sur-richelieu',
        'saint-jerome', 'terrebonne', 'repentigny', 'boucherville', 'victoriaville',
        'rouyn-noranda', 'sept-iles', 'riviere-du-loup', 'baie-comeau', 'val-d\'or', 'joliette',
        'magog', 'mont-tremblant', 'sainte-anne-de-bellevue', 'pointe-claire', 'dorval',
        'westmount',
    ];

    /** Mots qui indiquent une activité en ligne. */
    private const MOTS_VIRTUELS = [
        'en ligne', 'virtuel', 'zoom', 'webinaire', 'webinar', 'online', 'virtual', 'en direct',
    ];

    /** Correspondance des lettres accentuées vers leur forme de base. */
    private const ACCENTS = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
        'ç' => 'c',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ñ' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'ý' => 'y', 'ÿ' => 'y',
        'œ' => 'oe', 'æ' => 'ae',
    ];

    /** Source des fiches proposées par le public (formulaire de la page Veille). */
    public const SOURCE_PROPOSITION = 'proposition';

    /** Âge maximal, en jours, d'un appel sans date limite connue (voir `appel_en_cours`). */
    public const JOURS_APPEL_SANS_LIMITE = 90;

    /** @var string evenement|appel|nouvelle */
    public string $type = '';
    public string $titre = '';
    public ?string $debut = null;
    public ?string $fin = null;
    public string $heure = '';
    public string $organisateur = '';
    public string $categorie_rsn = '';
    public string $lieu = '';
    public string $ville = '';
    public string $region = '';
    public string $format = '';
    public string $prix = '';
    public string $langue = 'fr';
    /** @var string[] */
    public array $themes = [];
    public string $resume = '';
    public string $url = '';
    public string $image_url = '';
    public string $source = '';
    /** @var string A|B|C|M */
    public string $niveau = '';
    public string $statut = 'a_approuver';
    /** Identifiant stable de la source, au format « source:id ». */
    public string $cle_source = '';
    /** @var string[] Toutes les clés de source fusionnées sur cette fiche. */
    public array $cles_source = [];
    /** @var array<string,string> Dernier titre vu par clé de source (posé et suivi par le dépôt, pour repérer une annulation). */
    public array $titres_source = [];
    /** Catégorie propre aux appels. */
    public string $categorie = '';
    /** Date limite propre aux appels (Y-m-d). */
    public ?string $echeance = null;
    // Champs de persistance.
    public ?int $id = null;
    public ?string $absent_depuis = null;
    public int $absences = 0;
    public bool $manuel = false;
    /** Jour de création de la fiche dans l'extension (Y-m-d), posé par l'entrepôt ; sert au courriel hebdomadaire. */
    public ?string $cree_le = null;

    /**
     * Texte brut : sections CDATA dépliées, entités décodées, balises retirées, espaces réduits.
     * Les émojis sont conservés.
     */
    public static function nettoyer_texte(string $html): string {
        $texte = preg_replace('/<!\[CDATA\[(.*?)\]\]>/s', '$1', $html) ?? $html;
        $texte = html_entity_decode($texte, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Les balises de bloc et les sauts de ligne deviennent une espace (pas de mots collés).
        $texte = preg_replace('/<\/?(?:br|p|div|li|ul|ol|h[1-6]|tr|td|th|table|blockquote|section|article)\b[^>]*>/i', ' ', $texte) ?? $texte;
        $texte = strip_tags($texte);
        $texte = preg_replace('/[\s\x{00A0}]+/u', ' ', $texte) ?? $texte;
        return trim($texte);
    }

    /**
     * Texte raccourci à $max caractères, coupé au dernier mot entier, avec « … ».
     */
    public static function court(string $texte, int $max = 260): string {
        if (mb_strlen($texte) <= $max) {
            return $texte;
        }
        $coupe = mb_substr($texte, 0, $max);
        // Si la coupure tombe pile sur une fin de mot, on garde tout ; sinon on recule au dernier espace.
        if (!preg_match('/\s/u', mb_substr($texte, $max, 1))) {
            $position = mb_strrpos($coupe, ' ');
            if ($position !== false) {
                $coupe = mb_substr($coupe, 0, $position);
            }
        }
        $coupe = rtrim($coupe, " \t\n\r,;:");
        return $coupe . '…';
    }

    /**
     * Devine le format (Virtuel, Hybride, Présentiel) à partir d'un texte libre.
     */
    public static function deviner_format(string $texte, bool $virtuel = false): string {
        if ($virtuel) {
            return 'Virtuel';
        }
        $minuscules = mb_strtolower($texte);
        if (str_contains($minuscules, 'hybride') || str_contains($minuscules, 'hybrid')) {
            return 'Hybride';
        }
        foreach (self::MOTS_VIRTUELS as $mot) {
            if (str_contains($minuscules, $mot)) {
                return 'Virtuel';
            }
        }
        return 'Présentiel';
    }

    /**
     * Devine la région (En ligne, Québec, Hors Québec). Sans ville ni lieu : Québec (sources québécoises).
     */
    public static function deviner_region(string $ville, string $lieu, string $format): string {
        if ($format === 'Virtuel') {
            return 'En ligne';
        }
        $endroit = self::plier($ville . ' ' . $lieu);
        if ($endroit === '') {
            return 'Québec';
        }
        // Mentions explicites (mot entier) : « QC », « Québec », « Quebec ».
        $mots = ' ' . $endroit . ' ';
        if (str_contains($mots, ' qc ')) {
            return 'Québec';
        }
        foreach (self::VILLES_QUEBEC as $ville_quebec) {
            if (str_contains($mots, ' ' . self::plier($ville_quebec) . ' ')) {
                return 'Québec';
            }
        }
        return 'Hors Québec';
    }

    /**
     * Clé de dédoublonnage : « début|22 premiers caractères du titre » (minuscules, sans accents,
     * seulement lettres et chiffres : ponctuation, espaces et émojis disparaissent).
     */
    public function cle_dedoublonnage(): string {
        $titre = preg_replace('/[^a-z0-9]/', '', self::simplifier($this->titre)) ?? '';
        return ($this->debut ?? '') . '|' . substr($titre, 0, 22);
    }

    /**
     * Fiche proposée par le public : jamais cible du dédoublonnage (une collecte ne fusionne pas un vrai
     * événement dans une proposition, qui garderait les dates et l'URL du proposant).
     */
    public function est_proposition(): bool {
        return $this->source === self::SOURCE_PROPOSITION;
    }

    /**
     * Date limite d'un appel (Y-m-d) : l'échéance, sinon une fin distincte du début (un lecteur qui ne
     * trouve pas d'échéance met la fin au jour de publication), sinon null.
     */
    public function limite_appel(): ?string {
        if (($this->echeance ?? '') !== '') {
            return $this->echeance;
        }
        if (($this->fin ?? '') !== '' && $this->fin !== $this->debut) {
            return $this->fin;
        }
        return null;
    }

    /**
     * Appel encore en cours le jour $aujourdhui (Y-m-d), règle unique de la page publique, du JSON, de
     * l'iCal, de l'infolettre et du courriel : sa date limite (`limite_appel`) n'est pas passée ; sans date
     * limite, il a été publié il y a moins de 90 jours. La date de publication est le début (date de la
     * source), sinon le jour de création dans la veille : une première collecte ne rajeunit pas un appel
     * publié il y a des mois. Sans aucune date, l'appel n'est pas tenu pour en cours.
     */
    public function appel_en_cours(string $aujourdhui): bool {
        $limite = $this->limite_appel();
        if ($limite !== null) {
            return $limite >= $aujourdhui;
        }
        $publication = ($this->debut ?? '') !== '' ? $this->debut : (string) ($this->cree_le ?? '');
        $jour = \DateTimeImmutable::createFromFormat('!Y-m-d', $aujourdhui);
        if ($publication === '' || $jour === false) {
            return false;
        }
        return $publication > $jour->modify('-' . self::JOURS_APPEL_SANS_LIMITE . ' days')->format('Y-m-d');
    }

    /**
     * Toutes les propriétés publiques, sous forme de tableau.
     */
    public function vers_tableau(): array {
        return get_object_vars($this);
    }

    /**
     * Reconstruit une fiche depuis un tableau : clés inconnues ignorées, valeurs par défaut pour les absentes.
     */
    public static function depuis_tableau(array $a): Fiche {
        $fiche = new self();
        foreach (array_keys(get_object_vars($fiche)) as $nom) {
            if (!array_key_exists($nom, $a)) {
                continue;
            }
            $valeur = $a[$nom];
            $type = (new \ReflectionProperty($fiche, $nom))->getType();
            if ($valeur === null) {
                if ($type->allowsNull()) {
                    $fiche->$nom = null;
                }
                continue;
            }
            if ($valeur === '' && $type->allowsNull()) {
                $fiche->$nom = null;
                continue;
            }
            $fiche->$nom = match ($type->getName()) {
                'int' => (int) $valeur,
                'bool' => self::vers_booleen($valeur),
                'array' => is_array($valeur) ? self::vers_liste_de_chaines($valeur) : [],
                default => (string) $valeur,
            };
        }
        return $fiche;
    }

    /** Valeur booléenne tolérante : '0', '', 'false', false et 0 sont faux. */
    private static function vers_booleen(mixed $valeur): bool {
        if (is_string($valeur)) {
            return !in_array(strtolower(trim($valeur)), ['', '0', 'false'], true);
        }
        return (bool) $valeur;
    }

    /**
     * Les éléments scalaires, convertis en chaînes. Une liste est renumérotée ; un tableau associatif
     * garde ses clés.
     *
     * @return array<string>|array<string,string>
     */
    private static function vers_liste_de_chaines(array $valeurs): array {
        $chaines = array_map('strval', array_filter($valeurs, 'is_scalar'));
        return array_keys($valeurs) === array_keys(array_values($valeurs)) ? array_values($chaines) : $chaines;
    }

    /** Minuscules, sans accents, toute ponctuation (traits d'union compris) réduite à une espace. */
    private static function plier(string $texte): string {
        $texte = preg_replace('/[^a-z0-9]+/', ' ', self::simplifier($texte)) ?? '';
        return trim($texte);
    }

    /** Minuscules et sans accents. */
    private static function simplifier(string $texte): string {
        return strtr(mb_strtolower($texte), self::ACCENTS);
    }
}

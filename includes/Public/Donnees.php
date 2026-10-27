<?php
declare(strict_types=1);

namespace RSN\Veille\Public;

use RSN\Veille\Collecte;
use RSN\Veille\Entrepot;
use RSN\Veille\EntrepotWordPress;
use RSN\Veille\Fiche;
use RSN\Veille\Journal;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Données de la page publique : `{genere_le, sources[], items[]}`, la structure que lit `assets/veille.js`
 * (et que sert `/wp-json/rsn/v1/veille`).
 *
 * `construire` ne fait aucune écriture : elle lit l'entrepôt, le registre des sources et le journal.
 */
final class Donnees {
    /** Transient du JSON public (une heure, un par langue), vidé par la collecte et par toute écriture de fiche. */
    public const TRANSIENT = 'rsn_veille_json';

    /** Langues vidées sans WPML (ou si WPML ne répond pas). */
    private const LANGUES_PAR_DEFAUT = ['fr', 'en'];

    /** Valeurs de « Qui organise ». */
    public const ORGANISE_PAR = ['rsn', 'partenaires', 'ecosysteme'];

    /** Clés d'une fiche montrées au public, dans cet ordre. Tout le reste (clés de source, courriel, absences…) est privé. */
    private const CLES_PUBLIQUES = [
        'id', 'type', 'titre', 'debut', 'fin', 'heure', 'organisateur', 'categorie_rsn', 'lieu', 'ville', 'region',
        'format', 'prix', 'langue', 'themes', 'resume', 'url', 'image_url', 'statut', 'categorie', 'echeance',
        'organise_par',
    ];

    /** Clés ajoutées pour l'équipe seulement : source technique et niveau de fiabilité. */
    private const CLES_EQUIPE = ['niveau', 'source'];

    /** Types de fiches publiés sur la page (les nouvelles restent dans le radar de l'équipe). */
    private const TYPES = ['evenement', 'appel'];

    /** Un événement fini depuis plus de tant de mois n'est plus envoyé (le JSON est inclus dans chaque page). */
    public const MOIS_EVENEMENTS_PASSES = 12;

    /**
     * @param array<int,array<string,mixed>> $evenements_rsn Événements de The Events Calendar du site, au format fiche.
     * @return array{genere_le: string, sources: array<int,array<string,mixed>>, items: array<int,array<string,mixed>>}
     */
    public static function construire(string $aujourdhui, Entrepot $e, array $evenements_rsn, bool $equipe = false): array {
        $cles = $equipe ? array_merge(self::CLES_PUBLIQUES, self::CLES_EQUIPE) : self::CLES_PUBLIQUES;
        $items = [];
        foreach (self::elements($e, $evenements_rsn, $aujourdhui) as $element) {
            $item = [];
            foreach ($cles as $cle) {
                $item[$cle] = $element[$cle] ?? null;
            }
            $organise = $element['organise_par'] ?? null;
            $organisateur = self::organisateur_canonique((string) ($item['organisateur'] ?? ''));
            if (!in_array($organise, self::ORGANISE_PAR, true)) {
                // La catégorie du site décide quand elle est connue. Sans catégorie, le RSN organise ses propres
                // fiches : collectées depuis son site (`rsn:`), appels de son flux (`rsn_appels:`) ou organisateur
                // reconnu comme « RSN ».
                $categorie = trim((string) ($element['categorie_rsn'] ?? ''));
                $cle_source = (string) ($element['cle_source'] ?? '');
                if ($categorie !== '') {
                    $organise = self::organise_par($categorie);
                } else {
                    $organise = str_starts_with($cle_source, 'rsn:') || str_starts_with($cle_source, 'rsn_appels:') || $organisateur === 'RSN'
                        ? 'rsn'
                        : self::organise_par('');
                }
            }
            $item['organise_par'] = $organise;
            $item['organisateur'] = $organisateur;
            // L'image mise en avant choisie à la main prime sur celle de la source.
            $image_manuelle = (string) ($element['image_manuelle'] ?? '');
            if ($image_manuelle !== '') {
                $item['image_url'] = $image_manuelle;
            }
            $items[] = $item;
        }
        $derniere = get_option(Collecte::OPTION_DERNIERE, 0);
        return [
            'genere_le' => is_numeric($derniere) && (int) $derniere > 0 ? wp_date('c', (int) $derniere) : $aujourdhui,
            'sources' => self::sources($equipe),
            'items' => $items,
        ];
    }

    /**
     * Fiches publiées (événements et appels en cours) et événements du RSN, dédoublonnés et triés, complets
     * (clés de source comprises : `Ical` s'en sert pour ses UID). À expurger avant toute sortie publique.
     * Un appel n'y figure que s'il est en cours (`Fiche::appel_en_cours`) : les appels périmés ne sont
     * jamais envoyés au navigateur. Un événement fini depuis plus de 12 mois non plus. Les fiches sont lues
     * avec ces bornes (`Entrepot::lister_pour_page`) : jamais toute la base.
     *
     * @param array<int,array<string,mixed>> $evenements_rsn
     * @return array<int,array<string,mixed>>
     */
    public static function elements(Entrepot $e, array $evenements_rsn, ?string $aujourdhui = null): array {
        $aujourdhui ??= \rsn_veille_aujourdhui();
        $jour = \DateTimeImmutable::createFromFormat('!Y-m-d', $aujourdhui) ?: new \DateTimeImmutable('today');
        $limite_evenements = $jour->modify('-' . self::MOIS_EVENEMENTS_PASSES . ' months')->format('Y-m-d');
        $limite_appels = $jour->modify('-' . Fiche::JOURS_APPEL_SANS_LIMITE . ' days')->format('Y-m-d');
        $recent = static function (Fiche $f) use ($limite_evenements): bool {
            $fin = ($f->fin ?? '') !== '' && $f->fin > (string) $f->debut ? $f->fin : (string) $f->debut;
            return $fin === '' || $fin >= $limite_evenements;
        };
        $evenements = [];
        $appels = [];
        /** @var array<string,int> $positions Clé de dédoublonnage => position de la première fiche qui l'a. */
        $positions = [];
        foreach ($e->lister_pour_page($limite_evenements, $aujourdhui, $limite_appels) as $fiche) {
            if ($fiche->type === 'appel') {
                if ($fiche->appel_en_cours($aujourdhui)) {
                    $appels[] = $fiche->vers_tableau();
                }
            } elseif ($fiche->type === 'evenement' && $recent($fiche)) {
                $cle = $fiche->cle_dedoublonnage();
                $position = $positions[$cle] ?? null;
                if ($position === null) {
                    $positions[$cle] = count($evenements);
                    $evenements[] = $fiche->vers_tableau();
                } elseif ((string) $evenements[$position]['source'] === Fiche::SOURCE_PROPOSITION && !$fiche->est_proposition()) {
                    // Doublon entre fiches de veille : une proposition publiée cède la place à la fiche collectée.
                    $evenements[$position] = $fiche->vers_tableau();
                }
                // Sinon, la première fiche de même date et même titre reste seule.
            }
        }

        foreach ($evenements_rsn as $brut) {
            if (!is_array($brut)) {
                continue;
            }
            $rsn = Fiche::depuis_tableau(array_merge($brut, ['type' => 'evenement', 'statut' => 'publie', 'source' => 'RSN']));
            if ($rsn->titre === '' || $rsn->debut === null || !$recent($rsn)) {
                continue;
            }
            $tableau = $rsn->vers_tableau();
            // « Qui organise » déjà établi par EvenementsRsn (catégorie TEC lue par son slug).
            if (in_array($brut['organise_par'] ?? null, self::ORGANISE_PAR, true)) {
                $tableau['organise_par'] = $brut['organise_par'];
            }
            $position = $positions[$rsn->cle_dedoublonnage()] ?? null;
            if ($position === null) {
                $evenements[] = $tableau;
            } elseif ((string) $evenements[$position]['image_url'] === '' && (string) ($evenements[$position]['image_manuelle'] ?? '') === '') {
                // Doublon : la fiche collectée gagne si elle a une image (de la source ou mise en avant à la main),
                // sinon l'événement du RSN.
                $evenements[$position] = $tableau;
            } else {
                // La fiche collectée garde son image, mais l'événement du RSN fait foi pour « Qui organise ».
                $evenements[$position]['categorie_rsn'] = $tableau['categorie_rsn'];
                $evenements[$position]['organise_par'] = $tableau['organise_par'] ?? null;
            }
        }

        usort($evenements, static fn(array $a, array $b): int => [(string) $a['debut'], (string) $a['titre']] <=> [(string) $b['debut'], (string) $b['titre']]);
        // Appels : ceux qui ont une échéance d'abord (la plus proche en premier), puis les autres par date de publication.
        $cle_appel = static fn(array $a): array => [$a['echeance'] === null ? 1 : 0, (string) $a['echeance'], (string) $a['debut'], (string) $a['titre']];
        usort($appels, static fn(array $a, array $b): int => $cle_appel($a) <=> $cle_appel($b));
        return array_merge($evenements, $appels);
    }

    /**
     * Données publiques, depuis le transient d'une heure ou reconstruites.
     *
     * @return array{genere_le: string, sources: array<int,array<string,mixed>>, items: array<int,array<string,mixed>>}
     */
    public static function en_cache(): array {
        $cle = self::transient(self::TRANSIENT);
        $donnees = get_transient($cle);
        if (is_array($donnees) && isset($donnees['items'], $donnees['sources'], $donnees['genere_le'])) {
            return $donnees;
        }
        $donnees = self::construire(\rsn_veille_aujourdhui(), new EntrepotWordPress(), EvenementsRsn::lire());
        set_transient($cle, $donnees, HOUR_IN_SECONDS);
        return $donnees;
    }

    /** Nom du transient pour la langue courante (WPML), « {base}_fr » sans WPML. */
    public static function transient(string $base, ?string $langue = null): string {
        $langue ??= apply_filters('wpml_current_language', null);
        $langue = is_string($langue) && $langue !== '' && $langue !== 'all' ? sanitize_key($langue) : 'fr';
        return $base . '_' . $langue;
    }

    /**
     * Langues dont les caches sont vidés : celles de WPML, sinon français et anglais.
     *
     * @return string[]
     */
    public static function langues(): array {
        $actives = apply_filters('wpml_active_languages', null);
        $langues = is_array($actives) ? array_map('strval', array_keys($actives)) : [];
        return array_values(array_unique(array_merge($langues === [] ? self::LANGUES_PAR_DEFAUT : $langues, ['fr'])));
    }

    /** Vide les caches publics (JSON et iCal) de chaque langue : collecte terminée, fiche modifiée, événement du RSN modifié. */
    public static function vider_cache(): void {
        foreach (self::langues() as $langue) {
            delete_transient(self::transient(self::TRANSIENT, $langue));
            delete_transient(self::transient(Ical::TRANSIENT, $langue));
        }
    }

    /** Graphies canoniques des organisateurs fréquents (clé : nom en minuscules sans accents ni ponctuation). */
    private const ORGANISATEURS = [
        'obvia' => 'Obvia',
        'rsn' => 'RSN',
        'reseau sante numerique' => 'RSN',
        'ivado' => 'IVADO',
        'mila' => 'Mila',
        'frq' => 'Fonds de recherche du Québec',
        'fonds de recherche du quebec' => 'Fonds de recherche du Québec',
        'digital health canada' => 'Digital Health Canada',
        'acfas' => 'Acfas',
        'crm' => 'CRM',
        'centre de recherches mathematiques' => 'CRM',
    ];

    /**
     * Graphie unique d'un organisateur pour le filtre « Organisateur » de la page : « OBVIA » et « Obvia »,
     * « RSN » et « Réseau santé numérique » ne font qu'un. Un nom inconnu est rendu tel quel.
     */
    public static function organisateur_canonique(string $nom): string {
        $nom = trim($nom);
        $cle = strtr(mb_strtolower($nom), ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'â' => 'a', 'ô' => 'o', 'î' => 'i', 'ç' => 'c', 'ù' => 'u']);
        $cle = trim((string) preg_replace('/[^a-z0-9]+/', ' ', $cle));
        return self::ORGANISATEURS[$cle] ?? $nom;
    }

    /**
     * « Qui organise » d'après le nom (ou le slug) de la catégorie du site : RSN, partenaires, sinon écosystème.
     */
    public static function organise_par(string $categorie): string {
        $slug = strtr(mb_strtolower(trim($categorie)), ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'â' => 'a', 'ô' => 'o', 'î' => 'i', 'ç' => 'c']);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $slug), '-');
        // Slugs exacts des catégories de The Events Calendar (`rsn`, `partenaires`),
        // puis préfixes des noms (« Événements RSN », « RSN Events », « Événements partenaires », « Partner events »).
        if ($slug === 'rsn' || str_starts_with($slug, 'evenements-rsn') || str_starts_with($slug, 'rsn-events')) {
            return 'rsn';
        }
        if ($slug === 'partenaires' || str_starts_with($slug, 'evenements-partenaires') || str_starts_with($slug, 'partner-events')) {
            return 'partenaires';
        }
        // Tout le reste, dont `ecosysteme` et `digital-ecosystem`, relève de l'écosystème.
        return 'ecosysteme';
    }

    /**
     * Accroche l'invalidation des caches publics : purge de la collecte ou d'une écriture de fiche, fin
     * de collecte, et événement de The Events Calendar enregistré (purge complète : la page Veille de
     * LiteSpeed et d'Elementor contient aussi les événements du RSN).
     */
    public static function accrocher(): void {
        add_action('rsn_veille_caches_purges', [self::class, 'vider_cache']);
        add_action('rsn_veille_apres_collecte', [self::class, 'vider_cache']);
        add_action('save_post_tribe_events', [Collecte::class, 'purger_caches']);
    }

    /**
     * Sources actives qui donnent des fiches (pas les flux de nouvelles du radar) : nom, ce qu'on lit,
     * adresse du site et nombre d'éléments lus à la dernière collecte ; niveau pour l'équipe seulement.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function sources(bool $equipe): array {
        $sources = [];
        foreach (Collecte::sources() as $id => $source) {
            if (!empty($source['categorie_nouvelles'])) {
                continue;
            }
            $derniere = Journal::derniere((string) $id);
            $ligne = [
                'nom' => (string) ($source['nom'] ?? $id),
                'flux' => self::ce_qu_on_lit($source),
                'url' => self::adresse_publique($source),
                'nb' => (int) ($derniere['lus'] ?? 0),
            ];
            if ($equipe) {
                $ligne['niveau'] = (string) ($source['niveau'] ?? '');
            }
            $sources[] = $ligne;
        }
        return $sources;
    }

    /** Ce qu'on lit, en clair. */
    private static function ce_qu_on_lit(array $source): string {
        $type = (string) ($source['type'] ?? '');
        $mode = (string) ($source['mode'] ?? '');
        if ($type === 'tec') {
            return __('Calendrier des événements du site', 'rsn-veille');
        }
        if ($type === 'ical') {
            return __('Calendrier iCal', 'rsn-veille');
        }
        if ($type === 'rss') {
            return $mode === 'appels' ? __('Fil RSS, articles d’appels et de concours', 'rsn-veille') : __('Fil RSS des événements', 'rsn-veille');
        }
        return __('Page des événements du site', 'rsn-veille');
    }

    /** Une page lue est montrée telle quelle ; un flux ou une API, par l'adresse de son site. */
    private static function adresse_publique(array $source): string {
        $url = (string) ($source['url'] ?? '');
        if (str_starts_with((string) ($source['type'] ?? ''), 'html')) {
            return $url;
        }
        $hote = parse_url($url, PHP_URL_HOST);
        $schema = parse_url($url, PHP_URL_SCHEME);
        return is_string($hote) && $hote !== '' ? (is_string($schema) ? $schema : 'https') . '://' . $hote . '/' : '';
    }
}

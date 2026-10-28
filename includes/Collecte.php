<?php
declare(strict_types=1);

namespace RSN\Veille;

use RSN\Veille\Lecteurs\Lecteur;
use RSN\Veille\Lecteurs\LecteurFrqEvenements;
use RSN\Veille\Lecteurs\LecteurIcal;
use RSN\Veille\Lecteurs\LecteurIvado;
use RSN\Veille\Lecteurs\LecteurMila;
use RSN\Veille\Lecteurs\LecteurObvia;
use RSN\Veille\Lecteurs\LecteurRss;
use RSN\Veille\Lecteurs\LecteurTec;
use RSN\Veille\Lecteurs\Resultat;
use RSN\Veille\Public\Donnees;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Collecte nocturne par tâches planifiées (WP-Cron) : `rsn_veille_nuit` met les sources en file,
 * puis `rsn_veille_source` en traite une par exécution et se replanifie tant que la file n'est pas
 * vide ; la dernière purge les caches de la page Veille.
 *
 * Les rappels accrochés à WordPress ne prennent aucun paramètre : `do_action` sans argument leur
 * passe une chaîne vide, que PHP ignore.
 */
final class Collecte {
    public const OPTION_REGLAGES = 'rsn_veille_reglages';
    public const OPTION_FILE = 'rsn_veille_file';
    public const OPTION_DERNIERE = 'rsn_veille_derniere_collecte';
    /** Dernière version de l'extension dont les caches publics ont été purgés (voir `purger_si_nouvelle_version`). */
    public const OPTION_VERSION = 'rsn_veille_version';
    public const CROCHET_NUIT = 'rsn_veille_nuit';
    public const CROCHET_SOURCE = 'rsn_veille_source';
    public const VERROU = 'rsn_veille_collecte_en_cours';

    /** Délais avant la première source et entre deux sources, en secondes. */
    private const DELAI_PREMIERE = 60;
    private const DELAI_SUIVANTE = 120;

    /** Heure de collecte par défaut (heure de Montréal). */
    private const HEURE_PAR_DEFAUT = '03:00';

    /** Méta du cache de rendu d'Elementor. */
    private const META_ELEMENTOR = '_elementor_element_cache';

    /** Contenu attendu selon le type de source : valeur de `Accept` et méthode de reconnaissance de Http. */
    private const CONTENUS = [
        'tec' => ['json', 'est_json'],
        'rss' => ['xml', 'est_flux'],
        'ical' => ['ical', 'est_ical'],
        'html' => ['html', 'est_html'],
    ];

    /**
     * Client HTTP de remplacement pour les tests (même signature et même réponse que `Http::obtenir`).
     * Null en production.
     */
    public static ?\Closure $obtenir_pour_tests = null;

    /** Horloge de remplacement pour les tests (secondes, comme `microtime(true)`). Null en production. */
    public static ?\Closure $horloge_pour_tests = null;

    /** Durée au-delà de laquelle un verrou est tenu pour abandonné, en secondes. */
    private const VERROU_PERIME = 600;

    /** Limite d'exécution demandée pour une source (une page IVADO, son flux et le budget des résolutions). */
    private const LIMITE_EXECUTION = 120;

    /** Vrai pendant l'intégration d'une source : la purge est faite une fois à la fin, pas à chaque fiche. */
    private static bool $purge_suspendue = false;

    /** @return array<string,mixed> Option `rsn_veille_reglages` (tableau vide si absente ou illisible). */
    public static function reglages(): array {
        $reglages = get_option(self::OPTION_REGLAGES, []);
        return is_array($reglages) ? $reglages : [];
    }

    /** Règles de pertinence par défaut, écrasées par les listes non vides des réglages. */
    public static function pertinence(): Pertinence {
        $config = Pertinence::config_par_defaut();
        $reglages = self::reglages();
        foreach (['mots_sante', 'mots_numerique', 'themes'] as $cle) {
            if (!empty($reglages[$cle]) && is_array($reglages[$cle])) {
                $config[$cle] = $reglages[$cle];
            }
        }
        return new Pertinence($config);
    }

    /** @return array<string,array<string,mixed>> Sources actives, réglages appliqués. */
    public static function sources(): array {
        $reglages = self::reglages()['sources'] ?? [];
        return Sources::actives(Sources::fusionner(Sources::par_defaut(), is_array($reglages) ? $reglages : []));
    }

    public static function lecteur_pour(array $source): Lecteur {
        return match ((string) ($source['type'] ?? '')) {
            'tec' => new LecteurTec(),
            'rss' => new LecteurRss(),
            'ical' => new LecteurIcal(),
            'html_obvia' => new LecteurObvia(),
            'html_mila' => new LecteurMila(),
            'html_frq' => new LecteurFrqEvenements(),
            'html_ivado' => new LecteurIvado(),
            default => throw new \InvalidArgumentException('Type de source inconnu : ' . ($source['type'] ?? '')),
        };
    }

    /** Accroche la collecte et les purges aux actions de WordPress (chargement de l'extension). */
    public static function accrocher(): void {
        add_action(self::CROCHET_NUIT, [self::class, 'mettre_en_file']);
        add_action(self::CROCHET_NUIT, [self::class, 'recaler_nuit'], 20);
        add_action(self::CROCHET_SOURCE, [self::class, 'traiter_une_source']);
        add_action('save_post_' . TypeContenu::TYPE, [self::class, 'purger_apres_ecriture']);
        add_action('deleted_post', [self::class, 'purger_apres_suppression'], 10, 2);
        // Extension mise à jour sans réactivation : la collecte quotidienne est planifiée quand même.
        add_action('admin_init', [self::class, 'planifier_nuit']);
        // Idem pour les caches : une mise à jour change le JSON public et les textes de la page.
        add_action('admin_init', [self::class, 'purger_si_nouvelle_version']);
    }

    // --- Planification ---

    /** Planifie la collecte quotidienne si elle ne l'est pas (activation de l'extension, admin_init). */
    public static function planifier_nuit(): void {
        if (wp_next_scheduled(self::CROCHET_NUIT) === false) {
            wp_schedule_event(self::prochaine_collecte(), 'daily', self::CROCHET_NUIT);
        }
    }

    /** Replanifie la collecte quotidienne (après un changement de l'heure dans les réglages). */
    public static function replanifier_nuit(): void {
        wp_clear_scheduled_hook(self::CROCHET_NUIT);
        self::planifier_nuit();
    }

    /** Retire toutes les tâches planifiées de l'extension (désactivation). */
    public static function deplanifier(): void {
        wp_clear_scheduled_hook(self::CROCHET_NUIT);
        wp_clear_scheduled_hook(self::CROCHET_SOURCE);
    }

    /**
     * Prochain horodatage de l'heure `heure_collecte` des réglages (H:i, heure de Montréal selon le
     * fuseau du site ; 03:00 si absente ou illisible), strictement après $maintenant.
     */
    public static function prochaine_collecte(?int $maintenant = null): int {
        $maintenant ??= time();
        $heure = (string) (self::reglages()['heure_collecte'] ?? '');
        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $heure, $m) !== 1) {
            preg_match('/^(\d+):(\d+)$/', self::HEURE_PAR_DEFAUT, $m);
        }
        $date = (new \DateTimeImmutable('@' . $maintenant))->setTimezone(wp_timezone())->setTime((int) $m[1], (int) $m[2]);
        if ($date->getTimestamp() <= $maintenant) {
            $date = $date->modify('+1 day');
        }
        return $date->getTimestamp();
    }

    // --- File ---

    /** Met en file les sources actives, sauf les flux de nouvelles (lus à la volée par le radar). */
    public static function mettre_en_file(): void {
        $ids = [];
        foreach (self::sources() as $id => $source) {
            if (empty($source['categorie_nouvelles'])) {
                $ids[] = (string) $id;
            }
        }
        update_option(self::OPTION_FILE, $ids, false);
        if ($ids !== []) {
            self::planifier_source(self::DELAI_PREMIERE);
        }
    }

    /**
     * Dépile et traite une source, consigne le résultat, puis termine si la file est vide. La source
     * suivante est planifiée avant le traitement : une exécution interrompue ne casse pas la chaîne.
     * Si un autre traitement tient le verrou, la tâche est seulement replanifiée.
     */
    public static function traiter_une_source(): void {
        if (function_exists('set_time_limit')) {
            @set_time_limit(self::LIMITE_EXECUTION);
        }
        $jeton = Verrou::prendre(self::VERROU, self::VERROU_PERIME);
        if ($jeton === null) {
            if (self::file() !== []) {
                self::planifier_source(self::DELAI_SUIVANTE);
            }
            return;
        }
        try {
            // File relue sous le verrou : un autre traitement a pu la dépiler entre-temps.
            $file = self::file();
            if ($file === []) {
                return;
            }
            $id = array_shift($file);
            update_option(self::OPTION_FILE, $file, false);
            if ($file !== []) {
                self::planifier_source(self::DELAI_SUIVANTE);
            }

            try {
                $source = self::sources()[$id] ?? null;
                if ($source === null) {
                    $ligne = self::ligne_vide();
                    $ligne['erreurs'][] = __('Source inconnue ou désactivée', 'rsn-veille');
                } else {
                    $ligne = self::collecter_source($source, new Depot(new EntrepotWordPress()), self::pertinence());
                }
            } catch (\Throwable $e) {
                $ligne = self::ligne_vide();
                /* translators: %s : message de l'exception. */
                $ligne['erreurs'][] = sprintf(__('Erreur : %s', 'rsn-veille'), $e->getMessage());
            }
            Journal::consigner($id, $ligne);

            if ($file === []) {
                self::terminer();
            }
        } finally {
            Verrou::liberer(self::VERROU, $jeton);
        }
    }

    /** Fin de la collecte : horodatage, purge des caches, action pour les autres modules. */
    public static function terminer(): void {
        update_option(self::OPTION_DERNIERE, time(), false);
        self::purger_caches();
        do_action('rsn_veille_apres_collecte');
    }

    // --- Purge des caches ---

    /**
     * Purge LiteSpeed et le cache Elementor de la page Veille (réglage `page_veille_id`) et de ses
     * traductions WPML, puis déclenche `rsn_veille_caches_purges` (cache du JSON public, etc.). Appelée par
     * la collecte et par toute modification de fiches.
     */
    public static function purger_caches(): void {
        foreach (self::pages_veille() as $id_page) {
            do_action('litespeed_purge_post', $id_page);
            delete_post_meta($id_page, self::META_ELEMENTOR);
        }
        do_action('rsn_veille_caches_purges');
    }

    /**
     * Mise à jour de l'extension (sans réactivation) : au premier chargement de l'admin avec une autre version,
     * purge les caches publics (JSON par langue, LiteSpeed, Elementor) puis retient la version. Sans effet
     * quand la version est déjà celle de l'option.
     */
    public static function purger_si_nouvelle_version(): void {
        if (get_option(self::OPTION_VERSION, '') === RSN_VEILLE_VERSION) {
            return;
        }
        self::purger_caches();
        update_option(self::OPTION_VERSION, RSN_VEILLE_VERSION, false);
    }

    /**
     * Identifiants de la page Veille (réglage `page_veille_id`) et de ses traductions WPML, sans doublon ;
     * vide si aucune page n'est choisie. Sans WPML, le filtre `wpml_object_id` n'existe pas et rend
     * l'identifiant reçu ; une langue sans traduction (WPML rend null ou false) est ignorée.
     *
     * @return int[]
     */
    public static function pages_veille(): array {
        $id_page = (int) (self::reglages()['page_veille_id'] ?? 0);
        if ($id_page <= 0) {
            return [];
        }
        $ids = [$id_page];
        foreach (Donnees::langues() as $langue) {
            $traduite = apply_filters('wpml_object_id', $id_page, 'page', false, $langue);
            if (is_numeric($traduite) && (int) $traduite > 0) {
                $ids[] = (int) $traduite;
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * Exécute $travail sans purge des caches par les écritures de fiches (proposition du public : rien de
     * public ne change), puis rétablit l'état précédent ; rend le résultat de $travail.
     */
    public static function sans_purge(callable $travail): mixed {
        $avant = self::$purge_suspendue;
        self::$purge_suspendue = true;
        try {
            return $travail();
        } finally {
            self::$purge_suspendue = $avant;
        }
    }

    /** Rappel de `save_post_rsn_veille` : purge, sauf pendant l'intégration d'une source (purgée à la fin). */
    public static function purger_apres_ecriture(): void {
        if (!self::$purge_suspendue) {
            self::purger_caches();
        }
    }

    /** Rappel de `deleted_post` (deux arguments) : purge si l'article supprimé est une fiche. */
    public static function purger_apres_suppression(mixed $id = 0, mixed $article = null): void {
        if (is_object($article) && ($article->post_type ?? '') === TypeContenu::TYPE) {
            self::purger_apres_ecriture();
        }
    }

    // --- Traitement d'une source ---

    /**
     * Télécharge, vérifie, lit et intègre une source ; signale les absences. Ne lève jamais d'exception :
     * toute erreur est consignée dans la ligne de journal rendue.
     *
     * @return array<string,mixed> Ligne de journal.
     */
    public static function collecter_source(array $source, Depot $depot, Pertinence $pertinence): array {
        $debut = microtime(true);
        $ligne = self::ligne_vide();
        $id = (string) ($source['id'] ?? '');
        $type = (string) ($source['type'] ?? '');
        $reussie = false;
        $cles_vues = [];
        $compteurs = self::compteurs_vides();
        $echec = false;
        $ecritures_en = 0;
        self::$purge_suspendue = true;
        try {
            [$accept, $reconnaitre] = self::CONTENUS[str_starts_with($type, 'html_') ? 'html' : $type] ?? ['any', null];
            $reponse = self::obtenir(self::url_de_requete($source), ['accept' => $accept]);
            if (($reponse['erreur'] ?? null) !== null) {
                $ligne['erreurs'][] = (string) $reponse['erreur'];
            } elseif ($reconnaitre === null || !Http::$reconnaitre($reponse)) {
                /* translators: %s : type de contenu reçu. */
                $ligne['erreurs'][] = sprintf(__('Contenu inattendu (type %s)', 'rsn-veille'), ($reponse['type'] ?? '') !== '' ? $reponse['type'] : __('inconnu', 'rsn-veille'));
            } else {
                if ($type === 'html_ivado') {
                    $source = self::avec_flux_ivado($source, $ligne);
                }
                $resultat = self::lecteur_pour($source)->lire((string) $reponse['corps'], $source);
                $ligne['lus'] = count($resultat->fiches);
                $ligne['ignores'] = $resultat->ignores;
                array_push($ligne['erreurs'], ...$resultat->erreurs);
                foreach ($resultat->fiches as $fiche) {
                    $cles_vues[] = $fiche->cle_source;
                }
                // Une source lisible qui ne rend rien (gabarit changé ?) ne compte pas comme collecte réussie.
                $reussie = $resultat->fiches !== [] || $resultat->ignores > 0;

                // Pages d'événements IVADO : plafond et budget partagés par les deux langues.
                $resolutions = ['debut' => self::horloge(), 'reste' => ResolutionIvado::MAX];
                if ($type === 'html_ivado') {
                    // Chaque fiche est intégrée dès qu'elle est résolue.
                    $integrer = static function (Fiche $fiche) use ($source, $depot, $pertinence, &$compteurs): void {
                        self::integrer_fiche($fiche, $source, $depot, $pertinence, $compteurs);
                    };
                    $resolution = ResolutionIvado::resoudre($resultat->fiches, $source, $depot, [self::class, 'obtenir'], $integrer, ResolutionIvado::MAX, ResolutionIvado::BUDGET, [self::class, 'horloge']);
                    $ligne['en_attente'] = $resolution['en_attente'];
                    array_push($ligne['erreurs'], ...$resolution['erreurs']);
                    $resolutions['reste'] -= self::tentatives($resolution);
                } else {
                    // Fiche par fiche (comme integrer_resultat) : les compteurs restent justes après une exception.
                    foreach ($resultat->fiches as $fiche) {
                        self::integrer_fiche($fiche, $source, $depot, $pertinence, $compteurs);
                    }
                }

                if ((string) ($source['url_en'] ?? '') !== '') {
                    $ecritures_en = self::apparier_anglais($source, $resultat->fiches, $depot, $ligne, $accept, $reconnaitre, $resolutions);
                }
            }
            $depot->signaler_absents($id, $cles_vues, $reussie, \rsn_veille_aujourdhui());
        } catch (\Throwable $e) {
            /* translators: %s : message de l'exception. */
            $ligne['erreurs'][] = sprintf(__('Erreur : %s', 'rsn-veille'), $e->getMessage());
            // Des fiches ont pu être écrites avant l'exception.
            $echec = true;
        } finally {
            self::$purge_suspendue = false;
        }
        $ligne = array_merge($ligne, $compteurs);
        // Un titre anglais écrit change aussi la page publique (version anglaise).
        if ($echec || $ecritures_en + $compteurs['crees'] + $compteurs['fusionnes'] + $compteurs['mis_a_jour'] + $compteurs['annules'] > 0) {
            self::purger_caches();
        }
        $ligne['duree_ms'] = (int) round((microtime(true) - $debut) * 1000);
        return $ligne;
    }

    /**
     * Version anglaise d'une source (`url_en`), lue après l'intégration française : mêmes client HTTP, contenu
     * attendu, `anti_cache` et lecteur, sur une copie de la source (`url` ⇒ `url_en`, `flux_evenements_url` ⇒
     * `flux_evenements_url_en`, `langue` ⇒ `en`). Les anglaises sont appariées aux françaises par leurs dates
     * (`Appariement`) et ne sont jamais intégrées : chaque paire complète seulement la fiche déjà enregistrée
     * de la française (`Depot::completer_anglais`, qui n'écrit jamais une valeur vide).
     *
     * IVADO : la page d'un événement anglais n'est lue que si la fiche connue en a besoin (`page_anglaise_a_lire`),
     * dans le plafond et le budget restants des résolutions françaises ($resolutions : `debut` des résolutions,
     * `reste` des tentatives) ; non résolue (plafond, budget, page en erreur), la paire attend la collecte suivante.
     *
     * Consigne `appariees_en` (paires écrites ou déjà à jour) et `orphelines_en` (anglaises sans équivalent) ;
     * une version anglaise illisible ou une exception va dans `erreurs` sans faire échouer la source.
     *
     * @param Fiche[] $francaises Fiches françaises lues (et intégrées) à cette collecte.
     * @param array{debut: float, reste: int} $resolutions
     * @return int Fiches dont des champs anglais ont été écrits.
     */
    private static function apparier_anglais(array $source, array $francaises, Depot $depot, array &$ligne, string $accept, ?string $reconnaitre, array $resolutions): int {
        $ligne['appariees_en'] = 0;
        $ligne['orphelines_en'] = 0;
        $ecritures = 0;
        $ivado = ($source['type'] ?? '') === 'html_ivado';
        $source_en = ['url' => (string) $source['url_en'], 'langue' => 'en'] + $source;
        unset($source_en['flux_evenements'], $source_en['flux_evenements_url']);
        if ((string) ($source['flux_evenements_url_en'] ?? '') !== '') {
            $source_en['flux_evenements_url'] = (string) $source['flux_evenements_url_en'];
        }
        try {
            $reponse = self::obtenir(self::url_de_requete($source_en), ['accept' => $accept]);
            if (($reponse['erreur'] ?? null) !== null) {
                /* translators: 1 : adresse de la version anglaise, 2 : erreur. */
                $ligne['erreurs'][] = sprintf(__('Version anglaise %1$s illisible : %2$s', 'rsn-veille'), $source_en['url'], (string) $reponse['erreur']);
                return 0;
            }
            if ($reconnaitre === null || !Http::$reconnaitre($reponse)) {
                /* translators: 1 : adresse de la version anglaise, 2 : type de contenu reçu. */
                $ligne['erreurs'][] = sprintf(__('Version anglaise %1$s : contenu inattendu (type %2$s)', 'rsn-veille'), $source_en['url'], ($reponse['type'] ?? '') !== '' ? $reponse['type'] : __('inconnu', 'rsn-veille'));
                return 0;
            }
            if ($ivado) {
                // Une erreur du flux anglais d'IVADO porte le même préfixe que les autres erreurs de la passe anglaise.
                $avant = count($ligne['erreurs']);
                $source_en = self::avec_flux_ivado($source_en, $ligne);
                for ($i = $avant, $n = count($ligne['erreurs']); $i < $n; $i++) {
                    /* translators: %s : erreur du flux des événements IVADO anglais. */
                    $ligne['erreurs'][$i] = sprintf(__('Version anglaise : %s', 'rsn-veille'), $ligne['erreurs'][$i]);
                }
            }
            $resultat = self::lecteur_pour($source_en)->lire((string) $reponse['corps'], $source_en);
            foreach ($resultat->erreurs as $erreur) {
                /* translators: %s : erreur du lecteur. */
                $ligne['erreurs'][] = sprintf(__('Version anglaise : %s', 'rsn-veille'), $erreur);
            }
            $appariement = Appariement::apparier($francaises, $resultat->fiches);
            $ligne['orphelines_en'] = count($appariement['orphelines']);
            foreach ($appariement['paires'] as [$francaise, $anglaise]) {
                // Événement non traduit, listé par la page anglaise sous son adresse française (toutes les sources) ou, pour
                // IVADO, sous son identifiant français (WPML en donne un autre à chaque traduction) : ce n'est pas une
                // version anglaise, rien à compléter. Chez Mila, les deux langues partagent le même identifiant.
                if (($ivado && $anglaise->cle_source !== '' && $anglaise->cle_source === $francaise->cle_source) || ($anglaise->url !== '' && $anglaise->url === $francaise->url)) {
                    continue;
                }
                $connue = $depot->fiche_de_cle($francaise);
                if ($connue === null) {
                    continue;
                }
                if ($ivado) {
                    if (!self::page_anglaise_a_lire($connue, $anglaise)) {
                        // Titre anglais déjà là : l'anglaise n'a que son adresse courte, rien à écrire.
                        $ligne['appariees_en']++;
                        continue;
                    }
                    $anglaise = self::resoudre_anglaise($anglaise, $source_en, $depot, $ligne, $resolutions);
                    if ($anglaise === null) {
                        continue;
                    }
                }
                if ($depot->completer_anglais($connue, $anglaise->titre, $anglaise->resume, $anglaise->url)) {
                    $ecritures++;
                }
                $ligne['appariees_en']++;
            }
        } catch (\Throwable $e) {
            /* translators: %s : message de l'exception. */
            $ligne['erreurs'][] = sprintf(__('Version anglaise : %s', 'rsn-veille'), $e->getMessage());
        }
        return $ecritures;
    }

    /**
     * Vrai si la page de l'événement anglais d'IVADO doit être lue : la fiche connue n'a pas de titre anglais,
     * ou l'anglaise donne une adresse finale différente de l'adresse anglaise enregistrée. L'adresse courte
     * « ?p=id » que rend le calendrier n'est pas comparée : elle diffère toujours de l'adresse finale enregistrée.
     */
    private static function page_anglaise_a_lire(Fiche $connue, Fiche $anglaise): bool {
        if ($connue->titre_en === '') {
            return true;
        }
        $courte = preg_match('~^https://ivado\.ca/\?p=\d+$~', $anglaise->url) === 1;
        return !$courte && $connue->url_en !== $anglaise->url;
    }

    /**
     * Résout une anglaise d'IVADO (`ResolutionIvado`) dans le plafond et le budget restants, qu'elle consomme ;
     * ses erreurs vont dans la ligne. Null si elle n'est pas résolue.
     *
     * @param array{debut: float, reste: int} $resolutions
     */
    private static function resoudre_anglaise(Fiche $anglaise, array $source_en, Depot $depot, array &$ligne, array &$resolutions): ?Fiche {
        $resolue = null;
        $recueillir = static function (Fiche $fiche) use (&$resolue): void {
            $resolue = $fiche;
        };
        $budget = ResolutionIvado::BUDGET - (self::horloge() - $resolutions['debut']);
        $resolution = ResolutionIvado::resoudre([$anglaise], $source_en, $depot, [self::class, 'obtenir'], $recueillir, max(0, $resolutions['reste']), $budget, [self::class, 'horloge']);
        $resolutions['reste'] -= self::tentatives($resolution);
        array_push($ligne['erreurs'], ...$resolution['erreurs']);
        return $resolue;
    }

    /**
     * Pages demandées par une résolution IVADO : chaque tentative est résolue ou donne exactement une erreur.
     *
     * @param array{resolues: int, erreurs: string[], en_attente: int} $resolution
     */
    private static function tentatives(array $resolution): int {
        return $resolution['resolues'] + count($resolution['erreurs']);
    }

    /**
     * Intègre les fiches lues. Une fiche nouvelle reçoit son statut et ses thèmes (jamais une fiche
     * existante) ; écartée, elle n'est pas écrite. `annules` : fiches publiées repassées à approuver.
     *
     * @return array{crees: int, fusionnes: int, mis_a_jour: int, inchanges: int, ecartes: int, annules: int}
     */
    public static function integrer_resultat(Resultat $r, array $source, Depot $d, Pertinence $p): array {
        $compteurs = self::compteurs_vides();
        foreach ($r->fiches as $fiche) {
            self::integrer_fiche($fiche, $source, $d, $p, $compteurs);
        }
        return $compteurs;
    }

    /** Intègre une fiche (voir `integrer_resultat`) et incrémente le compteur correspondant. */
    private static function integrer_fiche(Fiche $fiche, array $source, Depot $d, Pertinence $p, array &$compteurs): void {
        if (!$d->existe($fiche)) {
            $texte = $fiche->titre . ' ' . $fiche->resume;
            $fiche->statut = $p->statut((string) ($source['type_source'] ?? ''), $texte);
            $fiche->themes = $p->themes($texte);
            if ($fiche->statut === 'ecarte') {
                $compteurs['ecartes']++;
                return;
            }
        }
        $noms = ['cree' => 'crees', 'fusionne' => 'fusionnes', 'mis_a_jour' => 'mis_a_jour', 'inchange' => 'inchanges'];
        $compteurs[$noms[$d->integrer($fiche)]]++;
        if ($d->annuler_si_necessaire($fiche)) {
            $compteurs['annules']++;
        }
    }

    /** @return array{crees: int, fusionnes: int, mis_a_jour: int, inchanges: int, ecartes: int, annules: int} */
    private static function compteurs_vides(): array {
        return ['crees' => 0, 'fusionnes' => 0, 'mis_a_jour' => 0, 'inchanges' => 0, 'ecartes' => 0, 'annules' => 0];
    }

    /**
     * Adresse demandée pour une source : celle du registre, plus un paramètre « t= » (horodatage) pour une
     * source `anti_cache` (API du site, dont LiteSpeed met les réponses en cache). L'horodatage est ajouté à
     * chaque requête, jamais enregistré dans les réglages.
     */
    public static function url_de_requete(array $source): string {
        $url = (string) ($source['url'] ?? '');
        if ($url === '' || empty($source['anti_cache'])) {
            return $url;
        }
        return $url . (str_contains($url, '?') ? '&' : '?') . 't=' . time();
    }

    /** Secondes (`microtime(true)`), ou l'horloge de test. */
    public static function horloge(): float {
        return self::$horloge_pour_tests !== null ? (float) (self::$horloge_pour_tests)() : microtime(true);
    }

    /** Client HTTP de la collecte : `Http::obtenir`, ou son remplaçant de test. */
    public static function obtenir(string $url, array $options = []): array {
        return self::$obtenir_pour_tests !== null ? (self::$obtenir_pour_tests)($url, $options) : Http::obtenir($url, $options);
    }

    /** Source IVADO avec le contenu de son flux d'événements (`flux_evenements`) ; sans flux lisible, une erreur non bloquante. */
    private static function avec_flux_ivado(array $source, array &$ligne): array {
        $url = (string) ($source['flux_evenements_url'] ?? '');
        if ($url === '') {
            return $source;
        }
        $flux = self::obtenir($url, ['accept' => 'xml']);
        if (Http::est_flux($flux)) {
            $source['flux_evenements'] = (string) $flux['corps'];
        } else {
            /* translators: %s : erreur ou type de contenu reçu. */
            $ligne['erreurs'][] = sprintf(__('Flux des événements IVADO illisible (%s) : résumés absents', 'rsn-veille'), $flux['erreur'] ?? ($flux['type'] ?? ''));
        }
        return $source;
    }

    /**
     * Rappel de `rsn_veille_nuit` (après la mise en file) : la récurrence « daily » compte 24 h, donc au
     * changement d'heure, ou si l'heure des réglages change, la prochaine collecte prévue est ramenée à
     * l'heure voulue la plus proche (à ± 12 h). Une exécution en retard ne décale rien : WordPress a
     * déjà replanifié sur la grille d'origine. Une heure voulue déjà passée laisse la prévision telle quelle.
     */
    public static function recaler_nuit(): void {
        self::recaler_nuit_depuis(time());
    }

    /** `recaler_nuit` à l'instant $maintenant (tests). */
    public static function recaler_nuit_depuis(int $maintenant): void {
        $prevue = wp_next_scheduled(self::CROCHET_NUIT);
        if ($prevue === false) {
            return;
        }
        $voulue = self::prochaine_collecte($prevue - 12 * HOUR_IN_SECONDS);
        if ($voulue !== $prevue && $voulue > $maintenant) {
            wp_unschedule_event($prevue, self::CROCHET_NUIT);
            wp_schedule_event($voulue, 'daily', self::CROCHET_NUIT);
        }
    }

    /** @return string[] File des sources, éléments illisibles ignorés. */
    private static function file(): array {
        $file = get_option(self::OPTION_FILE, []);
        if (!is_array($file)) {
            return [];
        }
        $ids = [];
        foreach ($file as $id) {
            if ((is_string($id) || is_int($id)) && (string) $id !== '') {
                $ids[] = (string) $id;
            }
        }
        return $ids;
    }

    private static function planifier_source(int $delai): void {
        if (wp_next_scheduled(self::CROCHET_SOURCE) === false) {
            wp_schedule_single_event(time() + $delai, self::CROCHET_SOURCE);
        }
    }

    /** @return array<string,mixed> Ligne de journal aux compteurs à zéro, datée de maintenant (heure du site). */
    private static function ligne_vide(): array {
        return [
            'date' => wp_date('Y-m-d H:i'),
            'lus' => 0, 'crees' => 0, 'fusionnes' => 0, 'mis_a_jour' => 0, 'inchanges' => 0, 'ecartes' => 0, 'annules' => 0,
            'ignores' => 0, 'en_attente' => 0, 'erreurs' => [], 'duree_ms' => 0,
        ];
    }
}

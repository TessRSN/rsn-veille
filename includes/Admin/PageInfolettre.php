<?php
declare(strict_types=1);

namespace RSN\Veille\Admin;

use RSN\Veille\EntrepotWordPress;
use RSN\Veille\Infolettre;
use RSN\Veille\Public\Donnees;
use RSN\Veille\Public\EvenementsRsn;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Page Infolettre : six blocs HTML à coller dans Mailchimp. Ce que le RSN publie et organise a ses propres
 * blocs, en billets avec image (Nouvelles du RSN, Événements du RSN, Programmes de financement du RSN) ; le
 * reste est en listes (Agenda de l'écosystème, Appels de l'écosystème, Nouvelles gardées).
 *
 * Chaque bloc a un aperçu (cadre isolé, sans script), « Copier » (collage enrichi : HTML et texte) et
 * « Copier le code HTML ». Les événements et les appels viennent de `Donnees::construire` (les fiches
 * publiées et les événements du RSN, dédoublonnés comme sur la page publique) ; les programmes ouverts du
 * RSN, des articles de la catégorie 149, avec les renseignements de leur case sur le site ; les nouvelles du
 * RSN, des articles de la catégorie 10 (« Actualités du RSN ») parus depuis la date choisie ; les nouvelles
 * gardées, de l'option du radar. Les images des billets passent par `ImageCourriel` (copie JPEG, pour Outlook).
 *
 * Le HTML des blocs est produit par `Infolettre` (testée sans WordPress) ; `page_html` assemble la page à
 * partir de données déjà lues. `afficher`, `traiter_vider`, `programmes_rsn` et `articles_rsn` lisent WordPress.
 */
final class PageInfolettre {
    public const NONCE = 'rsn_veille_infolettre';
    public const ACTION_VIDER = 'rsn_veille_infolettre_vider';

    /** Nombres d'événements proposés pour l'agenda. */
    public const NOMBRES = [6, 8, 10, 12];

    /** Catégorie des articles de programmes du RSN (site rsn.quebec). */
    public const CATEGORIE_PROGRAMMES = 149;

    /** Méta de l'article qui porte la période d'un programme (« date limite : 15 novembre 2026 »). */
    public const META_PERIODE = 'rsn_appel_periode';

    // Autres champs de l'encadré « Appel de financement (RSN) » de l'article (extraits 44 et 45 du site).
    public const META_DATE_LIMITE = 'rsn_date_limite';
    public const META_TITRE = 'rsn_appel_titre';
    public const META_LIEN = 'rsn_appel_lien';
    public const META_PUBLIC = 'rsn_appel_public';
    public const META_NIVEAUX = 'rsn_appel_niveaux';

    /** Nombre d'articles de programmes lus au plus. */
    private const MAX_PROGRAMMES = 50;

    /** Catégorie « Actualités du RSN » des articles du site (rsn.quebec) : les nouvelles du RSN. */
    public const CATEGORIE_ACTUALITES = 10;

    /** Nombre d'articles d'actualité lus au plus. */
    private const MAX_ARTICLES = 20;

    /** Sans date choisie, les nouvelles du RSN sont celles des `JOURS_NOUVELLES_RSN` derniers jours. */
    public const JOURS_NOUVELLES_RSN = 30;

    /** État rendu quand `rsn_appel_etat` échoue ou rend une forme inattendue : le programme n'est pas repris. */
    public const ETAT_INCONNU = 'inconnu';

    /** État « ouvert » tel que l'extrait 45 le nomme (clé `cle` du tableau rendu par `rsn_appel_etat`). */
    public const ETAT_OUVERT = 'en-cours';

    // --- WordPress ---

    /** Accroche le vidage des nouvelles gardées (admin-post.php, connectés seulement). */
    public static function accrocher(): void {
        add_action('admin_post_' . self::ACTION_VIDER, [self::class, 'traiter_vider']);
    }

    /** Affiche la page (rappel du menu). */
    public static function afficher(): void {
        if (!current_user_can(Menu::CAPACITE)) {
            wp_die(esc_html__("Vous n'avez pas accès à cette page.", 'rsn-veille'), '', 403);
        }
        // Réglages demandés par l'adresse (lecture seule, aucune écriture).
        $nombre = self::nombre_demande(isset($_GET['nombre']) ? wp_unslash($_GET['nombre']) : null);
        $horizon = self::horizon_demande(isset($_GET['jusqu_au']) ? wp_unslash($_GET['jusqu_au']) : null);
        $aujourdhui = \rsn_veille_aujourdhui();
        $depuis = self::depuis_demande(isset($_GET['depuis']) ? wp_unslash($_GET['depuis']) : null, $aujourdhui);
        // Même source que la page publique : fiches publiées et événements du RSN dédoublonnés, sans les champs d'équipe.
        // L'infolettre est française : toujours `fr`, quelle que soit la langue de l'administration.
        // Les fiches tenues hors de l'infolettre sont marquées avant le dédoublonnage (`Donnees::elements`).
        $hors = PageFile::lire_hors_infolettre();
        $donnees = Donnees::construire($aujourdhui, new EntrepotWordPress(), EvenementsRsn::lire(langue: 'fr'), false, 'fr', $hors);
        // Sortie entièrement échappée par les méthodes de rendu.
        echo self::page_html($donnees['items'], PageRadar::lire_gardees(), self::programmes_rsn(), [
            'image' => [ImageCourriel::class, 'adresse'],
            'articles_rsn' => self::articles_rsn($depuis ?? self::depuis_par_defaut($aujourdhui)),
            'depuis' => $depuis,
            'aujourdhui' => $aujourdhui,
            'nombre' => $nombre,
            'jusqu_au' => $horizon,
            'url_page' => admin_url('admin.php'),
            'url_action' => admin_url('admin-post.php'),
            'nonce' => wp_create_nonce(self::NONCE),
            'vide' => isset($_GET['vide']) && $_GET['vide'] === '1',
        ]);
    }

    /** admin-post.php : « Vider les nouvelles gardées » (capacité et jeton exigés), puis retour à la page. */
    public static function traiter_vider(): void {
        if (!current_user_can(Menu::CAPACITE)) {
            wp_die(esc_html__("Vous n'avez pas le droit de modifier la veille.", 'rsn-veille'), '', 403);
        }
        check_admin_referer(self::NONCE);
        self::vider_gardees();
        wp_safe_redirect(admin_url('admin.php?page=' . Menu::SLUG_INFOLETTRE . '&vide=1'));
        exit;
    }

    /** Vide l'option des nouvelles gardées (la même que celle du radar). */
    public static function vider_gardees(): void {
        update_option(PageRadar::OPTION_GARDEES, [], false);
    }

    /**
     * Programmes du RSN ouverts : articles de la catégorie 149 pour lesquels la fonction `rsn_appel_etat`
     * du site (extrait 45) répond « en cours » (clé `en-cours`), convertis en appels. Sans cette fonction, liste vide.
     * Non testable sans WordPress : vérification dans `tests/manuel/verifier-admin.md`.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function programmes_rsn(): array {
        if (!function_exists('rsn_appel_etat') || !function_exists('get_posts')) {
            return [];
        }
        $articles = get_posts([
            'cat' => self::CATEGORIE_PROGRAMMES,
            'post_type' => 'post',
            'post_status' => 'publish',
            'numberposts' => self::MAX_PROGRAMMES,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
        $aujourdhui = \rsn_veille_aujourdhui();
        $programmes = [];
        foreach (is_array($articles) ? $articles : [] as $article) {
            if (!$article instanceof \WP_Post || self::etat_programme('rsn_appel_etat', $article) !== self::ETAT_OUVERT) {
                continue;
            }
            $programmes[] = self::programme_depuis_article($article, $aujourdhui);
        }
        return $programmes;
    }

    /**
     * Un article de programme converti en appel, avec ce que sa case montre sur la page Programmes du site
     * (extrait 45) : titre court de l'encadré s'il est rempli, date limite, image mise en avant, lien du
     * formulaire de dépôt (champ de l'encadré, sinon premier lien Fourwaves du texte), « Pour qui » (cases de
     * l'encadré, sinon bloc « En bref » du texte) et « Montant » (bloc « En bref »).
     *
     * @return array<string,mixed>
     */
    public static function programme_depuis_article(\WP_Post $article, string $aujourdhui): array {
        $meta = static function (string $cle) use ($article): string {
            $valeur = get_post_meta($article->ID, $cle, true);
            return is_string($valeur) ? trim($valeur) : '';
        };
        $liste = static fn(string $cle): array => array_values(array_filter(array_map('trim', explode(',', $meta($cle))), static fn(string $v): bool => $v !== ''));
        $contenu = (string) $article->post_content;
        $extrait = (string) get_the_excerpt($article);
        if ($extrait === '') {
            $extrait = $contenu;
        }
        $titre = $meta(self::META_TITRE);
        if ($titre === '') {
            $titre = html_entity_decode(wp_strip_all_tags((string) get_the_title($article)), ENT_QUOTES, 'UTF-8');
        }
        $lien = $meta(self::META_LIEN);
        $image = function_exists('get_the_post_thumbnail_url') ? get_the_post_thumbnail_url($article->ID, 'large') : false;
        return Infolettre::appel_programme(
            $titre,
            (string) get_permalink($article),
            $extrait,
            $meta(self::META_PERIODE),
            (string) $article->post_date,
            $aujourdhui,
            [
                'echeance' => $meta(self::META_DATE_LIMITE),
                'image_url' => is_string($image) ? $image : '',
                'lien' => $lien !== '' ? $lien : Infolettre::lien_fourwaves($contenu),
                'pour_qui' => Infolettre::pour_qui($liste(self::META_PUBLIC), $liste(self::META_NIVEAUX), Infolettre::en_bref($contenu, [__('Pour qui', 'rsn-veille')])),
                'montant' => Infolettre::en_bref($contenu, [__('Montant', 'rsn-veille')]),
            ]
        );
    }

    /**
     * Nouvelles du RSN : articles publiés de la catégorie « Actualités du RSN » parus depuis `$depuis`, sans les
     * programmes de financement (catégorie 149, qui ont leur bloc). Les traductions ont leur propre catégorie :
     * seuls les articles français répondent. Non testable sans WordPress : `tests/manuel/verifier-admin.md`.
     *
     * @return array<int,array<string,mixed>> Articles au format de `Infolettre::article_rsn`.
     */
    public static function articles_rsn(string $depuis): array {
        if (!function_exists('get_posts')) {
            return [];
        }
        $posts = get_posts([
            'cat' => self::CATEGORIE_ACTUALITES,
            'category__not_in' => [self::CATEGORIE_PROGRAMMES],
            'post_type' => 'post',
            'post_status' => 'publish',
            // Un article protégé par mot de passe ne va pas dans une infolettre.
            'has_password' => false,
            'numberposts' => self::MAX_ARTICLES,
            'orderby' => 'date',
            'order' => 'DESC',
            'date_query' => [['after' => $depuis . ' 00:00:00', 'inclusive' => true]],
        ]);
        $articles = [];
        foreach (is_array($posts) ? $posts : [] as $post) {
            if ($post instanceof \WP_Post) {
                $articles[] = self::article_depuis_post($post);
            }
        }
        return $articles;
    }

    /**
     * Un article du site au format des nouvelles du RSN : titre, adresse, date de parution, texte (l'extrait
     * rédigé dans l'article, sinon le début du texte, codes courts retirés) et image mise en avant.
     *
     * @return array<string,mixed>
     */
    public static function article_depuis_post(\WP_Post $article): array {
        $contenu = (string) $article->post_content;
        if (function_exists('strip_shortcodes')) {
            $contenu = (string) strip_shortcodes($contenu);
        }
        $image = function_exists('get_the_post_thumbnail_url') ? get_the_post_thumbnail_url($article->ID, 'large') : false;
        return Infolettre::article_rsn(
            html_entity_decode(wp_strip_all_tags((string) get_the_title($article)), ENT_QUOTES, 'UTF-8'),
            (string) get_permalink($article),
            (string) $article->post_date,
            // L'extrait rédigé seulement : `get_the_excerpt` en fabriquerait un, coupé au milieu du surtitre et du titre.
            (string) $article->post_excerpt,
            $contenu,
            is_string($image) ? $image : ''
        );
    }

    /**
     * État d'un programme selon la fonction du site ($etat : `rsn_appel_etat` de l'extrait 45), appelée avec
     * l'identifiant de l'article : la fonction fait `get_post_meta($post_id, …)`, qui attend un entier. Elle rend
     * un tableau `['cle' => 'en-cours'|'a-venir'|'evaluation'|'finance', 'libelle' => …]` ; on en garde la clé.
     * Toute erreur, ou une forme inattendue (ni tableau avec `cle`, ni chaîne), donne `ETAT_INCONNU` sans casser
     * la page Infolettre.
     */
    public static function etat_programme(callable $etat, \WP_Post $article): string {
        try {
            $resultat = $etat($article->ID);
        } catch (\Throwable $erreur) {
            return self::ETAT_INCONNU;
        }
        if (is_array($resultat) && isset($resultat['cle']) && is_string($resultat['cle']) && $resultat['cle'] !== '') {
            return $resultat['cle'];
        }
        return is_string($resultat) && $resultat !== '' ? $resultat : self::ETAT_INCONNU;
    }

    // --- Réglages demandés ---

    /** Nombre d'événements de l'agenda : 6, 8, 10 ou 12 ; 8 sinon. */
    public static function nombre_demande(mixed $brut): int {
        $nombre = is_scalar($brut) && preg_match('/^\d+$/', (string) $brut) === 1 ? (int) $brut : 0;
        return in_array($nombre, self::NOMBRES, true) ? $nombre : Infolettre::NOMBRE_PAR_DEFAUT;
    }

    /** Horizon de l'agenda : une date `Y-m-d` valide, sinon aucun. */
    public static function horizon_demande(mixed $brut): ?string {
        if (!is_string($brut) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $brut, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        return $brut;
    }

    /** Date de départ des nouvelles du RSN demandée : une date `Y-m-d` valide, pas dans le futur ; sinon aucune. */
    public static function depuis_demande(mixed $brut, string $aujourdhui): ?string {
        $date = self::horizon_demande($brut);
        return $date !== null && $date <= $aujourdhui ? $date : null;
    }

    /** Date de départ sans choix : `JOURS_NOUVELLES_RSN` jours avant aujourd'hui. */
    public static function depuis_par_defaut(string $aujourdhui): string {
        $jour = \DateTimeImmutable::createFromFormat('!Y-m-d', $aujourdhui) ?: new \DateTimeImmutable('today');
        return $jour->modify('-' . self::JOURS_NOUVELLES_RSN . ' days')->format('Y-m-d');
    }

    // --- Rendu ---

    /**
     * Page complète.
     *
     * @param array<int,mixed> $items Fiches de `Donnees` (événements et appels publiés).
     * @param array<int,mixed> $gardees Nouvelles gardées.
     * @param array<int,mixed> $programmes Programmes du RSN ouverts, au format appel.
     * @param array{aujourdhui?: string, nombre?: int, jusqu_au?: ?string, url_page?: string, url_action?: string, nonce?: string, vide?: bool, image?: callable, articles_rsn?: array<int,mixed>, depuis?: ?string} $options
     *        `image` : reçoit l'adresse de l'image d'un billet du RSN et rend celle à mettre dans le courriel.
     *        `articles_rsn` : articles du site au format de `Infolettre::article_rsn` ; `depuis` : date de départ
     *        choisie pour eux (sans choix, les `JOURS_NOUVELLES_RSN` derniers jours).
     *
     * Un élément marqué `hors_infolettre` par `Donnees` (fiche que l'équipe ne veut pas dans l'infolettre : « Publier
     * seulement » ou « Retirer de l'infolettre » dans la file) n'est pas repris. Une nouvelle gardée trop vieille
     * (`PageRadar::est_archivee`) non plus.
     */
    public static function page_html(array $items, array $gardees, array $programmes, array $options): string {
        $aujourdhui = (string) ($options['aujourdhui'] ?? \rsn_veille_aujourdhui());
        $nombre = self::nombre_demande($options['nombre'] ?? null);
        $horizon = self::horizon_demande($options['jusqu_au'] ?? null);
        $depuis_choisi = self::depuis_demande($options['depuis'] ?? null, $aujourdhui);
        $depuis = $depuis_choisi ?? self::depuis_par_defaut($aujourdhui);
        $articles = Infolettre::articles_rsn_retenus(is_array($options['articles_rsn'] ?? null) ? $options['articles_rsn'] : [], $depuis, $aujourdhui);
        $toutes_gardees = array_values(array_filter($gardees, static fn($g): bool => is_array($g)));
        $gardees = PageRadar::gardees_actives($toutes_gardees, $aujourdhui);
        $nb_archivees = count($toutes_gardees) - count($gardees);
        // Fiches que l'équipe tient hors de l'infolettre (marque de `Donnees`, qui tient compte des doublons) :
        // retirées avant le choix des blocs.
        $dedans = static fn(array $i): bool => empty($i['hors_infolettre']);
        $du_type = static fn(string $type): array => array_values(array_filter($items, static fn($i): bool => is_array($i) && ($i['type'] ?? '') === $type));
        $tous_appels = $du_type('appel');
        $tous_evenements = $du_type('evenement');
        $appels = array_values(array_filter($tous_appels, $dedans));
        $evenements = array_values(array_filter($tous_evenements, $dedans));
        // Un programme du site dont la fiche publiée (même adresse) est tenue hors de l'infolettre n'y va pas non plus.
        $adresse = static fn(array $a): string => rtrim(trim((string) ($a['url'] ?? '')), '/');
        $adresses_hors = [];
        foreach ($tous_appels as $appel) {
            if (!$dedans($appel) && $adresse($appel) !== '') {
                $adresses_hors[$adresse($appel)] = true;
            }
        }
        $tous_programmes = array_values(array_filter($programmes, static fn($p): bool => is_array($p)));
        $programmes = array_values(array_filter($tous_programmes, static fn(array $p): bool => !isset($adresses_hors[$adresse($p)])));

        // Images des billets du RSN : la copie pour courriel (JPEG), faite seulement pour ce qui est repris.
        $evenements_rsn = Infolettre::evenements_rsn_retenus($evenements, $horizon, $aujourdhui);
        $image = $options['image'] ?? null;
        if (is_callable($image)) {
            $adapter = static function (array $element) use ($image): array {
                if (Infolettre::du_rsn($element) && is_string($element['image_url'] ?? null) && $element['image_url'] !== '') {
                    $element['image_url'] = (string) $image($element['image_url']);
                }
                return $element;
            };
            $evenements_rsn = array_map($adapter, $evenements_rsn);
            $articles = array_map(static function (array $article) use ($image): array {
                if (is_string($article['image_url'] ?? null) && $article['image_url'] !== '') {
                    $article['image_url'] = (string) $image($article['image_url']);
                }
                return $article;
            }, $articles);
            $programmes = array_map($adapter, $programmes);
            $appels = array_map($adapter, $appels);
        }

        $nb_nouvelles_rsn = count($articles);
        $nb_evenements_rsn = count($evenements_rsn);
        $nb_programmes = count(Infolettre::appels_rsn_retenus($appels, $programmes, $aujourdhui));
        $nb_evenements = count(Infolettre::evenements_ecosysteme_retenus($evenements, $nombre, $horizon, $aujourdhui));
        $nb_appels = count(Infolettre::appels_ecosysteme_retenus($appels, $programmes, $aujourdhui));
        $nb_nouvelles = count(Infolettre::nouvelles_retenues($gardees));
        // Ce qui aurait été repris sans le choix de l'équipe : dit sous chaque bloc, pour que rien ne manque en silence.
        $hors_evenements_rsn = count(Infolettre::evenements_rsn_retenus($tous_evenements, $horizon, $aujourdhui)) - $nb_evenements_rsn;
        $hors_programmes = count(Infolettre::appels_rsn_retenus($tous_appels, $tous_programmes, $aujourdhui)) - $nb_programmes;
        $hors_evenements = count(Infolettre::evenements_ecosysteme_retenus($tous_evenements, PHP_INT_MAX, $horizon, $aujourdhui))
            - count(Infolettre::evenements_ecosysteme_retenus($evenements, PHP_INT_MAX, $horizon, $aujourdhui));
        $hors_appels = count(Infolettre::appels_ecosysteme_retenus($tous_appels, $tous_programmes, $aujourdhui)) - $nb_appels;

        $avis = !empty($options['vide'])
            ? '<div class="notice notice-success inline"><p>' . esc_html__('Nouvelles gardées vidées.', 'rsn-veille') . '</p></div>'
            : '';

        return '<div class="wrap rsn-veille-admin rsn-veille-infolettre">'
            . '<h1>' . esc_html__('Infolettre', 'rsn-veille') . '</h1>'
            . '<p class="description">' . esc_html__("Six blocs prêts pour Mailchimp : ce que le RSN publie et organise en billets avec image, le reste de l'écosystème en listes. « Copier » garde la mise en forme : collez-la dans l'éditeur de l'infolettre. « Copier le code HTML » sert pour un bloc Code.", 'rsn-veille') . '</p>'
            . $avis
            . '<div class="rsn-veille-message" role="status" aria-live="polite"></div>'
            . self::section_html(
                'nouvelles-rsn',
                __('Nouvelles du RSN', 'rsn-veille'),
                self::libelle_nouvelles($nb_nouvelles_rsn),
                Infolettre::bloc_nouvelles_rsn($articles, $depuis, $aujourdhui),
                $nb_nouvelles_rsn,
                self::depuis_html($depuis, $aujourdhui, $nombre, $horizon, $options)
            )
            . self::section_html(
                'evenements-rsn',
                __('Événements du RSN', 'rsn-veille'),
                self::libelle_evenements($nb_evenements_rsn),
                Infolettre::bloc_evenements_rsn($evenements_rsn, $horizon, $aujourdhui),
                $nb_evenements_rsn,
                self::note_html(self::note_evenements_hors($hors_evenements_rsn))
            )
            . self::section_html(
                'programmes-rsn',
                __('Programmes de financement du RSN', 'rsn-veille'),
                self::libelle_programmes($nb_programmes),
                Infolettre::bloc_programmes_rsn($appels, $programmes, $aujourdhui),
                $nb_programmes,
                self::note_html(self::note_appels_hors($hors_programmes))
            )
            . self::section_html(
                'agenda',
                __("Agenda de l'écosystème", 'rsn-veille'),
                self::libelle_evenements($nb_evenements),
                Infolettre::bloc_agenda($evenements, $nombre, $horizon, $aujourdhui),
                $nb_evenements,
                self::reglages_html($nombre, $horizon, $depuis_choisi, $options) . self::note_html(self::note_evenements_hors($hors_evenements))
            )
            . self::section_html(
                'appels',
                __("Appels de l'écosystème", 'rsn-veille'),
                self::libelle_appels($nb_appels),
                Infolettre::bloc_appels($appels, $programmes, $aujourdhui),
                $nb_appels,
                self::note_html(self::note_appels_hors($hors_appels))
            )
            . self::section_html(
                'nouvelles',
                __('Nouvelles gardées', 'rsn-veille'),
                self::libelle_nouvelles($nb_nouvelles),
                Infolettre::bloc_nouvelles($gardees, $aujourdhui),
                $nb_nouvelles,
                self::note_html($nb_archivees > 0
                    /* translators: 1 : nombre de nouvelles gardées archivées ; 2 : nombre de jours. */
                    ? sprintf(_n('%1$d nouvelle gardée, publiée depuis plus de %2$d jours, est archivée (Radar › Archivées).', '%1$d nouvelles gardées, publiées depuis plus de %2$d jours, sont archivées (Radar › Archivées).', $nb_archivees, 'rsn-veille'), $nb_archivees, PageRadar::JOURS_ARCHIVE)
                    : '') . self::vider_html(count($toutes_gardees), $options)
            )
            . '</div>';
    }

    private static function note_evenements_hors(int $n): string {
        /* translators: %d : nombre d'événements publiés que l'équipe tient hors de l'infolettre. */
        return $n > 0 ? sprintf(_n("%d événement publié est tenu hors de l'infolettre (File d'approbation › Publiées).", "%d événements publiés sont tenus hors de l'infolettre (File d'approbation › Publiées).", $n, 'rsn-veille'), $n) : '';
    }

    private static function note_appels_hors(int $n): string {
        /* translators: %d : nombre d'appels publiés que l'équipe tient hors de l'infolettre. */
        return $n > 0 ? sprintf(_n("%d appel publié est tenu hors de l'infolettre (File d'approbation › Publiées).", "%d appels publiés sont tenus hors de l'infolettre (File d'approbation › Publiées).", $n, 'rsn-veille'), $n) : '';
    }

    /** Note sous un bloc (ce que l'équipe a tenu hors de l'infolettre, ce qui est archivé) ; vide sans texte. */
    private static function note_html(string $texte): string {
        return $texte !== '' ? '<p class="description rsn-veille-infolettre-note">' . esc_html($texte) . '</p>' : '';
    }

    /**
     * Un bloc : titre, compteur, réglages éventuels, boutons, aperçu et codes cachés (HTML et texte).
     * Sans élément, les boutons de copie sont désactivés (le bloc ne contient que son message).
     */
    private static function section_html(string $cle, string $titre, string $compteur, string $html, int $nb, string $complement): string {
        $desactive = $nb === 0 ? ' disabled' : '';
        $apercu = '<!doctype html><html><head><meta charset="utf-8"><base target="_blank"></head>'
            . '<body style="margin:16px;background:#fff">' . $html . '</body></html>';
        return '<section class="rsn-veille-bloc" data-bloc="' . esc_attr($cle) . '">'
            . '<h2>' . esc_html($titre) . ' <span class="rsn-veille-compteur">' . esc_html($compteur) . '</span></h2>'
            . $complement
            . '<div class="rsn-veille-bloc-actions">'
            . '<button type="button" class="button button-primary rsn-veille-copier" data-mode="riche"' . $desactive . '>' . esc_html__('Copier', 'rsn-veille') . '</button> '
            . '<button type="button" class="button rsn-veille-copier" data-mode="code"' . $desactive . '>' . esc_html__('Copier le code HTML', 'rsn-veille') . '</button>'
            . '</div>'
            // Cadre isolé : aucun script (pas d'allow-scripts), même origine seulement pour que « Copier » puisse sélectionner le rendu.
            // `htmlspecialchars` (et non esc_attr) : esc_attr ne ré-encode pas les entités, ce qui rendrait « &lt;b&gt; » en vraie balise.
            . '<iframe class="rsn-veille-apercu" title="' . esc_attr(sprintf(
                /* translators: %s : nom du bloc (Événements du RSN, Agenda de l'écosystème, Nouvelles gardées…). */
                __('Aperçu du bloc %s', 'rsn-veille'),
                $titre
            )) . '" sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox" srcdoc="'
            . htmlspecialchars($apercu, ENT_QUOTES, 'UTF-8') . '"></iframe>'
            . '<textarea hidden readonly data-role="html">' . esc_textarea($html) . '</textarea>'
            . '<textarea hidden readonly data-role="texte">' . esc_textarea(Infolettre::texte_brut($html)) . '</textarea>'
            . '</section>';
    }

    /**
     * Réglage des nouvelles du RSN : formulaire GET (date de départ). Les réglages de l'agenda suivent en
     * champs cachés, pour qu'un formulaire n'efface pas le choix de l'autre.
     */
    private static function depuis_html(string $depuis, string $aujourdhui, int $nombre, ?string $horizon, array $options): string {
        /* translators: %d : nombre de jours couverts quand aucune date n'est choisie. */
        $aide = sprintf(__('Articles de la catégorie « Actualités du RSN » du site. Sans date choisie : les %d derniers jours.', 'rsn-veille'), self::JOURS_NOUVELLES_RSN);
        return '<form method="get" action="' . esc_url((string) ($options['url_page'] ?? '')) . '" class="rsn-veille-reglages-bloc">'
            . '<input type="hidden" name="page" value="' . esc_attr(Menu::SLUG_INFOLETTRE) . '">'
            . '<input type="hidden" name="nombre" value="' . $nombre . '">'
            . ($horizon !== null ? '<input type="hidden" name="jusqu_au" value="' . esc_attr($horizon) . '">' : '')
            . '<label for="rsn-veille-depuis">' . esc_html__('Articles publiés depuis le', 'rsn-veille') . '</label> '
            . '<input type="date" name="depuis" id="rsn-veille-depuis" value="' . esc_attr($depuis) . '" max="' . esc_attr($aujourdhui) . '"> '
            . '<button type="submit" class="button">' . esc_html__('Appliquer', 'rsn-veille') . '</button> '
            . '<span class="description">' . esc_html($aide) . '</span>'
            . '</form>';
    }

    /** Réglages de l'agenda : formulaire GET (nombre d'événements ou horizon) ; la date choisie pour les nouvelles du RSN suit en champ caché. */
    private static function reglages_html(int $nombre, ?string $horizon, ?string $depuis_choisi, array $options): string {
        $choix = '';
        foreach (self::NOMBRES as $n) {
            $choix .= '<option value="' . $n . '"' . ($n === $nombre ? ' selected' : '') . '>' . $n . '</option>';
        }
        return '<form method="get" action="' . esc_url((string) ($options['url_page'] ?? '')) . '" class="rsn-veille-reglages-bloc">'
            . '<input type="hidden" name="page" value="' . esc_attr(Menu::SLUG_INFOLETTRE) . '">'
            . ($depuis_choisi !== null ? '<input type="hidden" name="depuis" value="' . esc_attr($depuis_choisi) . '">' : '')
            . '<label for="rsn-veille-nombre">' . esc_html__("Nombre d'événements", 'rsn-veille') . '</label> '
            . '<select name="nombre" id="rsn-veille-nombre">' . $choix . '</select> '
            . '<label for="rsn-veille-jusqu-au">' . esc_html__("ou jusqu'au", 'rsn-veille') . '</label> '
            . '<input type="date" name="jusqu_au" id="rsn-veille-jusqu-au" value="' . esc_attr((string) $horizon) . '"> '
            . '<button type="submit" class="button">' . esc_html__('Appliquer', 'rsn-veille') . '</button> '
            . '<span class="description">' . esc_html__("Une date remplace le nombre d'événements ; elle borne aussi les événements du RSN.", 'rsn-veille') . '</span>'
            . '</form>';
    }

    /** Bouton « Vider les nouvelles gardées » (POST, jeton) ; désactivé quand rien n'est gardé. */
    private static function vider_html(int $nb_gardees, array $options): string {
        return '<form method="post" action="' . esc_url((string) ($options['url_action'] ?? '')) . '" class="rsn-veille-vider">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_VIDER) . '">'
            . '<input type="hidden" name="_wpnonce" value="' . esc_attr((string) ($options['nonce'] ?? '')) . '">'
            . '<button type="submit" class="button"' . ($nb_gardees === 0 ? ' disabled' : '') . '>' . esc_html__('Vider les nouvelles gardées', 'rsn-veille') . '</button>'
            . '</form>';
    }

    private static function libelle_evenements(int $n): string {
        /* translators: %d : nombre d'événements dans un bloc d'événements (du RSN, de l'écosystème). */
        return sprintf(_n('%d événement', '%d événements', $n, 'rsn-veille'), $n);
    }

    private static function libelle_programmes(int $n): string {
        /* translators: %d : nombre de programmes dans le bloc Programmes de financement du RSN. */
        return sprintf(_n('%d programme', '%d programmes', $n, 'rsn-veille'), $n);
    }

    private static function libelle_appels(int $n): string {
        /* translators: %d : nombre d'appels dans le bloc Appels de l'écosystème. */
        return sprintf(_n('%d appel', '%d appels', $n, 'rsn-veille'), $n);
    }

    private static function libelle_nouvelles(int $n): string {
        /* translators: %d : nombre de nouvelles dans le bloc Nouvelles gardées. */
        return sprintf(_n('%d nouvelle', '%d nouvelles', $n, 'rsn-veille'), $n);
    }
}

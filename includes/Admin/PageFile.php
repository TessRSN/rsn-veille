<?php
declare(strict_types=1);

namespace RSN\Veille\Admin;

use RSN\Veille\Collecte;
use RSN\Veille\Entrepot;
use RSN\Veille\EntrepotWordPress;
use RSN\Veille\Fiche;
use RSN\Veille\Journal;
use RSN\Veille\Public\Formulaire;
use RSN\Veille\Ressemblance;
use RSN\Veille\TypeContenu;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * File d'approbation : onglets À approuver, Publiées, Écartées, À vérifier ; filtres par type et par
 * source ; boutons Publier, Écarter, Retirer (action AJAX `rsn_veille_changer_statut`).
 *
 * Le HTML est produit par des méthodes pures (testées sans WordPress réel) ; `afficher` et
 * `ajax_changer_statut` lisent la requête et appellent WordPress.
 */
final class PageFile {
    public const NONCE = 'rsn_veille_file';
    public const ACTION_AJAX = 'rsn_veille_changer_statut';
    /** Action AJAX : mettre une fiche publiée dans l'infolettre du RSN, ou l'en retirer. */
    public const ACTION_INFOLETTRE = 'rsn_veille_fiche_infolettre';
    /**
     * Identifiants des fiches publiées que l'équipe ne veut pas dans l'infolettre (option). Sans y être, une fiche
     * publiée et d'actualité est reprise par la page Infolettre, comme avant la 0.15.0.
     */
    public const OPTION_HORS_INFOLETTRE = 'rsn_veille_hors_infolettre';
    /** Nombre d'identifiants gardés au plus dans cette option (les plus récents). */
    private const MAX_HORS_INFOLETTRE = 500;

    /** Onglets, dans l'ordre d'affichage. `a_verifier` : fiches publiées signalées absentes de leur source. */
    /** Origines d'une fiche (`Fiche::origine`), pour le filtre de la file. */
    public const ORIGINES = ['proposition', 'collecte', 'equipe'];

    public const ONGLETS = ['a_approuver', 'publie', 'archive', 'ecarte', 'a_verifier'];

    /** Statuts qu'un bouton peut donner. */
    private const STATUTS = ['publie', 'a_approuver', 'ecarte'];

    /** Types filtrables. */
    private const TYPES = ['evenement', 'appel'];

    /** Longueur de l'extrait affiché. */
    private const LONGUEUR_EXTRAIT = 180;

    /** Fiches affichées au plus dans les onglets Publiées et Écartées (les plus récentes). */
    public const PLAFOND = 200;

    // --- WordPress : page et action AJAX ---

    /** Affiche la page (rappel du menu). */
    public static function afficher(): void {
        if (!current_user_can(Menu::CAPACITE)) {
            wp_die(esc_html__("Vous n'avez pas accès à cette page.", 'rsn-veille'), '', 403);
        }
        $onglet = self::parametre('statut');
        $type = self::parametre('type');
        $source = isset($_GET['source']) && is_string($_GET['source']) ? sanitize_text_field(wp_unslash($_GET['source'])) : '';
        $origine = self::parametre('origine');
        $onglet = in_array($onglet, self::ONGLETS, true) ? $onglet : 'a_approuver';
        $type = in_array($type, self::TYPES, true) ? $type : '';
        $entrepot = new EntrepotWordPress();
        $aujourdhui = rsn_veille_aujourdhui();
        // Lues une seule fois : elles servent aux fiches de l'onglet, aux nombres des onglets et aux ressemblances.
        $actuelles = self::publiees_d_actualite($entrepot, $aujourdhui);
        $attente = $entrepot->lister('a_approuver');
        [$fiches, $plafond_atteint] = self::fiches_de_l_onglet($entrepot, $onglet, $type, $aujourdhui, $actuelles, $attente);
        // Courriel des proposants (méta privée, jamais publiée), pour que l'équipe puisse leur répondre.
        $courriels = [];
        foreach ($fiches as $fiche) {
            if ($fiche->est_proposition() && $fiche->id !== null) {
                $courriel = get_post_meta($fiche->id, TypeContenu::PREFIXE . 'courriel_proposant', true);
                if (is_string($courriel) && $courriel !== '') {
                    $courriels[$fiche->id] = $courriel;
                }
            }
        }
        // Sortie entièrement échappée par les méthodes de rendu.
        echo self::page_html($fiches, $onglet, $type, $source, admin_url('admin.php?page=' . Menu::SLUG), [
            'url_admin' => admin_url(),
            'compteurs' => self::compteurs_de($entrepot, $aujourdhui, $actuelles, $attente),
            'aujourdhui' => $aujourdhui,
            'hors_infolettre' => self::lire_hors_infolettre(),
            'plafond_atteint' => $plafond_atteint,
            'peut_ajouter' => current_user_can('edit_posts'),
            'origine' => $origine,
            'courriels' => $courriels,
            'sources_a_verifier' => self::sources_a_verifier(Journal::alertes(Journal::tout()), Collecte::sources()),
            // Mêmes repères que le Radar : ce qui est peut-être déjà publié, ce qu'une infolettre annonce plusieurs fois.
            'ressemblances' => $onglet === 'a_approuver' ? self::ressemblances($entrepot, $fiches, $aujourdhui, $actuelles) : [],
            'mentions' => Collecte::mentions(),
            'url_journal' => admin_url('admin.php?page=' . Menu::SLUG_JOURNAL),
        ]);
    }

    /**
     * Fiche ressemblante de chaque fiche en attente (`Ressemblance::pour_la_file`), parmi les fiches publiées encore
     * d'actualité (événements à venir ou en cours, appels en cours : jamais toute la base) et les autres fiches en attente.
     *
     * @param Fiche[] $en_attente
     * @param Fiche[]|null $publiees Fiches publiées d'actualité déjà lues (`publiees_d_actualite`), sinon lues ici.
     * @return array<int,Fiche>
     */
    public static function ressemblances(Entrepot $e, array $en_attente, string $aujourdhui, ?array $publiees = null): array {
        if ($en_attente === []) {
            return [];
        }
        return Ressemblance::pour_la_file($en_attente, $publiees ?? self::publiees_d_actualite($e, $aujourdhui), $aujourdhui);
    }

    /**
     * Fiches publiées qui peuvent encore être d'actualité (événements à venir ou en cours, appels en cours) : un
     * sur-ensemble borné par les dates (`Entrepot::lister_pour_page`), jamais toute la base.
     *
     * @return Fiche[]
     */
    private static function publiees_d_actualite(Entrepot $e, string $aujourdhui): array {
        $jour = \DateTimeImmutable::createFromFormat('!Y-m-d', $aujourdhui);
        $limite_appels = $jour !== false ? $jour->modify('-' . Fiche::JOURS_APPEL_SANS_LIMITE . ' days')->format('Y-m-d') : $aujourdhui;
        return $e->lister_pour_page($aujourdhui, $aujourdhui, $limite_appels);
    }

    /**
     * Nombre de fiches par onglet. Une fiche dépassée (`Fiche::est_passee` : événement fini, appel qui n'est plus
     * en cours) quitte « À approuver » ou « Publiées » pour « Archivées », sans que son statut change.
     *
     * Réservé à l'affichage de la page : il lit la file en attente et les fiches publiées d'actualité (ou reçoit
     * celles que la page a déjà lues). Une action AJAX rend seulement des variations (`onglets_de`).
     *
     * @param Fiche[]|null $actuelles Fiches publiées d'actualité déjà lues (`publiees_d_actualite`).
     * @param Fiche[]|null $attente Fiches à approuver déjà lues, tous types.
     * @return array<string,int>
     */
    public static function compteurs_de(Entrepot $e, string $aujourdhui, ?array $actuelles = null, ?array $attente = null): array {
        $bruts = $e->compter();
        $en_cours = count(array_filter($actuelles ?? self::publiees_d_actualite($e, $aujourdhui), static fn(Fiche $f): bool => self::dans_onglet($f, 'publie', $aujourdhui)));
        $attente_passees = count(array_filter($attente ?? $e->lister('a_approuver'), static fn(Fiche $f): bool => self::dans_onglet($f, 'archive', $aujourdhui)));
        return [
            'a_approuver' => max(0, (int) ($bruts['a_approuver'] ?? 0) - $attente_passees),
            'publie' => $en_cours,
            'archive' => max(0, (int) ($bruts['publie'] ?? 0) - $en_cours) + $attente_passees,
            'ecarte' => (int) ($bruts['ecarte'] ?? 0),
            'a_verifier' => (int) ($bruts['a_verifier'] ?? 0),
        ];
    }

    /**
     * Onglets où une fiche est montrée ce jour-là (« À vérifier » recoupe « Publiées » ou « Archivées »).
     *
     * @return string[]
     */
    public static function onglets_de(Fiche $f, string $aujourdhui): array {
        return array_values(array_filter(self::ONGLETS, static fn(string $onglet): bool => self::dans_onglet($f, $onglet, $aujourdhui)));
    }

    /**
     * Ce qu'une action change aux nombres des onglets : -1 pour ceux que la fiche quitte, +1 pour ceux où elle
     * arrive. Le navigateur l'applique aux nombres affichés, sans que le serveur relise la file.
     *
     * @return array<string,int>
     */
    public static function variations(?Fiche $avant, ?Fiche $apres, string $aujourdhui): array {
        $variations = [];
        foreach ($avant !== null ? self::onglets_de($avant, $aujourdhui) : [] as $onglet) {
            $variations[$onglet] = ($variations[$onglet] ?? 0) - 1;
        }
        foreach ($apres !== null ? self::onglets_de($apres, $aujourdhui) : [] as $onglet) {
            $variations[$onglet] = ($variations[$onglet] ?? 0) + 1;
        }
        return array_filter($variations, static fn(int $v): bool => $v !== 0);
    }

    // --- Infolettre du RSN : fiches publiées que l'équipe n'y veut pas ---

    /** @return int[] Identifiants des fiches publiées tenues hors de l'infolettre (option illisible : aucun). */
    public static function lire_hors_infolettre(): array {
        $option = get_option(self::OPTION_HORS_INFOLETTRE, []);
        $ids = [];
        foreach (is_array($option) ? $option : [] as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $ids[] = (int) $id;
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * Liste mise à jour : `$dans = false` y ajoute la fiche (à la fin), `true` l'en retire. Bornée aux
     * `MAX_HORS_INFOLETTRE` derniers identifiants : les plus anciens sont ceux de fiches depuis longtemps dépassées.
     *
     * @param array<int,mixed> $hors
     * @return int[]
     */
    public static function regler_infolettre(array $hors, int $id, bool $dans): array {
        $ids = [];
        foreach ($hors as $autre) {
            if (is_numeric($autre) && (int) $autre > 0 && (int) $autre !== $id) {
                $ids[] = (int) $autre;
            }
        }
        $ids = array_values(array_unique($ids));
        if (!$dans && $id > 0) {
            $ids[] = $id;
        }
        return array_slice($ids, -self::MAX_HORS_INFOLETTRE);
    }

    /** Enregistre le choix pour une fiche et rend la liste à jour. @return int[] */
    private static function enregistrer_infolettre(int $id, bool $dans): array {
        $hors = self::regler_infolettre(self::lire_hors_infolettre(), $id, $dans);
        update_option(self::OPTION_HORS_INFOLETTRE, $hors, false);
        return $hors;
    }

    /**
     * Noms des sources actives dont la dernière collecte porte une alerte de gabarit, dans l'ordre du registre.
     *
     * @param array<string,string> $alertes `Journal::alertes()`
     * @param array<string,array<string,mixed>> $sources Sources actives.
     * @return string[]
     */
    public static function sources_a_verifier(array $alertes, array $sources): array {
        $noms = [];
        foreach ($sources as $id => $source) {
            if (isset($alertes[$id]) && is_array($source)) {
                $noms[] = (string) ($source['nom'] ?? $id);
            }
        }
        return $noms;
    }

    /**
     * Fiches à charger pour un onglet (la page les filtre et les range ensuite, `filtrer`) : « À approuver » et
     * « À vérifier » en entier ; « Publiées », les fiches publiées encore d'actualité ; « Archivées », les `PLAFOND`
     * fiches publiées les plus récentes (seules les dépassées seront gardées) et les fiches en attente ;
     * « Écartées », les `PLAFOND` plus récentes. Rend [fiches, plafond atteint].
     *
     * @return array{0: Fiche[], 1: bool}
     */
    public static function fiches_de_l_onglet(Entrepot $e, string $onglet, string $type, ?string $aujourdhui = null, ?array $actuelles = null, ?array $attente = null): array {
        $aujourdhui ??= rsn_veille_aujourdhui();
        $du_type = static fn(array $fiches): array => array_values(array_filter($fiches, static fn(Fiche $f): bool => $type === '' || $f->type === $type));
        // Fiches à approuver : celles que la page a déjà lues (tous types), sinon lues ici.
        $en_attente = static fn(): array => $attente !== null ? $du_type($attente) : $e->lister('a_approuver', $type);
        if ($onglet === 'a_verifier') {
            return [$e->lister_absentes(), false];
        }
        if ($onglet === 'publie') {
            return [$du_type($actuelles ?? self::publiees_d_actualite($e, $aujourdhui)), false];
        }
        if ($onglet === 'archive' || $onglet === 'ecarte') {
            // Une de plus que le plafond : savoir s'il est atteint sans compter à part.
            $fiches = $e->lister_recentes($onglet === 'archive' ? 'publie' : 'ecarte', self::PLAFOND + 1, $type);
            $atteint = count($fiches) > self::PLAFOND;
            $fiches = array_slice($fiches, 0, self::PLAFOND);
            return [$onglet === 'archive' ? array_merge($fiches, $en_attente()) : $fiches, $atteint];
        }
        return [$en_attente(), false];
    }

    /**
     * Action AJAX : jeton `rsn_veille_file` (champ `nonce`) et capacité `rsn_veille_gerer` exigés.
     * Réponse `{success, data: {ok, statut, variations}}`, ou `{success: false, data: {ok, message}}`
     * en 403 (jeton ou droits) ou 400 (statut ou fiche invalides).
     */
    public static function ajax_changer_statut(): void {
        if (check_ajax_referer(self::NONCE, 'nonce', false) === false) {
            wp_send_json_error(['ok' => false, 'message' => __('Jeton de sécurité expiré : rechargez la page.', 'rsn-veille')], 403);
            return;
        }
        if (!current_user_can(Menu::CAPACITE)) {
            wp_send_json_error(['ok' => false, 'message' => __("Vous n'avez pas le droit de modifier la veille.", 'rsn-veille')], 403);
            return;
        }
        $id = isset($_POST['id']) && is_scalar($_POST['id']) ? absint(wp_unslash((string) $_POST['id'])) : 0;
        $statut = isset($_POST['statut']) && is_string($_POST['statut']) ? sanitize_key(wp_unslash($_POST['statut'])) : '';
        $resultat = self::changer_statut(new EntrepotWordPress(), $id, $statut);
        if ($resultat['ok']) {
            // « Publier seulement » (infolettre = 0) ou « Publier + infolettre » (1) : le choix suit la publication.
            $infolettre = isset($_POST['infolettre']) && is_string($_POST['infolettre']) ? sanitize_key(wp_unslash($_POST['infolettre'])) : '';
            if ($statut === 'publie' && ($infolettre === '0' || $infolettre === '1')) {
                self::enregistrer_infolettre($id, $infolettre === '1');
            }
            wp_send_json_success($resultat);
            return;
        }
        wp_send_json_error($resultat, 400);
    }

    /**
     * Action AJAX : met une fiche dans l'infolettre du RSN (`dans = 1`) ou l'en retire (`dans = 0`). Mêmes jeton et
     * capacité que le changement de statut. Réponse `{success, data: {ok, dans, html}}` : `html` est le contenu à
     * jour de la cellule « Infolettre du RSN » de la ligne.
     */
    public static function ajax_infolettre(): void {
        if (check_ajax_referer(self::NONCE, 'nonce', false) === false) {
            wp_send_json_error(['ok' => false, 'message' => __('Jeton de sécurité expiré : rechargez la page.', 'rsn-veille')], 403);
            return;
        }
        if (!current_user_can(Menu::CAPACITE)) {
            wp_send_json_error(['ok' => false, 'message' => __("Vous n'avez pas le droit de modifier la veille.", 'rsn-veille')], 403);
            return;
        }
        $id = isset($_POST['id']) && is_scalar($_POST['id']) ? absint(wp_unslash((string) $_POST['id'])) : 0;
        $dans = isset($_POST['dans']) && is_string($_POST['dans']) ? sanitize_key(wp_unslash($_POST['dans'])) : '';
        if ($id <= 0 || ($dans !== '0' && $dans !== '1') || (new EntrepotWordPress())->obtenir($id) === null) {
            wp_send_json_error(['ok' => false, 'message' => __('Fiche introuvable.', 'rsn-veille')], 400);
            return;
        }
        $hors = self::enregistrer_infolettre($id, $dans === '1');
        wp_send_json_success(['ok' => true, 'dans' => $dans === '1', 'html' => self::infolettre_html(!in_array($id, $hors, true))]);
    }

    // --- Logique ---

    /**
     * Change le statut d'une fiche à la main (`manuel = true`). Publier remet aussi les absences à zéro
     * et lève le drapeau « absent de la source ». Purge ensuite les caches de la page Veille.
     *
     * La réponse donne les `variations` des nombres des onglets (`variations()`), pas un recomptage : une action ne
     * relit jamais la file.
     *
     * @return array{ok: bool, statut?: string, variations?: array<string,int>, message?: string}
     */
    public static function changer_statut(Entrepot $e, int $id, string $statut): array {
        if (!in_array($statut, self::STATUTS, true)) {
            return ['ok' => false, 'message' => __('Statut inconnu.', 'rsn-veille')];
        }
        if ($id <= 0) {
            return ['ok' => false, 'message' => __('Fiche introuvable.', 'rsn-veille')];
        }
        $champs = ['statut' => $statut, 'manuel' => true];
        if ($statut === 'publie') {
            $champs += ['absences' => 0, 'absent_depuis' => null];
        }
        $aujourdhui = rsn_veille_aujourdhui();
        $avant = $e->obtenir($id);
        try {
            $e->mettre_a_jour($id, $champs);
        } catch (\InvalidArgumentException $erreur) {
            // L'entrepôt refuse un identifiant qui n'est pas une fiche de veille.
            return ['ok' => false, 'message' => __('Fiche introuvable.', 'rsn-veille')];
        } catch (\RuntimeException $erreur) {
            return ['ok' => false, 'message' => __("Le changement de statut n'a pas pu être enregistré.", 'rsn-veille')];
        }
        $apres = $e->obtenir($id);
        if ($statut === 'ecarte' && $apres !== null && $apres->est_proposition()) {
            self::retirer_image_proposee($e, $id);
        }
        Collecte::purger_caches();
        return ['ok' => true, 'statut' => $statut, 'variations' => self::variations($avant, $apres, $aujourdhui)];
    }

    /**
     * Proposition du public écartée : son image (téléversée par un anonyme, publique à son adresse) est
     * supprimée de la médiathèque, fichier compris, et la fiche n'a plus d'image. Seulement une pièce
     * téléversée par le formulaire (méta repère) et rattachée à cette fiche : une image de la médiathèque
     * choisie par l'équipe comme vignette n'est jamais supprimée.
     */
    private static function retirer_image_proposee(Entrepot $e, int $id): void {
        $piece = (int) get_post_thumbnail_id($id);
        $post = $piece > 0 ? get_post($piece) : null;
        if ($post === null || (int) $post->post_parent !== $id || !get_post_meta($piece, Formulaire::META_IMAGE_PROPOSEE, true)) {
            return;
        }
        wp_delete_attachment($piece, true);
        try {
            $e->mettre_a_jour($id, ['image_url' => '']);
        } catch (\RuntimeException $erreur) {
            // L'image est supprimée ; l'adresse restée dans la fiche ne mène plus à rien.
        }
    }

    /**
     * Fiches d'un onglet, d'un type et d'une source (vides : tous), rangées par la date qui compte
     * (`Fiche::date_utile` : début d'un événement, date limite d'un appel, jamais la date de publication).
     * « À approuver » et « Publiées » : de la plus proche à la plus lointaine. « Archivées » et « Écartées » : de la
     * plus récente à la plus ancienne. Sans date : à la fin. « À vérifier » : les dernières arrivées d'abord (jour
     * `cree_le` décroissant, sans jour en dernier, puis `id` décroissant).
     *
     * @param Fiche[] $fiches
     * @param ?string $aujourdhui Jour courant (Y-m-d) : une fiche dépassée va dans « Archivées ».
     * @return Fiche[]
     */
    public static function filtrer(array $fiches, string $statut, string $type, string $source, string $origine = '', ?string $aujourdhui = null): array {
        $aujourdhui ??= rsn_veille_aujourdhui();
        $origine = in_array($origine, self::ORIGINES, true) ? $origine : '';
        $retenues = array_values(array_filter($fiches, static fn(Fiche $f): bool => self::dans_onglet($f, $statut, $aujourdhui)
            && ($type === '' || $f->type === $type)
            && ($source === '' || $f->source === $source)
            && ($origine === '' || $f->origine() === $origine)));
        if ($statut === 'a_verifier') {
            usort($retenues, static function (Fiche $a, Fiche $b): int {
                $ja = $a->cree_le ?? '';
                $jb = $b->cree_le ?? '';
                if ($ja === '' || $jb === '') {
                    return ($ja === '') <=> ($jb === '') ?: (int) $b->id <=> (int) $a->id;
                }
                return strcmp($jb, $ja) ?: (int) $b->id <=> (int) $a->id;
            });
            return $retenues;
        }
        $sens = $statut === 'ecarte' || $statut === 'archive' ? -1 : 1;
        usort($retenues, static function (Fiche $a, Fiche $b) use ($sens): int {
            $da = $a->date_utile();
            $db = $b->date_utile();
            if ($da === '' || $db === '') {
                return ($da === '') <=> ($db === '') ?: (int) $a->id <=> (int) $b->id;
            }
            return $sens * strcmp($da, $db) ?: (int) $a->id <=> (int) $b->id;
        });
        return $retenues;
    }

    /**
     * Fiche arrivée aujourd'hui ou hier (jour `cree_le`) : repère « Nouveau » de la file.
     *
     * @param string $aujourdhui Jour courant, `AAAA-MM-JJ`.
     */
    public static function est_nouvelle(Fiche $f, string $aujourdhui): bool {
        if ($f->cree_le === null || $f->cree_le === '') {
            return false;
        }
        $hier = (new \DateTimeImmutable($aujourdhui . ' 12:00:00', new \DateTimeZone('UTC')))->modify('-1 day')->format('Y-m-d');
        return $f->cree_le >= $hier;
    }

    /** Onglets où les fiches attendent une décision : triés par arrivée, avec le repère « Nouveau ». */
    private static function est_onglet_d_arrivee(string $onglet): bool {
        return $onglet === 'a_approuver' || $onglet === 'a_verifier';
    }

    /**
     * Nombre de fiches par onglet.
     *
     * @param Fiche[] $fiches
     * @return array<string,int>
     */
    public static function compteurs(array $fiches, ?string $aujourdhui = null): array {
        $aujourdhui ??= rsn_veille_aujourdhui();
        $compteurs = array_fill_keys(self::ONGLETS, 0);
        foreach ($fiches as $fiche) {
            foreach (self::ONGLETS as $onglet) {
                if (self::dans_onglet($fiche, $onglet, $aujourdhui)) {
                    $compteurs[$onglet]++;
                }
            }
        }
        return $compteurs;
    }

    /**
     * Sources distinctes des fiches, triées.
     *
     * @param Fiche[] $fiches
     * @return string[]
     */
    public static function sources_distinctes(array $fiches): array {
        $sources = array_unique(array_filter(array_map(static fn(Fiche $f): string => $f->source, $fiches), static fn(string $s): bool => $s !== ''));
        sort($sources, SORT_NATURAL | SORT_FLAG_CASE);
        return array_values($sources);
    }

    // --- Rendu ---

    /**
     * Page complète : titre et bouton « Ajouter une fiche », onglets, filtres, zone de message et tableau.
     *
     * @param Fiche[] $fiches Fiches chargées (celles de l'onglet, ou toutes).
     * @param array{url_admin?: string, compteurs?: array<string,int>, plafond_atteint?: bool, peut_ajouter?: bool, sources_a_verifier?: string[], url_journal?: string} $options
     *        `compteurs` : nombres par onglet de l'entrepôt (`Entrepot::compter`), sinon comptés sur $fiches ;
     *        `plafond_atteint` : l'onglet ne montre que les `PLAFOND` fiches les plus récentes ;
     *        `peut_ajouter` : bouton « Ajouter une fiche » (vrai par défaut) ;
     *        `sources_a_verifier`, `url_journal` : rappel des sources dont le gabarit a peut-être changé ;
     *        `ressemblances` (fiche ressemblante par identifiant, `Ressemblance::pour_la_file`) et `mentions`
     *        (`Collecte::mentions`) : étiquettes « Peut-être déjà dans l'agenda » et « Annoncé dans n numéros ».
     */
    public static function page_html(array $fiches, string $onglet, string $type, string $source, string $url_page, array $options): string {
        $aujourdhui = is_string($options['aujourdhui'] ?? null) && $options['aujourdhui'] !== '' ? $options['aujourdhui'] : rsn_veille_aujourdhui();
        $options['aujourdhui'] = $aujourdhui;
        $compteurs = is_array($options['compteurs'] ?? null) ? $options['compteurs'] : self::compteurs($fiches, $aujourdhui);
        $mention = !empty($options['plafond_atteint'])
            ? '<p class="description rsn-veille-plafond">' . esc_html(sprintf(
                /* translators: %d : nombre de fiches affichées au plus dans l'onglet. */
                $onglet === 'archive' ? __('Seules les fiches dépassées parmi les %d dernières publiées sont affichées.', 'rsn-veille') : __('Les %d plus récentes sont affichées.', 'rsn-veille'),
                self::PLAFOND
            )) . '</p>'
            : '';
        if ($onglet === 'archive') {
            $mention = '<p class="description rsn-veille-intro-archive">' . esc_html__("Événements passés et appels dont la date limite est dépassée. Ils quittent seuls « À approuver » et « Publiées » ; rien n'est supprimé.", 'rsn-veille') . '</p>' . $mention;
        }
        // Bouton « Ajouter une fiche » à côté du titre, comme « Ajouter » des listes de WordPress.
        $ajouter = ($options['peut_ajouter'] ?? true)
            ? ' <a href="' . esc_url(admin_url('post-new.php?post_type=' . TypeContenu::TYPE)) . '" class="page-title-action">' . esc_html__('Ajouter une fiche', 'rsn-veille') . '</a>'
            : '';
        $origine = in_array($options['origine'] ?? '', self::ORIGINES, true) ? (string) $options['origine'] : '';
        return '<div class="wrap rsn-veille-admin">'
            . '<h1 class="wp-heading-inline">' . esc_html__("File d'approbation", 'rsn-veille') . '</h1>'
            . $ajouter . '<hr class="wp-header-end">'
            . self::onglets_html($onglet, $compteurs, $type, $source, $url_page, $origine)
            . self::filtres_html($onglet, $type, $source, self::sources_distinctes($fiches), $origine)
            . '<div class="rsn-veille-message" role="status" aria-live="polite"></div>'
            . $mention
            . self::rappel_propositions_html($fiches, $onglet, $type, $source, $origine, $url_page, $aujourdhui)
            . self::rappel_gabarit_html($onglet, is_array($options['sources_a_verifier'] ?? null) ? $options['sources_a_verifier'] : [], (string) ($options['url_journal'] ?? ''))
            . self::tableau_html(self::filtrer($fiches, $onglet, $type, $source, $origine, $aujourdhui), $onglet, $options)
            . self::legende_html()
            . '</div>';
    }

    /**
     * Une ligne du tableau : titre (lien Modifier, lien d'information, drapeau d'absence), type (pastille), dates,
     * organisateur, source et niveau, extrait, puis, dans « Publiées », si la fiche va dans l'infolettre du RSN, et
     * enfin les boutons selon le statut.
     *
     * @param array{url_admin?: string, onglet?: string, aujourdhui?: string, hors_infolettre?: int[]} $options
     *        `url_admin` : adresse de wp-admin, terminée par « / » ; `onglet` : onglet affiché (le repère « Nouveau »
     *        n'apparaît que dans « À approuver » et « À vérifier », la colonne de l'infolettre que dans « Publiées ») ;
     *        `hors_infolettre` : fiches que l'équipe ne veut pas dans l'infolettre.
     */
    public static function ligne_html(Fiche $f, array $options): string {
        $id = (int) $f->id;
        $url_admin = (string) ($options['url_admin'] ?? '');
        $onglet = (string) ($options['onglet'] ?? '');
        $aujourdhui = is_string($options['aujourdhui'] ?? null) && $options['aujourdhui'] !== '' ? $options['aujourdhui'] : rsn_veille_aujourdhui();
        // Fiche ressemblante (déjà publiée, ou autre fiche en attente) : seulement dans la file à approuver.
        $pareille = $onglet === 'a_approuver' && is_array($options['ressemblances'] ?? null) ? ($options['ressemblances'][$id] ?? null) : null;
        $pareille = $pareille instanceof Fiche ? $pareille : null;
        $hors = is_array($options['hors_infolettre'] ?? null) ? $options['hors_infolettre'] : [];
        return '<tr id="rsn-veille-fiche-' . $id . '" data-id="' . $id . '" data-statut="' . esc_attr($f->statut) . '" data-origine="' . esc_attr($f->origine()) . '"'
            . ($pareille !== null ? ' data-ressemble="' . ($pareille->statut === 'publie' ? 'publie' : 'a_approuver') . '"' : '') . '>'
            . '<td class="rsn-veille-titre">' . self::titre_html($f, $url_admin, self::est_onglet_d_arrivee($onglet) && self::est_nouvelle($f, $aujourdhui))
            . ($pareille !== null ? self::ressemblance_html($pareille, $url_admin, in_array((int) $pareille->id, is_array($options['affichees'] ?? null) ? $options['affichees'] : [], true)) : '') . '</td>'
            . '<td>' . self::type_html($f->type) . ($onglet === 'archive' && $f->statut === 'a_approuver' ? '<br><span class="rsn-veille-jamais-publiee">' . esc_html__('Jamais publiée', 'rsn-veille') . '</span>' : '') . '</td>'
            . '<td>' . self::dates_html($f) . '</td>'
            . '<td>' . esc_html($f->organisateur) . '</td>'
            . '<td>' . self::source_html($f, is_array($options['courriels'] ?? null) ? (string) ($options['courriels'][$id] ?? '') : '')
            . self::mentions_html($f, is_array($options['mentions'] ?? null) ? $options['mentions'] : []) . '</td>'
            . '<td class="rsn-veille-extrait">' . esc_html(Fiche::court($f->resume, self::LONGUEUR_EXTRAIT)) . '</td>'
            . ($onglet === 'publie' ? '<td class="rsn-veille-infolettre-cellule">' . self::infolettre_html(!in_array($id, $hors, true)) . '</td>' : '')
            . '<td class="rsn-veille-actions">' . self::boutons_html($f, $f->est_passee($aujourdhui)) . '</td>'
            . '</tr>';
    }

    /** @param Fiche[] $fiches Fiches déjà filtrées et triées. */
    private static function tableau_html(array $fiches, string $onglet, array $options): string {
        $colonnes = [
            __('Titre', 'rsn-veille'), __('Type', 'rsn-veille'), __('Dates', 'rsn-veille'), __('Organisateur', 'rsn-veille'),
            __('Source', 'rsn-veille'), __('Extrait', 'rsn-veille'),
        ];
        if ($onglet === 'publie') {
            // « du RSN » : une source peut elle-même être une infolettre (celle de l'Unité SSA).
            $colonnes[] = __('Infolettre du RSN', 'rsn-veille');
        }
        $colonnes[] = __('Actions', 'rsn-veille');
        $entete = '';
        foreach ($colonnes as $colonne) {
            $entete .= '<th scope="col">' . esc_html($colonne) . '</th>';
        }
        $lignes = '';
        $affichees = array_map(static fn(Fiche $f): int => (int) $f->id, $fiches);
        foreach ($fiches as $fiche) {
            $lignes .= self::ligne_html($fiche, ['onglet' => $onglet, 'affichees' => $affichees] + $options);
        }
        if ($lignes === '') {
            $lignes = '<tr class="rsn-veille-vide"><td colspan="' . count($colonnes) . '">' . esc_html__('Aucune fiche.', 'rsn-veille') . '</td></tr>';
        }
        return '<table class="wp-list-table widefat striped rsn-veille-file" data-onglet="' . esc_attr($onglet) . '">'
            . '<thead><tr>' . $entete . '</tr></thead><tbody>' . $lignes . '</tbody></table>';
    }

    /** @param array<string,int> $compteurs */
    private static function onglets_html(string $actif, array $compteurs, string $type, string $source, string $url_page, string $origine = ''): string {
        $libelles = [
            'a_approuver' => __('À approuver', 'rsn-veille'),
            'publie' => __('Publiées', 'rsn-veille'),
            'archive' => __('Archivées', 'rsn-veille'),
            'ecarte' => __('Écartées', 'rsn-veille'),
            'a_verifier' => __('À vérifier', 'rsn-veille'),
        ];
        $html = '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__('Statut des fiches', 'rsn-veille') . '">';
        foreach (self::ONGLETS as $onglet) {
            $url = $url_page . '&' . http_build_query(array_filter(['statut' => $onglet, 'type' => $type, 'source' => $source, 'origine' => $origine], static fn(string $v): bool => $v !== ''), '', '&', PHP_QUERY_RFC3986);
            $classe = 'nav-tab' . ($onglet === $actif ? ' nav-tab-active' : '');
            $courant = $onglet === $actif ? ' aria-current="page"' : '';
            $html .= '<a class="' . $classe . '"' . $courant . ' href="' . esc_url($url) . '">' . esc_html($libelles[$onglet])
                . ' <span class="count" data-compteur="' . esc_attr($onglet) . '">(' . (int) ($compteurs[$onglet] ?? 0) . ')</span></a>';
        }
        return $html . '</nav>';
    }

    /** @param string[] $sources */
    private static function filtres_html(string $onglet, string $type, string $source, array $sources, string $origine = ''): string {
        $types = ['' => __('Tous les types', 'rsn-veille'), 'evenement' => __('Événements', 'rsn-veille'), 'appel' => __('Appels', 'rsn-veille')];
        $origines = ['' => __('Toutes les origines', 'rsn-veille')] + self::libelles_origines();
        $choix_sources = ['' => __('Toutes les sources', 'rsn-veille')];
        foreach ($sources as $nom) {
            $choix_sources[$nom] = $nom;
        }
        return '<form class="rsn-veille-filtres" method="get" action="">'
            . '<input type="hidden" name="page" value="' . esc_attr(Menu::SLUG) . '">'
            . '<input type="hidden" name="statut" value="' . esc_attr($onglet) . '">'
            . '<label class="screen-reader-text" for="rsn-veille-type">' . esc_html__('Type', 'rsn-veille') . '</label>'
            . self::liste_html('rsn-veille-type', 'type', $types, $type)
            . '<label class="screen-reader-text" for="rsn-veille-source">' . esc_html__('Source', 'rsn-veille') . '</label>'
            . self::liste_html('rsn-veille-source', 'source', $choix_sources, $source)
            . '<label class="screen-reader-text" for="rsn-veille-origine">' . esc_html__('Origine', 'rsn-veille') . '</label>'
            . self::liste_html('rsn-veille-origine', 'origine', $origines, $origine)
            . '<button type="submit" class="button">' . esc_html__('Filtrer', 'rsn-veille') . '</button>'
            . '</form>';
    }

    /** @param array<string,string> $choix valeur => libellé */
    private static function liste_html(string $id, string $nom, array $choix, string $choisi): string {
        $html = '<select id="' . esc_attr($id) . '" name="' . esc_attr($nom) . '">';
        foreach ($choix as $valeur => $libelle) {
            $valeur = (string) $valeur;
            $html .= '<option value="' . esc_attr($valeur) . '"' . ($valeur === $choisi ? ' selected' : '') . '>' . esc_html($libelle) . '</option>';
        }
        return $html . '</select>';
    }

    private static function titre_html(Fiche $f, string $url_admin, bool $nouvelle): string {
        $titre = $f->titre !== '' ? $f->titre : __('(sans titre)', 'rsn-veille');
        $liens = [];
        if ($url_admin !== '') {
            $liens[] = '<a href="' . esc_url($url_admin . 'post.php?post=' . (int) $f->id . '&action=edit') . '">' . esc_html__('Modifier', 'rsn-veille') . '</a>';
        }
        $info = esc_url($f->url);
        if ($info !== '') {
            $liens[] = '<a href="' . $info . '" target="_blank" rel="noopener noreferrer">' . esc_html__("Page d'origine", 'rsn-veille') . '</a>';
        }
        $html = '<strong>' . esc_html($titre) . '</strong>';
        if ($nouvelle) {
            $html .= ' <span class="rsn-veille-nouveau">' . esc_html__('Nouveau', 'rsn-veille') . '</span>';
        }
        if ($f->absent_depuis !== null && $f->absent_depuis !== '') {
            $depuis = Dates::date_longue($f->absent_depuis);
            /* translators: %s : date à laquelle la fiche n'a plus été trouvée à sa source. */
            $html .= '<p class="rsn-veille-absent">' . esc_html(sprintf(__('Absent de la source depuis le %s', 'rsn-veille'), $depuis !== '' ? $depuis : $f->absent_depuis)) . '</p>';
        }
        if ($liens !== []) {
            $html .= '<div class="rsn-veille-liens">' . implode(' | ', $liens) . '</div>';
        }
        return $html;
    }

    /**
     * Sous le titre : la fiche qui annonce sans doute la même chose (`Ressemblance`), avec l'étiquette orange du Radar.
     * Publiée : « Peut-être déjà dans l'agenda », lien vers sa page de modification. En attente : lien vers sa ligne
     * (`$affichee` : elle est dans le tableau).
     */
    private static function ressemblance_html(Fiche $pareille, string $url_admin, bool $affichee): string {
        $publiee = $pareille->statut === 'publie';
        $libelle = $publiee ? __("Peut-être déjà dans l'agenda", 'rsn-veille') : __('Ressemble à une autre fiche en attente', 'rsn-veille');
        $titre = esc_html($pareille->titre !== '' ? $pareille->titre : __('(sans titre)', 'rsn-veille'));
        if (!$publiee) {
            // Le lien mène à sa ligne, si elle est dans le tableau (un filtre peut la cacher).
            $titre = $affichee ? '<a href="#rsn-veille-fiche-' . (int) $pareille->id . '">' . $titre . '</a>' : $titre;
        } elseif ($url_admin !== '') {
            $titre = '<a href="' . esc_url($url_admin . 'post.php?post=' . (int) $pareille->id . '&action=edit') . '">' . $titre . '</a>';
        }
        $quand = $pareille->type === 'appel' ? Dates::date_longue($pareille->echeance) : Dates::periode($pareille->debut, $pareille->fin);
        return '<p class="rsn-veille-ressemble"><span class="rsn-veille-deja-paru">' . esc_html($libelle) . '</span> '
            . $titre . ($quand !== '' ? ' <span class="rsn-veille-ressemble-date">' . esc_html($quand) . '</span>' : '') . '</p>';
    }

    /**
     * Événement tiré de l'agenda d'une infolettre : « Annoncé dans n numéros » quand plusieurs numéros l'annoncent.
     *
     * @param array<string,mixed> $mentions Nombre de numéros par clé de source (`Collecte::mentions`).
     */
    private static function mentions_html(Fiche $f, array $mentions): string {
        $nombre = 0;
        foreach ($f->cles_source as $cle) {
            $nombre = max($nombre, is_int($mentions[$cle] ?? null) ? $mentions[$cle] : 0);
        }
        if ($nombre < 2) {
            return '';
        }
        /* translators: %d : nombre de numéros de l'infolettre qui annoncent l'événement. */
        return '<br><span class="rsn-veille-mentions" title="' . esc_attr__("Nombre de numéros de l'infolettre qui annoncent cet événement.", 'rsn-veille') . '">'
            . esc_html(sprintf(_n('Annoncé dans %d numéro', 'Annoncé dans %d numéros', $nombre, 'rsn-veille'), $nombre)) . '</span>';
    }

    private static function dates_html(Fiche $f): string {
        $html = esc_html(Dates::periode($f->debut, $f->fin, $f->heure));
        $limite = Dates::date_longue($f->echeance);
        if ($limite !== '') {
            /* translators: %s : date limite d'un appel. */
            $html .= ($html !== '' ? '<br>' : '') . esc_html(sprintf(__('Date limite : %s', 'rsn-veille'), $limite));
        }
        return $html;
    }

    /** @return array<string,string> Origines d'une fiche et leur libellé, dans l'ordre du filtre. */
    private static function libelles_origines(): array {
        return [
            'proposition' => __('Propositions du public', 'rsn-veille'),
            'collecte' => __('Collecte automatique', 'rsn-veille'),
            'equipe' => __("Saisie par l'équipe", 'rsn-veille'),
        ];
    }

    /**
     * Niveaux d'une source : à quel point ce qu'elle donne est sûr. [nom court, explication].
     *
     * @return array<string,array{0: string, 1: string}>
     */
    private static function niveaux(): array {
        return [
            'A' => [__('flux structuré', 'rsn-veille'), __("Calendrier ou flux d'événements : dates, heures et lieux sont donnés tels quels par l'organisme. Le plus sûr.", 'rsn-veille')],
            'C' => [__('page web lue', 'rsn-veille'), __("Page du site de l'organisme, lue par l'extension : sûre tant que le site garde la même présentation.", 'rsn-veille')],
            'M' => [__('saisie à la main', 'rsn-veille'), __("Fiche ajoutée par l'équipe ou proposée par le public : ce qu'une personne a écrit.", 'rsn-veille')],
            'B' => [__('flux RSS ou infolettre', 'rsn-veille'), __("Fil de nouvelles ou infolettre : le titre et le lien sont sûrs, les dates sont devinées dans le texte. À relire.", 'rsn-veille')],
        ];
    }

    /** Légende des niveaux, repliée sous le tableau, du plus sûr au moins sûr. */
    private static function legende_html(): string {
        $items = '';
        foreach (self::niveaux() as $lettre => [$nom, $explication]) {
            /* translators: 1 : lettre du niveau (A, B, C, M) ; 2 : son nom court. */
            $items .= '<li><strong>' . esc_html(sprintf(__('Niveau %1$s, %2$s', 'rsn-veille'), $lettre, $nom)) . '</strong> : ' . esc_html($explication) . '</li>';
        }
        return '<details class="rsn-veille-deroule rsn-veille-legende"><summary>' . esc_html__('Que veulent dire les niveaux ?', 'rsn-veille') . '</summary>'
            . '<p>' . esc_html__("Le niveau dit d'où vient la fiche et à quel point ses dates sont sûres. Quand deux sources annoncent la même chose, la plus sûre l'emporte.", 'rsn-veille') . '</p>'
            . '<ul>' . $items . '</ul></details>';
    }

    /** Type de la fiche en pastille de couleur, avec son icône : un événement et un appel se distinguent d'un coup d'œil. */
    private static function type_html(string $type): string {
        $icone = ['evenement' => 'dashicons-calendar-alt', 'appel' => 'dashicons-megaphone'][$type] ?? '';
        return '<span class="rsn-veille-type" data-type="' . esc_attr($type) . '">'
            . ($icone !== '' ? '<span class="dashicons ' . $icone . '" aria-hidden="true"></span> ' : '')
            . esc_html(self::libelle_type($type)) . '</span>';
    }

    /**
     * Cellule « Infolettre du RSN » d'une fiche publiée : pastille (dedans ou non) et bouton pour changer. « Dedans »
     * veut dire reprise par la page Infolettre tant qu'elle est d'actualité.
     */
    public static function infolettre_html(bool $dans): string {
        $pastille = $dans ? __("Dans l'infolettre", 'rsn-veille') : __("Pas dans l'infolettre", 'rsn-veille');
        $bouton = $dans ? __("Retirer de l'infolettre", 'rsn-veille') : __("Mettre dans l'infolettre", 'rsn-veille');
        return '<span class="rsn-veille-pastille-infolettre" data-dans="' . ($dans ? '1' : '0') . '" title="' . esc_attr__("La page Infolettre reprend les prochains événements publiés (8 par défaut) et les appels encore ouverts.", 'rsn-veille') . '">'
            . esc_html($pastille) . '</span><br>'
            . '<button type="button" class="button-link rsn-veille-action-infolettre" data-dans="' . ($dans ? '0' : '1') . '">' . esc_html($bouton) . '</button>';
    }

    /**
     * Origine (pastille : proposition du public, collecte automatique ou saisie par l'équipe), puis la source et son
     * niveau en clair. Une proposition montre « Formulaire de la page » et le courriel de la personne (lien pour lui répondre).
     */
    private static function source_html(Fiche $f, string $courriel = ''): string {
        $origine = $f->origine();
        $pastilles = [
            'proposition' => __('Proposition du public', 'rsn-veille'),
            'collecte' => __('Collecte automatique', 'rsn-veille'),
            'equipe' => __("Saisie par l'équipe", 'rsn-veille'),
        ];
        $html = '<span class="rsn-veille-origine" data-origine="' . esc_attr($origine) . '">'
            . ($origine === 'proposition' ? '<span class="dashicons dashicons-admin-users" aria-hidden="true"></span> ' : '')
            . esc_html($pastilles[$origine]) . '</span><br>';
        if ($origine === 'proposition') {
            $html .= esc_html__('Formulaire de la page', 'rsn-veille');
            if ($courriel !== '' && is_email($courriel)) {
                $html .= '<br><a href="' . esc_url('mailto:' . $courriel) . '">' . esc_html($courriel) . '</a>';
            }
            return $html;
        }
        $html .= esc_html($f->source);
        if ($f->niveau !== '') {
            $niveau = self::niveaux()[$f->niveau] ?? null;
            /* translators: 1 : lettre du niveau ; 2 : son nom court (« flux structuré »…). */
            $texte = $niveau !== null ? sprintf(__('niveau %1$s : %2$s', 'rsn-veille'), $f->niveau, $niveau[0]) : sprintf(__('niveau %s', 'rsn-veille'), $f->niveau);
            $html .= '<br><span class="rsn-veille-niveau"' . ($niveau !== null ? ' title="' . esc_attr($niveau[1]) . '"' : '') . '>' . esc_html($texte) . '</span>';
        }
        return $html;
    }

    /**
     * Rappel au-dessus de la file « À approuver » : le nombre de propositions du public en attente, avec un lien qui
     * ne montre qu'elles. Rien s'il n'y en a pas, ni quand on les regarde déjà.
     *
     * @param Fiche[] $fiches
     */
    private static function rappel_propositions_html(array $fiches, string $onglet, string $type, string $source, string $origine, string $url_page, ?string $aujourdhui = null): string {
        if ($onglet !== 'a_approuver' || $origine === 'proposition') {
            return '';
        }
        $nombre = count(self::filtrer($fiches, $onglet, $type, $source, 'proposition', $aujourdhui));
        if ($nombre === 0) {
            return '';
        }
        $url = $url_page . '&' . http_build_query(['statut' => $onglet, 'origine' => 'proposition'], '', '&', PHP_QUERY_RFC3986);
        /* translators: %d : nombre de propositions du public en attente. */
        $texte = sprintf(_n('%d proposition du public à traiter', '%d propositions du public à traiter', $nombre, 'rsn-veille'), $nombre);
        return '<p class="rsn-veille-rappel-propositions"><span class="dashicons dashicons-admin-users" aria-hidden="true"></span> '
            . '<a href="' . esc_url($url) . '">' . esc_html($texte) . '</a></p>';
    }

    /**
     * Rappel, dans la file à approuver, des sources dont la dernière collecte rompt avec l'habitude (`Journal::alertes`) :
     * leur page a peut-être changé de gabarit, et ce qu'elles annoncent n'arrive plus dans la file.
     *
     * @param array<int,mixed> $sources Noms des sources à vérifier.
     */
    private static function rappel_gabarit_html(string $onglet, array $sources, string $url_journal): string {
        $sources = array_values(array_filter($sources, static fn($s): bool => is_string($s) && $s !== ''));
        if ($onglet !== 'a_approuver' || $sources === []) {
            return '';
        }
        /* translators: 1 : nombre de sources ; 2 : leurs noms, séparés par des virgules. */
        $texte = sprintf(_n('%1$d source à vérifier (gabarit peut-être changé) : %2$s', '%1$d sources à vérifier (gabarit peut-être changé) : %2$s', count($sources), 'rsn-veille'), count($sources), implode(', ', $sources));
        return '<p class="rsn-veille-rappel-gabarit"><span class="dashicons dashicons-warning" aria-hidden="true"></span> ' . esc_html($texte)
            . ($url_journal !== '' ? ' <a href="' . esc_url($url_journal) . '">' . esc_html__('Voir le journal', 'rsn-veille') . '</a>' : '')
            . '</p>';
    }

    /**
     * Boutons selon le statut. À approuver : « Publier + infolettre » (sur la page et dans l'infolettre du RSN),
     * « Publier seulement » (sur la page, pas dans l'infolettre) ou « Écarter » ; une fiche en attente déjà dépassée
     * ne peut plus qu'être écartée. Une fiche publiée absente de sa source peut aussi être gardée (drapeau levé).
     */
    private static function boutons_html(Fiche $f, bool $passee = false): string {
        $boutons = match (true) {
            $f->statut === 'a_approuver' && $passee => [['ecarte', __('Écarter', 'rsn-veille'), false, '']],
            $f->statut === 'a_approuver' => [
                ['publie', __('Publier + infolettre', 'rsn-veille'), true, '1'],
                ['publie', __('Publier seulement', 'rsn-veille'), false, '0'],
                ['ecarte', __('Écarter', 'rsn-veille'), false, ''],
            ],
            $f->statut === 'publie' => [['ecarte', __('Retirer de la page', 'rsn-veille'), false, '']],
            default => [['publie', __('Publier', 'rsn-veille'), true, '']],
        };
        if ($f->statut === 'publie' && $f->absent_depuis !== null && $f->absent_depuis !== '') {
            array_unshift($boutons, ['publie', __('Garder publiée', 'rsn-veille'), false, '']);
        }
        $html = '';
        foreach ($boutons as [$statut, $libelle, $principal, $infolettre]) {
            $html .= '<button type="button" class="button' . ($principal ? ' button-primary' : '') . ' rsn-veille-action" data-statut="' . esc_attr($statut) . '"'
                . ($infolettre !== '' ? ' data-infolettre="' . esc_attr($infolettre) . '"' : '') . '>'
                . esc_html($libelle) . '</button> ';
        }
        return rtrim($html);
    }

    private static function libelle_type(string $type): string {
        return match ($type) {
            'evenement' => __('Événement', 'rsn-veille'),
            'appel' => __('Appel', 'rsn-veille'),
            'nouvelle' => __('Nouvelle', 'rsn-veille'),
            default => $type,
        };
    }

    /**
     * Une fiche dépassée (`Fiche::est_passee`) quitte « À approuver » et « Publiées » pour « Archivées » : son statut
     * ne change pas, seul l'onglet où elle est montrée.
     */
    private static function dans_onglet(Fiche $f, string $onglet, string $aujourdhui): bool {
        if ($onglet === 'a_verifier') {
            return $f->statut === 'publie' && $f->absent_depuis !== null && $f->absent_depuis !== '';
        }
        if ($onglet === 'ecarte') {
            return $f->statut === 'ecarte';
        }
        if ($f->statut !== 'publie' && $f->statut !== 'a_approuver') {
            return false;
        }
        // Une fiche publiée qui n'est ni un événement ni un appel n'est sur aucune page : rangée avec les archivées,
        // pour qu'aucune fiche publiée ne soit introuvable dans la file.
        $passee = $f->est_passee($aujourdhui) || ($f->statut === 'publie' && !in_array($f->type, self::TYPES, true));
        return $onglet === 'archive' ? $passee : (!$passee && $f->statut === $onglet);
    }

    /** Paramètre d'adresse réduit à une clé (`sanitize_key`), vide s'il manque. */
    private static function parametre(string $nom): string {
        return isset($_GET[$nom]) && is_string($_GET[$nom]) ? sanitize_key(wp_unslash($_GET[$nom])) : '';
    }
}

<?php
/**
 * Imitation de WordPress en mémoire pour les tests : ce n'est PAS une référence.
 *
 * Les comportements imités sont des hypothèses sur WordPress 6.x : la bascule « publish » → « future »
 * d'une date à venir, l'encodage kses (`&` → `&amp;`) des titres et résumés, la remise à maintenant de
 * la date d'une fiche à approuver sans `edit_date`, le déslashage des données, la comparaison exacte
 * d'un `meta_query` de type BINARY (et le « contient » de LIKE, `!=`, `>=`, `>`, `<=`, `<`, EXISTS, NOT EXISTS,
 * groupes imbriqués reliés par AND ou OR), `post__in`, l'ordre par identifiant (DESC par défaut, comme
 * WordPress) et `found_posts`. La vérification de référence reste
 * `tests/manuel/verifier-cpt.php`, exécuté dans un vrai WordPress (`wp eval-file`).
 *
 * Administration : menus et crochets de pages (`toplevel_page_…`, `{menu}_page_…`), capacités de
 * l'utilisateur courant (liste `capacites`), rôles (`WP_Role`, chaque `add_cap` compté comme une
 * écriture), jetons déterministes (« jeton-{action} »), et `wp_send_json_*` / `wp_die` qui lèvent
 * `RsnVeilleArretRequete` au lieu d'arrêter PHP. La vérification de référence est
 * `tests/manuel/verifier-admin.md`.
 *
 * Réglages : `register_setting` pose le filtre `sanitize_option_{option}`, appliqué par `update_option`
 * (deux fois quand l'option n'existe pas encore, comme WordPress qui passe alors par `add_option`) ;
 * `update_option` déclenche `add_option_{option}` ou, si la valeur change, `update_option_{option}`.
 * `add_settings_error` note les messages (`erreurs_reglages`), `spawn_cron` compte ses appels
 * (`cron_lances`). La vérification de référence des réglages est la section Réglages de
 * `tests/manuel/verifier-admin.md`.
 *
 * Courriel : `wp_mail` capture les messages en mémoire (`courriels`, résultat `wp_mail_reussit`) et
 * déclenche `phpmailer_init` avec un objet vide (texte alternatif noté). La vérification de référence est
 * la section Courriel hebdomadaire de `tests/manuel/verifier-admin.md`.
 *
 * Public : `add_shortcode` / `do_shortcode` (raccourcis notés dans `raccourcis`), `shortcode_atts`,
 * `has_shortcode`, la page demandée (`objet_demande`, singulière si c'est un WP_Post), les routes REST
 * (`routes`, `WP_REST_Response` avec ses en-têtes), les règles de réécriture (`regles`, chaque
 * `flush_rewrite_rules` compté dans `regles_regenerees`) et les variables de requête
 * (`variables_requete`, lues par `get_query_var`). `tribe_get_events` (The Events Calendar) est
 * absente : `EvenementsRsn::lire()` rend alors une liste vide. La vérification de référence est
 * `tests/manuel/verifier-public.md`.
 *
 * Formulaire public : `wp_salt` déterministe, `wp_check_filetype_and_ext` (extension parmi `mimes` et
 * type réel lu par finfo), `media_handle_upload` imité (appels notés dans `televersements`, échec forcé
 * par `televersement_erreur`), `set_post_thumbnail` (méta `_thumbnail_id`) et
 * `wp_get_attachment_image_url`, `get_post_thumbnail_id` et `wp_delete_attachment` (noté dans
 * `pieces_supprimees`, métas `_thumbnail_id` retirées). La vérification de référence est la section Formulaire de
 * `tests/manuel/verifier-public.md`.
 *
 * HTTP : `wp_remote_get` note ses appels (`requetes_http`) et rend `reponse_http` (200 vide par défaut) ;
 * `wp_remote_retrieve_response_code`, `_header` (nom insensible à la casse) et `_body`.
 *
 * Chargé seulement par les tests qui en ont besoin (`require_once` en tête du fichier de test),
 * jamais par `tests/bootstrap.php`. Les bouchons déjà définis par le bootstrap sont gardés.
 * `rsn_veille_bouchons_reinitialiser()` remet l'état à zéro et réenregistre le type de contenu.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

/** État du faux WordPress. */
function rsn_veille_bouchons_reinitialiser(): void {
    $GLOBALS['rsn_veille_wp'] = [
        'posts' => [], 'meta' => [], 'termes' => [], 'filtres' => [], 'actions' => [],
        'prochain_id' => 1, 'types' => [], 'taxonomies' => [], 'metas_enregistrees' => [], 'requetes' => [],
        'options' => [], 'cache_options' => [], 'transients' => [], 'cron' => [], 'declenchees' => [],
        // Administration : menus, capacités de l'utilisateur courant, rôles, scripts et styles en file, métaboxes.
        'menus' => [], 'sous_menus' => [], 'crochets_menus' => [], 'capacites' => [], 'ecritures_roles' => 0,
        'scripts' => [], 'styles' => [], 'localisations' => [], 'metaboxes' => [],
        // Réglages : options enregistrées, messages d'erreur des champs, lancements de WP-Cron.
        'reglages' => [], 'erreurs_reglages' => [], 'cron_lances' => 0,
        // Public : raccourcis, routes REST, règles de réécriture, variables de requête, page demandée.
        'raccourcis' => [], 'routes' => [], 'regles' => [], 'variables_requete' => [], 'objet_demande' => null,
        'regles_regenerees' => 0,
        // Courriel : messages remis à wp_mail (sujet, message, en-têtes, texte alternatif) et résultat à renvoyer.
        'courriels' => [], 'wp_mail_reussit' => true,
        // HTTP : appels à wp_remote_get (adresse et arguments) et réponse à rendre (null : 200 vide).
        'requetes_http' => [], 'reponse_http' => null,
    ];
    $GLOBALS['rsn_veille_wp']['roles'] = [
        'administrator' => new WP_Role('administrator', ['manage_options' => true, 'edit_posts' => true]),
        'editor' => new WP_Role('editor', ['edit_others_posts' => true, 'edit_posts' => true]),
        'author' => new WP_Role('author', ['edit_posts' => true]),
        'subscriber' => new WP_Role('subscriber', ['read' => true]),
    ];
    \RSN\Veille\TypeContenu::enregistrer();
}

// --- HTTP (imitation de wp-includes/http.php) ---

/** Note l'appel (`requetes_http`) et rend `reponse_http` : un tableau au format de WordPress, ou un WP_Error. */
function wp_remote_get(string $url, array $arguments = []): array|WP_Error {
    $GLOBALS['rsn_veille_wp']['requetes_http'][] = ['url' => $url, 'arguments' => $arguments];
    return $GLOBALS['rsn_veille_wp']['reponse_http']
        ?? ['headers' => [], 'body' => '', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'http_response' => null];
}

/** Code de la réponse, '' pour une erreur ou une réponse incomplète (comme WordPress). */
function wp_remote_retrieve_response_code(array|WP_Error $reponse): int|string {
    return is_array($reponse) && isset($reponse['response']['code']) ? (int) $reponse['response']['code'] : '';
}

/** En-tête de la réponse (nom insensible à la casse), '' s'il manque. */
function wp_remote_retrieve_header(array|WP_Error $reponse, string $nom): array|string {
    if (!is_array($reponse) || !is_array($reponse['headers'] ?? null)) {
        return '';
    }
    $entetes = array_change_key_case($reponse['headers'], CASE_LOWER);
    return $entetes[strtolower($nom)] ?? '';
}

/** Corps de la réponse, '' pour une erreur. */
function wp_remote_retrieve_body(array|WP_Error $reponse): string {
    return is_array($reponse) ? (string) ($reponse['body'] ?? '') : '';
}

class WP_Error {
    public function __construct(public string $code = '', public string $message = '') {
    }

    public function get_error_message(): string {
        return $this->message;
    }
}

class WP_Post {
    public int $ID = 0;
    public string $post_type = '';
    public string $post_status = '';
    public string $post_title = '';
    public string $post_excerpt = '';
    public string $post_content = '';
    public string $post_date = '';
    public string $post_date_gmt = '';
    public int $post_parent = 0;
    public string $post_name = '';
}

// Bouchons communs avec tests/bootstrap.php (celui-ci, chargé en premier, l'emporte).
if (!function_exists('__')) {
    function __(string $texte, string $domaine = 'default'): string {
        return $texte;
    }
}
if (!function_exists('wp_date')) {
    function wp_date(string $format, ?int $horodatage = null): string {
        return date($format, $horodatage ?? time());
    }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $texte): string {
        return trim(preg_replace('/\s+/', ' ', strip_tags($texte)));
    }
}

function add_action(string $crochet, callable $rappel, int $priorite = 10, int $nb_arguments = 1): void {
    $GLOBALS['rsn_veille_wp']['actions'][$crochet][] = ['rappel' => $rappel, 'nb_arguments' => $nb_arguments, 'priorite' => $priorite];
}

/**
 * Comme WordPress : sans argument, les rappels reçoivent une chaîne vide ; chacun reçoit au plus
 * `nb_arguments` arguments. Chaque déclenchement est noté dans `declenchees` (crochet => listes d'arguments).
 */
function do_action(string $crochet, mixed ...$arguments): void {
    if ($arguments === []) {
        $arguments = [''];
    }
    $GLOBALS['rsn_veille_wp']['declenchees'][$crochet][] = $arguments;
    foreach ($GLOBALS['rsn_veille_wp']['actions'][$crochet] ?? [] as $action) {
        ($action['rappel'])(...array_slice($arguments, 0, $action['nb_arguments']));
    }
}

/** Arguments de chaque déclenchement d’une action (outil de test, pas une fonction WordPress). */
function rsn_veille_bouchons_declenchements(string $crochet): array {
    return $GLOBALS['rsn_veille_wp']['declenchees'][$crochet] ?? [];
}

/**
 * Options : `options` imite la base, `cache_options` le cache de la requête (rempli par la lecture et
 * par nos écritures, vidé par `wp_cache_delete`). Un « autre processus » écrit directement dans
 * `options` : la requête courante ne le voit qu'après `wp_cache_delete`.
 */
function get_option(string $nom, mixed $defaut = false): mixed {
    $wp = &$GLOBALS['rsn_veille_wp'];
    if (!array_key_exists($nom, $wp['cache_options'])) {
        $wp['cache_options'][$nom] = array_key_exists($nom, $wp['options']) ? $wp['options'][$nom] : null;
    }
    return $wp['cache_options'][$nom] ?? $defaut;
}

/**
 * Écrit toujours (contrairement à WordPress, qui n'écrit pas une valeur inchangée). La valeur passe par
 * `sanitize_option_{nom}` ; option absente : nettoyée une seconde fois et `add_option_{nom}` ($nom,
 * $valeur) ; option changée : `update_option_{nom}` ($ancienne, $nouvelle, $nom).
 */
function update_option(string $nom, mixed $valeur, mixed $chargement_auto = null): bool {
    $existait = array_key_exists($nom, $GLOBALS['rsn_veille_wp']['options']);
    $ancienne = $existait ? get_option($nom) : null;
    $valeur = apply_filters('sanitize_option_' . $nom, $valeur, $nom);
    if (!$existait) {
        $valeur = apply_filters('sanitize_option_' . $nom, $valeur, $nom);
    }
    $GLOBALS['rsn_veille_wp']['options'][$nom] = $valeur;
    $GLOBALS['rsn_veille_wp']['cache_options'][$nom] = $valeur;
    if (!$existait) {
        do_action('add_option_' . $nom, $nom, $valeur);
    } elseif ($ancienne !== $valeur) {
        do_action('update_option_' . $nom, $ancienne, $valeur, $nom);
    }
    return true;
}

/** Écriture d'un autre processus : la base seulement, pas le cache de cette requête (outil de test). */
function rsn_veille_bouchons_ecrire_option_ailleurs(string $nom, mixed $valeur): void {
    $GLOBALS['rsn_veille_wp']['options'][$nom] = $valeur;
}

if (!function_exists('_n')) {
    function _n(string $singulier, string $pluriel, int $nombre, string $domaine = 'default'): string {
        return $nombre === 1 ? $singulier : $pluriel;
    }
}

/**
 * Comme WordPress : échoue si l'option existe déjà. `apres_add_option` (rappel de test, facultatif) est
 * appelé après une insertion réussie, pour imiter un processus concurrent qui écrit la même option
 * (le `INSERT … ON DUPLICATE KEY UPDATE` de WordPress laisse alors réussir les deux).
 */
function add_option(string $nom, mixed $valeur = '', string $deprecie = '', mixed $chargement_auto = null): bool {
    if (array_key_exists($nom, $GLOBALS['rsn_veille_wp']['options'])) {
        return false;
    }
    $GLOBALS['rsn_veille_wp']['options'][$nom] = $valeur;
    $GLOBALS['rsn_veille_wp']['cache_options'][$nom] = $valeur;
    $concurrent = $GLOBALS['rsn_veille_wp']['apres_add_option'] ?? null;
    if ($concurrent !== null) {
        $GLOBALS['rsn_veille_wp']['apres_add_option'] = null;
        $concurrent($nom);
    }
    return true;
}

/** Seul le groupe « options » est imité. */
function wp_cache_delete(string $cle, string $groupe = ''): bool {
    if ($groupe === 'options') {
        unset($GLOBALS['rsn_veille_wp']['cache_options'][$cle]);
    }
    return true;
}

function delete_option(string $nom): bool {
    unset($GLOBALS['rsn_veille_wp']['options'][$nom], $GLOBALS['rsn_veille_wp']['cache_options'][$nom]);
    return true;
}

/** Transients en mémoire ; une durée nulle ne périme jamais. */
function get_transient(string $nom): mixed {
    $transient = $GLOBALS['rsn_veille_wp']['transients'][$nom] ?? null;
    if ($transient === null || ($transient['expire'] !== 0 && $transient['expire'] <= time())) {
        unset($GLOBALS['rsn_veille_wp']['transients'][$nom]);
        return false;
    }
    return $transient['valeur'];
}

function set_transient(string $nom, mixed $valeur, int $duree = 0): bool {
    $GLOBALS['rsn_veille_wp']['transients'][$nom] = ['valeur' => $valeur, 'expire' => $duree > 0 ? time() + $duree : 0];
    return true;
}

function delete_transient(string $nom): bool {
    unset($GLOBALS['rsn_veille_wp']['transients'][$nom]);
    return true;
}

/**
 * Tâches planifiées : liste d'événements {horodatage, crochet, recurrence (false si unique), args}.
 * Comme WordPress 5.1+, un événement unique est refusé s'il en existe déjà un du même crochet (mêmes
 * arguments) à moins de 10 minutes.
 */
function wp_schedule_single_event(int $horodatage, string $crochet, array $args = []): bool {
    foreach ($GLOBALS['rsn_veille_wp']['cron'] as $evenement) {
        if ($evenement['crochet'] === $crochet && $evenement['args'] === $args && abs($evenement['horodatage'] - $horodatage) <= 10 * MINUTE_IN_SECONDS) {
            return false;
        }
    }
    $GLOBALS['rsn_veille_wp']['cron'][] = ['horodatage' => $horodatage, 'crochet' => $crochet, 'recurrence' => false, 'args' => $args];
    return true;
}

function wp_schedule_event(int $horodatage, string $recurrence, string $crochet, array $args = []): bool {
    $GLOBALS['rsn_veille_wp']['cron'][] = ['horodatage' => $horodatage, 'crochet' => $crochet, 'recurrence' => $recurrence, 'args' => $args];
    return true;
}

function wp_next_scheduled(string $crochet, array $args = []): int|false {
    $prochain = false;
    foreach ($GLOBALS['rsn_veille_wp']['cron'] as $evenement) {
        if ($evenement['crochet'] === $crochet && $evenement['args'] === $args && ($prochain === false || $evenement['horodatage'] < $prochain)) {
            $prochain = $evenement['horodatage'];
        }
    }
    return $prochain;
}

function wp_unschedule_event(int $horodatage, string $crochet, array $args = []): bool {
    foreach ($GLOBALS['rsn_veille_wp']['cron'] as $i => $evenement) {
        if ($evenement['horodatage'] === $horodatage && $evenement['crochet'] === $crochet && $evenement['args'] === $args) {
            unset($GLOBALS['rsn_veille_wp']['cron'][$i]);
            return true;
        }
    }
    return false;
}

function wp_clear_scheduled_hook(string $crochet, array $args = []): int {
    $avant = count($GLOBALS['rsn_veille_wp']['cron']);
    $GLOBALS['rsn_veille_wp']['cron'] = array_filter(
        $GLOBALS['rsn_veille_wp']['cron'],
        static fn(array $e): bool => !($e['crochet'] === $crochet && $e['args'] === $args)
    );
    return $avant - count($GLOBALS['rsn_veille_wp']['cron']);
}

/** Événements planifiés d'un crochet (outil de test). */
function rsn_veille_bouchons_planifies(string $crochet): array {
    return array_values(array_filter($GLOBALS['rsn_veille_wp']['cron'], static fn(array $e): bool => $e['crochet'] === $crochet));
}

/**
 * Comme le lanceur de WP-Cron pour un crochet : retire le prochain événement (unique) puis déclenche
 * l'action, sans attendre son heure. Faux s'il n'y a rien de planifié (outil de test).
 */
function rsn_veille_bouchons_executer_cron(string $crochet): bool {
    $horodatage = wp_next_scheduled($crochet);
    if ($horodatage === false) {
        return false;
    }
    wp_unschedule_event($horodatage, $crochet);
    do_action($crochet);
    return true;
}

if (!function_exists('wp_timezone')) {
    function wp_timezone(): DateTimeZone {
        return new DateTimeZone('America/Toronto');
    }
}

function add_filter(string $crochet, callable $rappel): void {
    $GLOBALS['rsn_veille_wp']['filtres'][$crochet][] = $rappel;
}

function apply_filters(string $crochet, mixed $valeur, mixed ...$arguments): mixed {
    foreach ($GLOBALS['rsn_veille_wp']['filtres'][$crochet] ?? [] as $rappel) {
        $valeur = $rappel($valeur, ...$arguments);
    }
    return $valeur;
}

function register_post_type(string $type, array $arguments): void {
    $GLOBALS['rsn_veille_wp']['types'][$type] = $arguments;
}

function register_taxonomy(string $taxonomie, array $types, array $arguments): void {
    $GLOBALS['rsn_veille_wp']['taxonomies'][$taxonomie] = $arguments;
}

function register_post_meta(string $type, string $cle, array $arguments): void {
    $GLOBALS['rsn_veille_wp']['metas_enregistrees'][$cle] = $arguments;
}

function post_type_exists(string $type): bool {
    return isset($GLOBALS['rsn_veille_wp']['types'][$type]);
}

function taxonomy_exists(string $taxonomie): bool {
    return isset($GLOBALS['rsn_veille_wp']['taxonomies'][$taxonomie]);
}

function wp_generate_password(int $longueur = 12, bool $speciaux = true, bool $extra = false): string {
    $texte = '';
    while (strlen($texte) < $longueur) {
        $texte .= md5((string) mt_rand());
    }
    return substr($texte, 0, $longueur);
}

function wp_check_invalid_utf8(string $texte): string {
    return mb_check_encoding($texte, 'UTF-8') ? $texte : '';
}

function sanitize_textarea_field(string $texte): string {
    return trim(strip_tags($texte));
}

function sanitize_title(string $texte): string {
    return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($texte)), '-');
}

function sanitize_email(string $courriel): string {
    return (string) filter_var($courriel, FILTER_SANITIZE_EMAIL);
}

/** Version courte d'esc_url_raw : caractères permis seulement, espaces encodées. */
function esc_url_raw(string $url): string {
    $url = str_replace(' ', '%20', trim($url));
    return (string) preg_replace('|[^a-z0-9\-~+_.?#=!&;,/:%@$\|*\'()\[\]\x80-\xff]|i', '', $url);
}

function wp_slash(mixed $valeur): mixed {
    if (is_array($valeur)) {
        return array_map('wp_slash', $valeur);
    }
    return is_string($valeur) ? addslashes($valeur) : $valeur;
}

function wp_unslash(mixed $valeur): mixed {
    if (is_array($valeur)) {
        return array_map('wp_unslash', $valeur);
    }
    return is_string($valeur) ? stripslashes($valeur) : $valeur;
}

function is_wp_error(mixed $valeur): bool {
    return $valeur instanceof WP_Error;
}

function user_can(int $utilisateur, string $capacite, int $id = 0): bool {
    return $utilisateur > 0;
}

function get_gmt_from_date(string $date): string {
    return gmdate('Y-m-d H:i:s', (int) strtotime($date . ' America/Toronto'));
}

/** Comme wp_kses_normalize_entities : un `&` qui n'ouvre pas d'entité devient `&amp;`. */
function rsn_veille_bouchon_kses(string $texte): string {
    return (string) preg_replace('/&(?![a-z#0-9]+;)/i', '&amp;', $texte);
}

function wp_insert_post(array $article, bool $erreur = false, bool $mise_a_jour = false): int|WP_Error {
    $article = wp_unslash($article);
    if (($article['post_title'] ?? '') === '' && ($article['post_excerpt'] ?? '') === '' && ($article['post_content'] ?? '') === '') {
        return new WP_Error('empty_content', 'Contenu, titre et extrait vides.');
    }
    $statut = $article['post_status'] ?? 'draft';
    // Date par défaut : l'heure de Montréal, la même base que get_gmt_from_date() (sinon une page `publish`
    // créée sans date passerait en `future` quand le fuseau de PHP n'est pas celui du site).
    $date = ($article['post_date'] ?? '') !== ''
        ? $article['post_date']
        : (new DateTimeImmutable('now', new DateTimeZone('America/Toronto')))->format('Y-m-d H:i:s');
    $gmt = $article['post_date_gmt'] ?? '';
    if ($gmt === '' || $gmt === '0000-00-00 00:00:00') {
        $gmt = in_array($statut, ['draft', 'pending'], true) ? '0000-00-00 00:00:00' : get_gmt_from_date($date);
    }
    if ($statut === 'publish' && strtotime($gmt . ' UTC') - time() >= 60) {
        $statut = 'future';
    }
    $donnees = [
        'post_type' => $article['post_type'] ?? 'post',
        'post_status' => $statut,
        'post_title' => rsn_veille_bouchon_kses($article['post_title'] ?? ''),
        'post_excerpt' => rsn_veille_bouchon_kses($article['post_excerpt'] ?? ''),
        'post_content' => $article['post_content'] ?? '',
        'post_date' => $date,
        'post_date_gmt' => $gmt,
        'post_name' => $article['post_name'] ?? '',
        'post_parent' => (int) ($article['post_parent'] ?? 0),
    ];
    $donnees = apply_filters('wp_insert_post_data', $donnees, $article, $article, $mise_a_jour);
    $id = (int) ($article['ID'] ?? $GLOBALS['rsn_veille_wp']['prochain_id']++);
    $post = new WP_Post();
    $post->ID = $id;
    foreach ($donnees as $champ => $valeur) {
        $post->$champ = $valeur;
    }
    $GLOBALS['rsn_veille_wp']['posts'][$id] = $post;
    do_action('save_post_' . $post->post_type, $id, clone $post, $mise_a_jour);
    return $id;
}

function wp_update_post(array $article, bool $erreur = false): int|WP_Error {
    $ancien = get_post((int) ($article['ID'] ?? 0));
    if ($ancien === null) {
        return new WP_Error('invalid_post', 'Identifiant inconnu.');
    }
    $vider_date = in_array($ancien->post_status, ['draft', 'pending'], true)
        && empty($article['edit_date']) && $ancien->post_date_gmt === '0000-00-00 00:00:00';
    $article = array_merge(wp_slash(get_object_vars($ancien)), $article);
    if ($vider_date) {
        $article['post_date'] = date('Y-m-d H:i:s');
        $article['post_date_gmt'] = '';
    }
    return wp_insert_post($article, $erreur, true);
}

function get_post(int $id): ?WP_Post {
    $post = $GLOBALS['rsn_veille_wp']['posts'][$id] ?? null;
    return $post === null ? null : clone $post;
}

function get_post_status(int $id): string|false {
    return $GLOBALS['rsn_veille_wp']['posts'][$id]->post_status ?? false;
}

function get_post_field(string $champ, int $id): string {
    return (string) ($GLOBALS['rsn_veille_wp']['posts'][$id]->$champ ?? '');
}

function wp_delete_post(int $id, bool $definitif = false): void {
    $post = get_post($id);
    unset($GLOBALS['rsn_veille_wp']['posts'][$id], $GLOBALS['rsn_veille_wp']['meta'][$id], $GLOBALS['rsn_veille_wp']['termes'][$id]);
    if ($post !== null) {
        do_action('deleted_post', $id, $post);
    }
}

/** Comme sanitize_meta : applique le sanitize_callback enregistré. */
function rsn_veille_bouchon_nettoyer_meta(string $cle, mixed $valeur): mixed {
    $rappel = $GLOBALS['rsn_veille_wp']['metas_enregistrees'][$cle]['sanitize_callback'] ?? null;
    return $rappel === null ? $valeur : $rappel($valeur, $cle, 'post', 'rsn_veille');
}

function update_post_meta(int $id, string $cle, mixed $valeur): bool {
    $GLOBALS['rsn_veille_wp']['meta'][$id][$cle] = [serialize(rsn_veille_bouchon_nettoyer_meta($cle, wp_unslash($valeur)))];
    return true;
}

function add_post_meta(int $id, string $cle, mixed $valeur): bool {
    $GLOBALS['rsn_veille_wp']['meta'][$id][$cle][] = serialize(rsn_veille_bouchon_nettoyer_meta($cle, wp_unslash($valeur)));
    return true;
}

function delete_post_meta(int $id, string $cle): bool {
    unset($GLOBALS['rsn_veille_wp']['meta'][$id][$cle]);
    return true;
}

function get_post_meta(int $id, string $cle = '', bool $unique = false): mixed {
    $valeurs = array_map('unserialize', $GLOBALS['rsn_veille_wp']['meta'][$id][$cle] ?? []);
    return $unique ? ($valeurs[0] ?? '') : $valeurs;
}

function metadata_exists(string $type, int $id, string $cle): bool {
    return isset($GLOBALS['rsn_veille_wp']['meta'][$id][$cle]);
}

function update_meta_cache(string $type, array $ids): void {
}

function wp_set_object_terms(int $id, array $noms, string $taxonomie, bool $ajouter = false): array|WP_Error {
    if (!taxonomy_exists($taxonomie)) {
        return new WP_Error('invalid_taxonomy', 'Taxonomie inconnue.');
    }
    $GLOBALS['rsn_veille_wp']['termes'][$id] = array_map('rsn_veille_bouchon_kses', $noms);
    return [];
}

function wp_get_object_terms(int|array $ids, string $taxonomie, array $arguments = []): array|WP_Error {
    $resultat = [];
    foreach ((array) $ids as $id) {
        foreach ($GLOBALS['rsn_veille_wp']['termes'][$id] ?? [] as $nom) {
            $resultat[] = ($arguments['fields'] ?? '') === 'names' ? $nom : (object) ['object_id' => $id, 'name' => $nom];
        }
    }
    return $resultat;
}

class WP_Query {
    /** @var array<int|WP_Post> */
    public array $posts = [];
    /** Nombre total d'articles trouvés, sans la limite `posts_per_page` (0 si `no_found_rows`, comme WordPress). */
    public int $found_posts = 0;

    /**
     * Imite `post_type`, `post_status`, `post__in` (une liste vide est ignorée, comme WordPress),
     * `meta_query`, `orderby` ID avec `order` ASC ou DESC, `posts_per_page` (-1 : tout), `fields` et
     * `no_found_rows`.
     */
    public function __construct(array $arguments) {
        $GLOBALS['rsn_veille_wp']['requetes'][] = $arguments;
        $ids = array_keys($GLOBALS['rsn_veille_wp']['posts']);
        sort($ids);
        if (strtoupper((string) ($arguments['order'] ?? 'DESC')) === 'DESC') {
            $ids = array_reverse($ids);
        }
        $parmi = !empty($arguments['post__in']) ? array_flip(array_map('intval', (array) $arguments['post__in'])) : null;
        $trouves = [];
        foreach ($ids as $id) {
            $post = $GLOBALS['rsn_veille_wp']['posts'][$id];
            if ($post->post_type !== $arguments['post_type'] || !in_array($post->post_status, (array) $arguments['post_status'], true)) {
                continue;
            }
            if ($parmi !== null && !isset($parmi[$id])) {
                continue;
            }
            if (!self::groupe($id, $arguments['meta_query'] ?? [])) {
                continue;
            }
            $trouves[] = ($arguments['fields'] ?? 'all') === 'ids' ? $id : clone $post;
        }
        $par_page = (int) ($arguments['posts_per_page'] ?? 10);
        $this->posts = $par_page > 0 ? array_slice($trouves, 0, $par_page) : $trouves;
        $this->found_posts = empty($arguments['no_found_rows']) && $this->posts !== [] ? count($trouves) : 0;
    }

    /**
     * Groupe de `meta_query` : clauses et sous-groupes reliés par `relation` (AND par défaut, ou OR),
     * comme WordPress.
     */
    private static function groupe(int $id, array $groupe): bool {
        $ou = strtoupper((string) ($groupe['relation'] ?? 'AND')) === 'OR';
        $clauses = array_filter($groupe, static fn($c, $cle): bool => $cle !== 'relation' && is_array($c), ARRAY_FILTER_USE_BOTH);
        if ($clauses === []) {
            return true;
        }
        foreach ($clauses as $clause) {
            $vrai = isset($clause['key']) ? self::satisfait($id, $clause) : self::groupe($id, $clause);
            if ($vrai === $ou) {
                return $ou;
            }
        }
        return !$ou;
    }

    /**
     * `=` (exact si BINARY, sinon insensible à la casse comme la collation MySQL), `!=` (une valeur
     * présente et différente : une méta absente ne satisfait pas `!=`, comme le JOIN de WordPress), LIKE
     * (« contient »), EXISTS et NOT EXISTS, et `>=`, `>`, `<=`, `<` (comparaison de chaînes : octet par
     * octet si BINARY, sinon insensible à la casse ; une méta absente ne les satisfait pas).
     */
    private static function satisfait(int $id, array $clause): bool {
        $compare = strtoupper((string) ($clause['compare'] ?? '='));
        $present = isset($GLOBALS['rsn_veille_wp']['meta'][$id][$clause['key']]);
        if ($compare === 'NOT EXISTS' || $compare === 'EXISTS') {
            return ($compare === 'EXISTS') === $present;
        }
        foreach (array_map('unserialize', $GLOBALS['rsn_veille_wp']['meta'][$id][$clause['key']] ?? []) as $valeur) {
            $valeur = (string) $valeur;
            $ecart = ($clause['type'] ?? '') === 'BINARY' ? strcmp($valeur, (string) $clause['value']) : strcasecmp($valeur, (string) $clause['value']);
            if (in_array($compare, ['>=', '>', '<=', '<'], true)) {
                $vrai = match ($compare) { '>=' => $ecart >= 0, '>' => $ecart > 0, '<=' => $ecart <= 0, default => $ecart < 0 };
                if ($vrai) {
                    return true;
                }
                continue;
            }
            $egal = ($clause['type'] ?? '') === 'BINARY' ? $valeur === $clause['value'] : strcasecmp($valeur, (string) $clause['value']) === 0;
            if ($compare === '!=') {
                if (!$egal) {
                    return true;
                }
                continue;
            }
            if ($compare === 'LIKE') {
                if (stripos($valeur, (string) $clause['value']) !== false) {
                    return true;
                }
            } elseif ($egal) {
                return true;
            }
        }
        return false;
    }
}

// --- Administration (imitation de wp-admin/includes/plugin.php, capabilities.php, pluggable.php) ---

if (!function_exists('esc_attr')) {
    function esc_attr(string $texte): string {
        return htmlspecialchars($texte, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('esc_url')) {
    /** Comme esc_url : protocoles autorisés seulement (vide sinon), puis encodé pour un attribut. */
    function esc_url(string $url): string {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $url, $m) === 1 && !in_array(strtolower($m[1]), ['http', 'https', 'mailto'], true)) {
            return '';
        }
        return htmlspecialchars(esc_url_raw($url), ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('esc_html__')) {
    function esc_html__(string $texte, string $domaine = 'default'): string {
        return esc_html(__($texte, $domaine));
    }
}
if (!function_exists('esc_attr__')) {
    function esc_attr__(string $texte, string $domaine = 'default'): string {
        return esc_attr(__($texte, $domaine));
    }
}

function absint(mixed $valeur): int {
    return abs((int) $valeur);
}

function sanitize_key(string $cle): string {
    return (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower($cle));
}

function admin_url(string $chemin = ''): string {
    return 'https://exemple.test/wp-admin/' . ltrim($chemin, '/');
}

if (!function_exists('home_url')) {
    function home_url(string $chemin = ''): string {
        return 'https://exemple.test' . ($chemin === '' ? '' : '/' . ltrim($chemin, '/'));
    }
}

/** Comme WordPress : le crochet d'une page de premier niveau est « toplevel_page_{slug} ». */
function add_menu_page(string $titre_page, string $titre_menu, string $capacite, string $slug, ?callable $rappel = null, string $icone = '', int|float|null $position = null): string {
    $GLOBALS['rsn_veille_wp']['menus'][$slug] = compact('titre_page', 'titre_menu', 'capacite', 'slug', 'rappel', 'icone', 'position');
    $GLOBALS['rsn_veille_wp']['crochets_menus'][$slug] = sanitize_title($titre_menu);
    return 'toplevel_page_' . $slug;
}

/**
 * Comme WordPress : faux si l'utilisateur n'a pas la capacité ; le crochet d'une sous-page vaut
 * « toplevel_page_{slug} » si elle reprend le slug du menu, sinon « {titre du menu nettoyé}_page_{slug} ».
 */
function add_submenu_page(string $parent, string $titre_page, string $titre_menu, string $capacite, string $slug, ?callable $rappel = null, int|float|null $position = null): string|false {
    if (!current_user_can($capacite)) {
        return false;
    }
    $GLOBALS['rsn_veille_wp']['sous_menus'][$parent][$slug] = compact('titre_page', 'titre_menu', 'capacite', 'slug', 'rappel');
    if (isset($GLOBALS['rsn_veille_wp']['crochets_menus'][$slug])) {
        return 'toplevel_page_' . $slug;
    }
    return ($GLOBALS['rsn_veille_wp']['crochets_menus'][$parent] ?? 'admin') . '_page_' . $slug;
}

/** Note la métabox (id, titre, rappel, écran, contexte, priorité) dans `metaboxes`, consultable par un test. */
function add_meta_box(string $id, string $titre, callable $rappel, string|array|null $ecran = null, string $contexte = 'advanced', string $priorite = 'default', ?array $args = null): void {
    $GLOBALS['rsn_veille_wp']['metaboxes'][$id] = compact('id', 'titre', 'rappel', 'ecran', 'contexte', 'priorite', 'args');
}

/** Capacités de l'utilisateur courant : liste posée par le test (`capacites`). */
function current_user_can(string $capacite, mixed ...$arguments): bool {
    return in_array($capacite, $GLOBALS['rsn_veille_wp']['capacites'], true);
}

class WP_Role {
    /** @param array<string,bool> $capabilities */
    public function __construct(public string $name, public array $capabilities = []) {
    }

    /** Comme WordPress, chaque appel réécrit l'option des rôles (compté dans `ecritures_roles`). */
    public function add_cap(string $capacite, bool $accorder = true): void {
        $this->capabilities[$capacite] = $accorder;
        $GLOBALS['rsn_veille_wp']['ecritures_roles']++;
    }

    public function remove_cap(string $capacite): void {
        unset($this->capabilities[$capacite]);
        $GLOBALS['rsn_veille_wp']['ecritures_roles']++;
    }

    public function has_cap(string $capacite): bool {
        return !empty($this->capabilities[$capacite]);
    }
}

class WP_Roles {
    /** @var array<string,WP_Role> */
    public array $role_objects = [];
    /** @var array<string,array{name: string, capabilities: array<string,bool>}> Comme WordPress : nom affiché et capacités. */
    public array $roles = [];

    public function __construct() {
        $this->role_objects = $GLOBALS['rsn_veille_wp']['roles'];
        foreach ($this->role_objects as $nom => $role) {
            $this->roles[$nom] = ['name' => ucfirst($nom), 'capabilities' => $role->capabilities];
        }
    }
}

function wp_roles(): WP_Roles {
    return new WP_Roles();
}

function get_role(string $nom): ?WP_Role {
    return $GLOBALS['rsn_veille_wp']['roles'][$nom] ?? null;
}

/** Jeton déterministe : « jeton-{action} » (imitation, aucune sécurité). */
function wp_create_nonce(string|int $action = -1): string {
    return 'jeton-' . $action;
}

function wp_verify_nonce(mixed $jeton, string|int $action = -1): int|false {
    return is_string($jeton) && $jeton === 'jeton-' . $action ? 1 : false;
}

/** Réponse de wp_send_json_* ou de wp_die : WordPress arrête la requête, l'imitation lève cette exception. */
class RsnVeilleArretRequete extends Exception {
    public function __construct(public mixed $reponse, public int $statut_http) {
        parent::__construct('Requête arrêtée (' . $statut_http . ')');
    }
}

function wp_die(mixed $message = '', mixed $titre = '', mixed $arguments = []): void {
    throw new RsnVeilleArretRequete($message, is_int($arguments) ? $arguments : (int) ($arguments['response'] ?? 500));
}

/** Comme WordPress : lit `$_REQUEST[$champ]` ; si le jeton est mauvais et $arreter est vrai, wp_die(-1, 403). */
function check_ajax_referer(string|int $action = -1, string|false $champ = false, bool $arreter = true): int|false {
    $jeton = $champ !== false ? ($_REQUEST[$champ] ?? '') : ($_REQUEST['_ajax_nonce'] ?? $_REQUEST['_wpnonce'] ?? '');
    $resultat = wp_verify_nonce($jeton, $action);
    if ($resultat === false && $arreter) {
        wp_die('-1', '', 403);
    }
    return $resultat;
}

function wp_send_json_success(mixed $donnees = null, ?int $statut = null): void {
    throw new RsnVeilleArretRequete(['success' => true, 'data' => $donnees], $statut ?? 200);
}

function wp_send_json_error(mixed $donnees = null, ?int $statut = null): void {
    throw new RsnVeilleArretRequete(['success' => false, 'data' => $donnees], $statut ?? 200);
}

function wp_enqueue_style(string $nom, string $source = '', array $dependances = [], string|bool|null $version = false, string $media = 'all'): void {
    $GLOBALS['rsn_veille_wp']['styles'][$nom] = compact('source', 'dependances', 'version');
}

function wp_enqueue_script(string $nom, string $source = '', array $dependances = [], string|bool|null $version = false, array|bool $pied = false): void {
    $GLOBALS['rsn_veille_wp']['scripts'][$nom] = compact('source', 'dependances', 'version', 'pied');
}

function wp_localize_script(string $nom, string $objet, array $donnees): bool {
    $GLOBALS['rsn_veille_wp']['localisations'][$nom][$objet] = $donnees;
    return true;
}

// --- Réglages (API Settings), téléversement, WP-Cron ---

/** Comme WordPress : le nettoyage est un filtre `sanitize_option_{option}` (un seul argument passé au rappel). */
function register_setting(string $groupe, string $option, array $arguments = []): void {
    $GLOBALS['rsn_veille_wp']['reglages'][$option] = ['groupe' => $groupe] + $arguments;
    if (isset($arguments['sanitize_callback']) && is_callable($arguments['sanitize_callback'])) {
        $rappel = $arguments['sanitize_callback'];
        add_filter('sanitize_option_' . $option, static fn(mixed $valeur): mixed => $rappel($valeur));
    }
}

function add_settings_error(string $reglage, string $code, string $message, string $type = 'error'): void {
    $GLOBALS['rsn_veille_wp']['erreurs_reglages'][] = ['setting' => $reglage, 'code' => $code, 'message' => $message, 'type' => $type];
}

/** Messages notés par `add_settings_error` (outil de test). */
function rsn_veille_bouchons_erreurs_reglages(): array {
    return $GLOBALS['rsn_veille_wp']['erreurs_reglages'];
}

/** Champs cachés du formulaire d'options.php : groupe, action, jeton « {groupe}-options ». */
function settings_fields(string $groupe): void {
    echo '<input type="hidden" name="option_page" value="' . esc_attr($groupe) . '">'
        . '<input type="hidden" name="action" value="update">'
        . '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce($groupe . '-options')) . '">';
}

/** Affiche les messages notés, comme des notices de wp-admin. */
function settings_errors(string $reglage = '', bool $nettoyer = false, bool $masquer = false): void {
    foreach ($GLOBALS['rsn_veille_wp']['erreurs_reglages'] as $erreur) {
        if ($reglage === '' || $erreur['setting'] === $reglage) {
            echo '<div class="notice notice-' . esc_attr($erreur['type']) . '"><p>' . esc_html($erreur['message']) . '</p></div>';
        }
    }
}

function is_email(string $courriel): string|false {
    return filter_var($courriel, FILTER_VALIDATE_EMAIL) !== false ? $courriel : false;
}

function esc_textarea(string $texte): string {
    return htmlspecialchars($texte, ENT_QUOTES, 'UTF-8');
}

function get_current_user_id(): int {
    return 1;
}

/** Comme WordPress : sans jeton valide dans `$_REQUEST['_wpnonce']`, la requête s'arrête (403). */
function check_admin_referer(string|int $action = -1, string $champ = '_wpnonce'): int|false {
    $resultat = wp_verify_nonce($_REQUEST[$champ] ?? '', $action);
    if ($resultat === false) {
        wp_die('Le lien a expiré.', '', 403);
    }
    return $resultat;
}

/**
 * Imitation de wp_mail : le message est capturé en mémoire (`courriels`), jamais envoyé. Comme WordPress,
 * `phpmailer_init` reçoit l'objet PHPMailer : le texte alternatif posé par un rappel (`AltBody`) est noté.
 * `wp_mail_reussit` (vrai par défaut) est le résultat rendu.
 */
function wp_mail(string|array $a, string $sujet, string $message, string|array $entetes = '', string|array $pieces_jointes = []): bool {
    $phpmailer = new stdClass();
    do_action('phpmailer_init', $phpmailer);
    $GLOBALS['rsn_veille_wp']['courriels'][] = [
        'a' => $a, 'sujet' => $sujet, 'message' => $message, 'entetes' => $entetes, 'texte' => $phpmailer->AltBody ?? null,
    ];
    return (bool) $GLOBALS['rsn_veille_wp']['wp_mail_reussit'];
}

/** Compte les lancements (outil de test : `cron_lances`). */
function spawn_cron(int $horodatage = 0): bool {
    $GLOBALS['rsn_veille_wp']['cron_lances']++;
    return true;
}

/**
 * Pages (`post_type` « page ») dont le statut est dans `post_status` (liste ou chaîne séparée par des
 * virgules, « publish » par défaut), triées par titre.
 *
 * @return WP_Post[]
 */
function get_pages(array $arguments = []): array {
    $statuts = $arguments['post_status'] ?? 'publish';
    $statuts = is_array($statuts) ? $statuts : array_map('trim', explode(',', (string) $statuts));
    $pages = array_values(array_filter(
        $GLOBALS['rsn_veille_wp']['posts'],
        static fn(WP_Post $p): bool => $p->post_type === 'page' && in_array($p->post_status, $statuts, true)
    ));
    usort($pages, static fn(WP_Post $a, WP_Post $b): int => strcmp($a->post_title, $b->post_title));
    return array_map(static fn(WP_Post $p): WP_Post => clone $p, $pages);
}

// --- Public : raccourcis, REST, réécriture (imitation de shortcodes.php, rest-api.php, rewrite.php) ---

if (!function_exists('_x')) {
    function _x(string $texte, string $contexte, string $domaine = 'default'): string {
        return $texte;
    }
}

function wp_json_encode(mixed $valeur, int $drapeaux = 0, int $profondeur = 512): string|false {
    return json_encode($valeur, $drapeaux, $profondeur);
}

function add_shortcode(string $nom, callable $rappel): void {
    $GLOBALS['rsn_veille_wp']['raccourcis'][$nom] = $rappel;
}

/** Comme WordPress : attributs connus seulement, valeurs par défaut pour les absents, filtre `shortcode_atts_{nom}`. */
function shortcode_atts(array $defauts, mixed $attributs, string $nom = ''): array {
    $attributs = (array) $attributs;
    $resultat = [];
    foreach ($defauts as $cle => $defaut) {
        $resultat[$cle] = array_key_exists($cle, $attributs) ? $attributs[$cle] : $defaut;
    }
    return $nom !== '' ? apply_filters('shortcode_atts_' . $nom, $resultat, $defauts, $attributs, $nom) : $resultat;
}

/** Raccourci `[nom]` ou `[nom attributs]` présent dans le texte (et enregistré, comme WordPress). */
function has_shortcode(string $texte, string $nom): bool {
    return isset($GLOBALS['rsn_veille_wp']['raccourcis'][$nom]) && preg_match('/\[' . preg_quote($nom, '/') . '(?=[\s\]\/])/', $texte) === 1;
}

/** Version simple : `[nom cle="valeur" …]` seulement. */
function do_shortcode(string $texte): string {
    return (string) preg_replace_callback('/\[([a-z_]+)((?:\s+[a-z_]+="[^"]*")*)\s*\]/', static function (array $m): string {
        $rappel = $GLOBALS['rsn_veille_wp']['raccourcis'][$m[1]] ?? null;
        if ($rappel === null) {
            return $m[0];
        }
        preg_match_all('/([a-z_]+)="([^"]*)"/', $m[2], $paires, PREG_SET_ORDER);
        $attributs = [];
        foreach ($paires as $paire) {
            $attributs[$paire[1]] = $paire[2];
        }
        return (string) $rappel($attributs === [] ? '' : $attributs, '', $m[1]);
    }, $texte);
}

function get_queried_object(): mixed {
    return $GLOBALS['rsn_veille_wp']['objet_demande'];
}

function is_singular(mixed $types = ''): bool {
    return $GLOBALS['rsn_veille_wp']['objet_demande'] instanceof WP_Post;
}

function __return_true(): bool {
    return true;
}

class WP_REST_Response {
    /** @var array<string,string> */
    public array $headers = [];

    public function __construct(public mixed $data = null, public int $status = 200) {
    }

    public function header(string $nom, string $valeur, bool $remplacer = true): void {
        $this->headers[$nom] = $valeur;
    }

    public function get_data(): mixed {
        return $this->data;
    }

    /** @return array<string,string> */
    public function get_headers(): array {
        return $this->headers;
    }
}

function rest_ensure_response(mixed $reponse): WP_REST_Response {
    return $reponse instanceof WP_REST_Response ? $reponse : new WP_REST_Response($reponse);
}

function register_rest_route(string $espace, string $route, array $arguments = [], bool $remplacer = false): bool {
    $GLOBALS['rsn_veille_wp']['routes'][$espace . $route] = $arguments;
    return true;
}

function add_rewrite_rule(string $motif, string|array $requete, string $position = 'bottom'): void {
    $GLOBALS['rsn_veille_wp']['regles'][$motif] = ['requete' => $requete, 'position' => $position];
}

function flush_rewrite_rules(bool $dur = true): void {
    $GLOBALS['rsn_veille_wp']['regles_regenerees']++;
}

function get_query_var(string $nom, mixed $defaut = ''): mixed {
    return $GLOBALS['rsn_veille_wp']['variables_requete'][$nom] ?? $defaut;
}

// --- Formulaire public : sel, type réel des fichiers, téléversement et vignette ---

/** Sel déterministe (imitation, aucune sécurité). */
function wp_salt(string $schema = 'auth'): string {
    return 'sel-' . $schema;
}

/**
 * Comme WordPress : l'extension du nom doit être parmi `$mimes` et le type réel du contenu (finfo)
 * doit correspondre à celui de cette extension ; sinon `ext` et `type` sont faux.
 *
 * @return array{ext: string|false, type: string|false, proper_filename: string|false}
 */
function wp_check_filetype_and_ext(string $fichier, string $nom, ?array $mimes = null): array {
    $refus = ['ext' => false, 'type' => false, 'proper_filename' => false];
    $extension = strtolower(pathinfo($nom, PATHINFO_EXTENSION));
    $type = false;
    foreach ($mimes ?? [] as $extensions => $mime) {
        if ($extension !== '' && in_array($extension, explode('|', $extensions), true)) {
            $type = $mime;
            break;
        }
    }
    if ($type === false || !is_file($fichier)) {
        return $refus;
    }
    $reel = (new finfo(FILEINFO_MIME_TYPE))->file($fichier);
    return $reel === $type ? ['ext' => $extension, 'type' => $type, 'proper_filename' => false] : $refus;
}

/**
 * Imitation de `media_handle_upload` : note l'appel dans `televersements` et crée une pièce jointe ;
 * `televersement_erreur` (message) fait échouer le téléversement.
 */
function media_handle_upload(string $champ, int $parent, array $donnees = [], array $substitutions = ['test_form' => false]): int|WP_Error {
    $erreur = $GLOBALS['rsn_veille_wp']['televersement_erreur'] ?? null;
    if (is_string($erreur)) {
        return new WP_Error('upload_error', $erreur);
    }
    $id = wp_insert_post(['post_type' => 'attachment', 'post_status' => 'inherit', 'post_parent' => $parent, 'post_title' => (string) ($_FILES[$champ]['name'] ?? 'image')]);
    $GLOBALS['rsn_veille_wp']['televersements'][] = ['champ' => $champ, 'parent' => $parent, 'substitutions' => $substitutions, 'id' => $id];
    return $id;
}

function set_post_thumbnail(int $article, int $piece): int|bool {
    return update_post_meta($article, '_thumbnail_id', $piece);
}

function wp_get_attachment_image_url(int $piece, string $taille = 'thumbnail'): string|false {
    return isset($GLOBALS['rsn_veille_wp']['posts'][$piece]) ? 'https://exemple.test/wp-content/uploads/' . $piece . '-' . $taille . '.png' : false;
}

function get_post_thumbnail_id(int $article): int|false {
    return isset($GLOBALS['rsn_veille_wp']['posts'][$article]) ? (int) get_post_meta($article, '_thumbnail_id', true) : false;
}

/** Comme WordPress : supprime la pièce jointe et les métas `_thumbnail_id` qui la désignent ; noté dans `pieces_supprimees`. */
function wp_delete_attachment(int $piece, bool $definitif = false): WP_Post|false|null {
    $post = get_post($piece);
    if ($post === null || $post->post_type !== 'attachment') {
        return false;
    }
    foreach ($GLOBALS['rsn_veille_wp']['meta'] as $id => $metas) {
        if (isset($metas['_thumbnail_id']) && (int) get_post_meta((int) $id, '_thumbnail_id', true) === $piece) {
            delete_post_meta((int) $id, '_thumbnail_id');
        }
    }
    unset($GLOBALS['rsn_veille_wp']['posts'][$piece], $GLOBALS['rsn_veille_wp']['meta'][$piece]);
    $GLOBALS['rsn_veille_wp']['pieces_supprimees'][] = ['id' => $piece, 'definitif' => $definitif];
    return $post;
}

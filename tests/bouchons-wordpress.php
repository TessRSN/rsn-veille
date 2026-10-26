<?php
/**
 * Imitation de WordPress en mémoire pour les tests : ce n'est PAS une référence.
 *
 * Les comportements imités sont des hypothèses sur WordPress 6.x : la bascule « publish » → « future »
 * d'une date à venir, l'encodage kses (`&` → `&amp;`) des titres et résumés, la remise à maintenant de
 * la date d'une fiche à approuver sans `edit_date`, le déslashage des données, la comparaison exacte
 * d'un `meta_query` de type BINARY (et le « contient » de LIKE). La vérification de référence reste
 * `tests/manuel/verifier-cpt.php`, exécuté dans un vrai WordPress (`wp eval-file`).
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
    ];
    \RSN\Veille\TypeContenu::enregistrer();
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
    $GLOBALS['rsn_veille_wp']['actions'][$crochet][] = ['rappel' => $rappel, 'nb_arguments' => $nb_arguments];
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

function update_option(string $nom, mixed $valeur, mixed $chargement_auto = null): bool {
    $GLOBALS['rsn_veille_wp']['options'][$nom] = $valeur;
    $GLOBALS['rsn_veille_wp']['cache_options'][$nom] = $valeur;
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

function wp_generate_password(int $longueur): string {
    return substr(md5((string) mt_rand()), 0, $longueur);
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
    $date = ($article['post_date'] ?? '') !== '' ? $article['post_date'] : date('Y-m-d H:i:s');
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

    public function __construct(array $arguments) {
        $GLOBALS['rsn_veille_wp']['requetes'][] = $arguments;
        $ids = array_keys($GLOBALS['rsn_veille_wp']['posts']);
        sort($ids);
        foreach ($ids as $id) {
            $post = $GLOBALS['rsn_veille_wp']['posts'][$id];
            if ($post->post_type !== $arguments['post_type'] || !in_array($post->post_status, (array) $arguments['post_status'], true)) {
                continue;
            }
            foreach ($arguments['meta_query'] ?? [] as $clause) {
                if (!self::satisfait($id, $clause)) {
                    continue 2;
                }
            }
            $this->posts[] = ($arguments['fields'] ?? 'all') === 'ids' ? $id : clone $post;
            if (($arguments['posts_per_page'] ?? 10) > 0 && count($this->posts) >= $arguments['posts_per_page']) {
                break;
            }
        }
    }

    /** `=` (exact si BINARY, sinon insensible à la casse comme la collation MySQL) ou LIKE (« contient »). */
    private static function satisfait(int $id, array $clause): bool {
        foreach (array_map('unserialize', $GLOBALS['rsn_veille_wp']['meta'][$id][$clause['key']] ?? []) as $valeur) {
            $valeur = (string) $valeur;
            if (($clause['compare'] ?? '=') === 'LIKE') {
                if (stripos($valeur, (string) $clause['value']) !== false) {
                    return true;
                }
            } elseif (($clause['type'] ?? '') === 'BINARY' ? $valeur === $clause['value'] : strcasecmp($valeur, (string) $clause['value']) === 0) {
                return true;
            }
        }
        return false;
    }
}

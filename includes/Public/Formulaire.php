<?php
declare(strict_types=1);

namespace RSN\Veille\Public;

use RSN\Veille\Depot;
use RSN\Veille\EntrepotWordPress;
use RSN\Veille\Fiche;
use RSN\Veille\Pertinence;
use RSN\Veille\TypeContenu;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Formulaire public « Proposer un événement » : action AJAX `rsn_veille_proposer`, ouverte sans
 * connexion. La proposition devient une fiche à approuver (`pending`) de la file de wp-admin.
 *
 * Défenses : jeton `rsn_veille_proposer`, limite de 5 envois par heure et par adresse (hachée et
 * salée), champ piège `site_web`, textes sans balises et tronqués, URL http(s) seulement (jamais
 * chargée), image vérifiée sur son contenu réel (JPEG, PNG ou WebP, 3 Mo au plus). Le courriel du
 * proposant reste dans la méta privée `rsn_veille_courriel_proposant`, jamais publiée. Une proposition
 * ne modifie jamais une fiche existante : un doublon (même date, même début de titre) est seulement
 * signalé.
 */
final class Formulaire {
    public const ACTION = 'rsn_veille_proposer';
    public const NONCE = 'rsn_veille_proposer';
    /** Action AJAX qui rend un jeton neuf (page servie depuis un cache plus vieux que le jeton). */
    public const ACTION_JETON = 'rsn_veille_jeton';

    public const PREFIXE_DEBIT = 'rsn_veille_debit_';
    public const ENVOIS_PAR_HEURE = 5;
    private const FENETRE = 3600;

    /** Types d'image permis (clés au format de WordPress, pour `mimes`). */
    public const MIMES = ['jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    public const TAILLE_MAX = 3 * 1024 * 1024;

    private const META_COURRIEL = TypeContenu::PREFIXE . 'courriel_proposant';

    /** Champs texte lus dans la requête. */
    private const CHAMPS = ['titre', 'debut', 'fin', 'organisateur', 'format', 'lieu', 'url', 'resume', 'courriel', 'site_web'];

    private const FORMATS = ['Présentiel', 'Virtuel', 'Hybride'];

    /** Longueurs maximales, en caractères. */
    private const MAX = ['titre' => 200, 'organisateur' => 120, 'lieu' => 160, 'resume' => 1000];

    /** Accroche l'envoi et le renouvellement du jeton, avec et sans connexion. */
    public static function accrocher(): void {
        foreach ([self::ACTION => 'traiter', self::ACTION_JETON => 'jeton'] as $action => $methode) {
            add_action('wp_ajax_' . $action, [self::class, $methode]);
            add_action('wp_ajax_nopriv_' . $action, [self::class, $methode]);
        }
    }

    /**
     * Valide et nettoie les champs envoyés. Sans effet de bord.
     *
     * Champ piège `site_web` rempli : `fiche` nulle et aucune erreur (rejet silencieux). Erreurs : tableau
     * nom du champ => message. Le courriel (vide s'il est invalide) est rendu à part : il n'est pas une
     * propriété de la fiche.
     *
     * @param array<string,mixed> $champs
     * @return array{fiche: ?Fiche, erreurs: array<string,string>, courriel: string}
     */
    public static function valider(array $champs, string $aujourdhui): array {
        $brut = static fn(string $nom): string => isset($champs[$nom]) && is_scalar($champs[$nom]) ? trim((string) $champs[$nom]) : '';
        // Scripts et styles retirés avec leur contenu, puis balises retirées (Fiche::nettoyer_texte), puis troncature.
        $texte = static fn(string $nom): string => mb_substr(
            Fiche::nettoyer_texte((string) (preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', ' ', $brut($nom)) ?? '')),
            0,
            self::MAX[$nom]
        );

        if ($brut('site_web') !== '') {
            return ['fiche' => null, 'erreurs' => [], 'courriel' => ''];
        }

        $erreurs = [];
        $titre = $texte('titre');
        if ($titre === '') {
            $erreurs['titre'] = __('Titre obligatoire', 'rsn-veille');
        }
        $debut = $brut('debut');
        if ($debut === '') {
            $erreurs['debut'] = __('Date de début obligatoire', 'rsn-veille');
        } elseif (!self::date_valide($debut)) {
            $erreurs['debut'] = __('Date de début invalide', 'rsn-veille');
        } elseif ($debut < self::il_y_a_un_an($aujourdhui)) {
            $erreurs['debut'] = __("Date de début trop ancienne (un an au plus)", 'rsn-veille');
        }
        $organisateur = $texte('organisateur');
        if ($organisateur === '') {
            $erreurs['organisateur'] = __('Organisateur obligatoire', 'rsn-veille');
        }
        $courriel = self::courriel($brut('courriel'));
        if ($erreurs !== []) {
            return ['fiche' => null, 'erreurs' => $erreurs, 'courriel' => $courriel];
        }

        $fin = $brut('fin');
        $format = in_array($brut('format'), self::FORMATS, true) ? $brut('format') : 'Présentiel';
        $lieu = $texte('lieu');
        $resume = $texte('resume');

        $fiche = new Fiche();
        $fiche->type = 'evenement';
        $fiche->titre = $titre;
        $fiche->debut = $debut;
        $fiche->fin = self::date_valide($fin) && $fin >= $debut ? $fin : $debut;
        $fiche->organisateur = $organisateur;
        $fiche->format = $format;
        $fiche->lieu = $lieu;
        $fiche->region = Fiche::deviner_region('', $lieu, $format);
        $fiche->url = self::url($brut('url'));
        $fiche->resume = $resume;
        $fiche->langue = 'fr';
        $fiche->themes = (new Pertinence())->themes($titre . ' ' . $resume);
        $fiche->source = 'proposition';
        $fiche->niveau = 'M';
        $fiche->statut = 'a_approuver';
        $fiche->cle_source = 'proposition:' . uniqid('', true);
        $fiche->cles_source = [$fiche->cle_source];
        return ['fiche' => $fiche, 'erreurs' => [], 'courriel' => $courriel];
    }

    /**
     * Compte un envoi pour cette clé et dit si la limite est dépassée (le refus n'est pas compté).
     * Fenêtre fixe d'une heure à partir du premier envoi.
     */
    public static function debit_depasse(string $cle): bool {
        $maintenant = time();
        $etat = get_transient($cle);
        if (!is_array($etat) || !isset($etat['n'], $etat['debut']) || (int) $etat['debut'] + self::FENETRE <= $maintenant) {
            $etat = ['n' => 0, 'debut' => $maintenant];
        }
        if ((int) $etat['n'] >= self::ENVOIS_PAR_HEURE) {
            return true;
        }
        $etat['n'] = (int) $etat['n'] + 1;
        set_transient($cle, $etat, max(1, (int) $etat['debut'] + self::FENETRE - $maintenant));
        return false;
    }

    /** Nom du transient de débit d'une adresse : l'adresse n'est jamais stockée en clair. */
    public static function cle_debit(string $adresse): string {
        return self::PREFIXE_DEBIT . hash('sha256', $adresse . wp_salt('nonce'));
    }

    /**
     * Action AJAX `rsn_veille_proposer`. Réponses (`data`) : `{ok: true, message}` ; `{ok: false, erreurs}`
     * en 400 ; `{ok: false, message}` en 403 (jeton), 429 (débit) ou 500.
     */
    public static function traiter(): void {
        if (check_ajax_referer(self::NONCE, 'nonce', false) === false) {
            wp_send_json_error(['ok' => false, 'message' => __('Jeton de sécurité expiré : rechargez la page.', 'rsn-veille')], 403);
            return;
        }
        if (self::debit_depasse(self::cle_debit(self::adresse()))) {
            wp_send_json_error(['ok' => false, 'message' => __('Trop de propositions, réessayez plus tard.', 'rsn-veille')], 429);
            return;
        }

        $champs = [];
        foreach (self::CHAMPS as $nom) {
            $champs[$nom] = isset($_POST[$nom]) && is_string($_POST[$nom]) ? wp_unslash($_POST[$nom]) : '';
        }
        $resultat = self::valider($champs, \rsn_veille_aujourdhui());
        $fiche = $resultat['fiche'];
        if ($fiche === null && $resultat['erreurs'] === []) {
            // Champ piège : même réponse qu'un envoi réussi, rien n'est écrit.
            self::repondre_merci();
            return;
        }

        $erreurs = $resultat['erreurs'];
        $image = self::image_envoyee();
        if ($image !== null) {
            $erreur = self::verifier_image($image);
            if ($erreur !== '') {
                $erreurs['image'] = $erreur;
            }
        }
        if ($fiche === null || $erreurs !== []) {
            wp_send_json_error(['ok' => false, 'erreurs' => $erreurs], 400);
            return;
        }

        try {
            $deja = self::enregistrer($fiche, $resultat['courriel'], $image !== null);
        } catch (\Throwable $e) {
            error_log('rsn-veille : proposition non enregistrée : ' . $e->getMessage());
            wp_send_json_error(['ok' => false, 'message' => __("L'envoi a échoué. Réessayez plus tard.", 'rsn-veille')], 500);
            return;
        }
        if ($deja) {
            wp_send_json_success(['ok' => true, 'message' => __('Merci, cet événement est déjà dans la veille.', 'rsn-veille')]);
            return;
        }
        self::repondre_merci();
    }

    /**
     * Crée la fiche, puis la méta du courriel et l'image. Rend vrai, sans rien écrire, si l'événement est
     * déjà dans la veille (même date, même début de titre) : une proposition anonyme ne modifie jamais
     * une fiche existante (pas de fusion qui lui donnerait ses dates ou son URL).
     */
    private static function enregistrer(Fiche $fiche, string $courriel, bool $avec_image): bool {
        $entrepot = new EntrepotWordPress();
        if ($entrepot->trouver_par_dedoublonnage($fiche->cle_dedoublonnage()) !== null) {
            return true;
        }
        // Sans recherche par URL : une page d'agenda commune ne fait pas d'une proposition un doublon.
        (new Depot($entrepot))->integrer($fiche, false);
        $creee = $entrepot->trouver_par_cle_source($fiche->cle_source);
        if ($creee === null || $creee->id === null) {
            throw new \RuntimeException('Fiche introuvable après sa création.');
        }
        if ($courriel !== '') {
            update_post_meta($creee->id, self::META_COURRIEL, wp_slash($courriel));
        }
        if ($avec_image) {
            self::attacher_image($creee->id, $entrepot);
        }
        return false;
    }

    /** Action AJAX `rsn_veille_jeton` : un jeton neuf, pour une page servie depuis le cache. */
    public static function jeton(): void {
        wp_send_json_success(['ok' => true, 'nonce' => wp_create_nonce(self::NONCE)]);
    }

    /**
     * Vérifie un fichier de `$_FILES` : reçu sans erreur, 3 Mo au plus, contenu réel JPEG, PNG ou WebP
     * (extension et type détecté concordants, image lisible). Rend '' si l'image convient, sinon le message.
     *
     * @param array<string,mixed> $fichier
     */
    public static function verifier_image(array $fichier): string {
        $trop_lourde = __('Image trop lourde (3 Mo au plus).', 'rsn-veille');
        $mauvais_type = __('Image en JPEG, PNG ou WebP seulement.', 'rsn-veille');
        $erreur = $fichier['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($erreur === UPLOAD_ERR_INI_SIZE || $erreur === UPLOAD_ERR_FORM_SIZE) {
            return $trop_lourde;
        }
        $chemin = is_string($fichier['tmp_name'] ?? null) ? $fichier['tmp_name'] : '';
        if ($erreur !== UPLOAD_ERR_OK || $chemin === '' || !is_file($chemin)) {
            return __('Image non reçue, réessayez.', 'rsn-veille');
        }
        if ((int) ($fichier['size'] ?? 0) > self::TAILLE_MAX || (int) filesize($chemin) > self::TAILLE_MAX) {
            return $trop_lourde;
        }
        $nom = is_string($fichier['name'] ?? null) ? $fichier['name'] : '';
        $verification = wp_check_filetype_and_ext($chemin, $nom, self::MIMES);
        if (empty($verification['ext']) || !in_array($verification['type'] ?? '', self::MIMES, true)) {
            return $mauvais_type;
        }
        $infos = @getimagesize($chemin);
        if (!is_array($infos) || !in_array($infos['mime'] ?? '', self::MIMES, true)) {
            return $mauvais_type;
        }
        return '';
    }

    /** Le fichier `image` envoyé, ou null si le champ est absent ou vide. */
    private static function image_envoyee(): ?array {
        $fichier = $_FILES['image'] ?? null;
        if (!is_array($fichier) || !is_int($fichier['error'] ?? null) || $fichier['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        return $fichier;
    }

    /** Téléverse `image` dans la médiathèque et en fait la vignette (et l'image) de la fiche. Un échec laisse la fiche sans image. */
    private static function attacher_image(int $id, EntrepotWordPress $entrepot): void {
        if (!function_exists('media_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }
        // `mimes` : WordPress revérifie le type réel et refuse tout autre type que ces trois-là.
        $piece = media_handle_upload('image', 0, [], ['test_form' => false, 'mimes' => self::MIMES]);
        if (is_wp_error($piece)) {
            return;
        }
        set_post_thumbnail($id, (int) $piece);
        $url = wp_get_attachment_image_url((int) $piece, 'medium_large');
        if (is_string($url) && $url !== '') {
            $entrepot->mettre_a_jour($id, ['image_url' => $url]);
        }
    }

    private static function repondre_merci(): void {
        wp_send_json_success(['ok' => true, 'message' => __("Merci ! Votre proposition est dans la file d'approbation de l'équipe du RSN.", 'rsn-veille')]);
    }

    /** Adresse du visiteur (REMOTE_ADDR seulement : X-Forwarded-For se falsifie). */
    private static function adresse(): string {
        $adresse = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? trim($_SERVER['REMOTE_ADDR']) : '';
        return filter_var($adresse, FILTER_VALIDATE_IP) !== false ? $adresse : 'inconnue';
    }

    private static function date_valide(string $date): bool {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private static function il_y_a_un_an(string $aujourdhui): string {
        try {
            return (new \DateTimeImmutable($aujourdhui))->modify('-365 days')->format('Y-m-d');
        } catch (\Exception $e) {
            return $aujourdhui;
        }
    }

    /** URL http(s) avec un hôte, sans espace ni caractère de balisage ; sinon ''. */
    private static function url(string $url): string {
        if (strlen($url) > 2000 || preg_match('#^https?://[^\s/?\#<>"\'`]+[^\s<>"\'`]*$#i', $url) !== 1) {
            return '';
        }
        return $url;
    }

    /** Courriel de forme simple (`nom@domaine.tld`), 254 caractères au plus ; sinon ''. */
    private static function courriel(string $courriel): string {
        if (strlen($courriel) > 254 || preg_match('/^[^@\s<>"\'(),;:\\\\\[\]]+@[^@\s<>"\'(),;:\\\\\[\]]+\.[a-z0-9-]{2,}$/i', $courriel) !== 1) {
            return '';
        }
        return $courriel;
    }
}

<?php
declare(strict_types=1);

namespace RSN\Veille\Admin;

use RSN\Veille\Depot;
use RSN\Veille\EntrepotWordPress;
use RSN\Veille\Fiche;
use RSN\Veille\TypeContenu;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Métabox « Détails de la fiche » de l'éditeur du type `rsn_veille` (0.6.0).
 *
 * Montre et corrige le type (événement ou appel), les dates, la date limite, l'organisateur, le lien, le format,
 * le lieu, la ville, la langue et la catégorie. Un champ factuel changé ici (`Depot::CHAMPS_PROTEGEABLES`) est
 * ajouté à `Fiche::$champs_manuels` : la collecte de nuit ne le change plus. Une fiche créée par « Ajouter »
 * devient une fiche saisie à la main (`source = manuel`, clé `manuel:<ID>`).
 *
 * Les champs de l'autre type, masqués par `assets/admin-details.js`, sont envoyés et enregistrés tels quels.
 * Seule exception : le début. L'événement l'envoie dans `debut` (« Date de début »), l'appel dans `publie`
 * (« Publié le ») ; seul le champ du type coché compte, et la fin d'un appel vaut son début.
 */
final class MetaboxDetails {
    public const ID = 'rsn_veille_details';
    public const NONCE = 'rsn_veille_details';

    /** Nom du tableau des champs dans le formulaire de l'éditeur (`rsn_veille_details[<nom>]`). */
    public const CHAMP = 'rsn_veille_details';

    /** Champ caché du jeton propre à la métabox. */
    public const CHAMP_JETON = 'rsn_veille_details_jeton';

    /** Poignée du script de bascule. */
    public const RESSOURCE = 'rsn-veille-details';

    /** Source des fiches créées par « Ajouter une fiche ». */
    public const SOURCE_MANUELLE = 'manuel';

    /** Préfixe du transient (par utilisateur) des avis à montrer après l'enregistrement. */
    private const AVIS = 'rsn_veille_details_avis_';

    /**
     * Priorité sur `save_post_rsn_veille` : avant la purge des caches (`Collecte::purger_apres_ecriture`, 10),
     * pour que la page publique soit refaite avec les détails enregistrés.
     */
    private const PRIORITE = 5;

    private const TYPES = ['evenement', 'appel'];
    private const FORMATS = ['Présentiel', 'Virtuel', 'Hybride'];
    private const LANGUES = ['fr', 'en'];

    /** Accroche la métabox, son enregistrement, son script et l'avis de date invalide. */
    public static function accrocher(): void {
        add_action('add_meta_boxes', [self::class, 'enregistrer'], 10, 2);
        add_action('save_post_' . TypeContenu::TYPE, [self::class, 'enregistrer_envoi'], self::PRIORITE, 1);
        add_action('admin_enqueue_scripts', [self::class, 'charger_ressources']);
        add_action('admin_notices', [self::class, 'afficher_avis']);
    }

    /**
     * Ajoute la métabox dans la colonne principale, sous le texte (sur `add_meta_boxes`). Le titre porte
     * l'origine de la fiche et son état.
     */
    public static function enregistrer(mixed $type = '', mixed $post = null): void {
        if ($type !== TypeContenu::TYPE) {
            return;
        }
        $titre = esc_html__('Détails de la fiche', 'rsn-veille');
        if ($post instanceof \WP_Post) {
            $fiche = (new EntrepotWordPress())->obtenir($post->ID);
            if ($fiche !== null) {
                $mention = self::origine($fiche);
                $etat = self::etat($fiche, $post->post_status);
                $titre .= ' <span class="rsn-veille-details-origine">' . esc_html($etat === '' ? $mention : $mention . ' · ' . $etat) . '</span>';
            }
        }
        add_meta_box(self::ID, $titre, [self::class, 'rendre'], TypeContenu::TYPE, 'normal', 'high');
    }

    /** Affiche le contenu de la métabox pour la fiche éditée. */
    public static function rendre(\WP_Post $post): void {
        $fiche = (new EntrepotWordPress())->obtenir($post->ID) ?? new Fiche();
        echo self::html($fiche, self::origine($fiche));
    }

    /** Script de bascule, seulement sur l'éditeur des fiches (`admin_enqueue_scripts`). */
    public static function charger_ressources(mixed $crochet = ''): void {
        if (!in_array($crochet, ['post.php', 'post-new.php'], true)) {
            return;
        }
        $ecran = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!is_object($ecran) || ($ecran->post_type ?? '') !== TypeContenu::TYPE) {
            return;
        }
        wp_enqueue_script(self::RESSOURCE, RSN_VEILLE_URL . 'assets/admin-details.js', [], RSN_VEILLE_VERSION, true);
    }

    /** Origine en clair : « Proposée par le public », « Collectée : <source> » ou « Saisie à la main ». */
    public static function origine(Fiche $f): string {
        if ($f->est_proposition()) {
            return __('Proposée par le public', 'rsn-veille');
        }
        if ($f->source === self::SOURCE_MANUELLE || $f->source === '') {
            return __('Saisie à la main', 'rsn-veille');
        }
        /* translators: %s : nom de la source de veille (celui de la colonne Source de la file). */
        return sprintf(__('Collectée : %s', 'rsn-veille'), $f->source);
    }

    /** État de la fiche dans la file ; rien pour une fiche pas encore enregistrée. */
    private static function etat(Fiche $f, string $statut_wp): string {
        if ($statut_wp === 'auto-draft') {
            return '';
        }
        return match ($f->statut) {
            'publie' => __('publiée', 'rsn-veille'),
            'a_approuver' => __('à approuver', 'rsn-veille'),
            default => __('écartée', 'rsn-veille'),
        };
    }

    // --- Rendu ---

    /** Contenu de la métabox : type, champs communs et champs du type, note sur la protection, origine. */
    public static function html(Fiche $f, string $origine): string {
        $type = $f->type === 'appel' ? 'appel' : 'evenement';
        $proteges = array_values(array_intersect($f->champs_manuels, Depot::CHAMPS_PROTEGEABLES));
        $debut = (string) $f->debut;
        // Un appel sans échéance montre sa date limite réelle (une fin distincte du début) : elle est gardée.
        $echeance = (string) ($f->echeance ?? ($f->type === 'appel' ? $f->limite_appel() : null));

        $types = '';
        foreach (['evenement' => __('Événement', 'rsn-veille'), 'appel' => __('Appel', 'rsn-veille')] as $valeur => $libelle) {
            $types .= '<label><input type="radio" name="' . self::nom('type') . '" value="' . esc_attr($valeur) . '"'
                . ($valeur === $type ? ' checked' : '') . '> ' . esc_html($libelle) . '</label> ';
        }
        // Choix vide : format inconnu de la fiche (un appel collecté), gardé tel quel à l'enregistrement.
        $formats = ['' => '—', 'Présentiel' => __('Présentiel', 'rsn-veille'), 'Virtuel' => __('Virtuel', 'rsn-veille'), 'Hybride' => __('Hybride', 'rsn-veille')];

        $lignes = '<tr><th scope="row">' . esc_html__('Type', 'rsn-veille') . '</th><td><fieldset class="rsn-veille-details-type">'
            . '<legend class="screen-reader-text">' . esc_html__('Type', 'rsn-veille') . '</legend>' . rtrim($types) . '</fieldset></td></tr>'
            . self::ligne('', 'organisateur', __('Organisateur', 'rsn-veille'), self::entree('organisateur', 'text', $f->organisateur), $proteges)
            . self::ligne('appel', 'echeance', __('Date limite', 'rsn-veille'), self::entree('echeance', 'date', $echeance), $proteges, '', $type)
            . self::ligne('appel', 'categorie', __('Catégorie', 'rsn-veille'), self::entree('categorie', 'text', $f->categorie, __('Ex. : Appel de financement', 'rsn-veille')), $proteges, __('Vide : « Appel ».', 'rsn-veille'), $type)
            . self::ligne('appel', 'publie', __('Publié le', 'rsn-veille'), self::entree('publie', 'date', $debut), $proteges, __("Date de l'annonce.", 'rsn-veille'), $type)
            . self::ligne('evenement', 'debut', __('Date de début', 'rsn-veille'), self::entree('debut', 'date', $debut), $proteges, '', $type)
            . self::ligne('evenement', 'fin', __('Date de fin', 'rsn-veille'), self::entree('fin', 'date', (string) $f->fin), $proteges, '', $type)
            . self::ligne('evenement', 'heure', __('Heure', 'rsn-veille'), self::entree('heure', 'time', $f->heure), $proteges, __('Facultative.', 'rsn-veille'), $type)
            . self::ligne('evenement', 'format', __('Format', 'rsn-veille'), self::liste('format', $formats, $f->format), $proteges, '', $type)
            . self::ligne('evenement', 'lieu', __('Lieu', 'rsn-veille'), self::entree('lieu', 'text', $f->lieu), $proteges, '', $type)
            . self::ligne('evenement', 'ville', __('Ville', 'rsn-veille'), self::entree('ville', 'text', $f->ville), $proteges, '', $type)
            . self::ligne('', 'langue', __('Langue', 'rsn-veille'), self::liste('langue', ['fr' => __('Français', 'rsn-veille'), 'en' => __('Anglais', 'rsn-veille')], $f->langue), $proteges)
            . self::ligne('', 'url', __("Lien d'information", 'rsn-veille'), self::entree('url', 'url', $f->url, 'https://'), $proteges);

        $recue = Dates::date_longue($f->cree_le);
        $pied = $origine === '' ? '' : '<p class="description rsn-veille-details-pied">' . esc_html($recue === ''
            ? $origine
            /* translators: 1 : origine de la fiche (« Proposée par le public »…), 2 : date longue. */
            : sprintf(__('%1$s · reçue le %2$s', 'rsn-veille'), $origine, $recue)) . '</p>';

        return '<style>.rsn-veille-details [hidden]{display:none}.rsn-veille-details .form-table th{width:150px}.rsn-veille-details-type label{margin-right:18px}'
            . '.rsn-veille-corrige{display:inline-block;margin-left:6px;padding:1px 6px;font-size:12px;color:#996800;background:#fcf9e8;border:1px solid #f0e6b5;border-radius:3px}'
            . '.rsn-veille-details-origine{font-weight:400;color:#646970;margin-left:8px}</style>'
            . '<div class="rsn-veille-details" id="rsn-veille-details">'
            . '<input type="hidden" name="' . esc_attr(self::CHAMP_JETON) . '" value="' . esc_attr(wp_create_nonce(self::NONCE)) . '">'
            . '<table class="form-table" role="presentation"><tbody>' . $lignes . '</tbody></table>'
            . '<div class="notice notice-info inline"><p>' . esc_html__('Une date, une heure, un lien, un lieu ou une ville corrigés ici ne sont plus changés par la collecte de nuit (pastille 🔒). Le titre et le texte ne le sont jamais.', 'rsn-veille') . '</p></div>'
            . $pied
            . '</div>';
    }

    /**
     * Une ligne du tableau. $pour : type dont c'est un champ ('' : commun) ; masquée d'emblée si ce n'est pas le
     * type coché. Pastille si le champ (le début pour « Publié le ») est corrigé à la main.
     *
     * @param string[] $proteges
     */
    private static function ligne(string $pour, string $nom, string $libelle, string $champ, array $proteges, string $aide = '', string $type = ''): string {
        $protege = in_array($nom === 'publie' ? 'debut' : $nom, $proteges, true);
        $attributs = $pour === '' ? '' : ' data-pour="' . esc_attr($pour) . '"' . ($pour !== $type ? ' hidden' : '');
        return '<tr' . $attributs . '><th scope="row"><label for="rsn-veille-details-' . esc_attr($nom) . '">' . esc_html($libelle) . '</label></th><td>'
            . $champ
            . ($protege ? ' <span class="rsn-veille-corrige"><span aria-hidden="true">🔒</span> ' . esc_html__('corrigé à la main', 'rsn-veille') . '</span>' : '')
            . ($aide === '' ? '' : '<p class="description">' . esc_html($aide) . '</p>')
            . '</td></tr>';
    }

    private static function entree(string $nom, string $type, string $valeur, string $exemple = ''): string {
        $classe = in_array($type, ['text', 'url'], true) ? ' class="regular-text"' : '';
        return '<input type="' . esc_attr($type) . '" id="rsn-veille-details-' . esc_attr($nom) . '" name="' . self::nom($nom) . '" value="' . esc_attr($valeur) . '"'
            . $classe . ($exemple === '' ? '' : ' placeholder="' . esc_attr($exemple) . '"') . '>';
    }

    /** @param array<string,string> $choix valeur => libellé */
    private static function liste(string $nom, array $choix, string $choisi): string {
        $html = '<select id="rsn-veille-details-' . esc_attr($nom) . '" name="' . self::nom($nom) . '">';
        foreach ($choix as $valeur => $libelle) {
            $valeur = (string) $valeur;
            $html .= '<option value="' . esc_attr($valeur) . '"' . ($valeur === $choisi ? ' selected' : '') . '>' . esc_html($libelle) . '</option>';
        }
        return $html . '</select>';
    }

    private static function nom(string $champ): string {
        return esc_attr(self::CHAMP . '[' . $champ . ']');
    }

    // --- Nettoyage (pur) ---

    /**
     * Champs à écrire d'après l'envoi de l'encadré : seulement ceux dont la valeur nettoyée diffère de la fiche,
     * plus `champs_manuels` (l'existant, plus les champs protégeables changés) s'il change, et la région si la
     * ville, le lieu ou le format changent. Un champ absent de l'envoi ou invalide garde sa valeur.
     *
     * @param array<string,mixed> $envoi Valeurs déslashées de `rsn_veille_details`.
     * @return array{champs: array<string,mixed>, erreurs: string[]}
     */
    public static function nettoyer(array $envoi, Fiche $actuelle): array {
        $lire = static fn(string $nom): ?string => isset($envoi[$nom]) && is_scalar($envoi[$nom]) ? trim((string) $envoi[$nom]) : null;
        // Texte, heure ou lien renvoyé tel qu'il est enregistré : pas un changement, même sous une forme que le
        // nettoyage écrirait autrement (valeur d'avant la 0.6.0) ; ni réécrit, ni protégé, ni signalé.
        $modifie = static fn(string $nom): ?string => ($valeur = $lire($nom)) !== null && $valeur !== trim((string) $actuelle->$nom) ? $valeur : null;
        $valeurs = [];
        $erreurs = [];

        $type = $lire('type');
        // Type hors liste : celui de la fiche (vide pour une fiche neuve, rien n'est alors écrit).
        $type = in_array($type, self::TYPES, true) ? $type : $actuelle->type;
        // Une fiche ne devient un événement qu'avec une date de début valide (envoyée, sinon la sienne).
        if ($type === 'evenement' && $actuelle->type !== 'evenement') {
            $debut_envoye = $lire('debut');
            if (!self::date_valide($debut_envoye ?? (string) $actuelle->debut)) {
                $type = $actuelle->type;
                $erreurs[] = __("Un événement doit avoir une date de début valide : sans elle, la fiche reste un appel.", 'rsn-veille');
            }
        }
        $valeurs['type'] = $type;
        $appel = $type === 'appel';

        foreach (['organisateur', 'categorie', 'lieu', 'ville'] as $nom) {
            $texte = $modifie($nom);
            if ($texte !== null) {
                $valeurs[$nom] = Fiche::nettoyer_texte($texte);
            }
        }
        $langue = $lire('langue');
        if (in_array($langue, self::LANGUES, true)) {
            $valeurs['langue'] = $langue;
        }
        $format = $lire('format');
        if ($format === '' || in_array($format, self::FORMATS, true)) {
            $valeurs['format'] = $format;
        }
        $heure = $modifie('heure');
        if ($heure === '') {
            $valeurs['heure'] = '';
        } elseif ($heure !== null && preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $heure, $h) === 1) {
            $valeurs['heure'] = sprintf('%02d:%s', (int) $h[1], $h[2]);
        }
        $url = $modifie('url');
        if ($url === '') {
            $valeurs['url'] = '';
        } elseif ($url !== null) {
            $propre = self::url($url);
            if ($propre === '') {
                $erreurs[] = __("Lien d'information refusé : seule une adresse http ou https est acceptée. L'ancien lien est gardé.", 'rsn-veille');
            } else {
                $valeurs['url'] = $propre;
            }
        }
        // Date limite : celle que l'encadré montre pour un appel sans échéance (sa fin, `limite_appel`), renvoyée
        // telle quelle, n'est pas un changement.
        $echeance = $lire('echeance');
        if ($echeance !== null && $actuelle->echeance === null && $echeance === (string) $actuelle->limite_appel()) {
            $echeance = null;
        }
        if ($echeance === '') {
            $valeurs['echeance'] = null;
        } elseif ($echeance !== null && self::date_valide($echeance)) {
            $valeurs['echeance'] = $echeance;
        }

        // Début : « Publié le » pour un appel (facultatif), « Date de début » pour un événement (obligatoire).
        $debut = $actuelle->debut;
        $saisi = $lire($appel ? 'publie' : 'debut');
        if ($saisi !== null) {
            if (self::date_valide($saisi)) {
                $debut = $saisi;
            } elseif ($appel && $saisi === '') {
                $debut = null;
            } elseif (!$appel) {
                $erreurs[] = __("Un événement doit avoir une date de début valide : l'ancienne date de début est gardée.", 'rsn-veille');
            }
        }
        $valeurs['debut'] = $debut;

        // Fin d'événement : vide => le début ; invalide => l'ancienne ; jamais avant le début.
        $fin = $actuelle->fin;
        $fin_saisie = $lire('fin');
        if ($fin_saisie === '') {
            $fin = $debut;
        } elseif ($fin_saisie !== null && self::date_valide($fin_saisie)) {
            $fin = $fin_saisie;
        }
        if ($fin === null || ($debut !== null && $fin < $debut)) {
            $fin = $debut;
        }
        if ($appel) {
            // La fin d'un appel suit son début, mais seulement si le type passe à appel ou si le début ou la date
            // limite changent : un appel collecté enregistré sans rien toucher garde sa fin (sa date limite réelle).
            $limite_changee = array_key_exists('echeance', $valeurs) && $valeurs['echeance'] !== $actuelle->echeance;
            if ($actuelle->type !== 'appel' || $debut !== $actuelle->debut || $limite_changee) {
                // Sans date limite, une fin distincte du début devient la date limite.
                $limite = array_key_exists('echeance', $valeurs) ? $valeurs['echeance'] : $actuelle->echeance;
                if ($limite === null && $fin !== null && $fin !== $debut) {
                    $valeurs['echeance'] = $fin;
                }
                $fin = $debut;
            } else {
                $fin = $actuelle->fin;
            }
        }
        $valeurs['fin'] = $fin;

        $champs = [];
        foreach ($valeurs as $nom => $valeur) {
            if ($valeur !== $actuelle->$nom) {
                $champs[$nom] = $valeur;
            }
        }
        if (isset($champs['ville']) || isset($champs['lieu']) || isset($champs['format'])) {
            $region = Fiche::deviner_region($valeurs['ville'] ?? $actuelle->ville, $valeurs['lieu'] ?? $actuelle->lieu, $valeurs['format'] ?? $actuelle->format);
            if ($region !== $actuelle->region) {
                $champs['region'] = $region;
            }
        }

        $existants = array_values(array_intersect($actuelle->champs_manuels, Depot::CHAMPS_PROTEGEABLES));
        $changes = array_values(array_intersect(Depot::CHAMPS_PROTEGEABLES, array_keys($champs)));
        $proteges = array_values(array_unique(array_merge($existants, $changes)));
        // Réécrite seulement si un champ s'ajoute (un ancien nom inconnu, ignoré à la lecture, ne force rien).
        if ($proteges !== $existants) {
            $champs['champs_manuels'] = $proteges;
        }
        return ['champs' => $champs, 'erreurs' => $erreurs];
    }

    private static function date_valide(string $date): bool {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** URL http(s) avec un hôte, sans identifiants (`@`), espace ni caractère de balisage, nettoyée ; sinon ''. */
    private static function url(string $url): string {
        if (strlen($url) > 2000 || preg_match('#^https?://([^\s/?\#<>"\'`]+)[^\s<>"\'`]*$#i', $url, $m) !== 1) {
            return '';
        }
        $hote = parse_url($url, PHP_URL_HOST);
        if (str_contains($m[1], '@') || !is_string($hote) || trim($hote, '.[]:') === '') {
            return '';
        }
        return esc_url_raw($url);
    }

    // --- Enregistrement ---

    /**
     * Rappel de `save_post_rsn_veille` : enregistre l'encadré par l'entrepôt. Rien sans le jeton de la métabox,
     * sans le droit de modifier la fiche, pendant une sauvegarde automatique ou pour une révision. Une fiche
     * neuve (sans clé de source) devient une fiche saisie à la main ; ces champs ne changent plus ensuite.
     */
    public static function enregistrer_envoi(int $post_id): void {
        $jeton = isset($_POST[self::CHAMP_JETON]) && is_string($_POST[self::CHAMP_JETON]) ? wp_unslash($_POST[self::CHAMP_JETON]) : '';
        if ($jeton === '' || !wp_verify_nonce($jeton, self::NONCE)) {
            return;
        }
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_autosave($post_id) !== false || wp_is_post_revision($post_id) !== false) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        $entrepot = new EntrepotWordPress();
        $actuelle = $entrepot->obtenir($post_id);
        if ($actuelle === null) {
            return;
        }
        $envoi = isset($_POST[self::CHAMP]) && is_array($_POST[self::CHAMP]) ? wp_unslash($_POST[self::CHAMP]) : [];

        $champs = [];
        $neuve = $actuelle->cle_source === '';
        if ($neuve) {
            $cle = self::SOURCE_MANUELLE . ':' . $post_id;
            $champs = [
                'source' => self::SOURCE_MANUELLE,
                'niveau' => 'M',
                'cle_source' => $cle,
                'cles_source' => [$cle],
                'manuel' => true,
                'cree_le' => \rsn_veille_aujourdhui(),
            ];
            if (!in_array($envoi['type'] ?? null, self::TYPES, true)) {
                $envoi['type'] = 'evenement';
            }
            // Événement créé sans date : le jour même (point de vigilance 4).
            if ($envoi['type'] === 'evenement' && trim((string) (is_scalar($envoi['debut'] ?? null) ? $envoi['debut'] : '')) === '') {
                $envoi['debut'] = \rsn_veille_aujourdhui();
            }
        }
        $resultat = self::nettoyer($envoi, $actuelle);
        $champs += $resultat['champs'];
        if ($neuve && !isset($champs['region'])) {
            $region = Fiche::deviner_region((string) ($champs['ville'] ?? $actuelle->ville), (string) ($champs['lieu'] ?? $actuelle->lieu), (string) ($champs['format'] ?? $actuelle->format));
            if ($region !== $actuelle->region) {
                $champs['region'] = $region;
            }
        }
        $erreurs = $resultat['erreurs'];

        if ($champs !== []) {
            // La mise à jour de la date du post relance save_post : l'encadré se désaccroche le temps de l'écriture.
            $rappel = [self::class, 'enregistrer_envoi'];
            $accroche = remove_action('save_post_' . TypeContenu::TYPE, $rappel, self::PRIORITE);
            try {
                $entrepot->mettre_a_jour($post_id, $champs);
            } catch (\RuntimeException | \InvalidArgumentException $e) {
                $erreurs[] = __("Les détails de la fiche n'ont pas pu être enregistrés.", 'rsn-veille') . ' ' . $e->getMessage();
            } finally {
                if ($accroche) {
                    add_action('save_post_' . TypeContenu::TYPE, $rappel, self::PRIORITE, 1);
                }
            }
        }
        if ($erreurs !== []) {
            set_transient(self::AVIS . get_current_user_id(), $erreurs, 5 * MINUTE_IN_SECONDS);
        }
    }

    /** Rappel de `admin_notices` : les avis du dernier enregistrement de l'utilisateur, une seule fois. */
    public static function afficher_avis(): void {
        $cle = self::AVIS . get_current_user_id();
        $avis = get_transient($cle);
        if (!is_array($avis)) {
            return;
        }
        delete_transient($cle);
        echo self::avis_html(array_map('strval', array_filter($avis, 'is_scalar')));
    }

    /** @param string[] $erreurs */
    public static function avis_html(array $erreurs): string {
        if ($erreurs === []) {
            return '';
        }
        $html = '<div class="notice notice-error is-dismissible"><p><strong>' . esc_html__('Détails de la fiche', 'rsn-veille') . '</strong></p>';
        foreach ($erreurs as $erreur) {
            $html .= '<p>' . esc_html($erreur) . '</p>';
        }
        return $html . '</div>';
    }
}

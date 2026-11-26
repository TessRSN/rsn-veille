<?php
declare(strict_types=1);

namespace RSN\Veille;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Proposition « par lien seulement » (0.19.0) : la personne n'a donné que l'adresse d'une page, l'équipe du RSN
 * remplit le reste. La fiche est créée « à approuver » avec un titre provisoire (l'adresse, sans son protocole),
 * sans organisateur et, pour un événement, sans date (un événement sans date ne passe jamais dans « Archivées »).
 * Elle porte une marque « à compléter », qui interdit de la publier telle quelle, par la file comme par l'éditeur
 * de WordPress (`retenir_publication`), et que l'enregistrement des détails lève quand rien ne manque plus.
 *
 * La marque, le titre provisoire, le mot de la personne et l'état de la lecture du titre sont des métas privées du
 * post, comme le courriel du proposant : jamais publiées, absentes des données de la page publique.
 *
 * Le formulaire public ne charge jamais l'adresse reçue. C'est l'ouverture de la file d'approbation, par une
 * personne de l'équipe, qui essaie une seule fois de lire le titre de la page (`lire_titres`). L'adresse, et
 * celle de chaque redirection, doit mener à une adresse IP publique sur le port 80 ou 443 (`adresse_sure`) ;
 * aucune protection anti-robots n'est contournée.
 */
final class ACompleter {
    /** « 1 » : proposition reçue par lien seulement, à compléter avant de la publier. */
    public const META = TypeContenu::PREFIXE . 'a_completer';
    /** Titre provisoire tel qu'il a été enregistré : tant que la fiche le porte, son titre manque. */
    public const META_TITRE_PROVISOIRE = TypeContenu::PREFIXE . 'titre_provisoire';
    /** Mot facultatif de la personne pour l'équipe. */
    public const META_MOT = TypeContenu::PREFIXE . 'mot_proposant';
    /** « 1 » : titre lu sur la page ; « 0 » : lecture essayée sans succès. Absente : pas encore essayée. */
    public const META_TITRE_LU = TypeContenu::PREFIXE . 'titre_lu';

    public const MOT_MAX = 300;
    public const TITRE_MAX = 200;
    private const TITRE_PROVISOIRE_MAX = 80;

    /** Lectures de titre essayées au plus à chaque ouverture de la file. */
    public const LECTURES_PAR_PAGE = 3;
    /** Secondes au-delà desquelles l'ouverture de la file n'essaie plus de nouvelle lecture. */
    public const BUDGET = 8.0;
    private const DELAI = 4;
    private const TAILLE_MAX = 300000;
    /** Redirections suivies au plus, chacune vérifiée comme l'adresse de départ. */
    private const REDIRECTIONS = 1;

    /** Débuts de titres servis par une page de refus ou d'erreur : pas le titre de l'événement. */
    private const TITRES_DE_REFUS = ['just a moment', 'attention required', 'access denied', 'accès refusé', 'forbidden', 'error', 'erreur', 'page not found', 'page introuvable', 'not found', '403', '404', '500', '502', '503', 'robot check', 'captcha', 'un instant', 'security check', 'are you a robot'];

    /** @var ?callable `fn(string $hote): string[]` : remplace le DNS (tests). */
    public static $resolveur = null;

    /** Accroche le garde-fou de l'éditeur : une fiche à compléter ne se publie par aucun chemin de WordPress. */
    public static function accrocher(): void {
        add_filter('wp_insert_post_data', [self::class, 'retenir_publication'], 10, 2);
    }

    // --- Règles (sans WordPress) ---

    /** Titre provisoire d'une proposition par lien : l'adresse sans protocole ni « www. », coupée à 80 caractères. */
    public static function titre_provisoire(string $url): string {
        $texte = (string) preg_replace('#^https?://(?:www\.)?#i', '', trim($url));
        $texte = rtrim($texte, '/');
        return mb_strlen($texte) > self::TITRE_PROVISOIRE_MAX ? mb_substr($texte, 0, self::TITRE_PROVISOIRE_MAX - 1) . '…' : $texte;
    }

    /**
     * Ce qui manque encore à une proposition par lien pour être publiée : `titre` (vide, ou resté le titre
     * provisoire), `organisateur`, `date` (un événement sans date de début ; la date limite d'un appel reste
     * facultative).
     *
     * @param string $provisoire Titre provisoire tel qu'il a été enregistré (`titre_provisoire_de`) : l'entrepôt
     *        peut l'avoir nettoyé, et l'adresse de la fiche peut avoir changé depuis.
     * @return string[]
     */
    public static function manque(Fiche $f, string $provisoire = ''): array {
        $manque = [];
        $titre = trim($f->titre);
        if ($titre === '' || $titre === self::titre_provisoire($f->url) || ($provisoire !== '' && $titre === trim($provisoire))) {
            $manque[] = 'titre';
        }
        if (trim($f->organisateur) === '') {
            $manque[] = 'organisateur';
        }
        if ($f->type !== 'appel' && trim((string) $f->debut) === '') {
            $manque[] = 'date';
        }
        return $manque;
    }

    /**
     * Titre d'une page HTML : la balise `og:title`, sinon `<title>` ; texte nettoyé, 200 caractères au plus. Vide
     * pour le titre d'une page de refus ou d'erreur (« Just a moment… », « 404 »), qui n'est pas celui de l'événement.
     */
    public static function titre_de_la_page(string $html): string {
        $titre = '';
        if (preg_match('#<meta\b[^>]*\bproperty\s*=\s*(["\'])og:title\1[^>]*>#i', $html, $balise) === 1
            && preg_match('#\bcontent\s*=\s*(["\'])(.*?)\1#is', $balise[0], $contenu) === 1) {
            $titre = Fiche::nettoyer_texte($contenu[2]);
        }
        if ($titre === '' && preg_match('#<title\b[^>]*>(.*?)</title>#is', $html, $m) === 1) {
            $titre = Fiche::nettoyer_texte($m[1]);
        }
        $debut = mb_strtolower($titre);
        foreach (self::TITRES_DE_REFUS as $refus) {
            if (str_starts_with($debut, $refus)) {
                return '';
            }
        }
        return mb_substr($titre, 0, self::TITRE_MAX);
    }

    /** Adresse ramenée à ce qui la distingue (hôte sans « www. », chemin sans barre finale, sans ancre), pour repérer deux envois du même lien. */
    public static function adresse_comparable(string $url): string {
        $texte = (string) preg_replace('#^https?://(?:www\.)?#i', '', trim($url));
        $texte = (string) preg_replace('/#.*$/', '', $texte);
        return mb_strtolower(rtrim($texte, '/'));
    }

    // --- Marque (métas du post) ---

    public static function est(int $id): bool {
        return $id > 0 && (string) get_post_meta($id, self::META, true) === '1';
    }

    /**
     * Marque la fiche « à compléter », garde son titre provisoire tel qu'il est enregistré et le mot de la
     * personne, s'il y en a un.
     */
    public static function marquer(int $id, string $mot = '', string $titre_provisoire = ''): void {
        update_post_meta($id, self::META, '1');
        if ($titre_provisoire !== '') {
            update_post_meta($id, self::META_TITRE_PROVISOIRE, wp_slash($titre_provisoire));
        }
        if ($mot !== '') {
            update_post_meta($id, self::META_MOT, wp_slash($mot));
        }
    }

    public static function lever(int $id): void {
        delete_post_meta($id, self::META);
    }

    public static function mot(int $id): string {
        $mot = get_post_meta($id, self::META_MOT, true);
        return is_string($mot) ? $mot : '';
    }

    public static function titre_provisoire_de(int $id): string {
        $titre = get_post_meta($id, self::META_TITRE_PROVISOIRE, true);
        return is_string($titre) ? $titre : '';
    }

    public static function titre_lu(int $id): bool {
        return (string) get_post_meta($id, self::META_TITRE_LU, true) === '1';
    }

    /** @return string[] Ce qui manque à la fiche enregistrée `$id` (`manque`, avec son titre provisoire enregistré). */
    public static function manque_de(int $id, Fiche $f): array {
        return self::manque($f, self::titre_provisoire_de($id));
    }

    /**
     * Filtre `wp_insert_post_data` : une fiche marquée « à compléter » ne passe pas à « publiée », quel que soit le
     * chemin (bouton « Publier » de l'éditeur, modification rapide, actions groupées, entrepôt) ; elle reste « en
     * attente ». La marque tombe à l'enregistrement des détails, et la fiche se publie ensuite depuis la file.
     *
     * @param mixed $donnees Champs du post sur le point d'être écrits.
     * @param mixed $envoi Tableau reçu par `wp_insert_post` (avec `ID` pour une mise à jour).
     * @return mixed
     */
    public static function retenir_publication(mixed $donnees, mixed $envoi = []): mixed {
        if (!is_array($donnees) || ($donnees['post_type'] ?? '') !== TypeContenu::TYPE || !in_array($donnees['post_status'] ?? '', ['publish', 'future'], true)) {
            return $donnees;
        }
        $id = is_array($envoi) ? (int) ($envoi['ID'] ?? 0) : 0;
        if (self::est($id)) {
            $donnees['post_status'] = 'pending';
        }
        return $donnees;
    }

    // --- Lecture du titre (file d'approbation, équipe seulement) ---

    /**
     * Essaie de lire le titre des propositions par lien qui ont encore leur titre provisoire et dont la lecture n'a
     * jamais été essayée : `LECTURES_PAR_PAGE` au plus, et plus aucune une fois `BUDGET` secondes écoulées. L'essai
     * est noté avant la requête (une page abandonnée ou deux ouvertures simultanées ne le relancent pas). Le titre
     * lu remplace le titre provisoire dans la fiche passée et dans l'entrepôt.
     *
     * @param Fiche[] $fiches Fiches de l'onglet affiché.
     * @param ?callable $obtenir `fn(string $url): string` : le HTML de la page, '' si elle ne se lit pas (tests).
     * @param ?callable $horloge `fn(): float` : secondes (tests) ; sinon `microtime(true)`.
     * @return int Nombre de titres lus.
     */
    public static function lire_titres(Entrepot $e, array $fiches, ?callable $obtenir = null, ?callable $horloge = null): int {
        $obtenir ??= [self::class, 'page'];
        $horloge ??= static fn(): float => microtime(true);
        $depart = (float) $horloge();
        $essais = 0;
        $lus = 0;
        foreach ($fiches as $fiche) {
            if ($essais >= self::LECTURES_PAR_PAGE || (float) $horloge() - $depart >= self::BUDGET) {
                break;
            }
            if (!$fiche instanceof Fiche) {
                continue;
            }
            $id = (int) $fiche->id;
            if ($id <= 0 || $fiche->url === '' || trim($fiche->titre) === '' || !self::est($id)
                || (string) get_post_meta($id, self::META_TITRE_LU, true) !== '' || !in_array('titre', self::manque_de($id, $fiche), true)) {
                continue;
            }
            $essais++;
            update_post_meta($id, self::META_TITRE_LU, '0');
            $titre = self::titre_de_la_page((string) $obtenir($fiche->url));
            if ($titre === '') {
                continue;
            }
            try {
                $e->mettre_a_jour($id, ['titre' => $titre]);
            } catch (\RuntimeException | \InvalidArgumentException $erreur) {
                continue;
            }
            update_post_meta($id, self::META_TITRE_LU, '1');
            $fiche->titre = $titre;
            $lus++;
        }
        return $lus;
    }

    /**
     * HTML d'une page donnée par le public, '' si elle ne se lit pas (erreur, autre chose que du HTML, refus du
     * site). Chaque adresse demandée, celle d'une redirection comprise, doit être sûre (`adresse_sure`) ;
     * WordPress la revérifie (`reject_unsafe_urls`) et ne suit aucune redirection de lui-même. Identité de
     * l'extension, aucune protection contournée.
     */
    private static function page(string $url): string {
        for ($saut = 0; $saut <= self::REDIRECTIONS; $saut++) {
            if (!self::adresse_sure($url)) {
                return '';
            }
            $reponse = wp_remote_get($url, [
                'timeout' => self::DELAI,
                'redirection' => 0,
                'reject_unsafe_urls' => true,
                'limit_response_size' => self::TAILLE_MAX,
                'user-agent' => 'RSN-Veille/' . RSN_VEILLE_VERSION . ' (+https://rsn.quebec)',
                'headers' => ['Accept' => 'text/html,application/xhtml+xml'],
            ]);
            if (is_wp_error($reponse)) {
                return '';
            }
            $code = (int) wp_remote_retrieve_response_code($reponse);
            if ($code >= 300 && $code < 400) {
                $suite = wp_remote_retrieve_header($reponse, 'location');
                $url = self::absolue($url, is_array($suite) ? (string) end($suite) : (string) $suite);
                if ($url === '') {
                    return '';
                }
                continue;
            }
            if ($code !== 200) {
                return '';
            }
            $type = wp_remote_retrieve_header($reponse, 'content-type');
            $type = is_array($type) ? (string) end($type) : (string) $type;
            if ($type !== '' && stripos($type, 'html') === false) {
                return '';
            }
            return (string) wp_remote_retrieve_body($reponse);
        }
        return '';
    }

    /**
     * Adresse qu'on peut demander depuis le serveur : http ou https, sans identifiants, port 80 ou 443 (ou
     * absent), et un hôte dont toutes les adresses IP (IPv4 et IPv6) sont publiques (`ip_publique`). Un hôte qui
     * ne se résout pas est refusé. Reste le cas d'un nom qui change d'adresse entre cette vérification et la
     * requête : WordPress revérifie l'hôte, et la réponse ne sert qu'à lire un titre montré à l'équipe.
     *
     * @param ?callable $resoudre `fn(string $hote): string[]` : adresses IP de l'hôte (tests) ; sinon le DNS.
     */
    public static function adresse_sure(string $url, ?callable $resoudre = null): bool {
        $p = parse_url($url);
        if (!is_array($p) || !in_array(strtolower((string) ($p['scheme'] ?? '')), ['http', 'https'], true) || isset($p['user']) || isset($p['pass'])) {
            return false;
        }
        if (isset($p['port']) && !in_array((int) $p['port'], [80, 443], true)) {
            return false;
        }
        $hote = strtolower(trim((string) ($p['host'] ?? ''), '[]'));
        if ($hote === '') {
            return false;
        }
        $resoudre ??= self::$resolveur ?? [self::class, 'resoudre'];
        $ips = filter_var($hote, FILTER_VALIDATE_IP) !== false ? [$hote] : $resoudre($hote);
        if (!is_array($ips) || $ips === []) {
            return false;
        }
        foreach ($ips as $ip) {
            if (!is_string($ip) || !self::ip_publique($ip)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Adresse IP publique. IPv4 : ni privée, ni locale, ni de liaison locale (169.254.0.0/16, métadonnées d'un
     * hébergeur), ni partagée (100.64.0.0/10), ni réservée, de documentation, d'essai ou de multidiffusion.
     * IPv6 : seulement l'unicast mondial (2000::/3), sans les plages qui enveloppent une adresse IPv4 (6to4
     * 2002::/16, Teredo 2001:0::/32) ni la documentation (2001:db8::/32). Tout le reste est refusé.
     */
    public static function ip_publique(string $ip): bool {
        $octets = @inet_pton($ip);
        if ($octets === false) {
            return false;
        }
        $o = array_map('ord', str_split($octets));
        if (count($o) === 4) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
            [$a, $b, $c] = $o;
            return !(
                $a === 0 || $a === 127 || $a >= 224
                || ($a === 100 && $b >= 64 && $b <= 127)
                || ($a === 169 && $b === 254)
                || ($a === 192 && $b === 0 && ($c === 0 || $c === 2))
                || ($a === 198 && ($b === 18 || $b === 19))
                || ($a === 198 && $b === 51 && $c === 100)
                || ($a === 203 && $b === 0 && $c === 113)
            );
        }
        if (($o[0] & 0xE0) !== 0x20) {
            return false;
        }
        $debut = ($o[0] << 8) | $o[1];
        $suite = ($o[2] << 8) | $o[3];
        return !($debut === 0x2002 || ($debut === 0x2001 && ($suite === 0x0000 || $suite === 0x0DB8)));
    }

    /** @return string[] Adresses IPv4 et IPv6 d'un hôte, vide s'il ne se résout pas. */
    private static function resoudre(string $hote): array {
        $ips = gethostbynamel($hote);
        $ips = is_array($ips) ? $ips : [];
        if (function_exists('dns_get_record')) {
            $aaaa = @dns_get_record($hote, DNS_AAAA);
            foreach (is_array($aaaa) ? $aaaa : [] as $enregistrement) {
                if (is_string($enregistrement['ipv6'] ?? null)) {
                    $ips[] = $enregistrement['ipv6'];
                }
            }
        }
        return $ips;
    }

    /** Adresse absolue d'une redirection : déjà absolue, sans protocole (« //hôte/… ») ou depuis la racine (« /… ») ; '' sinon. */
    private static function absolue(string $base, string $suite): string {
        $suite = trim($suite);
        if (preg_match('#^https?://#i', $suite) === 1) {
            return $suite;
        }
        $p = parse_url($base);
        if (!is_array($p) || !isset($p['scheme'], $p['host'])) {
            return '';
        }
        if (str_starts_with($suite, '//')) {
            return $p['scheme'] . ':' . $suite;
        }
        if (str_starts_with($suite, '/')) {
            return $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . $suite;
        }
        return '';
    }
}

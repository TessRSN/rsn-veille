<?php
declare(strict_types=1);

namespace RSN\Veille\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Image d'un billet de l'infolettre, dans un format que toutes les messageries affichent.
 *
 * Les images du site sont souvent en WebP, qu'Outlook sur ordinateur n'affiche pas, et bien plus larges que la
 * place qu'un billet leur donne (200 px). `adresse()` rend l'adresse d'une copie JPEG (PNG pour une image qui
 * peut être transparente) de 400 px de large, rangée dans `uploads/rsn-veille-infolettre/` et faite une seule
 * fois par image. Tout ce qui empêche la copie (image d'un autre site, format non pris en charge, dossier non
 * inscriptible, éditeur d'images absent) rend l'adresse d'origine : le billet garde son image.
 *
 * Les copies ne sont jamais effacées, pas même à la désinstallation : les infolettres déjà envoyées et leur
 * archive publique affichent ces fichiers. Une image remplacée donne une nouvelle copie, l'ancienne reste.
 *
 * `adresse()` et `copier()` lisent et écrivent dans le dossier des téléversements, avec l'éditeur d'images de
 * WordPress : essayés ici avec un éditeur simulé, à vérifier sur un vrai site (`tests/manuel/verifier-admin.md`).
 */
final class ImageCourriel {
    /** Largeur des copies : deux fois la largeur d'affichage d'un billet, pour les écrans à haute densité. */
    public const LARGEUR = 400;

    /** Dossier des copies, dans le dossier des téléversements. */
    public const DOSSIER = 'rsn-veille-infolettre';

    private const QUALITE_JPEG = 82;

    /** Durée (secondes) pendant laquelle une copie qui a échoué n'est pas retentée. */
    private const DELAI_ECHEC = 86400;

    /** Adresse de la copie pour courriel d'une image du site ; l'adresse reçue si la copie est impossible ou inutile. */
    public static function adresse(string $url): string {
        $url = trim($url);
        if ($url === '' || !function_exists('wp_upload_dir') || !function_exists('wp_get_image_editor') || !function_exists('wp_mkdir_p')) {
            return $url;
        }
        try {
            $envoi = wp_upload_dir(null, false);
            if (!is_array($envoi) || !empty($envoi['error'])) {
                return $url;
            }
            $base_url = (string) ($envoi['baseurl'] ?? '');
            $base_dir = rtrim((string) ($envoi['basedir'] ?? ''), '/\\');
            $relatif = self::chemin_relatif($url, $base_url);
            if ($relatif === null || $base_dir === '') {
                return $url;
            }
            $source = $base_dir . '/' . $relatif;
            if (!is_file($source) || !is_readable($source)) {
                return $url;
            }
            $extension = self::extension_copie($relatif, (string) file_get_contents($source, false, null, 0, 32));
            // Largeur lue dans l'en-tête du fichier, sans ouvrir l'image.
            $dimensions = @getimagesize($source);
            $largeur = is_array($dimensions) ? (int) $dimensions[0] : 0;
            if ($extension === null || $largeur <= 0) {
                return $url;
            }
            // Déjà lisible partout et pas plus large que nécessaire : l'image d'origine convient.
            if ($largeur <= self::LARGEUR && self::lisible_partout($relatif)) {
                return $url;
            }
            $nom = self::nom_copie($relatif, (int) filemtime($source), (int) filesize($source), $extension);
            $dossier = $base_dir . '/' . self::DOSSIER;
            if (is_file($dossier . '/' . $nom)) {
                return rtrim($base_url, '/') . '/' . self::DOSSIER . '/' . $nom;
            }
            // Une copie qui vient d'échouer n'est pas retentée à chaque affichage de la page.
            $temoin = 'rsn_veille_image_' . md5($nom);
            if (get_transient($temoin) !== false) {
                return $url;
            }
            if (!self::copier($source, $dossier, $nom, $extension, $largeur)) {
                set_transient($temoin, 1, self::DELAI_ECHEC);
                return $url;
            }
            return rtrim($base_url, '/') . '/' . self::DOSSIER . '/' . $nom;
        } catch (\Throwable $erreur) {
            return $url;
        }
    }

    /** Écrit la copie (`$dossier/$nom`) avec l'éditeur d'images de WordPress ; faux si elle n'a pas pu être faite. */
    private static function copier(string $source, string $dossier, string $nom, string $extension, int $largeur): bool {
        $editeur = wp_get_image_editor($source);
        if (is_wp_error($editeur)) {
            return false;
        }
        if ($largeur > self::LARGEUR && is_wp_error($editeur->resize(self::LARGEUR, null, false))) {
            return false;
        }
        if ($extension === 'jpg') {
            $editeur->set_quality(self::QUALITE_JPEG);
        }
        if (!wp_mkdir_p($dossier)) {
            return false;
        }
        $type = $extension === 'jpg' ? 'image/jpeg' : 'image/png';
        $ecrit = $editeur->save($dossier . '/' . $nom, $type);
        if (is_wp_error($ecrit) || !is_array($ecrit)) {
            return false;
        }
        // Une extension du site peut imposer un autre format de sortie (WebP), sous un autre nom : la copie ne
        // servirait à rien, et le fichier écrit est retiré.
        if (($ecrit['mime-type'] ?? '') !== $type || ($ecrit['file'] ?? '') !== $nom) {
            $ecrit_ou = is_string($ecrit['path'] ?? null) ? $ecrit['path'] : '';
            if ($ecrit_ou !== '' && dirname($ecrit_ou) === $dossier && is_file($ecrit_ou)) {
                wp_delete_file($ecrit_ou);
            }
            return false;
        }
        return is_file($dossier . '/' . $nom);
    }

    /**
     * Chemin d'une image dans le dossier des téléversements, d'après son adresse : `2026/07/photo-1024x683.webp`.
     * Null pour une image d'un autre site, un chemin qui sort du dossier, ou une copie déjà faite ici. Le
     * protocole (http ou https) ne compte pas.
     */
    public static function chemin_relatif(string $url, string $base_url): ?string {
        $sans_protocole = static fn(string $adresse): string => (string) preg_replace('#^https?:#i', '', trim($adresse));
        $base = rtrim($sans_protocole($base_url), '/') . '/';
        $adresse = (string) preg_replace('/[?#].*$/s', '', $sans_protocole($url));
        if ($base === '/' || !str_starts_with($base, '//') || strncasecmp($adresse, $base, strlen($base)) !== 0) {
            return null;
        }
        $relatif = rawurldecode(substr($adresse, strlen($base)));
        if ($relatif === '' || str_contains($relatif, "\0") || str_contains($relatif, '\\') || str_starts_with($relatif, '/')) {
            return null;
        }
        foreach (explode('/', $relatif) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }
        return str_starts_with($relatif, self::DOSSIER . '/') ? null : $relatif;
    }

    /**
     * Extension de la copie : `jpg` pour une photo (JPEG, WebP sans transparence), `png` pour ce qui peut être
     * transparent (PNG, WebP transparent d'après son en-tête, AVIF) : un fond transparent deviendrait noir en
     * JPEG. Null pour ce qui n'est pas copié (GIF, peut-être animé ; SVG ; autre).
     *
     * @param string $entete Premiers octets du fichier (une trentaine suffit) ; vide : WebP tenu pour opaque.
     */
    public static function extension_copie(string $relatif, string $entete = ''): ?string {
        return match (strtolower(pathinfo($relatif, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'jpg',
            'webp' => self::webp_transparent($entete) ? 'png' : 'jpg',
            'png', 'avif' => 'png',
            default => null,
        };
    }

    /**
     * Vrai si l'en-tête d'un fichier WebP annonce de la transparence : drapeau « alpha » du format étendu
     * (`VP8X`) ou du format sans perte (`VP8L`). Le format simple avec perte (`VP8 `) n'en a jamais.
     */
    public static function webp_transparent(string $entete): bool {
        if (strlen($entete) < 21 || substr($entete, 0, 4) !== 'RIFF' || substr($entete, 8, 4) !== 'WEBP') {
            return false;
        }
        return match (substr($entete, 12, 4)) {
            'VP8X' => (ord($entete[20]) & 0x10) !== 0,
            'VP8L' => strlen($entete) >= 25 && (ord($entete[24]) & 0x10) !== 0,
            default => false,
        };
    }

    /** Vrai pour un format que toutes les messageries affichent (JPEG, PNG). */
    public static function lisible_partout(string $relatif): bool {
        return in_array(strtolower(pathinfo($relatif, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png'], true);
    }

    /**
     * Nom du fichier de la copie : le nom de l'image, une empreinte de son chemin, de sa date et de sa taille
     * (une image remplacée donne une nouvelle copie), l'extension. « photo-1024x683-3f2a9c1e.jpg ».
     */
    public static function nom_copie(string $relatif, int $modifie, int $octets, string $extension): string {
        $nom = strtolower(pathinfo($relatif, PATHINFO_FILENAME));
        $nom = trim((string) preg_replace('/[^a-z0-9]+/', '-', $nom), '-');
        $nom = $nom === '' ? 'image' : substr($nom, 0, 60);
        return $nom . '-' . substr(md5($relatif . '|' . $modifie . '|' . $octets . '|' . self::LARGEUR), 0, 8) . '.' . $extension;
    }
}

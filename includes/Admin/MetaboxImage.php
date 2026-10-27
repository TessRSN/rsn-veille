<?php
declare(strict_types=1);

namespace RSN\Veille\Admin;

use RSN\Veille\TypeContenu;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Métabox « Image de la fiche » de l'éditeur du type `rsn_veille`.
 *
 * Montre l'image de la source (`image_url`, réécrite par la collecte chaque nuit) et rappelle que la
 * seule image durable est l'image mise en avant de WordPress, qui prime à l'affichage. N'écrit rien.
 */
final class MetaboxImage {
    public const ID = 'rsn_veille_image';

    /** Accroche l'ajout de la métabox à l'éditeur. */
    public static function accrocher(): void {
        add_action('add_meta_boxes', [self::class, 'enregistrer']);
    }

    /** Ajoute la métabox dans la colonne latérale de l'éditeur des fiches (sur `add_meta_boxes`). */
    public static function enregistrer(): void {
        add_meta_box(self::ID, __('Image de la fiche', 'rsn-veille'), [self::class, 'rendre'], TypeContenu::TYPE, 'side', 'default');
    }

    /** Affiche le contenu de la métabox pour la fiche éditée. */
    public static function rendre(\WP_Post $post): void {
        $image = get_post_meta($post->ID, TypeContenu::PREFIXE . 'image_url', true);
        echo self::html(is_string($image) ? $image : '');
    }

    /** Contenu de la métabox : l'image de la source (http ou https seulement) ou son absence, puis l'explication. */
    public static function html(string $image_url): string {
        $adresse = preg_match('#^https?://#i', trim($image_url)) === 1 ? esc_url($image_url) : '';
        if ($adresse !== '') {
            $haut = '<p><img src="' . $adresse . '" alt="" style="max-width:100%;height:auto"></p>';
        } else {
            $haut = '<p>' . esc_html__('Aucune image de la source', 'rsn-veille') . '</p>';
        }
        return $haut . '<p class="description">'
            . esc_html__("Pour la remplacer, choisissez une image mise en avant : elle prime et la collecte ne l'écrase jamais.", 'rsn-veille')
            . '</p>';
    }
}

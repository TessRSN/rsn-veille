<?php
declare(strict_types=1);

namespace RSN\Veille\Admin;

use RSN\Veille\Collecte;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * HTML de la page Réglages, depuis le contexte de `PageReglages::contexte` : état de la collecte,
 * import, formulaire du réglage. Toute valeur est échappée ici, sauf `champs_caches` (produit par
 * `settings_fields` de WordPress).
 */
final class RenduReglages {
    /** Préfixe du nom des champs : `rsn_veille_reglages[…]`. */
    private const NOM = Collecte::OPTION_REGLAGES;

    /** État de la collecte et bouton « Lancer la collecte maintenant ». */
    public static function collecte_html(array $c): string {
        $derniere = (int) $c['derniere'] > 0 ? Dates::date_heure(wp_date('Y-m-d H:i', (int) $c['derniere'])) : '';
        $prochaine = $c['prochaine'] !== false ? Dates::date_heure(wp_date('Y-m-d H:i', (int) $c['prochaine'])) : '';
        $html = '<h2>' . esc_html__('Collecte', 'rsn-veille') . '</h2><ul class="rsn-veille-etat">'
            . '<li>' . esc_html__('Dernière collecte terminée :', 'rsn-veille') . ' <strong>'
            . esc_html($derniere !== '' ? $derniere : __('aucune pour l’instant', 'rsn-veille')) . '</strong></li>'
            . '<li>' . esc_html__('Prochaine collecte planifiée :', 'rsn-veille') . ' <strong>'
            . esc_html($prochaine !== '' ? $prochaine : __('aucune', 'rsn-veille')) . '</strong></li></ul>';
        if (!empty($c['cron_desactive'])) {
            $html .= '<div class="notice notice-warning inline"><p>'
                . esc_html__('WP-Cron est désactivé (DISABLE_WP_CRON) : la tâche cron du serveur doit appeler wp-cron.php, sinon aucune collecte ne se fera.', 'rsn-veille')
                . '</p></div>';
        }
        return $html . '<form method="post" action="' . esc_url((string) $c['url_actions']) . '">'
            . self::champs_action(PageReglages::ACTION_COLLECTER)
            . '<p>' . esc_html__('Les sources actives sont collectées une à une, à deux minutes d’intervalle ; le Journal montre le résultat de chacune.', 'rsn-veille') . '</p>'
            . '<p><button type="submit" class="button">' . esc_html__('Lancer la collecte maintenant', 'rsn-veille') . '</button></p>'
            . '</form>';
    }

    /** Import d'un fichier JSON d'événements. */
    public static function import_html(array $c): string {
        return '<h2>' . esc_html__('Importer des événements', 'rsn-veille') . '</h2>'
            . '<form method="post" enctype="multipart/form-data" action="' . esc_url((string) $c['url_actions']) . '">'
            . self::champs_action(PageReglages::ACTION_IMPORTER)
            . '<p>' . esc_html__('Fichier JSON des événements recopiés des infolettres (2 Mo au plus). Chaque événement est publié avec le niveau M ; ceux qui existent déjà, comme les événements du RSN, sont fusionnés plutôt que recréés. Importer deux fois le même fichier ne crée rien de plus.', 'rsn-veille') . '</p>'
            . '<p><label for="rsn-veille-fichier">' . esc_html__('Fichier', 'rsn-veille') . '</label> '
            . '<input type="file" id="rsn-veille-fichier" name="fichier" accept=".json,application/json" required> '
            . '<button type="submit" class="button">' . esc_html__('Importer le fichier', 'rsn-veille') . '</button></p>'
            . '</form>';
    }

    /** Formulaire du réglage, envoyé à options.php. */
    public static function formulaire_html(array $c): string {
        return '<form method="post" action="' . esc_url((string) $c['url_options']) . '" class="rsn-veille-formulaire">'
            . $c['champs_caches']
            . self::sources_html($c['sources'])
            . self::pertinence_html($c)
            . self::acces_html($c['roles'], $c['reglages']['roles'])
            . self::courriel_html($c['reglages']['courriel'])
            . self::divers_html($c['reglages'], $c['pages'])
            . '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__('Enregistrer les réglages', 'rsn-veille') . '</button></p>'
            . '</form>';
    }

    /** @param array<string,array<string,mixed>> $sources Sources par défaut, réglages appliqués. */
    private static function sources_html(array $sources): string {
        $niveaux = [
            'A' => __('A : flux structuré avec dates', 'rsn-veille'),
            'C' => __('C : page web structurée', 'rsn-veille'),
            'M' => __('M : saisie manuelle', 'rsn-veille'),
            'B' => __('B : flux RSS', 'rsn-veille'),
        ];
        $lignes = '';
        foreach ($sources as $id => $s) {
            $id = (string) $id;
            $base = self::NOM . '[sources][' . $id . ']';
            $contenu = !empty($s['categorie_nouvelles']) ? __('Nouvelles (radar)', 'rsn-veille')
                : (($s['mode'] ?? '') === 'appels' ? __('Appels', 'rsn-veille') : __('Événements', 'rsn-veille'));
            $choix = '';
            foreach ($niveaux as $niveau => $libelle) {
                $choix .= '<option value="' . esc_attr($niveau) . '"' . ($niveau === $s['niveau'] ? ' selected' : '') . '>' . esc_html($libelle) . '</option>';
            }
            $lignes .= '<tr><th scope="row"><label for="rsn-veille-source-' . esc_attr($id) . '">' . esc_html((string) $s['nom']) . '</label>'
                . '<div class="rsn-veille-url">' . esc_html((string) $s['url']) . '</div></th>'
                . '<td>' . esc_html($contenu) . '</td>'
                . '<td><input type="checkbox" id="rsn-veille-source-' . esc_attr($id) . '" name="' . esc_attr($base . '[actif]') . '" value="1"' . (!empty($s['actif']) ? ' checked' : '') . '></td>'
                . '<td><label class="screen-reader-text" for="rsn-veille-niveau-' . esc_attr($id) . '">' . esc_html__('Niveau', 'rsn-veille') . '</label>'
                . '<select id="rsn-veille-niveau-' . esc_attr($id) . '" name="' . esc_attr($base . '[niveau]') . '">' . $choix . '</select></td></tr>';
        }
        return '<h2>' . esc_html__('Sources', 'rsn-veille') . '</h2>'
            . '<p class="description">' . esc_html__('Une source décochée n’est plus collectée. Le niveau décide quelle source l’emporte quand deux annoncent le même événement (A, puis C, puis M, puis B).', 'rsn-veille') . '</p>'
            . '<table class="widefat striped rsn-veille-sources"><thead><tr>'
            . '<th scope="col">' . esc_html__('Source (cocher pour activer)', 'rsn-veille') . '</th><th scope="col">' . esc_html__('Contenu', 'rsn-veille') . '</th>'
            . '<th scope="col">' . esc_html__('Active', 'rsn-veille') . '</th><th scope="col">' . esc_html__('Niveau', 'rsn-veille') . '</th>'
            . '</tr></thead><tbody>' . $lignes . '</tbody></table>';
    }

    private static function pertinence_html(array $c): string {
        return '<h2>' . esc_html__('Pertinence', 'rsn-veille') . '</h2>'
            . '<p class="description">' . esc_html__('Un mot par ligne, en minuscules. Une espace au début ou à la fin d’une ligne signifie « mot entier de ce côté » : « ia » entouré d’espaces trouve « IA » mais pas « mai ». Ces espaces sont gardées à l’enregistrement : ne les retirez pas.', 'rsn-veille') . '</p>'
            . '<table class="form-table" role="presentation">'
            . self::ligne_zone('mots_sante', __('Mots-clés santé', 'rsn-veille'), implode("\n", $c['mots_sante']), 12)
            . self::ligne_zone('mots_numerique', __('Mots-clés numérique', 'rsn-veille'), implode("\n", $c['mots_numerique']), 12)
            . self::ligne_zone('themes', __('Thèmes', 'rsn-veille'), NettoyageReglages::themes_en_texte($c['themes']), 14,
                __('Un thème par ligne : « Nom : mot1, mot2 ». Une espace sépare les mots après « : » et après chaque virgule ; une deuxième espace fait partie du mot (mot entier). L’ordre des lignes est l’ordre d’affichage. Zone vide : valeurs par défaut.', 'rsn-veille'))
            . '</table>';
    }

    /**
     * @param array<string,string> $roles nom => libellé
     * @param string[] $coches
     */
    private static function acces_html(array $roles, array $coches): string {
        $cases = '<input type="hidden" name="' . esc_attr(self::NOM . '[roles][]') . '" value="administrator">';
        foreach ($roles as $nom => $libelle) {
            if ($nom === 'administrator') {
                // Verrouillée : envoyée par le champ caché, personne ne perd l'accès.
                $cases .= '<label><input type="checkbox" value="administrator" checked disabled> ' . esc_html($libelle)
                    . ' <span class="description">' . esc_html__('(toujours)', 'rsn-veille') . '</span></label><br>';
                continue;
            }
            $cases .= '<label><input type="checkbox" name="' . esc_attr(self::NOM . '[roles][]') . '" value="' . esc_attr($nom) . '"'
                . (in_array($nom, $coches, true) ? ' checked' : '') . '> ' . esc_html($libelle) . '</label><br>';
        }
        return '<h2>' . esc_html__('Accès', 'rsn-veille') . '</h2><table class="form-table" role="presentation"><tr>'
            . '<th scope="row">' . esc_html__('Rôles qui gèrent la veille', 'rsn-veille') . '</th>'
            . '<td><fieldset><legend class="screen-reader-text">' . esc_html__('Rôles qui gèrent la veille', 'rsn-veille') . '</legend>' . $cases . '</fieldset></td>'
            . '</tr></table>';
    }

    /** @param array{actif: bool, destinataires: string[], jour: int} $courriel */
    private static function courriel_html(array $courriel): string {
        $jours = [__('dimanche', 'rsn-veille'), __('lundi', 'rsn-veille'), __('mardi', 'rsn-veille'), __('mercredi', 'rsn-veille'), __('jeudi', 'rsn-veille'), __('vendredi', 'rsn-veille'), __('samedi', 'rsn-veille')];
        $choix = '';
        foreach ($jours as $numero => $jour) {
            $choix .= '<option value="' . $numero . '"' . ($numero === $courriel['jour'] ? ' selected' : '') . '>' . esc_html($jour) . '</option>';
        }
        return '<h2>' . esc_html__('Courriel hebdomadaire', 'rsn-veille') . '</h2><table class="form-table" role="presentation">'
            . '<tr><th scope="row">' . esc_html__('Envoi', 'rsn-veille') . '</th><td><label><input type="checkbox" name="' . esc_attr(self::NOM . '[courriel][actif]') . '" value="1"'
            . ($courriel['actif'] ? ' checked' : '') . '> ' . esc_html__('Envoyer le courriel hebdomadaire de la veille', 'rsn-veille') . '</label></td></tr>'
            . self::ligne_zone('courriel][destinataires', __('Destinataires', 'rsn-veille'), implode("\n", $courriel['destinataires']), 4, __('Une adresse par ligne.', 'rsn-veille'))
            . '<tr><th scope="row"><label for="rsn-veille-jour">' . esc_html__('Jour d’envoi', 'rsn-veille') . '</label></th>'
            . '<td><select id="rsn-veille-jour" name="' . esc_attr(self::NOM . '[courriel][jour]') . '">' . $choix . '</select></td></tr>'
            . '</table>';
    }

    /** @param array<int,string> $pages id => titre */
    private static function divers_html(array $reglages, array $pages): string {
        $choisie = (int) $reglages['page_veille_id'];
        $choix = '<option value="0">' . esc_html__('— Aucune —', 'rsn-veille') . '</option>';
        if ($choisie > 0 && !isset($pages[$choisie])) {
            /* translators: %d : identifiant de la page. */
            $pages[$choisie] = sprintf(__('Page n° %d', 'rsn-veille'), $choisie);
        }
        foreach ($pages as $id => $titre) {
            $titre = $titre !== '' ? $titre : __('(sans titre)', 'rsn-veille');
            $choix .= '<option value="' . (int) $id . '"' . ((int) $id === $choisie ? ' selected' : '') . '>' . esc_html($titre) . '</option>';
        }
        return '<h2>' . esc_html__('Collecte et page publique', 'rsn-veille') . '</h2><table class="form-table" role="presentation">'
            . '<tr><th scope="row"><label for="rsn-veille-heure">' . esc_html__('Heure de la collecte quotidienne', 'rsn-veille') . '</label></th>'
            . '<td><input type="time" id="rsn-veille-heure" name="' . esc_attr(self::NOM . '[heure_collecte]') . '" value="' . esc_attr((string) $reglages['heure_collecte']) . '" required>'
            . '<p class="description">' . esc_html__('Heure du site (Montréal). La changer replanifie la collecte.', 'rsn-veille') . '</p></td></tr>'
            . '<tr><th scope="row"><label for="rsn-veille-page">' . esc_html__('Page Veille', 'rsn-veille') . '</label></th>'
            . '<td><select id="rsn-veille-page" name="' . esc_attr(self::NOM . '[page_veille_id]') . '">' . $choix . '</select>'
            . '<p class="description">' . esc_html__('Page qui affiche la veille : ses caches (LiteSpeed, Elementor) sont vidés à chaque changement de fiche.', 'rsn-veille') . '</p></td></tr>'
            . '</table>';
    }

    /** Ligne de tableau avec une zone de texte `rsn_veille_reglages[$cle]` (espaces gardées, police à chasse fixe). */
    private static function ligne_zone(string $cle, string $libelle, string $valeur, int $lignes, string $aide = ''): string {
        $id = 'rsn-veille-' . str_replace(['][', '_'], '-', $cle);
        return '<tr><th scope="row"><label for="' . esc_attr($id) . '">' . esc_html($libelle) . '</label></th><td>'
            . '<textarea id="' . esc_attr($id) . '" name="' . esc_attr(self::NOM . '[' . $cle . ']') . '" rows="' . $lignes . '" class="large-text code" spellcheck="false">'
            . esc_textarea($valeur) . '</textarea>'
            . ($aide !== '' ? '<p class="description">' . esc_html($aide) . '</p>' : '')
            . '</td></tr>';
    }

    /** Champs cachés d'un formulaire d'admin-post.php : action et jeton `rsn_veille_actions`. */
    private static function champs_action(string $action): string {
        return '<input type="hidden" name="action" value="' . esc_attr($action) . '">'
            . '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce(PageReglages::NONCE_ACTIONS)) . '">';
    }
}

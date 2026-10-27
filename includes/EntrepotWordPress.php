<?php
declare(strict_types=1);

namespace RSN\Veille;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Entrepôt des fiches dans WordPress : un post `rsn_veille` par fiche (voir `TypeContenu`).
 * Mêmes sémantiques qu'`EntrepotMemoire` : les fiches rendues sont des copies qui portent leur `id`,
 * et les recherches rendent la fiche la plus ancienne (plus petit identifiant).
 */
final class EntrepotWordPress implements Entrepot {
    /** Statuts WordPress des fiches : publiée, à approuver, écartée. */
    private const STATUTS_WP = ['publish', 'pending', 'draft'];

    /**
     * Statuts des recherches d'une fiche existante (clé de source, dédoublonnage, URL) : la corbeille en plus.
     * Une fiche mise à la corbeille depuis l'éditeur compte comme écartée : la collecte ne la recrée pas et
     * ne la republie pas. Elle n'est ni listée ni comptée.
     */
    private const STATUTS_RECHERCHE = ['publish', 'pending', 'draft', 'trash'];

    public function trouver_par_cle_source(string $cle): ?Fiche {
        return $cle === '' ? null : $this->premiere(TypeContenu::META_CLE, $cle);
    }

    public function trouver_par_dedoublonnage(string $cle): ?Fiche {
        return $cle === '' ? null : $this->premiere(TypeContenu::META_DEDOUBLONNAGE, $cle);
    }

    public function trouver_par_url(string $url): ?Fiche {
        // L'URL est cherchée sous la forme où elle est enregistrée.
        $url = $url === '' ? '' : (string) TypeContenu::nettoyer_meta('url', $url);
        if ($url === '') {
            return null;
        }
        // Une seule fiche, la plus ancienne de cette URL qui n'est pas une proposition (source différente ou
        // absente) : le coût ne dépend pas du nombre de propositions qui partagent l'URL.
        $arguments = self::requete(self::STATUTS_RECHERCHE);
        $arguments['posts_per_page'] = 1;
        $arguments['fields'] = 'ids';
        $arguments['meta_query'] = [
            'relation' => 'AND',
            ['key' => TypeContenu::PREFIXE . 'url', 'value' => $url, 'compare' => '=', 'type' => 'BINARY'],
            [
                'relation' => 'OR',
                ['key' => TypeContenu::PREFIXE . 'source', 'value' => Fiche::SOURCE_PROPOSITION, 'compare' => '!=', 'type' => 'BINARY'],
                ['key' => TypeContenu::PREFIXE . 'source', 'compare' => 'NOT EXISTS'],
            ],
        ];
        $ids = (new \WP_Query($arguments))->posts;
        return $ids === [] ? null : $this->obtenir((int) $ids[0]);
    }

    /** La fiche $id, ou null si ce n'est pas une fiche de veille. */
    public function obtenir(int $id): ?Fiche {
        $post = get_post($id);
        if (!$post instanceof \WP_Post || $post->post_type !== TypeContenu::TYPE) {
            return null;
        }
        return $this->vers_fiche($post);
    }

    public function creer(Fiche $f): int {
        $article = [
            'post_type' => TypeContenu::TYPE,
            'post_status' => TypeContenu::statut_wp($f->statut),
            'post_title' => sanitize_text_field($f->titre),
            'post_excerpt' => sanitize_textarea_field($f->resume),
            'post_content' => '',
            'post_name' => sanitize_title($f->titre),
            'comment_status' => 'closed',
            'ping_status' => 'closed',
        ] + self::champs_date($f->debut, $f->heure);
        $id = wp_insert_post(wp_slash($article), true);
        if (is_wp_error($id)) {
            throw new \RuntimeException('Création de la fiche impossible : ' . $id->get_error_message());
        }
        $id = (int) $id;

        foreach (array_keys(TypeContenu::METAS) as $nom) {
            if (property_exists($f, $nom)) {
                // Jour de création : celui de la fiche reçue, sinon aujourd'hui.
                $this->ecrire_meta($id, $nom, $nom === 'cree_le' ? ($f->cree_le ?? \rsn_veille_aujourdhui()) : $f->$nom);
            }
        }
        $this->ecrire_cles($id, $f->cles_source);
        // Clé calculée sur la fiche reçue, comme celle que le dépôt cherchera à la prochaine collecte.
        $this->ecrire_dedoublonnage($id, $f);
        // Thèmes en dernier : s'ils échouent, la fiche reste retrouvable par ses clés.
        $this->ecrire_themes($id, $f->themes);
        return $id;
    }

    public function mettre_a_jour(int $id, array $champs): void {
        $fiche = $this->fiche($id);
        // Tout est vérifié avant la première écriture.
        foreach ($champs as $nom => $valeur) {
            if ($nom === 'id' || !property_exists($fiche, $nom)) {
                throw new \InvalidArgumentException('Champ inconnu ou non modifiable : ' . $nom);
            }
            $fiche->$nom = $valeur;
        }

        $article = [];
        if (array_key_exists('titre', $champs)) {
            $article['post_title'] = sanitize_text_field($fiche->titre);
        }
        if (array_key_exists('resume', $champs)) {
            $article['post_excerpt'] = sanitize_textarea_field($fiche->resume);
        }
        if (array_key_exists('statut', $champs)) {
            $article['post_status'] = TypeContenu::statut_wp($fiche->statut);
        }
        if (array_key_exists('debut', $champs) || array_key_exists('heure', $champs)) {
            $article += self::champs_date($fiche->debut, $fiche->heure);
        }
        if ($article !== []) {
            // edit_date : sans lui, WordPress remet à maintenant la date d'une fiche à approuver.
            $resultat = wp_update_post(wp_slash($article + ['ID' => $id, 'edit_date' => true]), true);
            if (is_wp_error($resultat)) {
                throw new \RuntimeException('Mise à jour de la fiche ' . $id . ' impossible : ' . $resultat->get_error_message());
            }
        }

        foreach (array_keys($champs) as $nom) {
            if (isset(TypeContenu::METAS[$nom])) {
                $this->ecrire_meta($id, $nom, $fiche->$nom);
            }
        }
        if (array_key_exists('cles_source', $champs)) {
            $this->ecrire_cles($id, $fiche->cles_source);
        }
        if (array_key_exists('themes', $champs)) {
            $this->ecrire_themes($id, $fiche->themes);
        }
        if (array_key_exists('titre', $champs) || array_key_exists('debut', $champs) || array_key_exists('source', $champs)) {
            $this->ecrire_dedoublonnage($id, $fiche);
        }
    }

    public function lister(string $statut = 'publie', string $type = ''): array {
        if ($statut !== '' && !isset(TypeContenu::STATUTS[$statut])) {
            return [];
        }
        $arguments = self::requete($statut === '' ? self::STATUTS_WP : [TypeContenu::statut_wp($statut)]);
        $arguments['posts_per_page'] = -1;
        if ($type !== '') {
            $arguments['meta_query'] = [self::clause_type($type)];
        }
        return $this->charger($arguments);
    }

    public function lister_par_ids(array $ids): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        if ($ids === []) {
            // WordPress ignore un post__in vide : il rendrait toutes les fiches.
            return [];
        }
        $arguments = self::requete(self::STATUTS_WP);
        $arguments['post__in'] = $ids;
        $arguments['posts_per_page'] = count($ids);
        return $this->charger($arguments);
    }

    public function compter(): array {
        // Une requête groupée par statut (mise en cache par WordPress), sans charger de fiche.
        $nombres = wp_count_posts(TypeContenu::TYPE);
        $compteurs = [];
        foreach (['a_approuver', 'publie', 'ecarte'] as $statut) {
            $statut_wp = TypeContenu::statut_wp($statut);
            $compteurs[$statut] = (int) ($nombres->$statut_wp ?? 0);
        }
        $arguments = self::requete_absentes();
        $arguments['fields'] = 'ids';
        $arguments['posts_per_page'] = 1;
        $arguments['no_found_rows'] = false;
        $compteurs['a_verifier'] = (new \WP_Query($arguments))->found_posts;
        return $compteurs;
    }

    public function lister_absentes(): array {
        $arguments = self::requete_absentes();
        $arguments['posts_per_page'] = -1;
        return $this->charger($arguments);
    }

    public function lister_crees_depuis(string $date, string $statut = ''): array {
        if ($statut !== '' && !isset(TypeContenu::STATUTS[$statut])) {
            return [];
        }
        $arguments = self::requete($statut === '' ? self::STATUTS_WP : [TypeContenu::statut_wp($statut)]);
        $arguments['posts_per_page'] = -1;
        // Jour au format AAAA-MM-JJ : l'ordre des chaînes est celui des dates. Sans méta : pas de jointure, pas de fiche.
        $arguments['meta_query'] = [self::clause_au_moins('cree_le', $date)];
        return $this->charger($arguments);
    }

    public function lister_recentes(string $statut, int $max, string $type = ''): array {
        if (!isset(TypeContenu::STATUTS[$statut]) || $max <= 0) {
            return [];
        }
        $arguments = self::requete([TypeContenu::statut_wp($statut)]);
        $arguments['order'] = 'DESC';
        $arguments['posts_per_page'] = $max;
        if ($type !== '') {
            $arguments['meta_query'] = [self::clause_type($type)];
        }
        return $this->charger($arguments);
    }

    public function lister_pour_page(string $limite_evenements, string $aujourdhui, string $limite_appels): array {
        $evenements = self::requete(['publish']);
        $evenements['posts_per_page'] = -1;
        $evenements['meta_query'] = [
            'relation' => 'AND',
            self::clause_type('evenement'),
            [
                'relation' => 'OR',
                self::clause_au_moins('debut', $limite_evenements),
                self::clause_au_moins('fin', $limite_evenements),
                ['key' => TypeContenu::PREFIXE . 'debut', 'compare' => 'NOT EXISTS'],
            ],
        ];
        $appels = self::requete(['publish']);
        $appels['posts_per_page'] = -1;
        $appels['meta_query'] = [
            'relation' => 'AND',
            self::clause_type('appel'),
            [
                'relation' => 'OR',
                self::clause_au_moins('echeance', $aujourdhui),
                self::clause_au_moins('fin', $aujourdhui),
                self::clause_au_moins('debut', $limite_appels),
                self::clause_au_moins('cree_le', $limite_appels),
                [
                    'relation' => 'AND',
                    ['key' => TypeContenu::PREFIXE . 'debut', 'compare' => 'NOT EXISTS'],
                    ['key' => TypeContenu::PREFIXE . 'cree_le', 'compare' => 'NOT EXISTS'],
                ],
            ],
        ];
        $fiches = array_merge($this->charger($evenements), $this->charger($appels));
        usort($fiches, static fn(Fiche $a, Fiche $b): int => (int) $a->id <=> (int) $b->id);
        return $fiches;
    }

    /**
     * Fiches d'une requête, métas en cache et thèmes de toutes les fiches lus en une requête.
     *
     * @param array<string,mixed> $arguments
     * @return Fiche[]
     */
    private function charger(array $arguments): array {
        $arguments['update_post_meta_cache'] = true;
        $posts = (new \WP_Query($arguments))->posts;
        if ($posts === []) {
            return [];
        }

        // Thèmes de toutes les fiches en une requête, dans leur ordre d'enregistrement.
        $themes = array_fill_keys(array_map(static fn(\WP_Post $p): int => $p->ID, $posts), []);
        $termes = wp_get_object_terms(array_keys($themes), TypeContenu::TAXONOMIE, ['fields' => 'all_with_object_id', 'orderby' => 'term_order']);
        foreach (is_array($termes) ? $termes : [] as $terme) {
            $themes[(int) $terme->object_id][] = self::texte($terme->name);
        }

        $fiches = [];
        foreach ($posts as $post) {
            $fiches[] = $this->vers_fiche($post, $themes[$post->ID]);
        }
        return $fiches;
    }

    /** @return array<string,mixed> Requête des fiches publiées signalées absentes (méta `absent_depuis` non vide). */
    private static function requete_absentes(): array {
        $arguments = self::requete(['publish']);
        $arguments['meta_query'] = [['key' => TypeContenu::PREFIXE . 'absent_depuis', 'value' => '', 'compare' => '!=']];
        return $arguments;
    }

    /** @return array<string,string> Clause « type de fiche exactement $type ». */
    private static function clause_type(string $type): array {
        return ['key' => TypeContenu::PREFIXE . 'type', 'value' => $type, 'compare' => '=', 'type' => 'BINARY'];
    }

    /** @return array<string,string> Clause « date $nom (AAAA-MM-JJ) au moins $date » ; une méta absente ne la satisfait pas. */
    private static function clause_au_moins(string $nom, string $date): array {
        return ['key' => TypeContenu::PREFIXE . $nom, 'value' => $date, 'compare' => '>=', 'type' => 'CHAR'];
    }

    public function ids_publies_de_source(string $id_source): array {
        $prefixe = $id_source . ':';
        $arguments = self::requete(['publish']);
        $arguments['posts_per_page'] = -1;
        $arguments['fields'] = 'ids';
        $arguments['meta_query'] = [['key' => TypeContenu::META_CLE, 'value' => $prefixe, 'compare' => 'LIKE']];
        $ids = array_map('intval', (new \WP_Query($arguments))->posts);
        if ($ids === []) {
            return [];
        }
        // LIKE cherche « contient » : on garde les fiches dont une clé commence vraiment par le préfixe.
        update_meta_cache('post', $ids);
        return array_values(array_filter($ids, static function (int $id) use ($prefixe): bool {
            foreach ((array) get_post_meta($id, TypeContenu::META_CLE) as $cle) {
                if (str_starts_with((string) $cle, $prefixe)) {
                    return true;
                }
            }
            return false;
        }));
    }

    public function marquer_absent(int $id, string $depuis): void {
        $this->fiche($id);
        $this->ecrire_meta($id, 'absent_depuis', $depuis);
    }

    public function lever_absent(int $id): void {
        $this->fiche($id);
        $this->ecrire_meta($id, 'absent_depuis', null);
    }

    /**
     * Lit une fiche depuis son post, ses métadonnées et ses thèmes ($themes si déjà lus).
     *
     * @param string[]|null $themes
     */
    private function vers_fiche(\WP_Post $post, ?array $themes = null): Fiche {
        $donnees = [
            'id' => $post->ID,
            'titre' => self::texte($post->post_title),
            'resume' => self::texte($post->post_excerpt),
            'statut' => TypeContenu::statut_fiche($post->post_status),
        ];
        // Seules les métas présentes : une méta absente (fiche créée dans l'administration) laisse
        // la valeur par défaut de Fiche (langue fr, absences 0…).
        foreach (array_keys(TypeContenu::METAS) as $nom) {
            if (metadata_exists('post', $post->ID, TypeContenu::PREFIXE . $nom)) {
                $donnees[$nom] = get_post_meta($post->ID, TypeContenu::PREFIXE . $nom, true);
            }
        }
        if ($themes === null) {
            $termes = wp_get_object_terms($post->ID, TypeContenu::TAXONOMIE, ['fields' => 'names', 'orderby' => 'term_order']);
            $themes = is_array($termes) ? array_map(static fn(string $nom): string => self::texte($nom), $termes) : [];
        }
        // Fiche créée dans l'éditeur de WordPress (sans jour de création) : le jour de son post.
        if (!isset($donnees['cree_le']) && preg_match('/^\d{4}-\d{2}-\d{2}/', $post->post_date) === 1) {
            $donnees['cree_le'] = substr($post->post_date, 0, 10);
        }
        $donnees['themes'] = array_values($themes);
        $donnees['cles_source'] = is_array($donnees['cles_source'] ?? null) ? array_values($donnees['cles_source']) : [];
        if (!is_array($donnees['titres_source'] ?? null)) {
            $donnees['titres_source'] = [];
        }
        // Les champs vides et absents (dates, drapeau d'absence) redeviennent null dans depuis_tableau.
        return Fiche::depuis_tableau($donnees);
    }

    /** Copie de la première fiche (plus petit identifiant) dont la méta vaut exactement $valeur. */
    private function premiere(string $cle_meta, string $valeur): ?Fiche {
        $arguments = self::requete(self::STATUTS_RECHERCHE);
        $arguments['posts_per_page'] = 1;
        $arguments['fields'] = 'ids';
        // BINARY : comparaison exacte (la collation MySQL ignorerait la casse et les accents).
        $arguments['meta_query'] = [['key' => $cle_meta, 'value' => $valeur, 'compare' => '=', 'type' => 'BINARY']];
        $ids = (new \WP_Query($arguments))->posts;
        return $ids === [] ? null : $this->obtenir((int) $ids[0]);
    }

    /** La fiche $id (copie), ou une exception si ce n'est pas une fiche de veille. */
    private function fiche(int $id): Fiche {
        $fiche = $this->obtenir($id);
        if ($fiche === null) {
            throw new \InvalidArgumentException('Fiche inconnue : ' . $id);
        }
        return $fiche;
    }

    /**
     * Arguments communs des requêtes : sans pagination ni filtres d'autres extensions (WPML…).
     *
     * @param string[] $statuts
     * @return array<string,mixed>
     */
    private static function requete(array $statuts): array {
        return [
            'post_type' => TypeContenu::TYPE,
            'post_status' => $statuts,
            'fields' => 'all',
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
            'suppress_filters' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ];
    }

    /** Écrit une méta nettoyée ; null la supprime. */
    private function ecrire_meta(int $id, string $nom, mixed $valeur): void {
        $cle = TypeContenu::PREFIXE . $nom;
        if ($valeur === null) {
            delete_post_meta($id, $cle);
            return;
        }
        update_post_meta($id, $cle, wp_slash(TypeContenu::nettoyer_meta($nom, $valeur)));
    }

    /** Clé de dédoublonnage de la fiche ; aucune pour une proposition du public (jamais cible d'une fusion). */
    private function ecrire_dedoublonnage(int $id, Fiche $f): void {
        if ($f->est_proposition()) {
            delete_post_meta($id, TypeContenu::META_DEDOUBLONNAGE);
            return;
        }
        update_post_meta($id, TypeContenu::META_DEDOUBLONNAGE, wp_slash($f->cle_dedoublonnage()));
    }

    /** Une ligne `rsn_veille_cle` par clé de source. */
    private function ecrire_cles(int $id, array $cles): void {
        delete_post_meta($id, TypeContenu::META_CLE);
        foreach (TypeContenu::nettoyer_meta('cles_source', $cles) as $cle) {
            add_post_meta($id, TypeContenu::META_CLE, wp_slash($cle));
        }
    }

    /** Remplace les thèmes de la fiche (termes créés au besoin, ordre conservé). */
    private function ecrire_themes(int $id, array $themes): void {
        $noms = array_map(static fn($theme): string => sanitize_text_field(is_scalar($theme) ? (string) $theme : ''), $themes);
        $noms = array_values(array_unique(array_filter($noms, static fn(string $nom): bool => $nom !== '')));
        $resultat = wp_set_object_terms($id, $noms, TypeContenu::TAXONOMIE, false);
        if (is_wp_error($resultat)) {
            throw new \RuntimeException('Thèmes de la fiche ' . $id . ' impossibles à enregistrer : ' . $resultat->get_error_message());
        }
    }

    /**
     * Date du post : début à 00:00, ou à l'heure de la fiche. Rien si le début n'est pas une date valide.
     *
     * @return array<string,mixed>
     */
    private static function champs_date(?string $debut, string $heure): array {
        if ($debut === null || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $debut, $date)
            || !checkdate((int) $date[2], (int) $date[3], (int) $date[1])) {
            return [];
        }
        $moment = preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $heure, $h) ? sprintf('%02d:%s', (int) $h[1], $h[2]) : '00:00';
        $post_date = $debut . ' ' . $moment . ':00';
        return ['post_date' => $post_date, 'post_date_gmt' => get_gmt_from_date($post_date), 'edit_date' => true];
    }

    /**
     * Texte tel que saisi : WordPress enregistre titres, résumés et noms de termes avec leurs entités
     * HTML (`&amp;`), alors qu'une fiche porte du texte brut (échappé à l'affichage).
     */
    private static function texte(string $nom): string {
        return html_entity_decode($nom, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/bouchons-wordpress.php';

use PHPUnit\Framework\TestCase;
use RSN\Veille\EntrepotWordPress;
use RSN\Veille\Fiche;
use RSN\Veille\Public\Donnees;
use RSN\Veille\Public\Raccourci;

final class RaccourciTest extends TestCase {
    protected function setUp(): void {
        rsn_veille_bouchons_reinitialiser();
        Raccourci::reinitialiser();
        Raccourci::accrocher();
        do_action('init');
    }

    private function publier(array $champs = []): int {
        return (new EntrepotWordPress())->creer(Fiche::depuis_tableau(array_merge([
            'type' => 'evenement',
            'titre' => 'Colloque santé numérique',
            'debut' => '2026-11-12',
            'organisateur' => 'Obvia',
            'source' => 'Obvia : événements',
            'niveau' => 'C',
            'statut' => 'publie',
            'cle_source' => 'obvia:42',
            'cles_source' => ['obvia:42'],
            'url' => 'https://www.obvia.ca/evenements/colloque',
        ], $champs)));
    }

    /** Le JSON incorporé dans #rsn-veille-donnees, décodé. */
    private function donnees_incorporees(string $html): array {
        $this->assertSame(1, preg_match('#<script type="application/json" id="rsn-veille-donnees" data-rsn-veille-donnees>(.*?)</script>#s', $html, $m));
        $donnees = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($donnees);
        return $donnees;
    }

    public function test_rend_le_main_avec_les_donnees_et_sans_le_mode_equipe(): void {
        $this->publier();
        $html = do_shortcode('[rsn_veille]');

        $this->assertStringStartsWith('<div class="rsn-veille" lang="fr" role="region" aria-label="Agenda de l&#039;écosystème" data-section="tout" data-une="1">', trim($html));
        $this->assertStringNotContainsString('<main', $html, 'le gabarit du site a déjà son <main>');
        $donnees = $this->donnees_incorporees($html);
        $this->assertSame(['genere_le', 'langue', 'sources', 'items'], array_keys($donnees));
        $this->assertCount(1, $donnees['items']);
        $this->assertSame('Colloque santé numérique', $donnees['items'][0]['titre']);
        $this->assertArrayNotHasKey('niveau', $donnees['items'][0]);
        $this->assertArrayNotHasKey('source', $donnees['items'][0]);
        $this->assertStringNotContainsString('obvia:42', $html);

        foreach (['Mode équipe', 'mode-equipe', 'Prototype', 'prototype', "File d'approbation", 'id="file"', 'Actualités', 'infolettre"', 'btn-infolettre', 'Niveau', 'col-niv'] as $absent) {
            $this->assertStringNotContainsString($absent, $html, $absent);
        }
        $this->assertStringContainsString('id="btn-proposer"', $html);
        $this->assertStringContainsString('<section class="encadre" id="dialogue" hidden>', $html);
        $this->assertStringContainsString('id="vue-agenda"', $html);
        $this->assertStringNotContainsString('id="vue-appels"', $html, 'la liste des appels est dans la page agenda, pas dans un onglet');
        $this->assertStringContainsString('id="tbl-sources"', $html);
        $textes = [];
        $this->assertSame(1, preg_match('#<script type="application/json" id="rsn-veille-textes" data-rsn-veille-textes>(.*?)</script>#s', $html, $textes));
        $this->assertStringContainsString('<h2>Proposer un événement</h2>', $html);
        $this->assertArrayNotHasKey('envoi_non_branche', json_decode($textes[1], true));
        $this->assertStringNotContainsString('non encore branché', $html);
        $this->assertStringNotContainsString('Dans le prototype', $html);
    }

    public function test_formulaire_de_proposition_branche(): void {
        $html = Raccourci::rendre([]);

        $this->assertStringContainsString('<form id="form-proposition" method="post" enctype="multipart/form-data">', $html);
        foreach (['titre', 'debut', 'fin', 'organisateur', 'format', 'lieu', 'url', 'resume', 'courriel'] as $nom) {
            $this->assertStringContainsString('name="' . $nom . '"', $html, $nom);
        }
        $this->assertStringContainsString('name="image" type="file" accept="image/jpeg,image/png,image/webp"', $html);
        $this->assertSame(1, preg_match('#<div class="piege" aria-hidden="true">.*?<input name="site_web" type="text" tabindex="-1" autocomplete="off" aria-hidden="true">#s', $html));
        $this->assertStringContainsString('id="p-erreurs" role="alert"', $html);
        $this->assertStringContainsString('id="p-merci" role="status"', $html);
        $this->assertStringContainsString('Votre courriel sert seulement à vous répondre ; il n&#039;est jamais publié.', $html);

        $config = $GLOBALS['rsn_veille_wp']['localisations']['rsn-veille']['rsnVeillePublic'];
        $this->assertSame([
            'ajaxurl' => admin_url('admin-ajax.php'),
            'action' => 'rsn_veille_proposer',
            'action_jeton' => 'rsn_veille_jeton',
            'nonce' => 'jeton-rsn_veille_proposer',
        ], $config);
        $this->assertSame([['rsn_veille_proposer']], rsn_veille_bouchons_declenchements('litespeed_nonce'));

        // Un second rendu ne repasse pas la configuration.
        Raccourci::rendre([]);
        $this->assertCount(1, rsn_veille_bouchons_declenchements('litespeed_nonce'));
    }

    public function test_conteneur_et_champ_cache_portent_la_langue(): void {
        $html = Raccourci::rendre([]);
        $this->assertStringContainsString('<div class="rsn-veille" lang="fr" role="region"', $html);
        $this->assertStringContainsString('<input type="hidden" name="langue" value="fr">', $html);

        add_filter('wpml_current_language', static fn() => 'en');
        $html = Raccourci::rendre([]);
        $this->assertStringContainsString('<div class="rsn-veille" lang="en" role="region"', $html);
        $this->assertStringContainsString('<input type="hidden" name="langue" value="en">', $html);
    }

    public function test_bouton_jaune_et_lien_du_panneau_proposent_un_evenement_ou_un_appel(): void {
        $html = Raccourci::rendre([]);
        $this->assertSame(1, preg_match('#<button class="btn jaune" id="btn-proposer" type="button">(.*?)<svg#s', $html, $m));
        $this->assertSame('Faire une proposition', trim($m[1]));
        $this->assertStringContainsString('<button type="button" id="rsn-veille-panneau-proposer">Proposer un événement ou un appel</button>', $html);
    }

    public function test_formulaire_a_une_bascule_evenement_ou_appel(): void {
        $html = Raccourci::rendre([]);

        // Titre du formulaire, puis intro, puis « Vous proposez » avec deux boutons à bascule.
        $this->assertStringContainsString('<h2>Proposer un événement</h2>', $html);
        $this->assertStringContainsString('<span class="lab" id="lab-type">Vous proposez</span>', $html);
        $this->assertStringContainsString('<div class="seg" role="group" aria-labelledby="lab-type">', $html);
        $this->assertStringContainsString('<button type="button" id="p-type-evenement" data-type="evenement" aria-pressed="true">Un événement</button>', $html);
        $this->assertStringContainsString('<button type="button" id="p-type-appel" data-type="appel" aria-pressed="false">Un appel de financement ou de candidatures</button>', $html);
        $this->assertStringContainsString('<input type="hidden" name="type" id="p-type" value="evenement">', $html);
        $this->assertLessThan(strpos($html, 'id="lab-type"'), strpos($html, "Votre proposition entre dans la file"));
        $this->assertLessThan(strpos($html, 'class="champs"'), strpos($html, 'id="lab-type"'));

        // Date limite : champ d'appel, masqué et désactivé par défaut, avec son aide.
        $this->assertSame(1, preg_match('#<label data-pour="appel" hidden>.*?Date limite.*?<input id="p-limite" name="echeance" type="date" data-requis-pour="appel" disabled>.*?Dernier jour pour déposer\.#s', $html));
        // Champs d'événement : marqués, le début requis seulement pour un événement.
        foreach (['p-debut', 'p-fin', 'p-format', 'p-lieu'] as $id) {
            $this->assertMatchesRegularExpression('#<label data-pour="evenement">[^<]*<(input|select) id="' . $id . '"#', $html, $id);
        }
        $this->assertStringContainsString('<input id="p-debut" name="debut" type="date" required data-requis-pour="evenement">', $html);
        $this->assertStringContainsString('<input id="p-url" name="url" type="url" placeholder="https://" data-requis-pour="appel">', $html);
        // Libellés que le script échange.
        $this->assertStringContainsString('<span id="lab-orga">Organisateur</span>', $html);
        $this->assertStringContainsString('<span id="lab-url">Lien d&#039;information</span>', $html);
    }

    public function test_textes_de_la_bascule_pour_le_script(): void {
        $textes = Raccourci::textes();
        $attendus = [
            'titre_proposer_evenement' => 'Proposer un événement',
            'titre_proposer_appel' => 'Proposer un appel',
            'libelle_organisateur' => 'Organisateur',
            'libelle_organisme' => 'Organisme',
            'libelle_lien_info' => "Lien d'information",
            'libelle_lien_appel' => "Lien de l'appel",
            'placeholder_titre_evenement' => 'Ex. : Colloque sur la souveraineté numérique en santé',
            'placeholder_titre_appel' => 'Ex. : Appel à projets en santé numérique 2027',
        ];
        foreach ($attendus as $cle => $valeur) {
            $this->assertSame($valeur, $textes[$cle] ?? null, $cle);
        }
    }

    public function test_section_appels_sans_formulaire(): void {
        $html = Raccourci::rendre(['section' => 'appels']);
        $this->assertStringNotContainsString('form-proposition', $html);
        $this->assertStringNotContainsString('site_web', $html);
    }

    public function test_filtre_qui_organise_au_dessus_de_format(): void {
        $html = Raccourci::rendre([]);
        $type = strpos($html, 'id="f-type"');
        $qui = strpos($html, 'id="f-qui"');
        $mois = strpos($html, 'id="f-mois"');
        $format = strpos($html, 'id="f-format"');
        $this->assertNotFalse($type);
        $this->assertNotFalse($qui);
        $this->assertNotFalse($format);
        $this->assertLessThan($qui, $type);
        $this->assertLessThan($mois, $qui);
        $this->assertLessThan($format, $mois);
        $this->assertStringContainsString('<div class="rubs" id="f-qui"></div>', $html, 'rubriques, plus des étiquettes');
        $this->assertStringContainsString('<div class="rubs" id="f-type"></div>', $html);
        $this->assertStringNotContainsString('class="tags" id="f-qui"', $html);
        $this->assertStringContainsString('Qui organise', $html);
        $this->assertStringContainsString('Afficher', $html);
    }

    public function test_json_incorpore_echappe(): void {
        // Données telles qu'elles sortiraient d'une source mal nettoyée (le cache court-circuite l'entrepôt).
        $titre = 'Atelier </script><script>alert(1)</script> & « IA »';
        set_transient('rsn_veille_json_fr', ['genere_le' => '2026-10-25', 'sources' => [], 'items' => [
            ['type' => 'evenement', 'titre' => $titre, 'debut' => '2026-11-12', 'url' => 'https://exemple.test/?a=1&b=2'],
        ]], 3600);
        $html = Raccourci::rendre([]);

        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertSame(2, substr_count($html, '</script>'), 'seules les deux balises du gabarit ferment un script');
        $this->assertStringNotContainsString('&b=2', $html, '& encodé en \\u0026');
        $donnees = $this->donnees_incorporees($html);
        $this->assertSame($titre, $donnees['items'][0]['titre']);
        $this->assertSame('https://exemple.test/?a=1&b=2', $donnees['items'][0]['url']);
        $this->assertStringContainsString('« IA »', $html, 'Unicode lisible');
        $this->assertStringContainsString('https://exemple.test/', $html, 'barres obliques lisibles');
    }

    public function test_sections(): void {
        $agenda = Raccourci::rendre(['section' => 'agenda']);
        $this->assertStringContainsString('data-section="agenda"', $agenda);
        $this->assertStringContainsString('id="vue-agenda"', $agenda);
        $this->assertStringContainsString('id="btn-proposer"', $agenda);
        $this->assertStringNotContainsString('id="vue-appels"', $agenda);
        $this->assertStringNotContainsString('class="onglets"', $agenda);
        $this->assertStringNotContainsString('id="tbl-sources"', $agenda);

        Raccourci::reinitialiser();
        $appels = Raccourci::rendre(['section' => 'appels']);
        $this->assertStringContainsString('<div id="vue-appels">', $appels);
        $this->assertStringContainsString('data-section="appels"', $appels);
        $this->assertStringNotContainsString('id="vue-agenda"', $appels);
        $this->assertStringNotContainsString('id="btn-proposer"', $appels);

        Raccourci::reinitialiser();
        $this->assertStringContainsString('data-section="tout"', Raccourci::rendre(['section' => '<b>inconnue</b>']));
    }

    public function test_tout_contient_bandeau_alaune_limites_et_pas_d_onglets(): void {
        $html = Raccourci::rendre([]);

        $this->assertStringNotContainsString('class="onglets"', $html);
        $this->assertStringNotContainsString('data-onglet', $html);
        $this->assertStringContainsString('data-section="tout" data-une="1"', $html);
        $this->assertStringNotContainsString('id="vue-appels"', $html);

        $this->assertStringContainsString('<div class="bandeau">', $html);
        $this->assertStringContainsString('<a class="btn clair" href="webcal://exemple.test/veille.ics">', $html);
        $this->assertStringContainsString("S&#039;abonner au calendrier", $html);
        $this->assertStringContainsString('<button class="btn jaune" id="btn-proposer" type="button">', $html);
        $this->assertStringNotContainsString('class="actions"', $html);
        $this->assertStringNotContainsString('class="btn sobre" href', $html);
        $this->assertStringContainsString('<div class="fleches">', $html);
        $this->assertStringContainsString('id="lim-prec" aria-label="Dates limites précédentes"', $html);
        $this->assertStringContainsString('id="lim-suiv" aria-label="Dates limites suivantes"', $html);

        $this->assertStringContainsString('<section class="alaune" hidden', $html);
        $this->assertStringContainsString('À la une du RSN', $html);
        $this->assertStringContainsString('<a class="tout" href="#" id="lien-rsn">', $html);
        $this->assertStringContainsString('Tout ce que le RSN organise et finance →', $html);
        $this->assertStringContainsString('<div class="unes" id="unes"></div>', $html);
        $this->assertStringContainsString('<div class="limites" hidden>', $html);
        $this->assertStringContainsString('<div class="defile" id="limites"></div>', $html);
        $this->assertStringContainsString('<i class="appel"></i>', $html);
        $this->assertStringContainsString('id="tbl-sources"', $html);

        // Ordre : bandeau, à la une, formulaire, dates limites, agenda, sources.
        $ordre = ['class="bandeau"', 'class="alaune"', 'id="dialogue"', 'class="limites"', 'id="vue-agenda"', 'id="liste-agenda"', 'id="calendrier"', 'class="sources"'];
        $positions = array_map(static fn (string $marque): int|false => strpos($html, $marque), $ordre);
        foreach ($positions as $i => $position) {
            $this->assertNotFalse($position, $ordre[$i]);
            if ($i > 0) {
                $this->assertGreaterThan($positions[$i - 1], $position, $ordre[$i] . ' après ' . $ordre[$i - 1]);
            }
        }
    }

    public function test_abonnement_webcal_lien_google_et_copie_dans_le_bandeau(): void {
        foreach (['tout', 'agenda'] as $section) {
            Raccourci::reinitialiser();
            $html = Raccourci::rendre(['section' => $section]);
            $this->assertStringContainsString('<a class="btn clair" href="webcal://exemple.test/veille.ics">', $html, $section);
            $this->assertStringNotContainsString('href="https://exemple.test/veille.ics"', $html, $section . ' : plus de téléchargement direct');
            // Ligne « autres » sous les boutons, dans .act : Google Agenda (adresse webcal encodée) et copie.
            $this->assertMatchesRegularExpression('#<div class="act">\s*<div class="boutons">.*?</div>\s*<div class="autres">#s', $html, $section);
            $this->assertStringContainsString('<span>Google Agenda :</span>', $html, $section);
            $this->assertStringContainsString('<a href="https://calendar.google.com/calendar/render?cid=webcal%3A%2F%2Fexemple.test%2Fveille.ics" target="_blank" rel="noopener">ajouter</a>', $html, $section);
            $this->assertStringContainsString('<button type="button" class="copier" id="rsn-veille-copier" data-adresse="https://exemple.test/veille.ics">Copier l’adresse</button>', $html, $section);
            $this->assertStringContainsString('<span class="copie" id="rsn-veille-copie" role="status" hidden></span>', $html, $section);
        }
        Raccourci::reinitialiser();
        $appels = Raccourci::rendre(['section' => 'appels']);
        $this->assertStringNotContainsString('webcal://', $appels, 'pas de bandeau dans la section appels');
        $this->assertStringNotContainsString('rsn-veille-copier', $appels);
    }

    public function test_point_d_information_et_panneau_dans_le_bandeau_de_la_section_tout(): void {
        $html = Raccourci::rendre([]);

        // Bouton à la fin du paragraphe d'intro du bandeau.
        $this->assertStringContainsString('Digital Health Canada. Les appels de financement et de candidatures y figurent avec leur date limite.<button type="button" class="info-btn" id="rsn-veille-info" aria-expanded="false" aria-controls="rsn-veille-panneau">', $html);
        $this->assertStringContainsString('<span>Comment ça marche</span></button></p>', $html);
        // Panneau caché, juste après .tete, dans le .wrap du bandeau.
        $this->assertMatchesRegularExpression('#</div>\s*<div class="panneau" id="rsn-veille-panneau" hidden>\s*<button type="button" class="fermer" id="rsn-veille-fermer" aria-label="Fermer">×</button>\s*<h2 tabindex="-1">Un agenda tenu par une veille semi-automatique</h2>#s', $html);
        $this->assertStringContainsString('Chaque nuit, l’agenda lit les sites qui publient leurs événements de façon structurée :', $html);
        $this->assertStringContainsString('<li>des calendriers en ligne et des flux RSS ;</li>', $html);
        $this->assertStringContainsString('<li>des pages d’événements bien organisées ;</li>', $html);
        $this->assertStringContainsString('<li>les événements et les appels du RSN.</li>', $html);
        $this->assertStringContainsString('proposez-nous l’événement.</p>', $html);
        $this->assertStringContainsString('<a href="#sources">Voir les sources lues ↓</a>', $html);
        $this->assertStringContainsString('<button type="button" id="rsn-veille-panneau-proposer">Proposer un événement ou un appel</button>', $html);
        // La section des sources porte l'ancre.
        $this->assertStringContainsString('<section class="sources" id="sources">', $html);
        // Ordre : .tete, panneau, puis la fin du bandeau.
        $this->assertLessThan(strpos($html, 'id="rsn-veille-panneau"'), strpos($html, 'class="tete"'));
        $this->assertLessThan(strpos($html, 'class="alaune"'), strpos($html, 'id="rsn-veille-panneau"'));
    }

    public function test_pas_de_point_d_information_ni_de_panneau_sans_la_section_des_sources(): void {
        foreach (['agenda', 'appels'] as $section) {
            Raccourci::reinitialiser();
            $html = Raccourci::rendre(['section' => $section]);
            foreach (['rsn-veille-info', 'rsn-veille-panneau', 'info-btn', 'Voir les sources lues', 'id="sources"', 'aria-controls'] as $absent) {
                $this->assertStringNotContainsString($absent, $html, $section . ' : ' . $absent);
            }
        }
        Raccourci::reinitialiser();
        $this->assertStringContainsString('id="btn-proposer"', Raccourci::rendre(['section' => 'agenda']), 'le bandeau et son abonnement restent');
    }

    public function test_textes_du_script_pour_la_copie(): void {
        $this->assertSame('Adresse copiée', Raccourci::textes()['adresse_copiee']);
    }

    public function test_rendu_a_des_div_equilibres_pour_chaque_section(): void {
        foreach (['tout', 'agenda', 'appels'] as $section) {
            Raccourci::reinitialiser();
            $html = Raccourci::rendre(['section' => $section]);
            $this->assertSame(substr_count($html, '<div'), substr_count($html, '</div>'), 'div équilibrés, section ' . $section);
        }
        Raccourci::reinitialiser();
        $html = Raccourci::rendre(['une' => '0']);
        $this->assertSame(substr_count($html, '<div'), substr_count($html, '</div>'), 'div équilibrés, une=0');
    }

    public function test_une_0_retire_la_bande_a_la_une(): void {
        $html = Raccourci::rendre(['une' => '0']);

        $this->assertStringNotContainsString('class="alaune"', $html);
        $this->assertStringNotContainsString('id="unes"', $html);
        $this->assertStringNotContainsString('id="lien-rsn"', $html);
        $this->assertStringContainsString('data-une="0"', $html);
        $this->assertStringContainsString('class="limites"', $html);
        $this->assertStringContainsString('id="vue-agenda"', $html);

        Raccourci::reinitialiser();
        $this->assertStringContainsString('class="alaune"', Raccourci::rendre(['une' => '1']));
        Raccourci::reinitialiser();
        $this->assertStringContainsString('class="alaune"', Raccourci::rendre(['une' => 'peut-être']), 'seul « 0 » retire la bande');
    }

    public function test_section_agenda_sans_f_type_ni_limites_ni_alaune(): void {
        $html = Raccourci::rendre(['section' => 'agenda']);

        $this->assertStringContainsString('id="vue-agenda"', $html);
        $this->assertStringContainsString('id="f-qui"', $html);
        $this->assertStringContainsString('id="btn-proposer"', $html);
        $this->assertStringContainsString('class="bandeau"', $html);
        $this->assertStringNotContainsString('class="fleches"', $html);
        $this->assertStringNotContainsString('id="f-type"', $html);
        $this->assertStringNotContainsString('class="limites"', $html);
        $this->assertStringNotContainsString('id="limites"', $html);
        $this->assertStringNotContainsString('class="alaune"', $html);
        $this->assertStringNotContainsString('id="unes"', $html);
        $this->assertStringNotContainsString('id="vue-appels"', $html);
        $this->assertStringNotContainsString('class="sources"', $html);
        $this->assertStringContainsString('data-une="1"', $html, "l'attribut est exposé même quand la bande n'est pas rendue");
    }

    public function test_section_appels_avec_limites_et_liste_appels_sans_filtres(): void {
        $html = Raccourci::rendre(['section' => 'appels']);

        $this->assertStringContainsString('<div class="limites" hidden>', $html);
        $this->assertStringContainsString('id="limites"', $html);
        $this->assertStringContainsString('class="fleches"', $html);
        $this->assertStringNotContainsString('class="bandeau"', $html);
        $this->assertStringContainsString('<div id="vue-appels">', $html);
        $this->assertStringContainsString('<div id="liste-appels"></div>', $html);
        foreach (['id="f-type"', 'id="f-qui"', 'id="f-mois"', 'id="f-recherche"', 'id="vue-agenda"', 'id="liste-agenda"', 'id="calendrier"', 'class="alaune"', 'id="btn-proposer"', 'class="actions"', 'id="dialogue"', 'class="sources"', 'veille.ics'] as $absent) {
            $this->assertStringNotContainsString($absent, $html, $absent);
        }
    }

    public function test_textes_contiennent_les_nouvelles_cles(): void {
        $textes = Raccourci::textes();
        foreach (['a_la_une', 'tout_le_rsn', 'evenement_rsn', 'nouveau', 'ferme_dans', 'ferme_demain', 'ferme_aujourdhui', 'date_limite_le', 'dates_limites', 'afficher', 'evenements', 'appels', 'evenement', 'partenaire', 'nb_evenements_et_appels', 'nb_elements', 'date_limite_puce', 'legende_date_limite', 's_abonner', 'aucun_element_filtres', 'tous', 'en_cours', 'en_cours_tuile', 'date_limite_a_venir', 'limites_precedentes', 'limites_suivantes', 'en_francais', 'date_longue', 'date_courte', 'heure', 'premier_du_mois', 'du_au_meme_mois', 'du_au', 'en_anglais'] as $cle) {
            $this->assertArrayHasKey($cle, $textes, $cle);
        }
        foreach (['ferme_dans', 'nb_elements'] as $pluriel) {
            $this->assertIsArray($textes[$pluriel], $pluriel);
            $this->assertCount(2, $textes[$pluriel]);
            $this->assertStringContainsString('%d', $textes[$pluriel][0]);
            $this->assertStringContainsString('%d', $textes[$pluriel][1]);
        }
        $this->assertStringContainsString('%s', $textes['date_limite_le']);
        $this->assertStringContainsString('%s', $textes['partenaire']);
        $this->assertStringContainsString('%1$s', $textes['nb_evenements_et_appels']);
        $this->assertStringContainsString('%2$s', $textes['nb_evenements_et_appels']);
        $this->assertSame('À la une du RSN', $textes['a_la_une']);
        $this->assertSame('Dates limites', $textes['dates_limites']);
        $this->assertSame('Date limite à venir', $textes['date_limite_a_venir']);
        $this->assertSame('En cours', $textes['en_cours']);
        $this->assertSame('en cours', $textes['en_cours_tuile']);
        $this->assertSame('Dates limites précédentes', $textes['limites_precedentes']);
        $this->assertSame('Dates limites suivantes', $textes['limites_suivantes']);
        // Gabarits de date et d'heure : arguments positionnels pour que l'anglais puisse réordonner.
        $this->assertSame('En français', $textes['en_francais']);
        $this->assertSame('%1$s %2$s %3$s', $textes['date_longue']);
        $this->assertSame('%1$s h %2$s', $textes['heure']);
        $this->assertSame('1er', $textes['premier_du_mois']);
        $this->assertSame('Du %1$s au %2$s %3$s %4$s', $textes['du_au_meme_mois']);
        $this->assertSame('Du %1$s au %2$s', $textes['du_au']);
    }

    public function test_deux_raccourcis_dans_la_page_ont_des_noeuds_de_donnees_distincts(): void {
        $premier = Raccourci::rendre(['section' => 'agenda']);
        $second = Raccourci::rendre(['section' => 'appels']);
        $this->assertStringContainsString('id="rsn-veille-donnees" data-rsn-veille-donnees', $premier);
        $this->assertStringContainsString('id="rsn-veille-donnees-2" data-rsn-veille-donnees', $second);
        $this->assertStringContainsString('id="rsn-veille-textes-2" data-rsn-veille-textes', $second);
        $this->assertStringContainsString('<div id="vue-appels">', $second);
    }

    public function test_ressources_mises_en_file_au_rendu_sans_polices_par_defaut(): void {
        $this->assertSame([], $GLOBALS['rsn_veille_wp']['scripts']);
        Raccourci::rendre([]);
        $this->assertSame(RSN_VEILLE_VERSION, $GLOBALS['rsn_veille_wp']['styles']['rsn-veille']['version']);
        $this->assertStringEndsWith('assets/veille.css', $GLOBALS['rsn_veille_wp']['styles']['rsn-veille']['source']);
        $this->assertSame(RSN_VEILLE_VERSION, $GLOBALS['rsn_veille_wp']['scripts']['rsn-veille']['version']);
        $this->assertStringEndsWith('assets/veille.js', $GLOBALS['rsn_veille_wp']['scripts']['rsn-veille']['source']);
        $this->assertTrue($GLOBALS['rsn_veille_wp']['scripts']['rsn-veille']['pied']);
        $this->assertArrayNotHasKey('rsn-veille-polices', $GLOBALS['rsn_veille_wp']['styles']);
    }

    public function test_polices_chargees_si_le_filtre_le_demande(): void {
        add_filter('rsn_veille_charger_polices', '__return_true');
        Raccourci::mettre_en_file();
        $this->assertStringStartsWith('https://fonts.googleapis.com/css2?family=DM+Serif+Display', $GLOBALS['rsn_veille_wp']['styles']['rsn-veille-polices']['source']);
    }

    private function page(int $id, string $contenu): WP_Post {
        $page = new WP_Post();
        $page->ID = $id;
        $page->post_type = 'page';
        $page->post_content = $contenu;
        return $page;
    }

    public function test_ressources_absentes_des_pages_sans_raccourci(): void {
        do_action('wp_enqueue_scripts');
        $this->assertSame([], $GLOBALS['rsn_veille_wp']['scripts'], 'aucune page demandée');

        $GLOBALS['rsn_veille_wp']['objet_demande'] = $this->page(5, '<p>Événements du RSN [rsn_evenements]</p>');
        do_action('wp_enqueue_scripts');
        $this->assertSame([], $GLOBALS['rsn_veille_wp']['scripts']);
        $this->assertSame([], $GLOBALS['rsn_veille_wp']['styles']);
    }

    public function test_ressources_en_tete_si_la_page_contient_le_raccourci(): void {
        $GLOBALS['rsn_veille_wp']['objet_demande'] = $this->page(5, '<p>Intro</p>[rsn_veille section="agenda"]');
        do_action('wp_enqueue_scripts');
        $this->assertArrayHasKey('rsn-veille', $GLOBALS['rsn_veille_wp']['scripts']);
        $this->assertArrayHasKey('rsn-veille', $GLOBALS['rsn_veille_wp']['styles']);
    }

    public function test_ressources_en_tete_sur_la_page_veille_des_reglages(): void {
        update_option('rsn_veille_reglages', ['page_veille_id' => 9]);
        // Page Elementor : le raccourci n'est pas dans post_content.
        $GLOBALS['rsn_veille_wp']['objet_demande'] = $this->page(9, '');
        do_action('wp_enqueue_scripts');
        $this->assertArrayHasKey('rsn-veille', $GLOBALS['rsn_veille_wp']['scripts']);
    }

    public function test_ressources_en_tete_sur_la_traduction_wpml_de_la_page_veille(): void {
        update_option('rsn_veille_reglages', ['page_veille_id' => 9]);
        add_filter('wpml_active_languages', static fn() => ['fr' => ['code' => 'fr'], 'en' => ['code' => 'en']]);
        add_filter('wpml_object_id', static fn($id, $type = '', $original = false, $langue = null) => $langue === 'en' ? 19 : $id);
        $GLOBALS['rsn_veille_wp']['objet_demande'] = $this->page(19, '');
        do_action('wp_enqueue_scripts');
        $this->assertArrayHasKey('rsn-veille', $GLOBALS['rsn_veille_wp']['scripts']);
    }

    public function test_classe_body_sur_la_page_du_raccourci(): void {
        $GLOBALS['rsn_veille_wp']['objet_demande'] = $this->page(5, '<p>Intro</p>[rsn_veille section="agenda"]');
        $this->assertContains('rsn-veille-page', apply_filters('body_class', ['page']));
    }

    public function test_classe_body_sur_la_page_veille_des_reglages(): void {
        update_option('rsn_veille_reglages', ['page_veille_id' => 9]);
        // Page Elementor : le raccourci n'est pas dans post_content.
        $GLOBALS['rsn_veille_wp']['objet_demande'] = $this->page(9, '');
        $this->assertContains('rsn-veille-page', apply_filters('body_class', ['page']));
    }

    public function test_classe_body_sur_la_traduction_wpml_de_la_page_veille(): void {
        update_option('rsn_veille_reglages', ['page_veille_id' => 9]);
        add_filter('wpml_active_languages', static fn() => ['fr' => ['code' => 'fr'], 'en' => ['code' => 'en']]);
        add_filter('wpml_object_id', static fn($id, $type = '', $original = false, $langue = null) => $langue === 'en' ? 19 : $id);
        $GLOBALS['rsn_veille_wp']['objet_demande'] = $this->page(19, '');
        $this->assertContains('rsn-veille-page', apply_filters('body_class', ['page']));
    }

    public function test_classe_body_une_seule_fois(): void {
        $GLOBALS['rsn_veille_wp']['objet_demande'] = $this->page(5, '[rsn_veille]');
        $classes = apply_filters('body_class', ['page', 'rsn-veille-page']);
        $this->assertSame(['page', 'rsn-veille-page'], $classes);
    }

    public function test_classe_body_rend_telle_quelle_une_valeur_qui_n_est_pas_un_tableau(): void {
        // Un filtre `body_class` en amont peut rendre autre chose qu'un tableau : on ne plante pas (pas de TypeError).
        $GLOBALS['rsn_veille_wp']['objet_demande'] = $this->page(5, '[rsn_veille]');
        $this->assertSame('page', apply_filters('body_class', 'page'));
    }

    public function test_classe_body_absente_ailleurs(): void {
        $this->assertSame(['page'], apply_filters('body_class', ['page']), 'aucun objet demandé');

        $GLOBALS['rsn_veille_wp']['objet_demande'] = $this->page(5, '<p>Événements du RSN [rsn_evenements]</p>');
        $this->assertSame(['page'], apply_filters('body_class', ['page']));
    }

    public function test_json_en_cache_une_heure_et_vide_par_la_purge_et_la_collecte(): void {
        $this->publier();
        Raccourci::rendre([]);
        $this->assertIsArray(get_transient('rsn_veille_json_fr'));
        $this->assertGreaterThan(time() + 3500, $GLOBALS['rsn_veille_wp']['transients']['rsn_veille_json_fr']['expire']);

        // Nouvelle fiche : la purge (déclenchée par l'écriture) vide le cache.
        $this->publier(['titre' => 'Deuxième', 'cle_source' => 'obvia:43', 'cles_source' => ['obvia:43']]);
        do_action('rsn_veille_caches_purges');
        $this->assertFalse(get_transient('rsn_veille_json_fr'));
        Raccourci::reinitialiser();
        $this->assertCount(2, $this->donnees_incorporees(Raccourci::rendre([]))['items']);

        set_transient('rsn_veille_ics_fr', 'BEGIN:VCALENDAR', 3600);
        do_action('rsn_veille_apres_collecte');
        $this->assertFalse(get_transient('rsn_veille_json_fr'));
        $this->assertFalse(get_transient('rsn_veille_ics_fr'));
    }

    public function test_un_cache_par_langue_wpml_tous_vides(): void {
        $langue = 'fr';
        add_filter('wpml_current_language', static function () use (&$langue): string {
            return $langue;
        });
        add_filter('wpml_active_languages', static fn() => ['fr' => ['code' => 'fr'], 'en' => ['code' => 'en']]);
        $this->publier();
        Raccourci::rendre([]);
        $langue = 'en';
        Raccourci::rendre([]);
        set_transient('rsn_veille_ics_en', 'BEGIN:VCALENDAR', 3600);
        $this->assertIsArray(get_transient('rsn_veille_json_fr'));
        $this->assertIsArray(get_transient('rsn_veille_json_en'));
        $this->assertFalse(get_transient('rsn_veille_json'), 'jamais de transient commun');

        do_action('rsn_veille_caches_purges');
        foreach (['rsn_veille_json_fr', 'rsn_veille_json_en', 'rsn_veille_ics_en'] as $cle) {
            $this->assertFalse(get_transient($cle), $cle);
        }
    }

    public function test_sans_wpml_francais_et_anglais_vides(): void {
        set_transient('rsn_veille_json_fr', ['x'], 3600);
        set_transient('rsn_veille_json_en', ['x'], 3600);
        Donnees::vider_cache();
        $this->assertFalse(get_transient('rsn_veille_json_fr'));
        $this->assertFalse(get_transient('rsn_veille_json_en'));
    }

    public function test_cache_relu_sans_reconstruire(): void {
        set_transient('rsn_veille_json_fr', ['genere_le' => '2026-10-01', 'sources' => [], 'items' => [['titre' => 'Depuis le cache']]], 3600);
        $this->assertSame('Depuis le cache', $this->donnees_incorporees(Raccourci::rendre([]))['items'][0]['titre']);
    }
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/bouchons-wordpress.php';

use PHPUnit\Framework\TestCase;
use RSN\Veille\Infolettre;

final class InfolettreTest extends TestCase {
    private const AUJOURDHUI = '2026-10-26';

    private static function evenement(array $champs = []): array {
        return $champs + [
            'type' => 'evenement', 'titre' => 'Midi-conférence en santé numérique', 'debut' => '2026-11-12', 'fin' => null,
            'organisateur' => 'Mila', 'lieu' => 'Mila', 'ville' => 'Montréal', 'format' => 'Présentiel', 'prix' => 'Gratuit',
            'langue' => 'fr', 'themes' => ['IA', 'Santé'], 'resume' => 'Une présentation sur l’IA en santé.',
            'url' => 'https://mila.quebec/fr/evenements/midi',
        ];
    }

    private static function appel(array $champs = []): array {
        return $champs + [
            'type' => 'appel', 'titre' => 'Concours de bourses', 'debut' => '2026-10-01', 'echeance' => '2026-11-20',
            'organisateur' => 'FRQ', 'categorie' => 'Bourse', 'resume' => 'Bourses de recherche.',
            'url' => 'https://frq.test/' . md5((string) ($champs['titre'] ?? 'bourses')),
        ];
    }

    private static function gardee(array $champs = []): array {
        return $champs + [
            'url' => 'https://inspq.test/n1', 'titre' => 'Une nouvelle', 'date' => '2026-10-20', 'source' => 'INSPQ',
            'organisateur' => 'INSPQ', 'resume' => 'Résumé de la nouvelle.', 'gardee_le' => '2026-10-25 09:00',
        ];
    }

    private static function evenement_rsn(array $champs = []): array {
        return $champs + [
            'type' => 'evenement', 'titre' => 'Atelier de co-création', 'debut' => '2026-11-12', 'fin' => '2026-11-13',
            'organisateur' => 'RSN', 'organise_par' => 'rsn', 'lieu' => 'Agora de Mila', 'ville' => 'Montréal', 'format' => 'Présentiel', 'prix' => '',
            'langue' => 'fr', 'themes' => ['Formation'], 'resume' => 'Deux jours pour imaginer des solutions.',
            'url' => 'https://rsn.quebec/evenement/atelier/', 'image_url' => 'https://rsn.quebec/wp-content/uploads/atelier.jpg',
        ];
    }

    private static function programme(array $details = []): array {
        return Infolettre::appel_programme(
            'Appel à projets de recherche 2027-2028',
            'https://rsn.quebec/appel-projets/',
            '<p>Programme fondateur du RSN, destiné aux équipes multi-axes.</p>',
            '',
            '2026-10-23 09:00:00',
            self::AUJOURDHUI,
            $details + [
                'echeance' => '2026-12-13', 'image_url' => 'https://rsn.quebec/wp-content/uploads/cocktail.jpg',
                'lien' => 'https://event.fourwaves.com/fr/rsnappelprojets', 'pour_qui' => 'Chercheur·euses', 'montant' => '40 000 $ par projet, sur deux ans',
            ]
        );
    }

    // --- Agenda de l'écosystème ---

    public function test_agenda_deux_fiches(): void {
        $html = Infolettre::bloc_agenda([
            self::evenement(),
            self::evenement(['titre' => 'Atelier IVADO', 'debut' => '2026-12-01', 'fin' => '2026-12-03', 'lieu' => '', 'ville' => '', 'format' => 'Virtuel', 'prix' => '', 'langue' => 'en', 'themes' => []]),
        ], 8, null, self::AUJOURDHUI);
        $this->assertStringContainsString('Midi-conférence en santé numérique', $html);
        $this->assertStringContainsString('Atelier IVADO', $html);
        $this->assertSame(2, substr_count($html, 'En savoir plus →'));
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<style', $html);
        $this->assertStringStartsWith("<!-- Agenda de l'écosystème", $html);
        $this->assertStringContainsString("généré par la veille de l'écosystème, 26 octobre 2026) -->", $html);
        $this->assertStringContainsString('<table width="100%" cellpadding="0" cellspacing="0" border="0" style="font-family: Arial, Helvetica, sans-serif; max-width: 600px; margin: 0 auto;">', $html);
        // Un trait sépare les éléments, sauf après le dernier.
        $this->assertSame(1, substr_count($html, '<td style="border-bottom: 1px solid #E8E8E8; padding: 14px 0;">'));
        $this->assertSame(1, substr_count($html, '<td style="padding: 14px 0 0 0;">'));
    }

    public function test_agenda_au_style_de_l_infolettre(): void {
        $html = Infolettre::bloc_agenda([self::evenement(['themes' => ['IA', 'Réseautage', 'Financement', 'Gouvernance']])], 8, null, self::AUJOURDHUI);
        // Pastille de date : jour en jaune sur marine, mois en capitales.
        $this->assertStringContainsString('<table cellpadding="0" cellspacing="0" border="0" style="background-color: #003469; border-radius: 6px; width: 52px; text-align: center;">'
            . '<tr><td style="padding: 6px 4px 2px 4px; font-size: 18px; font-weight: bold; color: #FFCC4E; line-height: 1;">12</td></tr>'
            . '<tr><td style="padding: 2px 4px 6px 4px; font-size: 10px; font-weight: bold; color: #ffffff; letter-spacing: 1px; line-height: 1;">NOV.</td></tr></table>', $html);
        $this->assertStringContainsString('<p style="margin: 0 0 4px 0; font-size: 14px; font-weight: bold; color: #003469;">Midi-conférence en santé numérique</p>', $html);
        $this->assertStringContainsString('<p style="margin: 0; font-size: 12px; color: #0061AF;">Mila · Mila – Montréal</p>', $html);
        // Pastilles de thème aux couleurs de l'infolettre ; un thème sans couleur attitrée est en marine.
        $pastille = static fn(string $nom, string $fond, string $texte): string => '<span style="font-size: 10px; font-weight: bold; color: ' . $texte . '; background-color: ' . $fond . '; padding: 2px 8px; border-radius: 99px; margin-left: 4px;">' . $nom . '</span>';
        $this->assertStringContainsString($pastille('IA', '#0061AF', '#ffffff') . ' ' . $pastille('Réseautage', '#00A759', '#ffffff') . ' ' . $pastille('Financement', '#FFCC4E', '#003469') . ' ' . $pastille('Gouvernance', '#003469', '#ffffff'), $html);
        $this->assertStringContainsString('<a href="https://mila.quebec/fr/evenements/midi" style="display: inline-block; background-color: #0061AF; color: #ffffff; font-size: 12px; font-weight: bold; padding: 7px 16px; border-radius: 99px; text-decoration: none;">En savoir plus →</a>', $html);
        $this->assertStringContainsString('<span style="font-size: 11px; color: #767676; font-style: italic;">Présentiel – Gratuit</span>', $html);
    }

    public function test_agenda_pastille_sur_plusieurs_jours_et_langue_anglaise(): void {
        $jour = static fn(string $texte, int $taille): string => 'font-size: ' . $taille . 'px; font-weight: bold; color: #FFCC4E; line-height: 1;">' . $texte . '</td>';
        $meme_mois = Infolettre::bloc_agenda([self::evenement(['debut' => '2026-12-01', 'fin' => '2026-12-03', 'langue' => 'en'])], 8, null, self::AUJOURDHUI);
        $this->assertStringContainsString($jour('1-3', 14), $meme_mois);
        $this->assertStringContainsString('>DÉC.</td>', $meme_mois);
        $this->assertStringContainsString('Présentiel – Gratuit · En anglais', $meme_mois);
        $this->assertStringContainsString($jour('24-25', 13), Infolettre::bloc_agenda([self::evenement(['debut' => '2026-11-24', 'fin' => '2026-11-25'])], 8, null, self::AUJOURDHUI));
        // À cheval sur deux mois : le début, puis la fin en petit.
        $deux_mois = Infolettre::bloc_agenda([self::evenement(['debut' => '2026-11-28', 'fin' => '2026-12-02'])], 8, null, self::AUJOURDHUI);
        $this->assertStringContainsString($jour('28', 18), $deux_mois);
        $this->assertStringContainsString('>NOV.<br><span style="font-weight: normal; letter-spacing: 0;">→ 2 DÉC.</span></td>', $deux_mois);
        // Déjà commencé (une série, un événement de plusieurs jours) : sa fin.
        $en_cours = Infolettre::bloc_agenda([self::evenement(['debut' => '2026-09-04', 'fin' => '2026-12-18'])], 8, null, self::AUJOURDHUI);
        $this->assertStringContainsString('font-size: 8px; font-weight: bold; color: #ffffff; line-height: 1;">JUSQU\'AU</td>', $en_cours);
        $this->assertStringContainsString($jour('18', 18), $en_cours);
        $this->assertStringContainsString('>DÉC.</td>', $en_cours);
    }

    public function test_agenda_fin_avant_le_debut_est_ignoree(): void {
        $html = Infolettre::bloc_agenda([self::evenement(['debut' => '2026-11-12', 'fin' => '2026-11-10'])], 8, null, self::AUJOURDHUI);
        $this->assertStringContainsString('line-height: 1;">12</td>', $html);
    }

    public function test_agenda_lieu_en_ligne_long_ou_qui_nomme_deja_la_ville(): void {
        $lieu = static fn(array $champs): string => Infolettre::bloc_agenda([self::evenement($champs + ['organisateur' => 'Org'])], 8, null, self::AUJOURDHUI);
        $this->assertStringContainsString('>Org · En ligne</p>', $lieu(['lieu' => 'Online', 'ville' => '']));
        $this->assertStringContainsString('>Org · University of Waterloo</p>', $lieu(['lieu' => 'University of Waterloo, B.C. Matthews Hall (BMH) Room EXP 1686', 'ville' => 'Waterloo']));
        $this->assertStringContainsString('>Org · Terminal 30 – Québec</p>', $lieu(['lieu' => 'Terminal 30', 'ville' => 'Québec']));
        $this->assertStringContainsString('>Org</p>', $lieu(['lieu' => '', 'ville' => '']));
    }

    public function test_agenda_echappe_tout(): void {
        $html = Infolettre::bloc_agenda([self::evenement([
            'titre' => 'Titre <b>gras</b> & "guillemets"', 'organisateur' => '<i>Org</i>', 'resume' => '<script>alert(1)</script>',
            'themes' => ['<u>x</u>'], 'url' => 'javascript:alert(1)',
        ])], 8, null, self::AUJOURDHUI);
        $this->assertStringContainsString('Titre &lt;b&gt;gras&lt;/b&gt; &amp; &quot;guillemets&quot;', $html);
        $this->assertStringNotContainsString('<b>gras</b>', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<i>', $html);
        $this->assertStringNotContainsString('<u>', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('En savoir plus', $html, 'une adresse refusée ne donne aucun bouton');
        $this->assertStringContainsString('Présentiel – Gratuit', $html, 'la mention reste, sans bouton');
    }

    public function test_agenda_limite_par_nombre_et_tri_par_debut(): void {
        $fiches = [];
        foreach ([5, 3, 1, 4, 2] as $n) {
            $fiches[] = self::evenement(['titre' => 'Événement ' . $n, 'debut' => '2026-11-0' . $n]);
        }
        $html = Infolettre::bloc_agenda($fiches, 3, null, self::AUJOURDHUI);
        $this->assertSame(3, substr_count($html, 'En savoir plus →'));
        $this->assertLessThan(strpos($html, 'Événement 2'), strpos($html, 'Événement 1'));
        $this->assertLessThan(strpos($html, 'Événement 3'), strpos($html, 'Événement 2'));
        $this->assertStringNotContainsString('Événement 4', $html);
        $this->assertStringNotContainsString('Événement 5', $html);
    }

    public function test_agenda_horizon_remplace_le_nombre(): void {
        $fiches = [];
        foreach ([1, 2, 3, 4, 5] as $n) {
            $fiches[] = self::evenement(['titre' => 'Événement ' . $n, 'debut' => '2026-11-0' . $n]);
        }
        $html = Infolettre::bloc_agenda($fiches, 2, '2026-11-04', self::AUJOURDHUI);
        $this->assertSame(4, substr_count($html, 'En savoir plus →'));
        $this->assertStringNotContainsString('Événement 5', $html);
    }

    public function test_agenda_garde_seulement_les_evenements_a_venir(): void {
        $html = Infolettre::bloc_agenda([
            self::evenement(['titre' => 'Passé', 'debut' => '2026-10-20']),
            self::evenement(['titre' => 'Aujourd’hui', 'debut' => '2026-10-26']),
            self::evenement(['titre' => 'En cours', 'debut' => '2026-10-24', 'fin' => '2026-10-28']),
            self::evenement(['titre' => 'Un appel', 'type' => 'appel', 'debut' => '2026-11-01']),
            self::evenement(['titre' => 'Sans date', 'debut' => null]),
        ], 8, null, self::AUJOURDHUI);
        $this->assertStringNotContainsString('Passé', $html);
        $this->assertStringNotContainsString('Un appel', $html);
        $this->assertStringNotContainsString('Sans date', $html);
        $this->assertStringContainsString('Aujourd’hui', $html);
        $this->assertStringContainsString('En cours', $html);
    }

    public function test_agenda_vide(): void {
        $html = Infolettre::bloc_agenda([], 8, null, self::AUJOURDHUI);
        $this->assertStringContainsString('Aucun élément', $html);
        $this->assertStringStartsWith('<!-- Agenda', $html);
        $this->assertStringNotContainsString('En savoir plus', $html);
    }

    public function test_evenements_retenus_compte(): void {
        $this->assertCount(2, Infolettre::evenements_retenus([self::evenement(), self::evenement(['debut' => '2026-11-13']), self::evenement(['debut' => '2026-10-01'])], 8, null, self::AUJOURDHUI));
    }

    // --- Ce que le RSN organise est à part ---

    public function test_les_evenements_du_rsn_ne_sont_pas_dans_l_agenda_de_l_ecosysteme(): void {
        $fiches = [
            self::evenement(['titre' => 'Colloque Mila']),
            self::evenement_rsn(),
            // Un événement d'un partenaire, annoncé sur le site du RSN, reste dans l'écosystème.
            self::evenement(['titre' => 'Colloque des réseaux', 'organise_par' => 'partenaires']),
        ];
        $agenda = Infolettre::bloc_agenda($fiches, 8, null, self::AUJOURDHUI);
        $this->assertStringContainsString('Colloque Mila', $agenda);
        $this->assertStringContainsString('Colloque des réseaux', $agenda);
        $this->assertStringNotContainsString('Atelier de co-création', $agenda);
        $rsn = Infolettre::bloc_evenements_rsn($fiches, null, self::AUJOURDHUI);
        $this->assertStringContainsString('Atelier de co-création', $rsn);
        $this->assertStringNotContainsString('Colloque', $rsn);
        $this->assertCount(1, Infolettre::evenements_rsn_retenus($fiches, null, self::AUJOURDHUI));
        $this->assertCount(2, Infolettre::evenements_ecosysteme_retenus($fiches, 8, null, self::AUJOURDHUI));
    }

    public function test_le_nombre_ne_limite_pas_les_evenements_du_rsn_mais_l_horizon_si(): void {
        $fiches = [];
        foreach (range(1, 9) as $n) {
            $fiches[] = self::evenement_rsn(['titre' => 'RSN ' . $n, 'debut' => '2026-11-0' . $n, 'fin' => null]);
        }
        $fiches[] = self::evenement(['titre' => 'Écosystème']);
        // Le nombre demandé vaut pour l'écosystème seulement : les événements du RSN n'en prennent pas la place.
        $this->assertCount(1, Infolettre::evenements_ecosysteme_retenus($fiches, 1, null, self::AUJOURDHUI));
        $this->assertCount(9, Infolettre::evenements_rsn_retenus($fiches, null, self::AUJOURDHUI));
        $this->assertCount(4, Infolettre::evenements_rsn_retenus($fiches, '2026-11-04', self::AUJOURDHUI));
        $this->assertSame(0, substr_count(Infolettre::bloc_evenements_rsn([self::evenement_rsn(['debut' => '2026-10-01', 'fin' => '2026-10-02'])], null, self::AUJOURDHUI), 'En savoir plus'), 'un événement passé n’est pas repris');
    }

    public function test_billet_d_un_evenement_du_rsn(): void {
        $html = Infolettre::bloc_evenements_rsn([self::evenement_rsn()], null, self::AUJOURDHUI);
        $this->assertStringStartsWith('<!-- Événements du RSN (généré', $html);
        $this->assertStringContainsString('border: 1px solid #D5DEE8; border-radius: 10px; border-collapse: separate; background-color: #ffffff;', $html);
        $this->assertStringContainsString('letter-spacing: 0.5px;">ÉVÉNEMENT DU RSN</span>', $html);
        // L'image, liée à la page de l'événement, à gauche du texte.
        $this->assertStringContainsString('<a href="https://rsn.quebec/evenement/atelier/"><img src="https://rsn.quebec/wp-content/uploads/atelier.jpg" width="200" alt="" style="display: block; width: 100%; max-width: 200px; height: auto; border: 0; border-radius: 8px;"></a>', $html);
        $this->assertStringContainsString('<div style="display: inline-block; width: 100%; max-width: 216px; vertical-align: top;">', $html);
        // Le texte prend la place qui reste à côté de l'image, et passe dessous sous 460 px ; sans `calc()`, 314 px.
        $this->assertStringContainsString('<div style="display: inline-block; vertical-align: top; width: 100%; max-width: 314px; min-width: calc(100% - 216px); max-width: calc(100% + 0px); width: calc((460px - 100%) * 1000);">', $html);
        // Repli pour Outlook : l'image dans une cellule de 216 px, le texte dans le reste, quelle que soit la largeur.
        $this->assertStringContainsString('<tr><td width="216" valign="top"><![endif]-->', $html);
        $this->assertStringContainsString('<!--[if mso]></td><td valign="top"><![endif]-->', $html);
        $this->assertStringContainsString('line-height: 1;">12-13</td>', $html);
        $this->assertStringContainsString('font-size: 17px; font-weight: bold; color: #003469; line-height: 1.25;">Atelier de co-création</p>', $html);
        $this->assertStringContainsString('line-height: 1.4;">RSN · Agora de Mila – Montréal</p>', $html);
        $this->assertStringContainsString('margin-right: 4px;">Formation</span>', $html);
        $this->assertStringContainsString('Deux jours pour imaginer des solutions.', $html);
        $this->assertStringContainsString('>En savoir plus →</a>', $html);
        $this->assertStringContainsString('font-style: italic;">Présentiel</span>', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<style', $html);
    }

    public function test_billet_sans_image_et_echappement(): void {
        $html = Infolettre::bloc_evenements_rsn([self::evenement_rsn([
            'image_url' => 'javascript:alert(1)', 'titre' => '<b>Gras</b>', 'resume' => '<script>x</script>', 'url' => 'javascript:alert(2)', 'themes' => ['<u>t</u>'],
        ])], null, self::AUJOURDHUI);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<b>Gras', $html);
        $this->assertStringNotContainsString('<u>', $html);
        $this->assertStringContainsString('&lt;b&gt;Gras&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('max-width: 216px', $html, 'sans image, le texte prend toute la largeur');
        $this->assertStringNotContainsString('En savoir plus', $html);
    }

    public function test_un_pourcent_dans_un_titre_n_est_pas_un_format(): void {
        // « 100 % » dans un titre ne doit ni planter la page ni être mangé (le titre n'est jamais un format de sprintf).
        $evenement = Infolettre::bloc_evenements_rsn([self::evenement_rsn(['titre' => 'Journée 100 % IA : 50%s de %d places', 'resume' => 'Taux de 80 %.'])], null, self::AUJOURDHUI);
        $this->assertStringContainsString('>Journée 100 % IA : 50%s de %d places</p>', $evenement);
        $this->assertStringContainsString('Taux de 80 %.', $evenement);
        $programme = ['titre' => 'Programme 100% relève', 'montant' => '70 % des dépenses, %s'] + self::programme();
        $html = Infolettre::bloc_programmes_rsn([], [$programme], self::AUJOURDHUI);
        $this->assertStringContainsString('>Programme 100% relève</p>', $html);
        $this->assertStringContainsString('70 % des dépenses, %s</td>', $html);
        $this->assertStringContainsString("<b>13 décembre 2026</b>, à 23 h 59 (heure de l'Est)</td>", $html);
        $this->assertStringContainsString('100 % IA', Infolettre::bloc_agenda([self::evenement(['titre' => 'Midi 100 % IA'])], 8, null, self::AUJOURDHUI));
        $this->assertStringContainsString('100 % relève', Infolettre::bloc_appels([self::appel(['titre' => 'Bourse 100 % relève'])], [], self::AUJOURDHUI));
    }

    public function test_evenements_du_rsn_vide(): void {
        $html = Infolettre::bloc_evenements_rsn([self::evenement()], null, self::AUJOURDHUI);
        $this->assertStringContainsString('Aucun élément', $html);
        $this->assertStringNotContainsString('Midi-conférence', $html);
    }

    // --- Programmes de financement du RSN ---

    public function test_billet_d_un_programme_du_rsn(): void {
        $html = Infolettre::bloc_programmes_rsn([], [self::programme()], self::AUJOURDHUI);
        $this->assertStringStartsWith('<!-- Programmes de financement du RSN en cours (généré', $html);
        $this->assertStringContainsString('letter-spacing: 0.5px;">PROGRAMME DU RSN</span>', $html);
        $this->assertStringContainsString('line-height: 1.25;">Appel à projets de recherche 2027-2028</p>', $html);
        $this->assertStringContainsString('Programme fondateur du RSN, destiné aux équipes multi-axes.', $html);
        $this->assertStringContainsString('<a href="https://rsn.quebec/appel-projets/"><img src="https://rsn.quebec/wp-content/uploads/cocktail.jpg"', $html);
        // Les renseignements de la case du site.
        $this->assertStringContainsString('>POUR QUI</td><td valign="top" style="padding: 7px 0; font-size: 13px; color: #003469; line-height: 1.4;">Chercheur·euses</td>', $html);
        $this->assertStringContainsString('>MONTANT</td>', $html);
        $this->assertStringContainsString('40 000 $ par projet, sur deux ans</td>', $html);
        $this->assertStringContainsString('>DÉPÔT</td>', $html);
        $this->assertStringContainsString("<b>13 décembre 2026</b>, à 23 h 59 (heure de l'Est)</td>", $html);
        // Le bouton mène au formulaire de dépôt ; l'annonce est en second lien.
        $this->assertStringContainsString('<a href="https://event.fourwaves.com/fr/rsnappelprojets" style="display: inline-block; background-color: #0061AF;', $html);
        $this->assertStringContainsString(">Voir l'appel sur Fourwaves →</a>", $html);
        $this->assertStringContainsString('<a href="https://rsn.quebec/appel-projets/" style="font-size: 12px; font-weight: bold; color: #0061AF;">Lire l\'annonce</a>', $html);
        $this->assertStringNotContainsString("Consulter l'appel", $html);
    }

    public function test_billet_d_un_programme_sans_renseignements(): void {
        $html = Infolettre::bloc_programmes_rsn([], [self::programme(['echeance' => '', 'image_url' => '', 'lien' => '', 'pour_qui' => '', 'montant' => ''])], self::AUJOURDHUI);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('POUR QUI', $html);
        $this->assertStringNotContainsString('DÉPÔT', $html);
        $this->assertStringNotContainsString('#F2F6FA', $html, 'pas de cadre de renseignements vide');
        $this->assertStringContainsString('<a href="https://rsn.quebec/appel-projets/" style="display: inline-block;', $html);
        $this->assertStringContainsString(">Consulter l'appel →</a>", $html);
        $this->assertStringNotContainsString("Lire l'annonce", $html);
        // Un formulaire de dépôt ailleurs que sur Fourwaves : bouton sans nom de plateforme.
        $autre = Infolettre::bloc_programmes_rsn([], [self::programme(['lien' => 'https://formulaire.test/depot'])], self::AUJOURDHUI);
        $this->assertStringContainsString(">Voir l'appel →</a>", $autre);
        $this->assertStringNotContainsString('Fourwaves', $autre);
        // Un lien refusé : le bouton revient à l'annonce.
        $refuse = Infolettre::bloc_programmes_rsn([], [self::programme(['lien' => 'javascript:alert(1)'])], self::AUJOURDHUI);
        $this->assertStringNotContainsString('javascript:', $refuse);
        $this->assertStringContainsString(">Consulter l'appel →</a>", $refuse);
    }

    public function test_programmes_et_appels_du_rsn_separes_de_l_ecosysteme(): void {
        $collecte_rsn = self::appel(['titre' => 'Appel du RSN collecté', 'organisateur' => 'RSN', 'organise_par' => 'rsn', 'url' => 'https://rsn.quebec/autre-appel/', 'echeance' => '2026-11-30']);
        $doublon = self::appel(['titre' => 'Version collectée du programme', 'organisateur' => 'RSN', 'organise_par' => 'rsn', 'url' => 'https://rsn.quebec/appel-projets']);
        $externe = self::appel(['url' => 'https://frq.test/bourses']);
        $rsn = Infolettre::bloc_programmes_rsn([$externe, $collecte_rsn, $doublon], [self::programme()], self::AUJOURDHUI);
        $this->assertStringContainsString('Appel à projets de recherche 2027-2028', $rsn);
        $this->assertStringContainsString('Appel du RSN collecté', $rsn);
        $this->assertStringNotContainsString('Version collectée du programme', $rsn, 'une même adresse une seule fois : le programme du site gagne');
        $this->assertStringNotContainsString('Concours de bourses', $rsn);
        // Un appel publié que le RSN organise, sans être un programme du site : billet plus simple.
        $this->assertStringContainsString('>APPEL DU RSN</span>', $rsn);
        $this->assertStringContainsString('<b>30 novembre 2026</b></td>', $rsn);
        $this->assertStringNotContainsString('<b>30 novembre 2026</b>, à 23 h 59', $rsn);
        // Les dates limites les plus proches d'abord.
        $this->assertLessThan(strpos($rsn, 'Appel à projets de recherche'), strpos($rsn, 'Appel du RSN collecté'));

        $ecosysteme = Infolettre::bloc_appels([$externe, $collecte_rsn, $doublon], [self::programme()], self::AUJOURDHUI);
        $this->assertStringContainsString('Concours de bourses', $ecosysteme);
        $this->assertStringNotContainsString('RSN', $ecosysteme);
        $this->assertCount(2, Infolettre::appels_rsn_retenus([$externe, $collecte_rsn, $doublon], [self::programme()], self::AUJOURDHUI));
        $this->assertCount(1, Infolettre::appels_ecosysteme_retenus([$externe, $collecte_rsn, $doublon], [self::programme()], self::AUJOURDHUI));
    }

    public function test_programmes_du_rsn_vide(): void {
        $this->assertStringContainsString('Aucun élément', Infolettre::bloc_programmes_rsn([self::appel()], [], self::AUJOURDHUI));
    }

    public function test_appel_programme_depuis_un_article(): void {
        $appel = Infolettre::appel_programme('Programme <Émergence>', 'https://rsn.quebec/programmes/emergence/', "<p>Un programme\npour la relève.</p>", 'Date limite : 15 novembre 2026', '2026-10-10 08:00:00', self::AUJOURDHUI);
        $this->assertSame('appel', $appel['type']);
        $this->assertSame('Programme <Émergence>', $appel['titre']);
        $this->assertSame('https://rsn.quebec/programmes/emergence/', $appel['url']);
        $this->assertSame('Un programme pour la relève.', $appel['resume']);
        $this->assertSame('Un programme pour la relève.', $appel['texte']);
        $this->assertSame('2026-11-15', $appel['echeance']);
        $this->assertSame('2026-10-10', $appel['debut']);
        $this->assertSame('RSN', $appel['organisateur']);
        $this->assertSame('rsn', $appel['organise_par']);
        $this->assertTrue($appel['programme']);
        $this->assertSame('Programme du RSN', $appel['categorie']);
        $this->assertSame(['', '', '', ''], [$appel['image_url'], $appel['lien'], $appel['pour_qui'], $appel['montant']]);
        $sans = Infolettre::appel_programme('P', 'https://x.test', '', 'Du 1er au 30 novembre', '2026-10-10 08:00:00', self::AUJOURDHUI);
        $this->assertNull($sans['echeance'], 'une période sans date limite lisible ne donne pas d’échéance');
    }

    public function test_appel_programme_avec_les_renseignements_de_sa_case(): void {
        $long = str_repeat('Un programme pour la relève. ', 30);
        $appel = Infolettre::appel_programme('P', 'https://x.test', $long, 'Date limite : 15 novembre 2026', '2026-10-10 08:00:00', self::AUJOURDHUI, [
            'echeance' => '2026-12-13', 'image_url' => ' https://x.test/i.jpg ', 'lien' => 'https://event.fourwaves.com/fr/p', 'pour_qui' => 'Chercheur·euses', 'montant' => ['pas une chaîne'],
        ]);
        $this->assertSame('2026-12-13', $appel['echeance'], 'la date limite de l’encadré prime sur la période');
        $this->assertSame('https://x.test/i.jpg', $appel['image_url']);
        $this->assertSame('https://event.fourwaves.com/fr/p', $appel['lien']);
        $this->assertSame('Chercheur·euses', $appel['pour_qui']);
        $this->assertSame('', $appel['montant']);
        // Le billet montre plus de texte que la ligne d'une liste.
        $this->assertLessThanOrEqual(261, mb_strlen($appel['resume']));
        $this->assertGreaterThan(400, mb_strlen($appel['texte']));
        $this->assertLessThanOrEqual(601, mb_strlen($appel['texte']));
        // Une date limite illisible dans l'encadré : la période sert.
        $this->assertSame('2026-11-15', Infolettre::appel_programme('P', 'https://x.test', '', 'Date limite : 15 novembre 2026', '2026-10-10 08:00:00', self::AUJOURDHUI, ['echeance' => 'bientôt'])['echeance']);
    }

    public function test_en_bref_lit_une_ligne_du_bloc_de_l_article(): void {
        $contenu = '<h2>En bref</h2><ul><li><strong>Pour qui :</strong> équipes de recherche multi-axes</li>'
            . '<li><strong>Montant</strong> : 40 000 $ par projet, sur <em>deux ans</em></li><li><b>Dépôt :</b>&nbsp;13 décembre</li></ul><p>Suite du texte.</p>';
        $this->assertSame('Équipes de recherche multi-axes', Infolettre::en_bref($contenu, ['Pour qui']));
        $this->assertSame('40 000 $ par projet, sur deux ans', Infolettre::en_bref($contenu, ['Montant']));
        $this->assertSame('13 décembre', Infolettre::en_bref($contenu, ['Dépôt']));
        $this->assertSame('', Infolettre::en_bref($contenu, ['Durée']));
        // Sans gras : une ligne « Libellé : valeur » du texte.
        $this->assertSame('Jusqu’à 4 000 $ par bourse', Infolettre::en_bref("<p>Pour qui : relève étudiante<br>Montant : jusqu’à 4 000 $ par bourse</p>", ['Montant']));
        // Un bloc « En bref » écrit en un seul paragraphe, une ligne par saut de ligne : chaque valeur s'arrête à sa ligne.
        $un_paragraphe = '<p><strong>Montant :</strong> jusqu’à 5 000 $<br><strong>Durée :</strong> 12 mois<br /><strong>Pour qui&nbsp;:</strong> relève</p>';
        $this->assertSame('Jusqu’à 5 000 $', Infolettre::en_bref($un_paragraphe, ['Montant']));
        $this->assertSame('12 mois', Infolettre::en_bref($un_paragraphe, ['Durée']));
        $this->assertSame('Relève', Infolettre::en_bref($un_paragraphe, ['Pour qui']));
        // Le premier libellé trouvé sert.
        $this->assertSame('Relève', Infolettre::en_bref('<p><strong>Admissibilité :</strong> relève</p>', ['Pour qui', 'Admissibilité']));
        $this->assertSame('', Infolettre::en_bref('', ['Pour qui']));
    }

    public function test_pour_qui_d_apres_les_cases_de_l_article(): void {
        $this->assertSame('Chercheur·euses', Infolettre::pour_qui(['chercheurs'], [], 'Texte du bloc En bref'));
        $this->assertSame('Relève étudiante', Infolettre::pour_qui(['releve'], []));
        $this->assertSame('Relève étudiante, tous niveaux', Infolettre::pour_qui(['releve'], ['postdoc', 'bac', 'doctorat', 'maitrise']));
        $this->assertSame('Relève étudiante (maîtrise, doctorat, postdoc) et chercheur·euses', Infolettre::pour_qui(['chercheurs', 'releve'], ['postdoc', 'maitrise', 'doctorat', 'inconnu']));
        // Sans case cochée : la ligne du bloc « En bref ».
        $this->assertSame('Équipes multi-axes', Infolettre::pour_qui([], ['doctorat'], ' Équipes multi-axes '));
        $this->assertSame('', Infolettre::pour_qui([], []));
    }

    public function test_lien_fourwaves_du_texte(): void {
        $this->assertSame('https://event.fourwaves.com/fr/appel?a=1&b=2', Infolettre::lien_fourwaves('<p><a href="https://rsn.quebec/x">x</a> <a href="https://event.fourwaves.com/fr/appel?a=1&amp;b=2">Déposer</a></p>'));
        $this->assertSame('', Infolettre::lien_fourwaves('<p>Aucun formulaire. https://fourwaves.example.com/x</p>'));
    }

    // --- Appels de l'écosystème ---

    public function test_appels_au_style_de_l_infolettre(): void {
        $html = Infolettre::bloc_appels([
            self::appel(['url' => 'https://frq.test/bourses', 'themes' => ['Financement']]),
            self::appel(['titre' => 'Sans limite', 'echeance' => null, 'debut' => '2026-10-10', 'categorie' => 'Appel', 'url' => 'https://frq.test/sans']),
        ], [], self::AUJOURDHUI);
        $this->assertStringStartsWith("<!-- Appels de l'écosystème", $html);
        $this->assertSame(2, substr_count($html, "Consulter l'appel →"));
        // La date limite dans la pastille, avec son surtitre.
        $this->assertSame(1, substr_count($html, 'line-height: 1.1;">DATE LIMITE</p>'));
        $this->assertStringContainsString('color: #FFCC4E; line-height: 1;">20</td>', $html);
        $this->assertStringContainsString('>NOV.</td>', $html);
        // Sans date limite : la date de publication, dans une pastille en contour.
        $this->assertSame(1, substr_count($html, 'line-height: 1.1;">PUBLIÉ LE</p>'));
        $this->assertStringContainsString('background-color: #ffffff; border: 1px solid #003469; border-radius: 6px; width: 52px;', $html);
        $this->assertStringContainsString('color: #003469; line-height: 1;">10</td>', $html);
        $this->assertStringContainsString('<p style="margin: 0; font-size: 12px; color: #0061AF;">FRQ</p>', $html);
        // La catégorie en pastille devant les thèmes ; « Appel », qui ne dit rien de plus, n'en fait pas une.
        $this->assertStringContainsString('margin-left: 4px;">Bourse</span> <span style="font-size: 10px; font-weight: bold; color: #003469; background-color: #FFCC4E;', $html);
        $this->assertStringNotContainsString('>Appel</span>', $html);
        $this->assertStringContainsString('<a href="https://frq.test/bourses" style="display: inline-block; background-color: #0061AF;', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    public function test_appels_tri_par_date_limite_puis_sans_echeance(): void {
        $html = Infolettre::bloc_appels([
            self::appel(['titre' => 'Sans limite', 'echeance' => null, 'debut' => '2026-10-05']),
            self::appel(['titre' => 'Tard', 'echeance' => '2026-12-01']),
            self::appel(['titre' => 'Tôt', 'echeance' => '2026-11-01']),
        ], [], self::AUJOURDHUI);
        $this->assertLessThan(strpos($html, 'Tard'), strpos($html, 'Tôt'));
        $this->assertLessThan(strpos($html, 'Sans limite'), strpos($html, 'Tard'));
    }

    public function test_appels_ecartes_apres_la_date_limite_et_hors_type(): void {
        $html = Infolettre::bloc_appels([
            self::appel(['titre' => 'Expiré', 'echeance' => '2026-10-25']),
            self::appel(['titre' => 'Dernier jour', 'echeance' => '2026-10-26']),
            self::evenement(['titre' => 'Pas un appel']),
        ], [], self::AUJOURDHUI);
        $this->assertStringNotContainsString('Expiré', $html);
        $this->assertStringNotContainsString('Pas un appel', $html);
        $this->assertStringContainsString('Dernier jour', $html);
    }

    public function test_appels_externes_sans_limite_retenus_moins_de_quatre_vingt_dix_jours(): void {
        $externes = [
            self::appel(['titre' => 'Externe récent', 'echeance' => null, 'debut' => '2026-10-05', 'url' => 'https://a.test/1']),
            self::appel(['titre' => 'Externe de juin', 'echeance' => null, 'debut' => '2026-06-22', 'fin' => '2026-06-22', 'url' => 'https://a.test/2']),
            self::appel(['titre' => 'Externe fin passée', 'echeance' => null, 'debut' => '2026-10-01', 'fin' => '2026-10-20', 'url' => 'https://a.test/3']),
        ];
        // Programme du RSN : son état « ouvert » vient du site (rsn_appel_etat), aucun âge maximal.
        $programmes = [self::appel(['titre' => 'Programme ancien ouvert', 'echeance' => null, 'debut' => '2026-01-10', 'url' => 'https://rsn.test/p'])];
        $html = Infolettre::bloc_appels($externes, $programmes, self::AUJOURDHUI);
        $this->assertStringContainsString('Externe récent', $html);
        $this->assertStringNotContainsString('Externe de juin', $html);
        $this->assertStringNotContainsString('Externe fin passée', $html);
        $this->assertStringNotContainsString('Programme ancien ouvert', $html, 'un programme du site est du RSN, même sans le dire');
        $this->assertStringContainsString('Programme ancien ouvert', Infolettre::bloc_programmes_rsn($externes, $programmes, self::AUJOURDHUI));
    }

    public function test_appels_une_meme_adresse_une_seule_fois_le_programme_gagne(): void {
        $programme = self::appel(['titre' => 'Version du site', 'url' => 'https://rsn.quebec/programme/', 'organisateur' => 'RSN']);
        $collecte = self::appel(['titre' => 'Version collectée', 'url' => 'https://rsn.quebec/programme']);
        $externes = [$collecte, self::appel(['titre' => 'Sans adresse', 'url' => '']), self::appel(['titre' => 'Sans adresse bis', 'url' => ''])];
        $html = Infolettre::bloc_appels($externes, [$programme], self::AUJOURDHUI);
        $this->assertStringNotContainsString('Version collectée', $html, 'la fiche qui pointe vers un programme du site lui cède la place');
        $this->assertStringNotContainsString('Version du site', $html);
        $this->assertStringContainsString('>Sans adresse</p>', $html);
        $this->assertStringContainsString('Sans adresse bis', $html);
        $this->assertSame(0, substr_count($html, "Consulter l'appel →"), 'sans adresse, pas de bouton');
        $this->assertStringContainsString('Version du site', Infolettre::bloc_programmes_rsn($externes, [$programme], self::AUJOURDHUI));
    }

    public function test_appels_echappe(): void {
        $html = Infolettre::bloc_appels([self::appel(['titre' => '<b>Gras</b>', 'categorie' => '<i>c</i>', 'resume' => '<script>x</script>'])], [], self::AUJOURDHUI);
        $this->assertStringContainsString('&lt;b&gt;Gras&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<i>', $html);
    }

    public function test_appels_vide(): void {
        $this->assertStringContainsString('Aucun élément', Infolettre::bloc_appels([], [], self::AUJOURDHUI));
    }

    // --- Nouvelles ---

    public function test_nouvelles_gardees(): void {
        $html = Infolettre::bloc_nouvelles([
            self::gardee(),
            self::gardee(['url' => 'https://frq.test/n2', 'titre' => 'Plus récente <i>x</i>', 'date' => '2026-10-24', 'source' => 'FRQ']),
        ], self::AUJOURDHUI);
        $this->assertStringStartsWith('<!-- Nouvelles', $html);
        $this->assertLessThan(strpos($html, 'Une nouvelle'), strpos($html, 'Plus récente'));
        $this->assertStringContainsString('Plus récente &lt;i&gt;x&lt;/i&gt;', $html);
        // Source puis date, en petit et en gris, au-dessus du titre.
        $this->assertStringContainsString('<p style="margin: 0 0 3px 0; font-size: 11px; color: #767676; line-height: 1.4;">FRQ · 24 oct. 2026</p>', $html);
        // Le titre est le lien, suivi d'une flèche bleue ; plus de « Lire la suite ».
        $this->assertStringContainsString('<p style="margin: 0 0 4px 0; font-size: 14px; font-weight: bold; line-height: 1.35;"><a href="https://inspq.test/n1" style="color: #003469; text-decoration: none;">'
            . 'Une nouvelle&nbsp;<span style="color: #0061AF;">→</span></a></p>', $html);
        $this->assertStringNotContainsString('Lire la suite', $html);
        $this->assertStringContainsString('<p style="margin: 0; font-size: 13px; color: #444444; line-height: 1.5;">Résumé de la nouvelle.</p>', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    public function test_nouvelles_dans_une_carte_avec_son_en_tete(): void {
        $html = Infolettre::bloc_nouvelles([self::gardee(), self::gardee(['url' => 'https://frq.test/n2', 'titre' => 'Autre', 'date' => '2026-10-24']), self::gardee(['url' => 'https://frq.test/n3', 'titre' => 'Troisième', 'date' => '2026-10-22'])], self::AUJOURDHUI);
        // Une seule carte, aux coins arrondis, dans un gabarit d'au plus 600 px.
        $this->assertStringContainsString('max-width: 600px; margin: 0 auto;', $html);
        $this->assertSame(1, substr_count($html, 'border: 1px solid #D5DEE8; border-radius: 10px; border-collapse: separate; background-color: #ffffff;'));
        // En-tête : étiquette puis titre, une seule fois, avant la première nouvelle.
        $this->assertSame(1, substr_count($html, '>DANS L’ÉCOSYSTÈME</span>'));
        $this->assertSame(1, substr_count($html, '>Nouvelles de nos partenaires</p>'));
        $this->assertLessThan(strpos($html, 'Nouvelles de nos partenaires'), strpos($html, 'DANS L’ÉCOSYSTÈME'));
        $this->assertLessThan(strpos($html, 'Autre'), strpos($html, 'Nouvelles de nos partenaires'));
        // Un trait fin entre deux nouvelles, aucun au-dessus de la première.
        $this->assertSame(2, substr_count($html, '<td style="border-top: 1px solid #DCE5EE; padding: 12px 0;'));
        $this->assertSame(1, substr_count($html, '<td style="padding: 12px 0;'));
        // Tableaux équilibrés.
        $this->assertSame(substr_count($html, '<table'), substr_count($html, '</table>'));
        $this->assertSame(substr_count($html, '<tr'), substr_count($html, '</tr>'));
        $this->assertSame(substr_count($html, '<td'), substr_count($html, '</td>'));
    }

    public function test_nouvelles_sans_date_ni_source_et_adresse_refusee(): void {
        $html = Infolettre::bloc_nouvelles([self::gardee(['date' => '', 'source' => '']), self::gardee(['url' => 'javascript:alert(1)', 'titre' => 'Piégée', 'date' => '', 'source' => ''])], self::AUJOURDHUI);
        $this->assertStringNotContainsString('font-size: 11px', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        // Sans adresse valide : le titre reste, en marine, sans lien ni flèche.
        $this->assertSame(1, substr_count($html, '<a '));
        $this->assertSame(1, substr_count($html, '→'));
        $this->assertStringContainsString('font-weight: bold; color: #003469; line-height: 1.35;">Piégée</p>', $html);
    }

    public function test_nouvelles_une_seule_information_ou_sans_resume(): void {
        $html = Infolettre::bloc_nouvelles([self::gardee(['date' => '', 'resume' => '']), self::gardee(['url' => 'https://frq.test/n2', 'titre' => 'Autre', 'source' => ''])], self::AUJOURDHUI);
        $this->assertStringContainsString('line-height: 1.4;">INSPQ</p>', $html);
        $this->assertStringContainsString('line-height: 1.4;">20 oct. 2026</p>', $html);
        $this->assertStringNotContainsString(' · ', $html);
        // Sans résumé, pas de paragraphe vide.
        $this->assertSame(1, substr_count($html, 'font-size: 13px; color: #444444;'));
        $this->assertDoesNotMatchRegularExpression('/<p[^>]*><\/p>/', $html);
        $this->assertStringContainsString('<p style="margin: 0; font-size: 14px; font-weight: bold; line-height: 1.35;"><a href="https://inspq.test/n1"', $html);
    }

    public function test_nouvelles_pourcent_dans_le_titre(): void {
        $html = Infolettre::bloc_nouvelles([self::gardee(['titre' => 'Hausse de 30 % des demandes : 100%s', 'resume' => '50 %d de plus'])], self::AUJOURDHUI);
        $this->assertStringContainsString('Hausse de 30 % des demandes : 100%s&nbsp;', $html);
        $this->assertStringContainsString('50 %d de plus', $html);
    }

    public function test_nouvelles_source_et_resume_echappes(): void {
        $html = Infolettre::bloc_nouvelles([self::gardee(['source' => 'A & B <b>', 'resume' => 'Un "résumé" <script>alert(1)</script> --> fin'])], self::AUJOURDHUI);
        $this->assertStringContainsString('A &amp; B &lt;b&gt; · 20 oct. 2026', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; --&gt; fin', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertSame(1, substr_count($html, '-->'), 'seul le commentaire de tête se ferme');
    }

    public function test_nouvelles_titre_fait_d_espaces_insecables(): void {
        $nbsp = "\u{00A0}";
        $this->assertSame([], Infolettre::nouvelles_retenues([self::gardee(['titre' => $nbsp . ' ' . $nbsp])]));
        $html = Infolettre::bloc_nouvelles([self::gardee(['titre' => $nbsp . 'Entouré' . $nbsp])], self::AUJOURDHUI);
        $this->assertStringContainsString('text-decoration: none;">Entouré&nbsp;<span', $html);
    }

    public function test_nouvelles_vide(): void {
        $html = Infolettre::bloc_nouvelles([], self::AUJOURDHUI);
        $this->assertStringContainsString('Aucun élément', $html);
        // Rien à annoncer : ni carte ni en-tête.
        $this->assertStringNotContainsString('Nouvelles de nos partenaires', $html);
        $this->assertStringNotContainsString('border-radius', $html);
    }

    // --- Nouvelles du RSN (articles du site) ---

    private static function article(array $champs = []): array {
        return $champs + [
            'titre' => 'L’IA en santé : passer des projets pilotes à l’impact', 'url' => 'https://rsn.quebec/ia-en-sante/', 'date' => '2026-10-20',
            'texte' => 'Digital Health Canada et le RSN ont réuni la communauté à Montréal.', 'image_url' => 'https://rsn.quebec/wp-content/uploads/dhc.jpg',
        ];
    }

    public function test_texte_d_un_article_extrait_redige_d_abord(): void {
        $contenu = '<p>Premier paragraphe du texte, assez long pour être retenu comme résumé de la nouvelle.</p>';
        $this->assertSame('Le RSN félicite les onze lauréates et lauréats.', Infolettre::texte_article('Lauréats 2026', ' <p>Le RSN félicite les onze lauréates et lauréats.</p> ', $contenu));
        $this->assertSame('Premier paragraphe du texte, assez long pour être retenu comme résumé de la nouvelle.', Infolettre::texte_article('Lauréats 2026', '', $contenu));
    }

    public function test_texte_d_un_article_premier_vrai_paragraphe(): void {
        // Article en bloc HTML : feuille de style, surtitre, titre répété, puis le texte.
        $contenu = '<link rel="stylesheet" href="https://fonts.test/css"><style>.rsn-ia-article a{color:#003469}</style>'
            . '<script>console.log("x")</script><!-- wp:html -->'
            . '<div class="rsn-ia-article"><div>Intelligence artificielle en santé</div><h1>L’IA en santé : passer des projets pilotes à l’impact</h1>'
            . '<p style="margin: 0"></p><p>Court.</p>'
            . '<p style="margin: 0; font-size: 19px">Le 27 octobre 2026, Digital Health Canada et le <strong>Réseau santé numérique</strong> (RSN) ont réuni la communauté au Plaza Centre-Ville.</p>'
            . '<p>Deuxième paragraphe, lui aussi assez long pour compter, mais qui ne doit pas être repris ici.</p></div>';
        $texte = Infolettre::texte_article('L’IA en santé : passer des projets pilotes à l’impact', '', $contenu);
        $this->assertSame('Le 27 octobre 2026, Digital Health Canada et le Réseau santé numérique (RSN) ont réuni la communauté au Plaza Centre-Ville.', $texte);
        $this->assertStringNotContainsString('color:#003469', $texte);
    }

    public function test_texte_d_un_article_sans_paragraphe_ni_titre_repete(): void {
        // Texte sans balise de paragraphe (éditeur classique) : tout le texte, sans le surtitre ni le titre qui l'ouvrent.
        $contenu = "<div>Accès aux données</div><h1>LRSSS : où en sommes-nous?</h1>Entrée en vigueur en 2024, la loi change l'accès aux données.\n\nSuite du texte.";
        $this->assertSame("Entrée en vigueur en 2024, la loi change l'accès aux données. Suite du texte.", Infolettre::texte_article('LRSSS : où en sommes-nous?', '', $contenu));
        // L'apostrophe du titre n'a pas la même forme dans le texte : le titre est reconnu quand même.
        $this->assertSame('Le texte commence ici.', Infolettre::texte_article("L'IA en santé", '', 'L’IA en santé Le texte commence ici.'));
        // Un titre cité plus loin dans le texte n'est pas une ouverture : rien n'est retiré.
        $long = str_repeat('Une phrase pour éloigner le titre. ', 8);
        $this->assertStringStartsWith('Une phrase pour éloigner', Infolettre::texte_article('Titre cité', '', $long . 'Titre cité plus loin.'));
        $this->assertSame('', Infolettre::texte_article('Titre', '', '<style>p{color:red}</style>'));
    }

    public function test_texte_d_un_article_raccourci(): void {
        // Une seule longue phrase : coupée au dernier mot entier, avec « … ».
        $texte = Infolettre::texte_article('Titre', '', '<p>' . str_repeat('un mot après l’autre ', 40) . '</p>');
        $this->assertLessThanOrEqual(170, mb_strlen($texte));
        $this->assertGreaterThan(140, mb_strlen($texte));
        $this->assertStringEndsWith('…', $texte);
        $this->assertStringNotContainsString(' …', $texte);
    }

    public function test_texte_d_un_article_coupe_a_la_fin_d_une_phrase(): void {
        $debut = 'Le 27 octobre 2026, le RSN a réuni chercheur.e.s et clinicien.ne.s sous le thème « Bâtir une IA équitable et durable ». ';
        $suite = 'La question de la matinée : comment passer de projets pilotes à une IA utile. ';
        $texte = Infolettre::texte_article('Titre', '', '<p>' . $debut . $suite . str_repeat('Encore des mots pour dépasser la limite. ', 8) . '</p>');
        // La dernière phrase entière qui tient dans la limite, sans « … » ni mot orphelin ; « chercheur.e.s » n'est pas une fin de phrase.
        $this->assertSame(trim($debut), $texte);
        // Les phrases courtes qui tiennent ensemble dans la limite sont toutes gardées, la suivante est laissée.
        $this->assertSame('Une première phrase assez longue pour compter comme du texte. Une seconde phrase qui tient encore. Puis d’autres phrases qui dépassent.', Infolettre::texte_article('Titre', '', '<p>Une première phrase assez longue pour compter comme du texte. Une seconde phrase qui tient encore. ' . str_repeat('Puis d’autres phrases qui dépassent. ', 6) . '</p>'));
        // Une fin de phrase trop proche du début ne suffit pas : coupure au mot, avec « … ».
        $court = Infolettre::texte_article('Titre', '', '<p>Bref. ' . str_repeat('des mots sans point final ', 20) . '</p>');
        $this->assertStringEndsWith('…', $court);
    }

    public function test_texte_d_un_article_pas_coupe_sur_une_abreviation(): void {
        $suite = str_repeat('des mots pour dépasser la limite sans autre point ', 6);
        foreach (['selon M. Tremblay', 'explique la Dr. Tremblay', 'santé mobile, etc. et', 'selon J. Tremblay', 'à la p. 12 du rapport'] as $piege) {
            $texte = Infolettre::texte_article('Titre', '', '<p>Le RSN a reçu une subvention importante pour soutenir la recherche, ' . $piege . ' ' . $suite . '</p>');
            $this->assertStringEndsWith('…', $texte, $piege);
            $this->assertLessThanOrEqual(170, mb_strlen($texte), $piege);
        }
        // Une vraie fin de phrase avant l'abréviation est retenue.
        $texte = Infolettre::texte_article('Titre', '', '<p>Le RSN a reçu une subvention importante pour soutenir la recherche au Québec. C’est ce qu’annonce M. Tremblay ' . $suite . '</p>');
        $this->assertSame('Le RSN a reçu une subvention importante pour soutenir la recherche au Québec.', $texte);
    }

    public function test_texte_d_un_article_titre_cite_dans_la_premiere_phrase(): void {
        // Sans paragraphe : le titre n'est retiré que s'il ouvre le texte, pas quand ses mots sont dans une phrase.
        $this->assertSame('Le RSN annonce les lauréats des bourses de mobilité 2026 : onze étudiantes et étudiants.', Infolettre::texte_article('Lauréats des bourses de mobilité 2026', '', 'Le RSN annonce les lauréats des bourses de mobilité 2026 : onze étudiantes et étudiants.'));
        $this->assertSame('Le Réseau de recherche en santé numérique se réunit.', Infolettre::texte_article('Santé', '', '<div>Le Réseau de recherche en santé numérique se réunit.</div>'));
        $this->assertSame('Le Réseau Santé numérique se réunit.', Infolettre::texte_article('Santé', '', '<div>Le Réseau Santé numérique se réunit.</div>'));
        // Titre réécrit par WordPress (guillemets français, points de suspension, tiret) : reconnu quand même.
        $this->assertSame('Texte de la nouvelle.', Infolettre::texte_article("Le projet «\u{00A0}Santé connectée\u{00A0}» est lancé… enfin –\u{00A0}vraiment", '', '<div>NOUVELLE</div><h1>Le projet "Santé connectée" est lancé... enfin - vraiment</h1>Texte de la nouvelle.'));
        // Le texte n'est que le titre : rien à montrer.
        $this->assertSame('', Infolettre::texte_article('Un titre seul', '', '<h1>Un titre seul</h1>'));
    }

    public function test_article_du_rsn_mis_en_forme(): void {
        $article = Infolettre::article_rsn("\u{00A0}Rapport des journées ", 'https://rsn.quebec/rapport/', '2026-08-17 10:24:00', 'Bilan des journées.', '<p>Autre texte.</p>', ' https://rsn.quebec/wp-content/uploads/r.jpg ');
        $this->assertSame(['titre' => 'Rapport des journées', 'url' => 'https://rsn.quebec/rapport/', 'date' => '2026-08-17', 'texte' => 'Bilan des journées.', 'image_url' => 'https://rsn.quebec/wp-content/uploads/r.jpg'], $article);
        $this->assertNull(Infolettre::article_rsn('T', 'https://rsn.quebec/t/', '', '', '')['date']);
    }

    public function test_articles_du_rsn_retenus_depuis_une_date(): void {
        $articles = [
            self::article(['titre' => 'Ancien', 'date' => '2026-09-01']),
            self::article(['titre' => 'Du jour limite', 'date' => '2026-10-01']),
            self::article(['titre' => 'Récent', 'date' => '2026-10-24']),
            self::article(['titre' => 'À paraître', 'date' => '2026-10-30']),
            self::article(['titre' => "\u{00A0} ", 'date' => '2026-10-22']),
            self::article(['titre' => 'Sans date', 'date' => null]),
            'pas un tableau',
        ];
        $titres = static fn(array $liste): array => array_column($liste, 'titre');
        $this->assertSame(['Récent', 'Du jour limite'], $titres(Infolettre::articles_rsn_retenus($articles, '2026-10-01', self::AUJOURDHUI)));
        // Sans date de départ : tout ce qui est déjà paru, le plus récent d'abord.
        $this->assertSame(['Récent', 'Du jour limite', 'Ancien'], $titres(Infolettre::articles_rsn_retenus($articles, null, self::AUJOURDHUI)));
        $this->assertSame([], Infolettre::articles_rsn_retenus([], '2026-10-01', self::AUJOURDHUI));
        // Une date de départ suivie d'un saut de ligne n'est pas une date : aucun filtre, plutôt qu'un filtre décalé.
        $this->assertCount(3, Infolettre::articles_rsn_retenus($articles, "2026-10-01\n", self::AUJOURDHUI));
    }

    public function test_nouvelles_du_rsn_en_billets(): void {
        $html = Infolettre::bloc_nouvelles_rsn([self::article(), self::article(['titre' => 'Rapport <b>2026</b> : 100 % numérique', 'url' => 'https://rsn.quebec/rapport/', 'date' => '2026-10-24', 'image_url' => ''])], '2026-10-01', self::AUJOURDHUI);
        $this->assertStringStartsWith("<!-- Nouvelles du RSN (généré par la veille de l'écosystème, 26 octobre 2026) -->", $html);
        $this->assertSame(2, substr_count($html, '>NOUVELLE DU RSN</span>'));
        // Le plus récent d'abord ; le « % » et les balises du titre restent du texte.
        $this->assertLessThan(strpos($html, 'L’IA en santé'), strpos($html, 'Rapport &lt;b&gt;2026&lt;/b&gt; : 100 % numérique'));
        // Image liée à l'article pour le billet qui en a une ; l'autre est en texte seul.
        $this->assertSame(1, substr_count($html, '<img '));
        $this->assertStringContainsString('<a href="https://rsn.quebec/ia-en-sante/"><img src="https://rsn.quebec/wp-content/uploads/dhc.jpg" width="200" alt=""', $html);
        $this->assertStringContainsString('Digital Health Canada et le RSN ont réuni la communauté à Montréal.', $html);
        // Bouton vers l'article et date de parution en mention.
        $this->assertSame(2, substr_count($html, ">Lire l'article →</a>"));
        $this->assertStringContainsString('font-style: italic;">Publié le 20 octobre 2026</span>', $html);
        $this->assertStringContainsString('font-style: italic;">Publié le 24 octobre 2026</span>', $html);
        // Deux cartes, tableaux équilibrés.
        $this->assertSame(2, substr_count($html, 'border-radius: 10px; border-collapse: separate;'));
        $this->assertSame(substr_count($html, '<table'), substr_count($html, '</table>'));
    }

    public function test_nouvelles_du_rsn_adresse_refusee_et_bloc_vide(): void {
        $html = Infolettre::bloc_nouvelles_rsn([self::article(['url' => 'javascript:alert(1)', 'image_url' => 'javascript:alert(2)'])], null, self::AUJOURDHUI);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('L’IA en santé', $html);
        // Rien de paru depuis la date : le message, sans billet.
        $vide = Infolettre::bloc_nouvelles_rsn([self::article()], '2026-10-25', self::AUJOURDHUI);
        $this->assertStringContainsString('Aucun élément', $vide);
        $this->assertStringNotContainsString('NOUVELLE DU RSN', $vide);
    }

    public function test_texte_brut_des_nouvelles_du_rsn(): void {
        $texte = Infolettre::texte_brut(Infolettre::bloc_nouvelles_rsn([self::article(), self::article(['titre' => 'Rapport', 'url' => 'https://rsn.quebec/rapport/', 'date' => '2026-10-24', 'image_url' => '', 'texte' => ''])], null, self::AUJOURDHUI));
        $this->assertSame("NOUVELLE DU RSN\nRapport\nLire l'article → https://rsn.quebec/rapport/\nPublié le 24 octobre 2026\n\n"
            . "NOUVELLE DU RSN\nL’IA en santé : passer des projets pilotes à l’impact\nDigital Health Canada et le RSN ont réuni la communauté à Montréal.\nLire l'article → https://rsn.quebec/ia-en-sante/\nPublié le 20 octobre 2026", $texte);
    }

    // --- Texte brut ---

    public function test_texte_brut(): void {
        $html = Infolettre::bloc_agenda([self::evenement(['titre' => 'A & B']), self::evenement(['titre' => 'Deuxième', 'debut' => '2026-11-20', 'themes' => [], 'resume' => ''])], 8, null, self::AUJOURDHUI);
        $texte = Infolettre::texte_brut($html);
        $this->assertStringNotContainsString('<', $texte);
        $this->assertStringNotContainsString('&amp;', $texte);
        // Une information par ligne, une ligne vide entre deux événements.
        $this->assertSame("12 NOV.\nA & B\nMila · Mila – Montréal\nIA Santé\nUne présentation sur l’IA en santé.\nEn savoir plus → https://mila.quebec/fr/evenements/midi\nPrésentiel – Gratuit\n\n"
            . "20 NOV.\nDeuxième\nMila · Mila – Montréal\nEn savoir plus → https://mila.quebec/fr/evenements/midi\nPrésentiel – Gratuit", $texte);
        $this->assertSame('Aucun élément à inclure pour le moment.', Infolettre::texte_brut(Infolettre::bloc_nouvelles([], self::AUJOURDHUI)));
        $this->assertSame('Aucun élément à inclure pour le moment.', Infolettre::texte_brut(Infolettre::bloc_agenda([], 8, null, self::AUJOURDHUI)));
    }

    public function test_texte_brut_d_un_appel_et_des_billets(): void {
        $appels = Infolettre::texte_brut(Infolettre::bloc_appels([self::appel(['url' => 'https://frq.test/bourses'])], [], self::AUJOURDHUI));
        $this->assertSame("DATE LIMITE\n20 NOV.\nConcours de bourses\nFRQ\nBourse\nBourses de recherche.\nConsulter l'appel → https://frq.test/bourses", $appels);

        $billets = Infolettre::texte_brut(Infolettre::bloc_programmes_rsn([], [self::programme(), ['url' => 'https://rsn.quebec/mobilite/'] + self::programme(['echeance' => '2027-01-17', 'lien' => ''])], self::AUJOURDHUI));
        // L'image liée ne laisse pas une adresse seule ; les renseignements sont chacun sur leurs lignes.
        $this->assertStringStartsWith("PROGRAMME DU RSN\nAppel à projets de recherche 2027-2028\nProgramme fondateur du RSN, destiné aux équipes multi-axes.\n"
            . "POUR QUI\nChercheur·euses\nMONTANT\n40 000 \$ par projet, sur deux ans\nDÉPÔT\n13 décembre 2026, à 23 h 59 (heure de l'Est)\n"
            . "Voir l'appel sur Fourwaves → https://event.fourwaves.com/fr/rsnappelprojets\nLire l'annonce https://rsn.quebec/appel-projets/\n\nPROGRAMME DU RSN\n", $billets);
        $this->assertStringNotContainsString("\n\n\n", $billets);
        $this->assertSame(1, substr_count($billets, "\n\n"), 'une seule ligne vide, entre les deux billets');
        $this->assertStringNotContainsString('if mso', $billets);

        $evenement = Infolettre::texte_brut(Infolettre::bloc_evenements_rsn([self::evenement_rsn()], null, self::AUJOURDHUI));
        $this->assertSame("ÉVÉNEMENT DU RSN\n12-13 NOV.\nAtelier de co-création\nRSN · Agora de Mila – Montréal\nFormation\nDeux jours pour imaginer des solutions.\nEn savoir plus → https://rsn.quebec/evenement/atelier/\nPrésentiel", $evenement);

        // Les nouvelles : l'en-tête de la carte, puis une ligne vide entre deux nouvelles ; l'adresse suit le titre.
        $nouvelles = Infolettre::texte_brut(Infolettre::bloc_nouvelles([self::gardee(), self::gardee(['url' => 'https://frq.test/n2', 'titre' => 'Autre', 'date' => '2026-10-24'])], self::AUJOURDHUI));
        $this->assertSame("DANS L’ÉCOSYSTÈME\nNouvelles de nos partenaires\n\nINSPQ · 24 oct. 2026\nAutre → https://frq.test/n2\nRésumé de la nouvelle.\n\nINSPQ · 20 oct. 2026\nUne nouvelle → https://inspq.test/n1\nRésumé de la nouvelle.", $nouvelles);
        // Une seule nouvelle, sans adresse valide, sans résumé, sans source ni date : l'en-tête puis le titre.
        $seule = Infolettre::texte_brut(Infolettre::bloc_nouvelles([self::gardee(['url' => '', 'resume' => '', 'source' => '', 'date' => ''])], self::AUJOURDHUI));
        $this->assertSame("DANS L’ÉCOSYSTÈME\nNouvelles de nos partenaires\n\nUne nouvelle", $seule);
    }
}

<?php
declare(strict_types=1);

namespace RSN\Veille;

use RSN\Veille\Admin\Dates;
use RSN\Veille\Lecteurs\Echeance;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Blocs HTML de l'infolettre, à coller dans Mailchimp : billets du RSN (événements, programmes de
 * financement), agenda et appels de l'écosystème, nouvelles gardées.
 *
 * Ce que le RSN organise (`du_rsn`) a ses propres blocs, en billets avec image ; le reste de l'écosystème est en
 * listes, au style de l'infolettre de l'automne 2026 (pastille de date marine et jaune, pastilles de thème,
 * bouton bleu). Les nouvelles gardées tiennent dans une seule carte compacte.
 *
 * Classe pure : aucune lecture de WordPress (les fiches arrivent en tableaux au format fiche), seulement
 * l'échappement (`esc_html`, `esc_url`) et les dates en français (`Dates`). Tableaux HTML aux styles
 * en ligne seulement (les messageries et Mailchimp ignorent les feuilles de style).
 */
final class Infolettre {
    /** Nombre d'événements par défaut de l'agenda. */
    public const NOMBRE_PAR_DEFAUT = 8;

    private const GRIS = '#6E7A84';
    private const MARINE = '#003469';

    // Couleurs de l'infolettre du RSN (automne 2026).
    private const BLEU = '#0061AF';
    private const JAUNE = '#FFCC4E';
    private const VERT = '#00A759';
    private const ROUGE = '#EE3124';
    private const TEXTE = '#444444';
    /** Gris des mentions (format, surtitre) : plus foncé que le #AAAAAA de l'infolettre, pour rester lisible. */
    private const GRIS_MENTION = '#767676';
    private const TRAIT_LIGNE = '#E8E8E8';
    private const BORD_BILLET = '#D5DEE8';
    private const FOND_FAITS = '#F2F6FA';
    /** Traits de la carte des nouvelles : contour de l'étiquette, séparation entre deux nouvelles. */
    private const TRAIT_CARTE = '#DCE5EE';
    private const POLICE = 'font-family: Arial, Helvetica, sans-serif;';

    /** Couleur de la pastille de chaque thème ; un thème absent d'ici est en marine. Jaune : texte marine. */
    private const COULEURS_THEMES = [
        'IA' => self::BLEU, 'Données' => self::BLEU, 'Science ouverte' => self::BLEU,
        'Santé publique' => self::ROUGE, 'Vieillissement' => self::ROUGE, 'Sommeil' => self::ROUGE,
        'Réseautage' => self::VERT, 'Formation' => self::VERT, 'Appel à communications' => self::VERT,
        'Financement' => self::JAUNE,
    ];

    /** Niveaux d'études de la relève, dans l'ordre et les mots de la page Programmes du site (extrait 45). */
    private const NIVEAUX = ['bac' => 'baccalauréat', 'maitrise' => 'maîtrise', 'doctorat' => 'doctorat', 'postdoc' => 'postdoc'];

    /** Lieu plus long que cela : seule sa première partie (avant la virgule) est gardée. */
    private const LIEU_MAX = 44;

    /** Largeur intérieure d'un billet (px) sous laquelle le texte passe sous l'image. */
    private const BILLET_BASCULE = 460;

    /** Texte d'un billet de programme : plus long que le résumé d'une ligne. */
    private const TEXTE_BILLET_MAX = 600;

    // --- Sélection des éléments ---

    /**
     * Événements à venir (aujourd'hui compris, un événement en cours aussi), triés par début. Sans horizon,
     * les `$nombre` premiers ; avec un horizon (`$jusqu_au`, Y-m-d), tous ceux qui commencent au plus tard ce jour-là.
     *
     * @param array<int,mixed> $fiches Fiches au format `vers_tableau()` ; les autres types sont ignorés.
     * @return array<int,array<string,mixed>>
     */
    public static function evenements_retenus(array $fiches, int $nombre = self::NOMBRE_PAR_DEFAUT, ?string $jusqu_au = null, ?string $aujourdhui = null): array {
        $aujourdhui ??= \rsn_veille_aujourdhui();
        $horizon = self::date_valide($jusqu_au) ? $jusqu_au : null;
        $retenus = [];
        foreach ($fiches as $fiche) {
            if (!is_array($fiche) || !in_array($fiche['type'] ?? 'evenement', ['evenement', '', null], true)) {
                continue;
            }
            $debut = self::date_ou_null($fiche['debut'] ?? null);
            if ($debut === null || trim((string) ($fiche['titre'] ?? '')) === '') {
                continue;
            }
            $fin = self::date_ou_null($fiche['fin'] ?? null) ?? $debut;
            if (max($debut, $fin) < $aujourdhui || ($horizon !== null && $debut > $horizon)) {
                continue;
            }
            $retenus[] = $fiche;
        }
        usort($retenus, static fn(array $a, array $b): int => [(string) $a['debut'], (string) $a['titre']] <=> [(string) $b['debut'], (string) $b['titre']]);
        return $horizon === null ? array_slice($retenus, 0, max(0, $nombre)) : $retenus;
    }

    /**
     * Appels ouverts : externes et programmes du RSN ensemble (une même adresse une seule fois). Un appel
     * externe est retenu s'il est en cours (`Fiche::appel_en_cours` : date limite à venir, sinon publié il y
     * a moins de 30 jours) ; un programme du RSN, déjà dit « ouvert » par le site (`rsn_appel_etat`), si sa
     * date limite n'est pas passée.
     * Triés par date limite (les plus proches d'abord), puis ceux sans date limite par date de publication.
     *
     * @param array<int,mixed> $appels_externes
     * @param array<int,mixed> $programmes_rsn
     * @return array<int,array<string,mixed>>
     */
    public static function appels_retenus(array $appels_externes, array $programmes_rsn, ?string $aujourdhui = null): array {
        $aujourdhui ??= \rsn_veille_aujourdhui();
        $retenus = [];
        $adresses = [];
        // Programmes du RSN d'abord : un appel collecté qui pointe vers la même page leur cède la place.
        $nb_programmes = count($programmes_rsn);
        foreach (array_merge(array_values($programmes_rsn), array_values($appels_externes)) as $position => $appel) {
            if (!is_array($appel) || !in_array($appel['type'] ?? 'appel', ['appel', '', null], true) || trim((string) ($appel['titre'] ?? '')) === '') {
                continue;
            }
            $adresse = rtrim(trim((string) ($appel['url'] ?? '')), '/');
            if ($adresse !== '') {
                if (isset($adresses[$adresse])) {
                    continue;
                }
                $adresses[$adresse] = true;
            }
            $appel['echeance'] = self::date_ou_null($appel['echeance'] ?? null);
            if ($appel['echeance'] !== null && $appel['echeance'] < $aujourdhui) {
                continue;
            }
            if ($position >= $nb_programmes && !Fiche::depuis_tableau(array_merge($appel, [
                'debut' => self::date_ou_null($appel['debut'] ?? null),
                'fin' => self::date_ou_null($appel['fin'] ?? null),
                'cree_le' => self::date_ou_null($appel['cree_le'] ?? null),
            ]))->appel_en_cours($aujourdhui)) {
                continue;
            }
            $appel['debut'] = self::date_ou_null($appel['debut'] ?? null);
            if ($position < $nb_programmes) {
                $appel['organise_par'] = 'rsn';
            }
            $retenus[] = $appel;
        }
        $cle = static fn(array $a): array => [$a['echeance'] === null ? 1 : 0, (string) $a['echeance'], (string) $a['debut'], (string) $a['titre']];
        usort($retenus, static fn(array $a, array $b): int => $cle($a) <=> $cle($b));
        return $retenus;
    }

    /**
     * Nouvelles gardées qui ont un titre (autre chose que des espaces, insécables comprises), la plus récente
     * d'abord (celles sans date à la fin).
     *
     * @param array<int,mixed> $gardees
     * @return array<int,array<string,mixed>>
     */
    public static function nouvelles_retenues(array $gardees): array {
        $retenues = array_values(array_filter($gardees, static fn($g): bool => is_array($g) && self::net((string) ($g['titre'] ?? '')) !== ''));
        usort($retenues, static fn(array $a, array $b): int => strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? '')));
        return $retenues;
    }

    /** Vrai pour ce que le RSN organise : `organise_par` posé par `Donnees`, ou programme du site. */
    public static function du_rsn(array $element): bool {
        return ($element['organise_par'] ?? '') === 'rsn';
    }

    /**
     * Événements du RSN à venir, tous (ou jusqu'à l'horizon) : ils ont leur propre bloc, en billets.
     *
     * @param array<int,mixed> $fiches
     * @return array<int,array<string,mixed>>
     */
    public static function evenements_rsn_retenus(array $fiches, ?string $jusqu_au = null, ?string $aujourdhui = null): array {
        return self::evenements_retenus(self::selon_origine($fiches, true), PHP_INT_MAX, $jusqu_au, $aujourdhui);
    }

    /**
     * Événements de l'écosystème (tout sauf ceux du RSN) : les `$nombre` prochains, ou jusqu'à l'horizon.
     *
     * @param array<int,mixed> $fiches
     * @return array<int,array<string,mixed>>
     */
    public static function evenements_ecosysteme_retenus(array $fiches, int $nombre = self::NOMBRE_PAR_DEFAUT, ?string $jusqu_au = null, ?string $aujourdhui = null): array {
        return self::evenements_retenus(self::selon_origine($fiches, false), $nombre, $jusqu_au, $aujourdhui);
    }

    /**
     * Appels du RSN en cours : les programmes ouverts du site, et les appels publiés que le RSN organise.
     *
     * @param array<int,mixed> $appels_externes
     * @param array<int,mixed> $programmes_rsn
     * @return array<int,array<string,mixed>>
     */
    public static function appels_rsn_retenus(array $appels_externes, array $programmes_rsn, ?string $aujourdhui = null): array {
        return self::selon_origine(self::appels_retenus($appels_externes, $programmes_rsn, $aujourdhui), true);
    }

    /**
     * Appels de l'écosystème en cours (tout sauf ceux du RSN). Un appel collecté qui pointe vers la page d'un
     * programme du RSN n'y est pas : le programme, dans son bloc, le remplace.
     *
     * @param array<int,mixed> $appels_externes
     * @param array<int,mixed> $programmes_rsn
     * @return array<int,array<string,mixed>>
     */
    public static function appels_ecosysteme_retenus(array $appels_externes, array $programmes_rsn, ?string $aujourdhui = null): array {
        return self::selon_origine(self::appels_retenus($appels_externes, $programmes_rsn, $aujourdhui), false);
    }

    /**
     * @param array<int,mixed> $elements
     * @return array<int,array<string,mixed>>
     */
    private static function selon_origine(array $elements, bool $rsn): array {
        return array_values(array_filter($elements, static fn($e): bool => is_array($e) && self::du_rsn($e) === $rsn));
    }

    /**
     * Un programme du RSN (article du site) rendu comme un appel. Une période qui ne contient pas de date
     * limite lisible (« date limite : 15 novembre 2026 ») ne donne pas d'échéance.
     *
     * `$details` porte ce que la case du programme montre sur le site, pour son billet : `echeance` (date
     * limite `Y-m-d`, qui prime sur la période), `image_url`, `lien` (formulaire de dépôt, Fourwaves le plus
     * souvent), `pour_qui` et `montant`. Chaque clé est facultative.
     *
     * @param array{echeance?: mixed, image_url?: mixed, lien?: mixed, pour_qui?: mixed, montant?: mixed} $details
     * @return array<string,mixed>
     */
    public static function appel_programme(string $titre, string $url, string $extrait, string $periode, string $date_publication, ?string $aujourdhui = null, array $details = []): array {
        $aujourdhui ??= \rsn_veille_aujourdhui();
        $texte = Fiche::nettoyer_texte($extrait);
        $chaine = static fn(string $cle): string => is_string($details[$cle] ?? null) ? trim($details[$cle]) : '';
        return [
            'type' => 'appel',
            'titre' => $titre,
            'url' => $url,
            'resume' => Fiche::court($texte),
            'texte' => Fiche::court($texte, self::TEXTE_BILLET_MAX),
            'debut' => self::date_ou_null(substr($date_publication, 0, 10)),
            'echeance' => self::date_ou_null($details['echeance'] ?? null) ?? Echeance::trouver($periode, $aujourdhui),
            'organisateur' => 'RSN',
            'organise_par' => 'rsn',
            'programme' => true,
            'categorie' => __('Programme du RSN', 'rsn-veille'),
            'image_url' => $chaine('image_url'),
            'lien' => $chaine('lien'),
            'pour_qui' => $chaine('pour_qui'),
            'montant' => $chaine('montant'),
        ];
    }

    /**
     * Valeur d'une ligne « Libellé : valeur » du bloc « En bref » du texte d'un article de programme, comme la
     * page Programmes du site (extrait 45) : libellé en gras dans un paragraphe ou une puce, sinon ligne de texte.
     * La valeur s'arrête à la fin du paragraphe ou de la puce, ou à un saut de ligne (un bloc « En bref » écrit
     * en un seul paragraphe). Vide si aucun libellé n'est trouvé.
     *
     * @param string[] $libelles
     */
    public static function en_bref(string $contenu, array $libelles): string {
        foreach ($libelles as $libelle) {
            $l = preg_quote((string) $libelle, '#');
            if (preg_match('#<(?:strong|b)>\s*' . $l . '\s*(?::\s*)?</(?:strong|b)>\s*(?:&nbsp;|\s)*:?\s*(.*?)(?:<br\s*/?>|</(?:li|p)>)#isu', $contenu, $m) === 1) {
                return self::majuscule(Fiche::nettoyer_texte($m[1]));
            }
            $lignes = preg_replace('#</(?:li|p|h\d)>|<br\s*/?>#i', "\n", $contenu) ?? $contenu;
            $lignes = html_entity_decode(strip_tags($lignes), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match('#^[ \t\x{00A0}]*' . $l . '[ \t\x{00A0}]*:[ \t\x{00A0}]*(.+)$#imu', $lignes, $m) === 1) {
                return self::majuscule(trim($m[1]));
            }
        }
        return '';
    }

    /**
     * « Pour qui » d'un programme, d'après les cases de l'article (`rsn_appel_public` : `releve`, `chercheurs` ;
     * `rsn_appel_niveaux`) : « Relève étudiante (maîtrise, doctorat) et chercheur·euses ». Sans case cochée, la
     * ligne « Pour qui » du bloc « En bref ».
     *
     * @param string[] $public
     * @param string[] $niveaux
     */
    public static function pour_qui(array $public, array $niveaux, string $en_bref = ''): string {
        $morceaux = [];
        if (in_array('releve', $public, true)) {
            $choisis = array_values(array_intersect(array_keys(self::NIVEAUX), $niveaux));
            $releve = __('Relève étudiante', 'rsn-veille');
            if (count($choisis) === count(self::NIVEAUX)) {
                $releve .= ', ' . __('tous niveaux', 'rsn-veille');
            } elseif ($choisis !== []) {
                $releve .= ' (' . implode(', ', array_map(static fn(string $cle): string => self::NIVEAUX[$cle], $choisis)) . ')';
            }
            $morceaux[] = $releve;
        }
        if (in_array('chercheurs', $public, true)) {
            $morceaux[] = $morceaux === [] ? __('Chercheur·euses', 'rsn-veille') : __('chercheur·euses', 'rsn-veille');
        }
        return $morceaux !== [] ? implode(' ' . __('et', 'rsn-veille') . ' ', $morceaux) : trim($en_bref);
    }

    /** Premier lien Fourwaves du texte d'un article (formulaire de dépôt d'un programme) ; vide s'il n'y en a pas. */
    public static function lien_fourwaves(string $contenu): string {
        return preg_match('#https?://(?:[a-z0-9-]+\.)*fourwaves\.com/[^\s"\'<>]+#i', $contenu, $m) === 1 ? html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
    }

    // --- Blocs ---

    /**
     * Événements du RSN à venir, en billets (voir `evenements_rsn_retenus`).
     *
     * @param array<int,mixed> $fiches
     */
    public static function bloc_evenements_rsn(array $fiches, ?string $jusqu_au = null, ?string $aujourdhui = null): string {
        $aujourdhui ??= \rsn_veille_aujourdhui();
        $billets = [];
        foreach (self::evenements_rsn_retenus($fiches, $jusqu_au, $aujourdhui) as $fiche) {
            $billets[] = self::billet_evenement($fiche, $aujourdhui);
        }
        /* translators: %s : date du jour. */
        $commentaire = sprintf(__("Événements du RSN (généré par la veille de l'écosystème, %s)", 'rsn-veille'), Dates::date_longue($aujourdhui));
        return self::assembler($commentaire, $billets, 'padding: 0 0 16px 0;', 'padding: 0;');
    }

    /**
     * Programmes de financement du RSN en cours, en billets (voir `appels_rsn_retenus`).
     *
     * @param array<int,mixed> $appels_externes
     * @param array<int,mixed> $programmes_rsn
     */
    public static function bloc_programmes_rsn(array $appels_externes, array $programmes_rsn, ?string $aujourdhui = null): string {
        $aujourdhui ??= \rsn_veille_aujourdhui();
        $billets = [];
        foreach (self::appels_rsn_retenus($appels_externes, $programmes_rsn, $aujourdhui) as $appel) {
            $billets[] = self::billet_appel($appel);
        }
        /* translators: %s : date du jour. */
        $commentaire = sprintf(__("Programmes de financement du RSN en cours (généré par la veille de l'écosystème, %s)", 'rsn-veille'), Dates::date_longue($aujourdhui));
        return self::assembler($commentaire, $billets, 'padding: 0 0 16px 0;', 'padding: 0;');
    }

    /**
     * Agenda de l'écosystème : les événements à venir, sans ceux du RSN (voir `evenements_ecosysteme_retenus`).
     *
     * @param array<int,mixed> $fiches
     */
    public static function bloc_agenda(array $fiches, int $nombre = self::NOMBRE_PAR_DEFAUT, ?string $jusqu_au = null, ?string $aujourdhui = null): string {
        $aujourdhui ??= \rsn_veille_aujourdhui();
        $lignes = [];
        foreach (self::evenements_ecosysteme_retenus($fiches, $nombre, $jusqu_au, $aujourdhui) as $fiche) {
            $lignes[] = self::ligne_evenement($fiche, $aujourdhui);
        }
        /* translators: %s : date du jour. */
        $commentaire = sprintf(__("Agenda de l'écosystème (généré par la veille de l'écosystème, %s)", 'rsn-veille'), Dates::date_longue($aujourdhui));
        return self::assembler($commentaire, $lignes, 'border-bottom: 1px solid ' . self::TRAIT_LIGNE . '; padding: 14px 0;', 'padding: 14px 0 0 0;');
    }

    /**
     * Appels de l'écosystème en cours, sans ceux du RSN (voir `appels_ecosysteme_retenus`).
     *
     * @param array<int,mixed> $appels_externes
     * @param array<int,mixed> $programmes_rsn
     */
    public static function bloc_appels(array $appels_externes, array $programmes_rsn, ?string $aujourdhui = null): string {
        $aujourdhui ??= \rsn_veille_aujourdhui();
        $lignes = [];
        foreach (self::appels_ecosysteme_retenus($appels_externes, $programmes_rsn, $aujourdhui) as $appel) {
            $lignes[] = self::ligne_appel($appel);
        }
        /* translators: %s : date du jour. */
        $commentaire = sprintf(__("Appels de l'écosystème (généré par la veille de l'écosystème, %s)", 'rsn-veille'), Dates::date_longue($aujourdhui));
        return self::assembler($commentaire, $lignes, 'border-bottom: 1px solid ' . self::TRAIT_LIGNE . '; padding: 14px 0;', 'padding: 14px 0 0 0;');
    }

    /**
     * Nouvelles gardées depuis le radar, la plus récente d'abord, réunies dans une seule carte : une étiquette
     * et un titre en tête, puis chaque nouvelle (source et date, titre lié, résumé) séparée de la suivante par
     * un trait fin.
     *
     * @param array<int,mixed> $gardees `{url, titre, date, source, ...}`
     */
    public static function bloc_nouvelles(array $gardees, ?string $aujourdhui = null): string {
        $aujourdhui ??= \rsn_veille_aujourdhui();
        /* translators: %s : date du jour. */
        $commentaire = sprintf(__("Nouvelles de l'écosystème gardées pour l'infolettre (généré par la veille de l'écosystème, %s)", 'rsn-veille'), Dates::date_longue($aujourdhui));
        $nouvelles = self::nouvelles_retenues($gardees);
        if ($nouvelles === []) {
            return self::assembler($commentaire, [], '', '');
        }
        $rangees = [];
        foreach ($nouvelles as $i => $nouvelle) {
            // Le `</tr>` en fin de ligne marque la fin de l'élément pour `texte_brut`.
            $rangees[] = '              <tr><td style="' . ($i > 0 ? 'border-top: 1px solid ' . self::TRAIT_CARTE . '; ' : '') . 'padding: 12px 0; ' . self::POLICE . ' text-align: left;">'
                . self::nouvelle($nouvelle) . '</td></tr>';
        }
        $etiquette = '<p style="margin: 0 0 8px 0; line-height: 1.2;"><span style="font-size: 10px; font-weight: bold; color: ' . self::MARINE . '; background-color: ' . self::FOND_FAITS
            . '; border: 1px solid ' . self::TRAIT_CARTE . '; padding: 3px 9px; border-radius: 99px; letter-spacing: 0.5px;">' . self::texte(__('DANS L’ÉCOSYSTÈME', 'rsn-veille')) . '</span></p>';
        $titre = '<p style="margin: 0; font-size: 17px; font-weight: bold; color: ' . self::MARINE . '; line-height: 1.25;">' . self::texte(__('Nouvelles de nos partenaires', 'rsn-veille')) . '</p>';
        $table = '<table width="100%" cellpadding="0" cellspacing="0" border="0"';
        return '<!-- ' . self::commentaire($commentaire) . " -->\n"
            . $table . ' style="' . self::POLICE . ' max-width: 600px; margin: 0 auto;">' . "\n"
            . "  <tr>\n    <td style=\"padding: 0;\">\n"
            . '      ' . $table . ' style="border: 1px solid ' . self::BORD_BILLET . '; border-radius: 10px; border-collapse: separate; background-color: #ffffff;">' . "\n"
            . '        <tr><td style="padding: 16px 16px 6px 16px; ' . self::POLICE . ' text-align: left;">' . $etiquette . $titre . "</td></tr>\n"
            . '        <tr><td style="padding: 0 16px 8px 16px;">' . "\n"
            . '          ' . $table . ">\n"
            . implode("\n", $rangees) . "\n"
            . "          </table>\n        </td></tr>\n      </table>\n    </td>\n  </tr>\n</table>";
    }

    /**
     * Version texte d'un bloc (partie `text/plain` de la copie) : une ligne par information, la date sur sa
     * ligne, l'adresse des liens après leur texte, une ligne vide entre deux éléments, sans balises ni commentaire.
     *
     * Repère de fin d'élément : un `</tr>` en fin de ligne. Les rangées intérieures d'un élément (pastille de
     * date, renseignements, boutons) ne sont jamais suivies d'un saut de ligne dans le code produit ici.
     */
    public static function texte_brut(string $html): string {
        $texte = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;
        // Un lien : son texte puis son adresse. Un lien sans texte (une image) disparaît.
        $texte = preg_replace_callback(
            '/<a\s[^>]*?href="([^"]*)"[^>]*>(.*?)<\/a>/is',
            static fn(array $m): string => trim(strip_tags($m[2])) === '' ? '' : $m[2] . ($m[1] !== '' ? ' ' . $m[1] : ''),
            $texte
        ) ?? $texte;
        // Pastille de date (petit tableau de 52 px) : « 12 NOV. » sur une ligne.
        $texte = preg_replace_callback(
            '/<table[^>]*width: 52px[^>]*>(.*?)<\/table>/is',
            static fn(array $m): string => trim((string) preg_replace('/\s+/u', ' ', strip_tags(str_replace(['</td>', '<br>'], ' ', $m[1])))) . "\n",
            $texte
        ) ?? $texte;
        $texte = preg_replace('/<\/tr>[ \t]*\n/i', "\n\x1E\n", $texte) ?? $texte;
        $texte = preg_replace(['/<br\s*\/?>/i', '/<\/tr>/i', '/<\/td>/i', '/<\/p>/i', '/<\/div>/i'], "\n", $texte) ?? $texte;
        $texte = html_entity_decode(strip_tags($texte), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lignes = [];
        foreach (explode("\n", $texte) as $ligne) {
            $ligne = trim((string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $ligne), " \t");
            if ($ligne === "\x1E") {
                // Une seule ligne vide entre deux éléments, aucune en tête.
                if ($lignes !== [] && end($lignes) !== '') {
                    $lignes[] = '';
                }
            } elseif ($ligne !== '') {
                $lignes[] = $ligne;
            }
        }
        return trim(implode("\n", $lignes));
    }

    // --- Lignes de l'écosystème ---

    /** @param array<string,mixed> $fiche */
    private static function ligne_evenement(array $fiche, string $aujourdhui): string {
        return self::ligne(
            self::pastille_evenement((string) $fiche['debut'], self::date_ou_null($fiche['fin'] ?? null), $aujourdhui),
            '',
            (string) $fiche['titre'],
            self::jointure(' · ', [(string) ($fiche['organisateur'] ?? ''), self::lieu($fiche)]),
            self::themes($fiche),
            (string) ($fiche['resume'] ?? ''),
            self::bouton((string) ($fiche['url'] ?? ''), __('En savoir plus →', 'rsn-veille')),
            self::format($fiche)
        );
    }

    /** @param array<string,mixed> $appel */
    private static function ligne_appel(array $appel): string {
        $echeance = self::date_ou_null($appel['echeance'] ?? null);
        $publie = self::date_ou_null($appel['debut'] ?? null);
        $pastille = '';
        $surtitre = '';
        if ($echeance !== null) {
            $pastille = self::pastille_date(Dates::numero_jour($echeance), self::mois($echeance));
            $surtitre = __('Date limite', 'rsn-veille');
        } elseif ($publie !== null) {
            // Sans date limite : la date de publication, dans une pastille en contour.
            $pastille = self::pastille_date(Dates::numero_jour($publie), self::mois($publie), '', true);
            $surtitre = __('Publié le', 'rsn-veille');
        }
        $categorie = trim((string) ($appel['categorie'] ?? ''));
        $pastilles = self::themes($appel);
        if ($categorie !== '' && $categorie !== __('Appel', 'rsn-veille') && !in_array($categorie, $pastilles, true)) {
            array_unshift($pastilles, $categorie);
        }
        return self::ligne(
            $pastille,
            $surtitre,
            (string) $appel['titre'],
            trim((string) ($appel['organisateur'] ?? '')),
            $pastilles,
            (string) ($appel['resume'] ?? ''),
            self::bouton((string) ($appel['url'] ?? ''), __("Consulter l'appel →", 'rsn-veille')),
            ''
        );
    }

    /**
     * Une ligne : pastille de date à gauche ; titre, organisateur, pastilles de thème, résumé, bouton et mention.
     *
     * @param string[] $pastilles Noms des pastilles (thèmes, catégorie).
     * @param string $bouton HTML du bouton, vide sans adresse.
     */
    private static function ligne(string $pastille, string $surtitre, string $titre, string $sous_titre, array $pastilles, string $resume, string $bouton, string $mention): string {
        $haut = $surtitre === '' ? '' : '<p style="margin: 0 0 4px 0; font-size: 9px; font-weight: bold; color: ' . self::GRIS_MENTION
            . '; letter-spacing: 0.5px; text-align: center; width: 52px; line-height: 1.1;">' . self::texte(mb_strtoupper($surtitre)) . '</p>';
        $corps = ['<p style="margin: 0 0 4px 0; font-size: 14px; font-weight: bold; color: ' . self::MARINE . ';">' . self::texte($titre) . '</p>'];
        if ($sous_titre !== '') {
            $corps[] = '<p style="margin: 0; font-size: 12px; color: ' . self::BLEU . ';">' . self::texte($sous_titre) . '</p>';
        }
        if ($pastilles !== []) {
            $corps[] = '<p style="margin: 4px 0 10px 0; text-align: right;">' . implode(' ', array_map(static fn(string $p): string => self::pastille_theme($p, 'margin-left: 4px;'), $pastilles)) . '</p>';
        }
        $resume = trim($resume);
        if ($resume !== '') {
            $corps[] = '<p style="margin: ' . ($pastilles !== [] ? '0 0 10px 0' : '8px 0 10px 0') . '; font-size: 13px; color: ' . self::TEXTE . '; line-height: 1.5;">' . self::texte($resume) . '</p>';
        }
        $pied = self::pied($bouton, '', $mention, $resume === '' && $pastilles === [] ? ' style="margin-top: 10px;"' : '');
        if ($pied !== '') {
            $corps[] = $pied;
        }
        return '      <table width="100%" cellpadding="0" cellspacing="0" border="0"><tr>' . "\n"
            . '        <td width="60" valign="top" style="padding-right: 14px; padding-top: 2px;">' . $haut . $pastille . '</td>' . "\n"
            . '        <td valign="top">' . "\n          " . implode("\n          ", $corps) . "\n        </td>\n"
            . '      </tr></table>';
    }

    // --- Billets du RSN ---

    /** @param array<string,mixed> $fiche */
    private static function billet_evenement(array $fiche, string $aujourdhui): string {
        $url = (string) ($fiche['url'] ?? '');
        return self::billet([
            'etiquette' => __('Événement du RSN', 'rsn-veille'),
            'titre' => (string) $fiche['titre'],
            'pastille' => self::pastille_evenement((string) $fiche['debut'], self::date_ou_null($fiche['fin'] ?? null), $aujourdhui),
            'sous_titre' => self::jointure(' · ', [(string) ($fiche['organisateur'] ?? ''), self::lieu($fiche)]),
            'themes' => self::themes($fiche),
            'texte' => (string) ($fiche['resume'] ?? ''),
            'image' => (string) ($fiche['image_url'] ?? ''),
            'url' => $url,
            'pied' => self::pied(self::bouton($url, __('En savoir plus →', 'rsn-veille')), '', self::format($fiche)),
        ]);
    }

    /** @param array<string,mixed> $appel Programme du site (`programme`) ou appel publié que le RSN organise. */
    private static function billet_appel(array $appel): string {
        $programme = !empty($appel['programme']);
        $url = (string) ($appel['url'] ?? '');
        $faits = [];
        foreach (['pour_qui' => __('Pour qui', 'rsn-veille'), 'montant' => __('Montant', 'rsn-veille')] as $cle => $nom) {
            $valeur = trim((string) ($appel[$cle] ?? ''));
            if ($valeur !== '') {
                $faits[] = [$nom, self::texte($valeur)];
            }
        }
        $echeance = self::date_ou_null($appel['echeance'] ?? null);
        if ($echeance !== null) {
            $date = '<b>' . self::texte(Dates::date_longue($echeance)) . '</b>';
            // Heure de fermeture des programmes du RSN, comme sur la page Programmes du site (extrait 45).
            /* translators: %s : date limite du dépôt, en gras. */
            $faits[] = [__('Dépôt', 'rsn-veille'), $programme ? str_replace('%s', $date, self::texte(__("%s, à 23 h 59 (heure de l'Est)", 'rsn-veille'))) : $date];
        }
        // Le bouton mène au formulaire de dépôt quand l'article en donne un ; l'annonce reste en second lien.
        $lien = trim((string) ($appel['lien'] ?? ''));
        $bouton = $lien === '' ? '' : self::bouton($lien, stripos($lien, 'fourwaves') !== false ? __("Voir l'appel sur Fourwaves →", 'rsn-veille') : __("Voir l'appel →", 'rsn-veille'));
        $second = '';
        if ($bouton === '') {
            $bouton = self::bouton($url, __("Consulter l'appel →", 'rsn-veille'));
        } elseif (esc_url(trim($url)) !== '') {
            $second = '<a href="' . esc_url(trim($url)) . '" style="font-size: 12px; font-weight: bold; color: ' . self::BLEU . ';">' . self::texte(__("Lire l'annonce", 'rsn-veille')) . '</a>';
        }
        $texte = trim((string) ($appel['texte'] ?? ''));
        return self::billet([
            'etiquette' => $programme ? __('Programme du RSN', 'rsn-veille') : __('Appel du RSN', 'rsn-veille'),
            'titre' => (string) $appel['titre'],
            'pastille' => '',
            'sous_titre' => '',
            'themes' => [],
            'texte' => $texte !== '' ? $texte : (string) ($appel['resume'] ?? ''),
            'faits' => $faits,
            'image' => (string) ($appel['image_url'] ?? ''),
            'url' => $url,
            'pied' => self::pied($bouton, $second, ''),
        ]);
    }

    /**
     * Un billet : image à gauche et texte à droite (l'image passe au-dessus sur un écran étroit, sans feuille de
     * style : deux blocs `inline-block`, le second de largeur fluide), renseignements puis boutons dessous.
     *
     * @param array{etiquette: string, titre: string, pastille: string, sous_titre: string, themes: string[], texte: string, faits?: array<int,array{0:string,1:string}>, image: string, url: string, pied: string} $b
     */
    private static function billet(array $b): string {
        $droite = ['<p style="margin: 0 0 8px 0; line-height: 1.2;"><span style="font-size: 10px; font-weight: bold; color: ' . self::MARINE . '; background-color: ' . self::JAUNE
            . '; padding: 3px 9px; border-radius: 99px; letter-spacing: 0.5px;">' . self::texte(mb_strtoupper($b['etiquette'])) . '</span></p>'];
        // Par assemblage, jamais par `sprintf` : un « % » dans un titre (« 100 % IA ») n'est pas un format.
        $titre = static fn(string $marge_basse): string => '<p style="margin: 0 0 ' . $marge_basse . ' 0; font-size: 17px; font-weight: bold; color: ' . self::MARINE
            . '; line-height: 1.25;">' . self::texte($b['titre']) . '</p>';
        if ($b['pastille'] !== '') {
            $sous_titre = $b['sous_titre'] === '' ? '' : '<p style="margin: 0; font-size: 12px; color: ' . self::BLEU . '; line-height: 1.4;">' . self::texte($b['sous_titre']) . '</p>';
            $droite[] = '<table cellpadding="0" cellspacing="0" border="0"><tr><td width="52" valign="top" style="padding-right: 12px;">' . $b['pastille'] . '</td>'
                . '<td valign="top">' . $titre('3px') . $sous_titre . '</td></tr></table>';
        } else {
            $droite[] = $titre('6px');
        }
        if ($b['themes'] !== []) {
            $droite[] = '<p style="margin: 8px 0 0 0; line-height: 1.2;">' . implode(' ', array_map(static fn(string $t): string => self::pastille_theme($t, 'margin-right: 4px;'), $b['themes'])) . '</p>';
        }
        if (trim($b['texte']) !== '') {
            $droite[] = '<p style="margin: 8px 0 0 0; font-size: 13px; color: ' . self::TEXTE . '; line-height: 1.5;">' . self::texte(trim($b['texte'])) . '</p>';
        }
        $cellule_texte = '<table width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td style="padding: 0 0 12px 0; ' . self::POLICE . ' font-size: 13px; line-height: 1.5; text-align: left;">'
            . "\n              " . implode("\n              ", $droite) . "\n            </td></tr></table>";

        $image = esc_url(trim($b['image']));
        $adresse = esc_url(trim($b['url']));
        if ($image !== '') {
            $img = '<img src="' . $image . '" width="200" alt="" style="display: block; width: 100%; max-width: 200px; height: auto; border: 0; border-radius: 8px;">';
            // La colonne du texte prend la place qui reste à côté de l'image, quelle que soit la largeur du gabarit
            // où le bloc est collé, et passe dessous, sur toute la largeur, quand il reste moins de
            // `self::BILLET_BASCULE` px (téléphone). Sans feuille de style : `min-width`, `max-width` et `width` en
            // `calc()`. Une messagerie qui ignore `calc()` garde `width: 100%` et `max-width: 314px` (colonnes
            // fixes, qui tiennent dans un gabarit de 564 px) ; Outlook sur ordinateur suit le tableau de repli.
            $haut = '          <!--[if mso]><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td width="216" valign="top"><![endif]-->' . "\n"
                . '          <div style="display: inline-block; width: 100%; max-width: 216px; vertical-align: top;">' . "\n"
                . '            <table width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td style="padding: 0 16px 12px 0;">' . ($adresse !== '' ? '<a href="' . $adresse . '">' . $img . '</a>' : $img) . '</td></tr></table>' . "\n"
                . '          </div>' . "\n"
                . '          <!--[if mso]></td><td valign="top"><![endif]-->' . "\n"
                . '          <div style="display: inline-block; vertical-align: top; width: 100%; max-width: 314px; min-width: calc(100% - 216px); max-width: calc(100% + 0px); width: calc((' . self::BILLET_BASCULE . 'px - 100%) * 1000);">' . "\n"
                . '            ' . $cellule_texte . "\n"
                . '          </div>' . "\n"
                . '          <!--[if mso]></td></tr></table><![endif]-->';
        } else {
            $haut = '          <div style="display: inline-block; width: 100%; vertical-align: top;">' . "\n"
                . '            ' . $cellule_texte . "\n"
                . '          </div>';
        }
        $bas = '';
        if (!empty($b['faits'])) {
            $bas .= '<tr><td style="padding: 0 16px 14px 16px;">' . self::faits($b['faits']) . '</td></tr>';
        }
        if ($b['pied'] !== '') {
            $bas .= '<tr><td style="padding: 0 16px 16px 16px;">' . $b['pied'] . '</td></tr>';
        }
        return '      <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border: 1px solid ' . self::BORD_BILLET . '; border-radius: 10px; border-collapse: separate; background-color: #ffffff;">' . "\n"
            . '        <tr><td style="padding: 16px 16px 4px 16px; font-size: 0; line-height: 0; text-align: left;">' . "\n"
            . $haut . "\n"
            . '        </td></tr>' . $bas . '</table>';
    }

    /**
     * Renseignements d'un programme (Pour qui, Montant, Dépôt), un par rangée sur fond clair.
     *
     * @param array<int,array{0:string,1:string}> $faits Nom en texte, valeur en HTML déjà échappé.
     */
    private static function faits(array $faits): string {
        $rangees = '';
        foreach (array_values($faits) as $i => [$nom, $valeur]) {
            $haut = $i === 0 ? '' : 'border-top: 1px solid #DCE5EE; ';
            $rangees .= '<tr><td width="74" valign="top" style="' . $haut . 'padding: 7px 0; font-size: 10px; font-weight: bold; color: ' . self::GRIS_MENTION
                . '; letter-spacing: 0.5px; line-height: 1.6;">' . self::texte(mb_strtoupper($nom)) . '</td>'
                . '<td valign="top" style="' . $haut . 'padding: 7px 0; font-size: 13px; color: ' . self::MARINE . '; line-height: 1.4;">' . $valeur . '</td></tr>';
        }
        return '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: ' . self::FOND_FAITS . '; border-radius: 8px;">'
            . '<tr><td style="padding: 4px 14px;"><table width="100%" cellpadding="0" cellspacing="0" border="0">' . $rangees . '</table></td></tr></table>';
    }

    // --- Nouvelles ---

    /**
     * Contenu d'une nouvelle dans la carte : source et date en petit, titre lié suivi d'une flèche (titre seul
     * sans adresse valide), résumé. Assemblé sans `sprintf` : un « % » dans un titre reste tel quel.
     *
     * @param array<string,mixed> $nouvelle
     */
    private static function nouvelle(array $nouvelle): string {
        $mention = self::jointure(' · ', [(string) ($nouvelle['source'] ?? ''), Dates::date_courte((string) ($nouvelle['date'] ?? ''))]);
        $titre = self::texte(self::net((string) $nouvelle['titre']));
        $adresse = esc_url(trim((string) ($nouvelle['url'] ?? '')));
        $resume = trim((string) ($nouvelle['resume'] ?? ''));
        $style_titre = 'margin: ' . ($resume !== '' ? '0 0 4px 0' : '0') . '; font-size: 14px; font-weight: bold;';
        return ($mention !== '' ? '<p style="margin: 0 0 3px 0; font-size: 11px; color: ' . self::GRIS_MENTION . '; line-height: 1.4;">' . self::texte($mention) . '</p>' : '')
            . ($adresse !== ''
                ? '<p style="' . $style_titre . ' line-height: 1.35;"><a href="' . $adresse . '" style="color: ' . self::MARINE . '; text-decoration: none;">' . $titre
                    . '&nbsp;<span style="color: ' . self::BLEU . ';">→</span></a></p>'
                : '<p style="' . $style_titre . ' color: ' . self::MARINE . '; line-height: 1.35;">' . $titre . '</p>')
            . ($resume !== '' ? '<p style="margin: 0; font-size: 13px; color: ' . self::TEXTE . '; line-height: 1.5;">' . self::texte($resume) . '</p>' : '');
    }

    // --- Pièces ---

    /**
     * Assemble un bloc : chaque contenu dans sa rangée (`$style` ; `$style_dernier` pour la dernière). Sans
     * contenu, un message.
     *
     * @param string[] $contenus
     */
    private static function assembler(string $commentaire, array $contenus, string $style, string $style_dernier): string {
        $rangees = [];
        $dernier = count($contenus) - 1;
        foreach (array_values($contenus) as $i => $contenu) {
            // Le `</tr>` en fin de ligne marque la fin de l'élément pour `texte_brut`.
            $rangees[] = "  <tr>\n    <td style=\"" . ($i === $dernier ? $style_dernier : $style) . "\">\n" . $contenu . "\n    </td>\n  </tr>";
        }
        if ($rangees === []) {
            $rangees = ['  ' . self::ligne_vide()];
        }
        return '<!-- ' . self::commentaire($commentaire) . " -->\n"
            . '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="' . self::POLICE . ' max-width: 600px; margin: 0 auto;">' . "\n"
            . implode("\n", $rangees) . "\n</table>";
    }

    private static function ligne_vide(): string {
        return '<tr><td style="vertical-align:top;padding:0 0 18px 0;color:' . self::GRIS . '">' . self::texte(__('Aucun élément à inclure pour le moment.', 'rsn-veille')) . '</td></tr>';
    }

    /** Un commentaire HTML ne peut contenir « -- » ni se terminer par « - ». */
    private static function commentaire(string $texte): string {
        return str_replace('--', '–', $texte);
    }

    /**
     * Pastille de date : jour en jaune sur fond marine, mois en blanc dessous. `$creuse` : en contour (date de
     * publication d'un appel sans date limite). `$surtitre` : petite ligne au-dessus (« JUSQU'AU ») ; `$suite` :
     * petite ligne dessous (« → 2 DÉC. »).
     */
    private static function pastille_date(string $jour, string $mois, string $surtitre = '', bool $creuse = false, string $suite = ''): string {
        $taille = mb_strlen($jour) <= 2 ? 18 : (mb_strlen($jour) <= 3 ? 14 : 13);
        $c_jour = $creuse ? self::MARINE : self::JAUNE;
        $c_mois = $creuse ? self::MARINE : '#ffffff';
        $haut = $surtitre === '' ? '' : '<tr><td style="padding: 5px 2px 0 2px; font-size: 8px; font-weight: bold; color: ' . $c_mois . '; line-height: 1;">' . self::texte($surtitre) . '</td></tr>';
        $bas = $suite === '' ? '' : '<br><span style="font-weight: normal; letter-spacing: 0;">' . self::texte($suite) . '</span>';
        return '<table cellpadding="0" cellspacing="0" border="0" style="background-color: ' . ($creuse ? '#ffffff' : self::MARINE) . ';' . ($creuse ? ' border: 1px solid ' . self::MARINE . ';' : '')
            . ' border-radius: 6px; width: 52px; text-align: center;">' . $haut
            . '<tr><td style="padding: ' . ($surtitre === '' ? '6px' : '3px') . ' 4px 2px 4px; font-size: ' . $taille . 'px; font-weight: bold; color: ' . $c_jour . '; line-height: 1;">' . self::texte($jour) . '</td></tr>'
            . '<tr><td style="padding: 2px 4px 6px 4px; font-size: 10px; font-weight: bold; color: ' . $c_mois . '; letter-spacing: 1px; line-height: ' . ($suite === '' ? '1' : '1.3') . ';">' . self::texte($mois) . $bas . '</td></tr></table>';
    }

    /**
     * Pastille d'un événement : « 12 NOV. » ; « 7-8 OCT. » sur plusieurs jours d'un même mois ; « 28 NOV. → 2 DÉC. »
     * à cheval sur deux mois ; « JUSQU'AU 18 DÉC. » pour un événement ou une série déjà commencés.
     */
    private static function pastille_evenement(string $debut, ?string $fin, string $aujourdhui): string {
        if ($fin === null || $fin < $debut) {
            $fin = $debut;
        }
        if ($debut < $aujourdhui && $fin >= $aujourdhui) {
            return self::pastille_date(Dates::numero_jour($fin), self::mois($fin), mb_strtoupper(__("Jusqu'au", 'rsn-veille')));
        }
        if ($fin === $debut) {
            return self::pastille_date(Dates::numero_jour($debut), self::mois($debut));
        }
        if (substr($fin, 0, 7) === substr($debut, 0, 7)) {
            return self::pastille_date(Dates::numero_jour($debut) . '-' . Dates::numero_jour($fin), self::mois($debut));
        }
        return self::pastille_date(Dates::numero_jour($debut), self::mois($debut), '', false, '→ ' . Dates::numero_jour($fin) . ' ' . self::mois($fin));
    }

    /** Pastille de thème (ou de catégorie), à la couleur du thème. */
    private static function pastille_theme(string $nom, string $marge): string {
        $fond = self::COULEURS_THEMES[$nom] ?? self::MARINE;
        return '<span style="font-size: 10px; font-weight: bold; color: ' . ($fond === self::JAUNE ? self::MARINE : '#ffffff') . '; background-color: ' . $fond
            . '; padding: 2px 8px; border-radius: 99px; ' . $marge . '">' . self::texte($nom) . '</span>';
    }

    /** Bouton bleu arrondi ; vide sans adresse http(s) ou mailto valide. */
    private static function bouton(string $url, string $texte): string {
        $adresse = esc_url(trim($url));
        return $adresse === '' ? '' : '<a href="' . $adresse . '" style="display: inline-block; background-color: ' . self::BLEU
            . '; color: #ffffff; font-size: 12px; font-weight: bold; padding: 7px 16px; border-radius: 99px; text-decoration: none;">' . self::texte($texte) . '</a>';
    }

    /** Pied d'un élément : bouton, second lien et mention en italique sur une rangée ; vide si tout est vide. */
    private static function pied(string $bouton, string $second, string $mention, string $attribut = ''): string {
        $cellules = [];
        foreach ([$bouton, $second] as $html) {
            if ($html !== '') {
                $cellules[] = $html;
            }
        }
        if (trim($mention) !== '') {
            $cellules[] = '<span style="font-size: 11px; color: ' . self::GRIS_MENTION . '; font-style: italic;">' . self::texte(trim($mention)) . '</span>';
        }
        if ($cellules === []) {
            return '';
        }
        $dernier = count($cellules) - 1;
        $html = '';
        foreach ($cellules as $i => $cellule) {
            $html .= '<td' . ($i === $dernier ? '' : ' style="padding-right: 12px;"') . '>' . $cellule . '</td>';
        }
        return '<table cellpadding="0" cellspacing="0" border="0"' . $attribut . '><tr valign="middle">' . $html . '</tr></table>';
    }

    /**
     * Thèmes d'une fiche, sans vide ni doublon.
     *
     * @param array<string,mixed> $fiche
     * @return string[]
     */
    private static function themes(array $fiche): array {
        $themes = is_array($fiche['themes'] ?? null) ? $fiche['themes'] : [];
        return array_values(array_unique(array_filter(array_map(static fn($t): string => is_scalar($t) ? trim((string) $t) : '', $themes), static fn(string $t): bool => $t !== '')));
    }

    /**
     * Lieu d'un événement : « Agora de Mila – Montréal ». « Online » devient « En ligne » ; d'un lieu très long
     * (adresse complète), seule la première partie est gardée ; la ville n'est pas répétée si le lieu la nomme.
     *
     * @param array<string,mixed> $fiche
     */
    private static function lieu(array $fiche): string {
        $lieu = trim((string) ($fiche['lieu'] ?? ''));
        $ville = trim((string) ($fiche['ville'] ?? ''));
        if (mb_strtolower($lieu) === 'online') {
            $lieu = __('En ligne', 'rsn-veille');
        }
        if (mb_strlen($lieu) > self::LIEU_MAX && str_contains($lieu, ',')) {
            $lieu = trim(explode(',', $lieu)[0]);
        }
        if ($ville !== '' && $lieu !== '' && mb_stripos($lieu, $ville) !== false) {
            $ville = '';
        }
        return self::jointure(' – ', [$lieu, $ville]);
    }

    /**
     * Format d'un événement : « Présentiel – Gratuit · En anglais ».
     *
     * @param array<string,mixed> $fiche
     */
    private static function format(array $fiche): string {
        $format = self::jointure(' – ', [(string) ($fiche['format'] ?? ''), (string) ($fiche['prix'] ?? '')]);
        if (($fiche['langue'] ?? '') === 'en') {
            $format .= ($format !== '' ? ' · ' : '') . __('En anglais', 'rsn-veille');
        }
        return $format;
    }

    /** Mois abrégé en capitales (« NOV. », « DÉC. »). */
    private static function mois(?string $date): string {
        return mb_strtoupper(Dates::mois_court($date));
    }

    private static function majuscule(string $texte): string {
        return mb_strtoupper(mb_substr($texte, 0, 1)) . mb_substr($texte, 1);
    }

    /** Texte sans les espaces qui l'entourent, espaces insécables comprises (`trim` les laisse). */
    private static function net(string $texte): string {
        return (string) preg_replace('/^[\s\x{00A0}\x{202F}]+|[\s\x{00A0}\x{202F}]+$/u', '', $texte);
    }

    /** @param string[] $morceaux */
    private static function jointure(string $separateur, array $morceaux): string {
        return implode($separateur, array_filter(array_map('trim', $morceaux), static fn(string $m): bool => $m !== ''));
    }

    /** Texte échappé pour un contenu de balise ; l'apostrophe reste telle quelle (sûre hors attribut) pour un code lisible. */
    private static function texte(string $texte): string {
        return str_replace('&#039;', "'", esc_html($texte));
    }

    private static function date_valide(?string $date): bool {
        return $date !== null && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private static function date_ou_null(mixed $date): ?string {
        return is_string($date) && self::date_valide($date) ? $date : null;
    }
}

<?php
declare(strict_types=1);

namespace RSN\Veille;

use RSN\Veille\Admin\Dates;
use RSN\Veille\Lecteurs\Echeance;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Blocs HTML de l'infolettre (Agenda, Appels, Nouvelles), à coller dans Mailchimp.
 *
 * Classe pure : aucune lecture de WordPress (les fiches arrivent en tableaux au format fiche), seulement
 * l'échappement (`esc_html`, `esc_url`) et les dates en français (`Dates`). Tableaux HTML aux styles
 * en ligne seulement (les messageries et Mailchimp ignorent les feuilles de style), calqués sur l'infolettre
 * de septembre 2026 et sur les fonctions de copie de `docs/maquette/gabarit-v6.1.html`.
 */
final class Infolettre {
    /** Nombre d'événements par défaut de l'agenda. */
    public const NOMBRE_PAR_DEFAUT = 8;

    /** Style du tableau de chaque bloc. */
    private const STYLE_TABLE = "border-collapse:collapse;font-family:'Plus Jakarta Sans',Arial,sans-serif;font-size:14px;color:#101828";

    private const GRIS = '#6E7A84';
    private const MARINE = '#003469';
    private const TRAIT = '#3D6A99';

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
     * Appels ouverts : externes et programmes du RSN ensemble (une même adresse une seule fois), ceux dont
     * la date limite n'est pas passée.
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
        foreach (array_merge($programmes_rsn, $appels_externes) as $appel) {
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
            $appel['debut'] = self::date_ou_null($appel['debut'] ?? null);
            $retenus[] = $appel;
        }
        $cle = static fn(array $a): array => [$a['echeance'] === null ? 1 : 0, (string) $a['echeance'], (string) $a['debut'], (string) $a['titre']];
        usort($retenus, static fn(array $a, array $b): int => $cle($a) <=> $cle($b));
        return $retenus;
    }

    /**
     * Nouvelles gardées qui ont un titre, la plus récente d'abord (celles sans date à la fin).
     *
     * @param array<int,mixed> $gardees
     * @return array<int,array<string,mixed>>
     */
    public static function nouvelles_retenues(array $gardees): array {
        $retenues = array_values(array_filter($gardees, static fn($g): bool => is_array($g) && trim((string) ($g['titre'] ?? '')) !== ''));
        usort($retenues, static fn(array $a, array $b): int => strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? '')));
        return $retenues;
    }

    /**
     * Un programme du RSN (article du site) rendu comme un appel. Une période qui ne contient pas de date
     * limite lisible (« date limite : 15 novembre 2026 ») ne donne pas d'échéance.
     *
     * @return array<string,mixed>
     */
    public static function appel_programme(string $titre, string $url, string $extrait, string $periode, string $date_publication, ?string $aujourdhui = null): array {
        $aujourdhui ??= \rsn_veille_aujourdhui();
        return [
            'type' => 'appel',
            'titre' => $titre,
            'url' => $url,
            'resume' => Fiche::court(Fiche::nettoyer_texte($extrait)),
            'debut' => self::date_ou_null(substr($date_publication, 0, 10)),
            'echeance' => Echeance::trouver($periode, $aujourdhui),
            'organisateur' => 'RSN',
            'categorie' => __('Programme du RSN', 'rsn-veille'),
        ];
    }

    // --- Blocs ---

    /**
     * Agenda : les événements à venir (voir `evenements_retenus`).
     *
     * @param array<int,mixed> $fiches
     */
    public static function bloc_agenda(array $fiches, int $nombre = self::NOMBRE_PAR_DEFAUT, ?string $jusqu_au = null, ?string $aujourdhui = null): string {
        $aujourdhui ??= \rsn_veille_aujourdhui();
        $lignes = [];
        foreach (self::evenements_retenus($fiches, $nombre, $jusqu_au, $aujourdhui) as $fiche) {
            $lignes[] = self::ligne_evenement($fiche);
        }
        /* translators: %s : date du jour. */
        $commentaire = sprintf(__("Agenda — événements à venir (généré par la veille de l'écosystème, %s)", 'rsn-veille'), Dates::date_longue($aujourdhui));
        return self::assembler($commentaire, $lignes);
    }

    /**
     * Appels : appels externes publiés et programmes du RSN ouverts (voir `appels_retenus`).
     *
     * @param array<int,mixed> $appels_externes
     * @param array<int,mixed> $programmes_rsn
     */
    public static function bloc_appels(array $appels_externes, array $programmes_rsn, ?string $aujourdhui = null): string {
        $aujourdhui ??= \rsn_veille_aujourdhui();
        $lignes = [];
        foreach (self::appels_retenus($appels_externes, $programmes_rsn, $aujourdhui) as $appel) {
            $lignes[] = self::ligne_appel($appel);
        }
        /* translators: %s : date du jour. */
        $commentaire = sprintf(__("Appels en cours (généré par la veille de l'écosystème, %s)", 'rsn-veille'), Dates::date_longue($aujourdhui));
        return self::assembler($commentaire, $lignes);
    }

    /**
     * Nouvelles gardées depuis le radar, la plus récente d'abord.
     *
     * @param array<int,mixed> $gardees `{url, titre, date, source, ...}`
     */
    public static function bloc_nouvelles(array $gardees, ?string $aujourdhui = null): string {
        $aujourdhui ??= \rsn_veille_aujourdhui();
        $lignes = [];
        foreach (self::nouvelles_retenues($gardees) as $nouvelle) {
            $lignes[] = self::ligne_nouvelle($nouvelle);
        }
        /* translators: %s : date du jour. */
        $commentaire = sprintf(__("Nouvelles de l'écosystème gardées pour l'infolettre (généré par la veille de l'écosystème, %s)", 'rsn-veille'), Dates::date_longue($aujourdhui));
        return self::assembler($commentaire, $lignes);
    }

    /**
     * Version texte d'un bloc (partie `text/plain` de la copie) : une ligne par information, la date
     * sur sa ligne, l'adresse des liens après leur texte, sans balises ni commentaire.
     */
    public static function texte_brut(string $html): string {
        $texte = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;
        $texte = preg_replace_callback(
            '/<a\s[^>]*?href="([^"]*)"[^>]*>(.*?)<\/a>/is',
            static fn(array $m): string => $m[2] . ($m[1] !== '' ? ' ' . $m[1] : ''),
            $texte
        ) ?? $texte;
        $texte = preg_replace('/<\/td>\s+<td/i', '</td><td', $texte) ?? $texte;
        $texte = preg_replace(['/<br\s*\/?>/i', '/<\/tr>/i', '/<\/td>/i', '/<\/div>/i'], ["\n", "\n\n", "\n", ' '], $texte) ?? $texte;
        $texte = html_entity_decode(strip_tags($texte), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lignes = array_map(
            static fn(string $ligne): string => trim((string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $ligne), " \t"),
            explode("\n", $texte)
        );
        return trim((string) preg_replace('/\n{3,}/', "\n\n", implode("\n", $lignes)));
    }

    // --- Lignes ---

    /** @param array<string,mixed> $fiche */
    private static function ligne_evenement(array $fiche): string {
        $debut = (string) $fiche['debut'];
        $fin = self::date_ou_null($fiche['fin'] ?? null) ?? $debut;
        if ($fin < $debut) {
            $fin = $debut;
        }
        $jour = Dates::numero_jour($debut);
        if ($fin !== $debut) {
            $jour = substr($fin, 0, 7) === substr($debut, 0, 7)
                ? $jour . '–' . Dates::numero_jour($fin)
                : $jour . ' ' . Dates::mois_court($debut) . ' – ' . Dates::numero_jour($fin);
        }
        $date = '<td style="width:64px;text-align:center;vertical-align:top;color:' . self::MARINE . '">'
            . '<div style="font-size:28px;line-height:1">' . self::texte($jour) . '</div>'
            . '<div style="font-size:11px;letter-spacing:.08em;text-transform:uppercase">' . self::texte(Dates::mois_court($fin)) . '</div></td>';

        $lieu = implode(' – ', array_values(array_unique(array_filter([
            trim((string) ($fiche['lieu'] ?? '')),
            trim((string) ($fiche['ville'] ?? '')),
        ], static fn(string $v): bool => $v !== ''))));
        $themes = array_filter(array_map(static fn($t): string => trim((string) $t), is_array($fiche['themes'] ?? null) ? $fiche['themes'] : []), static fn(string $t): bool => $t !== '');
        $format = implode(' – ', array_filter([trim((string) ($fiche['format'] ?? '')), trim((string) ($fiche['prix'] ?? ''))], static fn(string $v): bool => $v !== ''));
        if (($fiche['langue'] ?? '') === 'en') {
            $format .= ($format !== '' ? ' · ' : '') . __('En anglais', 'rsn-veille');
        }

        $lien = self::lien((string) ($fiche['url'] ?? ''), __("Plus d'information →", 'rsn-veille'));
        $pied = $lien . ($format !== '' ? ($lien !== '' ? ' &nbsp; ' : '') . self::gris(self::texte($format)) : '');
        $lignes = [
            '<b style="font-size:16px">' . self::texte((string) $fiche['titre']) . '</b>',
            self::gris(self::texte(self::jointure(' · ', [(string) ($fiche['organisateur'] ?? ''), $lieu]))),
            $themes !== [] ? '<span style="font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:' . self::TRAIT . '">' . self::texte(implode(' · ', $themes)) . '</span>' : '',
            self::texte((string) ($fiche['resume'] ?? '')),
            $pied,
        ];
        return '<tr>' . $date . "\n" . self::cellule_contenu($lignes) . '</tr>';
    }

    /** @param array<string,mixed> $appel */
    private static function ligne_appel(array $appel): string {
        $echeance = $appel['echeance'] ?? null;
        $quand = $echeance ?? ($appel['debut'] ?? null);
        $date = '<td style="width:64px;text-align:center;vertical-align:top;color:' . self::MARINE . '">';
        if (is_string($quand) && $quand !== '') {
            $date .= '<div style="font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:' . self::GRIS . '">'
                . self::texte($echeance !== null ? __('Date limite', 'rsn-veille') : __('Publié', 'rsn-veille')) . '</div>'
                . '<div style="font-size:24px;line-height:1">' . self::texte(Dates::numero_jour($quand)) . '</div>'
                . '<div style="font-size:11px;letter-spacing:.08em;text-transform:uppercase">' . self::texte(Dates::mois_court($quand)) . '</div>';
        }
        $date .= '</td>';
        $categorie = trim((string) ($appel['categorie'] ?? ''));
        $lignes = [
            '<b style="font-size:16px">' . self::texte((string) $appel['titre']) . '</b>',
            self::gris(self::texte(self::jointure(' · ', [(string) ($appel['organisateur'] ?? ''), $categorie !== '' ? $categorie : __('Appel', 'rsn-veille')]))),
            self::texte((string) ($appel['resume'] ?? '')),
            self::lien((string) ($appel['url'] ?? ''), __("Consulter l'appel →", 'rsn-veille')),
        ];
        return '<tr>' . $date . "\n" . self::cellule_contenu($lignes) . '</tr>';
    }

    /** @param array<string,mixed> $nouvelle */
    private static function ligne_nouvelle(array $nouvelle): string {
        $entete = self::jointure(' · ', [Dates::date_courte((string) ($nouvelle['date'] ?? '')), trim((string) ($nouvelle['source'] ?? ''))]);
        $lignes = [
            $entete !== '' ? '<span style="font-size:12px;color:' . self::GRIS . '">' . self::texte($entete) . '</span>' : '',
            '<b style="font-size:16px">' . self::texte((string) $nouvelle['titre']) . '</b>',
            self::texte((string) ($nouvelle['resume'] ?? '')),
            self::lien((string) ($nouvelle['url'] ?? ''), __('Lire la suite →', 'rsn-veille')),
        ];
        return '<tr><td style="vertical-align:top;padding:0 0 16px 0">' . implode('<br>', array_filter($lignes, static fn(string $l): bool => $l !== '')) . '</td></tr>';
    }

    // --- Pièces ---

    /** @param string[] $lignes */
    private static function cellule_contenu(array $lignes): string {
        return '<td style="vertical-align:top;padding:0 0 18px 14px">' . implode('<br>', array_filter($lignes, static fn(string $l): bool => $l !== '')) . '</td>';
    }

    /** @param string[] $lignes Lignes `<tr>` ; sans ligne, un message. */
    private static function assembler(string $commentaire, array $lignes): string {
        if ($lignes === []) {
            $lignes = ['<tr><td style="vertical-align:top;padding:0 0 18px 0;color:' . self::GRIS . '">' . self::texte(__('Aucun élément à inclure pour le moment.', 'rsn-veille')) . '</td></tr>'];
        }
        // Un commentaire HTML ne peut contenir « -- » ni se terminer par « - ».
        $commentaire = str_replace('--', '–', $commentaire);
        return '<!-- ' . $commentaire . " -->\n<table style=\"" . self::STYLE_TABLE . "\">\n" . implode("\n", $lignes) . "\n</table>";
    }

    /** Lien « texte → » en marine ; vide sans adresse http(s) ou mailto valide. */
    private static function lien(string $url, string $texte): string {
        $adresse = esc_url(trim($url));
        return $adresse === '' ? '' : '<a href="' . $adresse . '" style="color:' . self::MARINE . '">' . self::texte($texte) . '</a>';
    }

    /** Texte gris (organisateur, lieu, format) ; vide si le contenu l'est. */
    private static function gris(string $html): string {
        return $html === '' ? '' : '<span style="color:' . self::GRIS . '">' . $html . '</span>';
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

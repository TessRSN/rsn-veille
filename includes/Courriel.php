<?php
declare(strict_types=1);

namespace RSN\Veille;

use RSN\Veille\Admin\Dates;
use RSN\Veille\Admin\Menu;
use RSN\Veille\Admin\PageRadar;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Courriel hebdomadaire optionnel de la veille (réglage `courriel` de `rsn_veille_reglages`) : les
 * fiches créées depuis sept jours, celles qui attendent une approbation et les nouvelles repérées par
 * le radar. Planifié par WP-Cron (`rsn_veille_hebdo`, récurrence « weekly »), le jour choisi à 08:00
 * à Montréal.
 *
 * `composer` est pure (aucune lecture de WordPress, seulement l'échappement et les dates en français) ;
 * le reste lit l'entrepôt, les réglages et le cache du radar, planifie et envoie. L'envoi ne fait aucune
 * requête réseau autre que le courriel : le radar est lu dans son cache (jamais relancé), et une section
 * sans cache est simplement absente.
 */
final class Courriel {
    public const CROCHET = 'rsn_veille_hebdo';
    /** Journal du dernier envoi : date (Y-m-d H:i), destinataires, nb (nouveautés), ok. */
    public const OPTION_DERNIER = 'rsn_veille_dernier_courriel';

    /** Heure d'envoi (heure du site, Montréal). */
    private const HEURE = [8, 0];
    /** Fenêtre des nouveautés, en jours. */
    private const JOURS = 7;
    /** Fiches listées par section ; au-delà, une ligne « Et N autres ». */
    private const MAX_PAR_SECTION = 25;
    /** Nouvelles du radar listées. */
    private const MAX_RADAR = 10;

    private const MARINE = '#003469';
    private const JAUNE = '#FFCC4E';
    private const ENCRE = '#101828';
    private const FILET = '#DCE1E8';
    private const GRIS = '#6E7A84';

    /** Version texte à joindre au prochain `wp_mail` (partie `text/plain` du courriel) ; null hors envoi. */
    private static ?string $texte_alternatif = null;

    // --- Composition (pure) ---

    /**
     * Courriel de la semaine, ou null s'il n'y a aucune nouveauté (fiches publiées + fiches à approuver) :
     * dans ce cas rien n'est envoyé, même si le radar a repéré des nouvelles.
     *
     * @param array<int,array<string,mixed>> $nouvelles_fiches Fiches publiées créées depuis sept jours (format `Fiche::vers_tableau()`).
     * @param array<int,array<string,mixed>> $en_attente Fiches à approuver.
     * @param array<int,array<string,mixed>> $nouvelles_radar Nouvelles du radar (titre, source, date, url).
     * @param string $url_file Adresse de la file d'approbation.
     * @param string $aujourdhui Date du jour, Y-m-d.
     * @return array{sujet: string, html: string, texte: string}|null
     */
    public static function composer(array $nouvelles_fiches, array $en_attente, array $nouvelles_radar, string $url_file, string $aujourdhui): ?array {
        $publiees = self::fiches_lisibles($nouvelles_fiches);
        $attente = self::fiches_lisibles($en_attente);
        $radar = self::fiches_lisibles($nouvelles_radar);
        $total = count($publiees) + count($attente);
        if ($total === 0) {
            return null;
        }

        /* translators: %d : nombre de nouveautés de la semaine. */
        $sujet = sprintf(_n("Veille de l'écosystème : %d nouveauté cette semaine", "Veille de l'écosystème : %d nouveautés cette semaine", $total, 'rsn-veille'), $total);
        $intro = self::intro(count($publiees), count($attente));
        $pied = __("Envoyé par l'extension RSN : veille de l'écosystème. Pour ne plus recevoir ce courriel, décochez l'option dans Veille › Réglages.", 'rsn-veille');
        $date = Dates::date_longue($aujourdhui);

        $sections = [];
        if ($publiees !== []) {
            $sections[] = ['publiees', __('Publiées automatiquement', 'rsn-veille'), $publiees, 'fiche'];
        }
        if ($attente !== []) {
            $sections[] = ['attente', __('À approuver', 'rsn-veille'), $attente, 'fiche'];
        }
        if ($radar !== []) {
            $sections[] = ['radar', __('Nouvelles repérées', 'rsn-veille'), array_slice($radar, 0, self::MAX_RADAR), 'nouvelle'];
        }

        // HTML.
        $html = '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>' . self::texte($sujet) . '</title></head>'
            . '<body style="margin:0;padding:24px 12px;background:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:' . self::ENCRE . '">'
            . '<table role="presentation" style="max-width:600px;width:100%;margin:0 auto;border-collapse:collapse"><tr><td>'
            . '<h1 style="margin:0 0 4px;font-size:22px;line-height:1.25;color:' . self::MARINE . '">' . self::texte(__("Veille de l'écosystème", 'rsn-veille')) . '</h1>'
            . ($date !== '' ? '<p style="margin:0 0 16px;color:' . self::GRIS . '">' . self::texte($date) . '</p>' : '')
            . '<p style="margin:0 0 8px">' . self::texte($intro) . '</p>';
        foreach ($sections as [$cle, $titre, $elements, $genre]) {
            $html .= '<h2 style="margin:28px 0 4px;padding-top:12px;border-top:1px solid ' . self::FILET . ';font-size:16px;color:' . self::MARINE . '">'
                . self::texte($titre . ' (' . count($elements) . ')') . '</h2>';
            foreach (array_slice($elements, 0, self::MAX_PAR_SECTION) as $element) {
                $html .= self::element_html($element, $genre);
            }
            if (count($elements) > self::MAX_PAR_SECTION) {
                $html .= '<p style="margin:12px 0 0;color:' . self::GRIS . '">' . self::texte(self::autres(count($elements) - self::MAX_PAR_SECTION)) . '</p>';
            }
            if ($cle === 'attente' && self::adresse($url_file) !== '') {
                $html .= '<p style="margin:20px 0 0"><a href="' . esc_url($url_file) . '" style="display:inline-block;background:' . self::JAUNE . ';color:' . self::ENCRE
                    . ';font-weight:bold;text-decoration:none;padding:10px 18px">' . self::texte(__("Ouvrir la file d'approbation", 'rsn-veille')) . '</a></p>';
            }
        }
        $html .= '<p style="margin:32px 0 0;padding-top:12px;border-top:1px solid ' . self::FILET . ';font-size:12px;color:' . self::GRIS . '">' . self::texte($pied) . '</p>'
            . '</td></tr></table></body></html>';

        // Texte.
        $lignes = [__("Veille de l'écosystème", 'rsn-veille') . ($date !== '' ? ' : ' . $date : ''), '', $intro];
        foreach ($sections as [$cle, $titre, $elements, $genre]) {
            $lignes[] = '';
            $lignes[] = mb_strtoupper($titre) . ' (' . count($elements) . ')';
            foreach (array_slice($elements, 0, self::MAX_PAR_SECTION) as $element) {
                array_push($lignes, ...self::element_texte($element, $genre));
            }
            if (count($elements) > self::MAX_PAR_SECTION) {
                $lignes[] = '';
                $lignes[] = self::autres(count($elements) - self::MAX_PAR_SECTION);
            }
            if ($cle === 'attente' && self::adresse($url_file) !== '') {
                $lignes[] = '';
                $lignes[] = __("Ouvrir la file d'approbation", 'rsn-veille') . ' : ' . self::adresse($url_file);
            }
        }
        array_push($lignes, '', '--', $pied);

        return ['sujet' => $sujet, 'html' => $html, 'texte' => implode("\n", $lignes) . "\n"];
    }

    // --- Réglages et planification ---

    /**
     * Réglage `courriel` d'un tableau de réglages, sous sa forme enregistrée (voir `NettoyageReglages`) :
     * lecture tolérante, adresses invalides retirées, jour 1 (lundi) s'il est illisible.
     *
     * @return array{actif: bool, destinataires: string[], jour: int}
     */
    public static function reglage_de(mixed $reglages): array {
        $courriel = is_array($reglages) && is_array($reglages['courriel'] ?? null) ? $reglages['courriel'] : [];
        $adresses = is_array($courriel['destinataires'] ?? null) ? $courriel['destinataires'] : [];
        $destinataires = [];
        foreach ($adresses as $adresse) {
            if (is_string($adresse) && is_email(trim($adresse)) !== false) {
                $destinataires[] = trim($adresse);
            }
        }
        $jour = filter_var($courriel['jour'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 6]]);
        return [
            'actif' => !empty($courriel['actif']),
            'destinataires' => array_values(array_unique($destinataires)),
            'jour' => $jour === false ? 1 : $jour,
        ];
    }

    /** @return array{actif: bool, destinataires: string[], jour: int} */
    public static function reglages(): array {
        return self::reglage_de(Collecte::reglages());
    }

    /** Accroche l'envoi, son texte alternatif et la planification (chargement de l'extension). */
    public static function accrocher(): void {
        add_action(self::CROCHET, [self::class, 'envoyer']);
        // Heure d'été ou d'hiver : la récurrence « weekly » compte 7 × 24 h, l'envoi est ramené à 08:00 après chaque envoi.
        add_action(self::CROCHET, [self::class, 'recaler'], 20);
        add_action('phpmailer_init', [self::class, 'ajouter_texte_alternatif']);
        // Extension mise à jour sans réactivation : la tâche est planifiée quand même, si l'envoi est actif.
        add_action('admin_init', [self::class, 'planifier']);
    }

    /** Planifie l'envoi hebdomadaire s'il est actif et ne l'est pas encore (activation de l'extension, admin_init). */
    public static function planifier(): void {
        $reglage = self::reglages();
        if ($reglage['actif'] && wp_next_scheduled(self::CROCHET) === false) {
            wp_schedule_event(self::prochain_envoi($reglage['jour']), 'weekly', self::CROCHET);
        }
    }

    /** Replanifie après un changement du jour ou de l'état de l'envoi : retire la tâche puis la replanifie si l'envoi est actif. */
    public static function replanifier(): void {
        self::deplanifier();
        self::planifier();
    }

    /** Retire la tâche planifiée (désactivation de l'extension, envoi désactivé). */
    public static function deplanifier(): void {
        wp_clear_scheduled_hook(self::CROCHET);
    }

    /**
     * Prochain horodatage du jour $jour (0 = dimanche … 6 = samedi) à 08:00, heure du site, strictement
     * après $maintenant.
     */
    public static function prochain_envoi(int $jour, ?int $maintenant = null): int {
        $maintenant ??= time();
        $date = (new \DateTimeImmutable('@' . $maintenant))->setTimezone(wp_timezone())->setTime(self::HEURE[0], self::HEURE[1]);
        $ecart = (($jour % 7) - (int) $date->format('w') + 7) % 7;
        if ($ecart > 0) {
            $date = $date->modify('+' . $ecart . ' days');
        }
        if ($date->getTimestamp() <= $maintenant) {
            $date = $date->modify('+7 days');
        }
        return $date->getTimestamp();
    }

    /** Rappel de `rsn_veille_hebdo` (après l'envoi) : ramène la prochaine échéance à 08:00 du jour choisi. */
    public static function recaler(): void {
        self::recaler_depuis(time());
    }

    /** `recaler` à l'instant $maintenant (tests). Une heure voulue déjà passée laisse la prévision telle quelle. */
    public static function recaler_depuis(int $maintenant): void {
        $prevue = wp_next_scheduled(self::CROCHET);
        if ($prevue === false) {
            return;
        }
        $voulue = self::prochain_envoi(self::reglages()['jour'], $prevue - 12 * HOUR_IN_SECONDS);
        if ($voulue !== $prevue && $voulue > $maintenant) {
            wp_unschedule_event($prevue, self::CROCHET);
            wp_schedule_event($voulue, 'weekly', self::CROCHET);
        }
    }

    // --- Envoi ---

    /**
     * Compose et envoie le courriel (rappel de `rsn_veille_hebdo`). Vrai si WordPress a remis le courriel
     * à son serveur d'envoi ; faux si l'envoi est désactivé, sans destinataire, sans nouveauté ou en échec.
     */
    public static function envoyer(): bool {
        return self::tenter()['statut'] === 'envoye';
    }

    /**
     * Comme `envoyer`, avec la raison du résultat : `statut` vaut `inactif`, `sans_destinataire`, `rien`
     * (aucune nouveauté), `envoye` ou `echec` ; `nb` est le nombre de nouveautés du courriel. Journalise
     * l'envoi tenté dans l'option `rsn_veille_dernier_courriel`.
     *
     * @return array{statut: string, nb: int, destinataires: string[]}
     */
    public static function tenter(): array {
        $reglage = self::reglages();
        $destinataires = $reglage['destinataires'];
        if (!$reglage['actif']) {
            return ['statut' => 'inactif', 'nb' => 0, 'destinataires' => $destinataires];
        }
        if ($destinataires === []) {
            return ['statut' => 'sans_destinataire', 'nb' => 0, 'destinataires' => []];
        }

        $aujourdhui = \rsn_veille_aujourdhui();
        $entrepot = new EntrepotWordPress();
        $limite = self::limite($aujourdhui);
        $publiees = array_values(array_filter(
            $entrepot->lister('publie'),
            static fn(Fiche $f): bool => $f->cree_le !== null && $f->cree_le >= $limite
        ));
        $en_attente = $entrepot->lister('a_approuver');
        $courriel = self::composer(
            array_map(static fn(Fiche $f): array => $f->vers_tableau(), $publiees),
            array_map(static fn(Fiche $f): array => $f->vers_tableau(), $en_attente),
            self::nouvelles_du_radar($limite),
            admin_url('admin.php?page=' . Menu::SLUG),
            $aujourdhui
        );
        if ($courriel === null) {
            return ['statut' => 'rien', 'nb' => 0, 'destinataires' => $destinataires];
        }

        $nb = count($publiees) + count($en_attente);
        self::$texte_alternatif = $courriel['texte'];
        try {
            $ok = (bool) wp_mail($destinataires, $courriel['sujet'], $courriel['html'], ['Content-Type: text/html; charset=UTF-8']);
        } finally {
            self::$texte_alternatif = null;
        }
        update_option(self::OPTION_DERNIER, ['date' => wp_date('Y-m-d H:i'), 'destinataires' => $destinataires, 'nb' => $nb, 'ok' => $ok], false);
        return ['statut' => $ok ? 'envoye' : 'echec', 'nb' => $nb, 'destinataires' => $destinataires];
    }

    /** Rappel de `phpmailer_init` : joint la version texte au courriel en cours d'envoi (partie `text/plain`). */
    public static function ajouter_texte_alternatif(mixed $phpmailer = null): void {
        if (self::$texte_alternatif !== null && is_object($phpmailer)) {
            $phpmailer->AltBody = self::$texte_alternatif;
        }
    }

    /** @return array{date: string, destinataires: string[], nb: int, ok: bool}|null Journal du dernier envoi, null s'il n'y en a pas. */
    public static function dernier_envoi(): ?array {
        $journal = get_option(self::OPTION_DERNIER, null);
        if (!is_array($journal) || !is_string($journal['date'] ?? null) || $journal['date'] === '') {
            return null;
        }
        return [
            'date' => $journal['date'],
            'destinataires' => is_array($journal['destinataires'] ?? null) ? array_values(array_filter($journal['destinataires'], 'is_string')) : [],
            'nb' => (int) ($journal['nb'] ?? 0),
            'ok' => !empty($journal['ok']),
        ];
    }

    /**
     * Nouvelles du radar lues dans le cache (résultat courant, sinon copie de 24 h) : aucune requête réseau.
     * Datées d'au moins $limite, sans celles que l'équipe a gardées ou ignorées.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function nouvelles_du_radar(string $limite): array {
        $nouvelles = [];
        foreach ([PageRadar::TRANSIENT, PageRadar::TRANSIENT_DERNIER] as $transient) {
            $cache = get_transient($transient);
            if (is_array($cache) && is_array($cache['nouvelles'] ?? null)) {
                $nouvelles = $cache['nouvelles'];
                break;
            }
        }
        $nouvelles = PageRadar::visibles($nouvelles, PageRadar::lire_gardees(), PageRadar::lire_ignorees());
        return array_values(array_filter(
            $nouvelles,
            static fn(array $n): bool => is_string($n['date'] ?? null) && $n['date'] >= $limite
        ));
    }

    /** Premier jour (Y-m-d) de la fenêtre des sept derniers jours. */
    private static function limite(string $aujourdhui): string {
        $jour = \DateTimeImmutable::createFromFormat('!Y-m-d', $aujourdhui);
        return ($jour !== false ? $jour : new \DateTimeImmutable('today'))->modify('-' . self::JOURS . ' days')->format('Y-m-d');
    }

    // --- Pièces ---

    /**
     * @param array<int,mixed> $elements
     * @return array<int,array<string,mixed>> Éléments qui ont un titre.
     */
    private static function fiches_lisibles(array $elements): array {
        return array_values(array_filter($elements, static fn($e): bool => is_array($e) && trim((string) ($e['titre'] ?? '')) !== ''));
    }

    private static function intro(int $publiees, int $attente): string {
        $parties = [];
        if ($publiees > 0) {
            /* translators: %d : nombre de fiches publiées. */
            $parties[] = sprintf(_n('%d fiche publiée automatiquement', '%d fiches publiées automatiquement', $publiees, 'rsn-veille'), $publiees);
        }
        if ($attente > 0) {
            /* translators: %d : nombre de fiches à approuver. */
            $parties[] = sprintf(_n('%d fiche à approuver', '%d fiches à approuver', $attente, 'rsn-veille'), $attente);
        }
        /* translators: %s : décompte des fiches de la semaine. */
        return sprintf(__('Cette semaine : %s.', 'rsn-veille'), implode(__(' et ', 'rsn-veille'), $parties));
    }

    private static function autres(int $n): string {
        /* translators: %d : nombre d'éléments non listés. */
        return sprintf(_n('Et %d autre.', 'Et %d autres.', $n, 'rsn-veille'), $n);
    }

    /** Ligne grise d'un élément : date et organisateur d'une fiche, date et source d'une nouvelle. */
    private static function precisions(array $e, string $genre): string {
        if ($genre === 'nouvelle') {
            $morceaux = [Dates::date_courte((string) ($e['date'] ?? '')), (string) ($e['source'] ?? '')];
        } else {
            $echeance = Dates::date_longue((string) ($e['echeance'] ?? ''));
            $quand = ($e['type'] ?? '') === 'appel' && $echeance !== ''
                /* translators: %s : date limite d'un appel. */
                ? sprintf(__('Date limite : %s', 'rsn-veille'), $echeance)
                : Dates::periode((string) ($e['debut'] ?? ''), (string) ($e['fin'] ?? ''), (string) ($e['heure'] ?? ''));
            $morceaux = [$quand, (string) ($e['organisateur'] ?? '')];
        }
        return implode(' · ', array_filter(array_map('trim', $morceaux), static fn(string $m): bool => $m !== ''));
    }

    private static function libelle_lien(string $genre): string {
        return $genre === 'nouvelle' ? __('Lire la suite →', 'rsn-veille') : __("Plus d'information →", 'rsn-veille');
    }

    private static function element_html(array $e, string $genre): string {
        $precisions = self::precisions($e, $genre);
        $url = self::adresse((string) ($e['url'] ?? ''));
        return '<p style="margin:12px 0 0"><b style="font-size:15px">' . self::texte((string) $e['titre']) . '</b>'
            . ($precisions !== '' ? '<br><span style="color:' . self::GRIS . '">' . self::texte($precisions) . '</span>' : '')
            . ($url !== '' ? '<br><a href="' . esc_url($url) . '" style="color:' . self::MARINE . '">' . self::texte(self::libelle_lien($genre)) . '</a>' : '')
            . '</p>';
    }

    /** @return string[] */
    private static function element_texte(array $e, string $genre): array {
        $precisions = self::precisions($e, $genre);
        $url = self::adresse((string) ($e['url'] ?? ''));
        $lignes = ['', '- ' . trim((string) $e['titre'])];
        if ($precisions !== '') {
            $lignes[] = '  ' . $precisions;
        }
        if ($url !== '') {
            $lignes[] = '  ' . $url;
        }
        return $lignes;
    }

    /** L'adresse si elle est web (http, https) ou de courriel ; vide sinon (javascript:, données…). */
    private static function adresse(string $url): string {
        return esc_url(trim($url)) === '' ? '' : trim($url);
    }

    /** Texte échappé pour un contenu de balise ; l'apostrophe reste telle quelle (sûre hors attribut). */
    private static function texte(string $texte): string {
        return str_replace('&#039;', "'", esc_html($texte));
    }
}

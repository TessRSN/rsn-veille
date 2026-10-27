<?php
declare(strict_types=1);

namespace RSN\Veille;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registre des sources de veille : liste par défaut (adresses de l'inventaire du 2026-10-25)
 * et application des réglages de l'équipe (activation, niveau).
 *
 * Classe pure : aucune fonction WordPress.
 */
final class Sources {
    /** Niveaux qu'un réglage peut imposer. */
    private const NIVEAUX = ['A', 'B', 'C', 'M'];

    /**
     * Sources par défaut, indexées par identifiant.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function par_defaut(): array {
        $sources = [
            // Événements : flux structurés (niveau A).
            // anti_cache : LiteSpeed met en cache les réponses REST du site ; la collecte ajoute « t= » à chaque requête.
            self::entree('rsn', 'RSN : événements', self::url_rsn(), 'tec', 'A', 'sante_numerique', 'RSN', ['inclure_passes' => true, 'anti_cache' => true]),
            self::entree('dhc', 'Digital Health Canada', 'https://digitalhealthcanada.com/wp-json/tribe/events/v1/events?per_page=50', 'tec', 'A', 'sante_numerique', 'Digital Health Canada', ['langue' => 'en']),
            self::entree('rqrv', 'RQRV : événements', 'https://www.rqrv.com/wp-json/tribe/events/v1/events?per_page=50', 'tec', 'A', 'sante', 'RQRV'),
            self::entree('sommeil', 'Réseau québécois de recherche sur le sommeil', 'https://reseausommeil.ca/events/feed/', 'rss', 'A', 'sante', 'Réseau québécois de recherche sur le sommeil', ['mode' => 'evenements']),

            // Événements : pages HTML structurées (niveau C).
            self::entree('obvia', 'Obvia : événements', 'https://www.obvia.ca/evenements', 'html_obvia', 'C', 'numerique', 'Obvia', ['base' => 'https://www.obvia.ca']),
            self::entree('mila', 'Mila : événements', 'https://mila.quebec/fr/evenements', 'html_mila', 'C', 'numerique', 'Mila', ['base' => 'https://mila.quebec']),
            self::entree('frq_evenements', 'FRQ : événements', 'https://frq.gouv.qc.ca/evenements/', 'html_frq', 'C', 'generaliste', 'Fonds de recherche du Québec', ['base' => 'https://frq.gouv.qc.ca']),
            self::entree('ivado', 'IVADO : événements', 'https://ivado.ca/evenements/', 'html_ivado', 'C', 'numerique', 'IVADO', ['base' => 'https://ivado.ca', 'flux_evenements_url' => 'https://ivado.ca/evenements/feed/']),

            // Appels : flux RSS filtrés par catégorie ou par titre (niveau B).
            self::entree('frq_appels', 'FRQ : appels', 'https://frq.gouv.qc.ca/feed/', 'rss', 'B', 'sante_numerique', 'Fonds de recherche du Québec', ['mode' => 'appels', 'categories' => ['Appel']]),
            self::entree('crm_appels', 'CRM : appels', 'https://www.crmath.ca/feed/', 'rss', 'B', 'numerique', 'CRM', ['mode' => 'appels', 'categories' => ['Appel', 'Concours ouvert']]),
            self::entree('rsn_appels', 'RSN : appels', 'https://rsn.quebec/feed/', 'rss', 'B', 'sante_numerique', 'RSN', ['mode' => 'appels', 'categories' => ['Appels de financement']]),
            self::entree('cares_appels', 'CARES : concours', 'https://reseaucares.ca/feed/', 'rss', 'B', 'sante', 'CARES', ['mode' => 'appels', 'categories' => ['Concours']]),
            self::entree('hippocrate_appels', 'Prix Hippocrate : appels de candidatures', 'https://prixhippocrate.ca/feed/', 'rss', 'B', 'sante', 'Prix Hippocrate', ['mode' => 'appels', 'motif_titre' => 'appel|candidature']),

            // Nouvelles : radar de l'équipe seulement, jamais de fiches persistées (devis § 3.3).
            self::nouvelles('frq', 'FRQ : nouvelles', 'https://frq.gouv.qc.ca/feed/', 'generaliste', 'Fonds de recherche du Québec'),
            self::nouvelles('ivado_nouvelles', 'IVADO : nouvelles', 'https://ivado.ca/feed/', 'numerique', 'IVADO'),
            self::nouvelles('mila_nouvelles', 'Mila : nouvelles', 'https://mila.quebec/fr/rss.xml', 'numerique', 'Mila'),
            self::nouvelles('inspq', 'INSPQ : nouvelles', 'https://www.inspq.qc.ca/nouvelles/rss.xml', 'sante', 'INSPQ'),
            self::nouvelles('ssa', 'Unité de soutien SSA Québec', 'https://ssaquebec.ca/feed/', 'sante', 'Unité de soutien SSA Québec'),
            self::nouvelles('conseil_innovation', 'Conseil de l’innovation du Québec', 'https://conseilinnovation.quebec/feed/', 'generaliste', 'Conseil de l’innovation du Québec'),
            self::nouvelles('genome', 'Génome Québec', 'https://genomequebec.com/feed/', 'sante', 'Génome Québec'),
            self::nouvelles('rqrm', 'RQRM : nouvelles', 'https://www.rqrm.ca/feed/', 'sante', 'RQRM'),
            self::nouvelles('rqrd', 'RQRD : nouvelles', 'https://qprn.ca/fr/feed/', 'sante', 'RQRD'),
            self::nouvelles('cmdo', 'CMDO : activités', 'https://www.rrcmdo.ca/activites/feed/', 'sante', 'CMDO'),
            self::nouvelles('acfas', 'Acfas : nouvelles', 'https://www.acfas.ca/rss/acfas.xml', 'generaliste', 'Acfas'),
            self::nouvelles('quebec_ca', 'Gouvernement du Québec : fil de presse', 'https://www.quebec.ca/fil-de-presse.rss', 'generaliste', 'Gouvernement du Québec'),
        ];
        return array_column($sources, null, 'id');
    }

    /**
     * Applique les réglages (`actif`, `niveau`) aux sources connues. Les identifiants inconnus,
     * les niveaux hors A, B, C, M et les valeurs `actif` illisibles sont ignorés.
     *
     * @param array<string,array<string,mixed>> $defaut
     * @param array<string,mixed> $reglages
     * @return array<string,array<string,mixed>>
     */
    public static function fusionner(array $defaut, array $reglages): array {
        foreach ($reglages as $id => $reglage) {
            if (!isset($defaut[$id]) || !is_array($reglage)) {
                continue;
            }
            if (array_key_exists('actif', $reglage)) {
                $actif = is_bool($reglage['actif'])
                    ? $reglage['actif']
                    : filter_var($reglage['actif'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($actif !== null) {
                    $defaut[$id]['actif'] = $actif;
                }
            }
            if (isset($reglage['niveau']) && in_array($reglage['niveau'], self::NIVEAUX, true)) {
                $defaut[$id]['niveau'] = $reglage['niveau'];
            }
        }
        return $defaut;
    }

    /**
     * Les sources actives seulement.
     *
     * @param array<string,array<string,mixed>> $sources
     * @return array<string,array<string,mixed>>
     */
    public static function actives(array $sources): array {
        return array_filter($sources, static fn(array $s): bool => !empty($s['actif']));
    }

    /** Événements du RSN, passés compris depuis mai 2025, jusqu'au 31 décembre dans trois ans. */
    private static function url_rsn(): string {
        $fin = ((int) substr(\rsn_veille_aujourdhui(), 0, 4) + 3) . '-12-31';
        return 'https://rsn.quebec/wp-json/tribe/events/v1/events?per_page=50&start_date=2025-05-01&end_date=' . $fin;
    }

    /** Une entrée du registre, avec les valeurs par défaut (français, active). */
    private static function entree(string $id, string $nom, string $url, string $type, string $niveau, string $type_source, string $organisateur, array $extras = []): array {
        return array_merge([
            'id' => $id,
            'nom' => $nom,
            'url' => $url,
            'type' => $type,
            'niveau' => $niveau,
            'type_source' => $type_source,
            'organisateur' => $organisateur,
            'langue' => 'fr',
            'actif' => true,
        ], $extras);
    }

    /** Un flux de nouvelles (RSS, niveau B, 8 éléments au plus). */
    private static function nouvelles(string $id, string $nom, string $url, string $type_source, string $organisateur): array {
        return self::entree($id, $nom, $url, 'rss', 'B', $type_source, $organisateur, [
            'mode' => 'nouvelles',
            'limite' => 8,
            'categorie_nouvelles' => true,
        ]);
    }
}

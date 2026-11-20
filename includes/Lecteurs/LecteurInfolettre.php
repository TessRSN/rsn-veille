<?php
declare(strict_types=1);

namespace RSN\Veille\Lecteurs;

use RSN\Veille\Fiche;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lecteur du bloc « À l'agenda » d'une infolettre Mailchimp (flux d'archive, dix derniers envois) : chaque ligne datée
 * devient un événement. Le même événement annoncé dans plusieurs numéros ne donne qu'une fiche (clé : date de début et
 * titre) ; un événement terminé est compté dans `ignores`.
 *
 * Une ligne d'agenda ne dit pas qui organise : l'organisateur n'est rempli que si le lien mène chez l'éditeur de
 * l'infolettre (`hotes_organisateur` de la source), sinon il reste vide et l'équipe le complète avant de publier.
 */
final class LecteurInfolettre implements Lecteur {
    public function lire(string $contenu, array $source): Resultat {
        $resultat = new Resultat();
        $numeros = DecoupageMailchimp::numeros($contenu);
        if ($numeros === []) {
            $resultat->erreurs[] = 'Flux vide ou invalide';
            return $resultat;
        }

        $aujourdhui = \rsn_veille_aujourdhui();
        $id = (string) ($source['id'] ?? '');
        $vues = [];
        $comptes = [];
        foreach ($numeros as $numero) {
            $lignes = DecoupageMailchimp::agenda($numero['html'], $numero['date'] !== '' ? $numero['date'] : $aujourdhui);
            // Seuls les numéros ordinaires (avec encadrés) font l'habitude : un envoi spécial n'a pas d'agenda.
            if ($lignes !== [] || DecoupageMailchimp::cartes($numero['html']) !== []) {
                $comptes[] = ['titre' => $numero['titre'], 'n' => count($lignes)];
            }
            foreach ($lignes as $ligne) {
                $cle = $id . ':' . Fiche::cle_dedoublonnage_de($ligne['debut'], $ligne['titre']);
                if (isset($vues[$cle])) {
                    continue;
                }
                $vues[$cle] = true;
                if ($ligne['fin'] < $aujourdhui) {
                    $resultat->ignores++;
                    continue;
                }
                $resultat->fiches[] = $this->fiche($ligne, $numero, $source, $cle);
            }
        }

        $rupture = DecoupageMailchimp::rupture($comptes);
        if ($rupture !== null && $rupture['n'] === 0) {
            /* translators: 1 : titre du numéro ; 2 : nombre habituel de lignes d'agenda. */
            $resultat->alertes[] = sprintf(
                __('Le numéro « %1$s » n’a donné aucune ligne d’agenda, alors que les précédents en donnent environ %2$d. Le bloc « À l’agenda » de l’infolettre a peut-être changé : à vérifier.', 'rsn-veille'),
                $rupture['titre'],
                $rupture['habitude']
            );
        }
        return $resultat;
    }

    /**
     * @param array{titre: string, url: string, debut: string, fin: string, lieu: string} $ligne
     * @param array{titre: string, url: string, date: string, html: string} $numero
     */
    private function fiche(array $ligne, array $numero, array $source, string $cle): Fiche {
        $mention = $ligne['lieu'];
        $fiche = new Fiche();
        $fiche->type = 'evenement';
        $fiche->titre = $ligne['titre'];
        $fiche->url = $ligne['url'] !== '' ? $ligne['url'] : $numero['url'];
        $fiche->debut = $ligne['debut'];
        $fiche->fin = $ligne['fin'];
        $fiche->ville = self::ville($mention);
        $en_ligne = Fiche::deviner_format($mention) !== 'Présentiel';
        if ($fiche->ville !== '') {
            $fiche->format = $en_ligne ? 'Hybride' : 'Présentiel';
        } else {
            $fiche->format = Fiche::deviner_format($ligne['titre'] . ' ' . $mention);
        }
        $fiche->region = Fiche::deviner_region($fiche->ville, '', $fiche->format);
        $fiche->langue = preg_match('/\ben anglais\b/iu', $mention) === 1 ? 'en' : (string) ($source['langue'] ?? 'fr');
        $fiche->organisateur = self::chez_l_editeur($fiche->url, (array) ($source['hotes_organisateur'] ?? [])) ? (string) ($source['organisateur'] ?? '') : '';
        $fiche->source = (string) ($source['nom'] ?? '');
        $fiche->niveau = (string) ($source['niveau'] ?? '');
        $fiche->cle_source = $cle;
        $fiche->cles_source = [$cle];
        $editeur = (string) ($source['organisateur'] ?? $source['nom'] ?? '');
        $date = DecoupageMailchimp::date_en_clair($numero['date']);
        /* translators: 1 : éditeur de l'infolettre ; 2 : date du numéro (« 20 novembre 2026 »), ou vide. */
        $resume = trim(sprintf(__('Signalé dans l’infolettre de %1$s %2$s', 'rsn-veille'), $editeur, $date !== '' ? sprintf(__('du %s', 'rsn-veille'), $date) : ''));
        $fiche->resume = Fiche::court($resume . ($mention !== '' ? ' : ' . $mention : '') . '.');
        return $fiche;
    }

    /** Villes d'une mention (« à Québec, à Montréal et en ligne » ⇒ « Québec, Montréal ») ; vide s'il n'y en a pas. */
    private static function ville(string $mention): string {
        if (preg_match_all('/(?<![\p{L}\d])à\s+(\p{Lu}[\p{L}\'’.-]*(?:[ -]\p{Lu}[\p{L}\'’.-]*)*)/u', $mention, $m) < 1) {
            return '';
        }
        return implode(', ', array_unique($m[1]));
    }

    /** @param array<int,mixed> $hotes Domaines de l'éditeur (le domaine lui-même ou l'un de ses sous-domaines). */
    private static function chez_l_editeur(string $url, array $hotes): bool {
        $hote = strtolower((string) parse_url($url, PHP_URL_HOST));
        foreach ($hotes as $domaine) {
            $domaine = strtolower((string) $domaine);
            if ($domaine !== '' && ($hote === $domaine || str_ends_with($hote, '.' . $domaine))) {
                return true;
            }
        }
        return false;
    }
}

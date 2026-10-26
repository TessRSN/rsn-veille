<?php
declare(strict_types=1);

namespace RSN\Veille\Lecteurs;

use RSN\Veille\Fiche;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lecteur de calendriers iCal (.ics) : un VEVENT devient une fiche « evenement ».
 *
 * Les heures sont ramenées à l'heure de Montréal (America/Toronto) ; une valeur sans fuseau
 * ni « Z » (heure flottante) est prise telle quelle.
 */
final class LecteurIcal implements Lecteur {
    private const FUSEAU = 'America/Toronto';

    public function lire(string $contenu, array $source): Resultat {
        $resultat = new Resultat();
        $contenu = preg_replace('/^\xEF\xBB\xBF/', '', $contenu) ?? $contenu;
        // Fins de ligne uniformes, puis dépliage : une ligne qui commence par une espace ou une
        // tabulation continue la précédente (on retire ce seul caractère).
        $contenu = str_replace(["\r\n", "\r"], "\n", $contenu);
        $contenu = preg_replace('/\n[ \t]/', '', $contenu) ?? $contenu;

        if (!str_contains($contenu, 'BEGIN:VCALENDAR')) {
            $resultat->erreurs[] = 'Contenu iCal invalide';
            return $resultat;
        }

        $aujourdhui = \rsn_veille_aujourdhui();
        $inclure_passes = !empty($source['inclure_passes']);

        foreach ($this->evenements(explode("\n", $contenu)) as $position => $proprietes) {
            $fiche = $this->fiche($proprietes, $source, $position + 1, $resultat);
            if ($fiche === null) {
                continue;
            }
            if (!$inclure_passes && ($fiche->fin ?? $fiche->debut) < $aujourdhui) {
                $resultat->ignores++;
                continue;
            }
            $resultat->fiches[] = $fiche;
        }
        return $resultat;
    }

    /**
     * Propriétés de chaque VEVENT : [NOM => ['valeur' => …, 'params' => ['TZID' => …]]].
     * Les sous-composants (VALARM, etc.) sont sautés ; la première occurrence d'une propriété l'emporte.
     *
     * @param string[] $lignes
     * @return array<int, array<string, array{valeur: string, params: array<string, string>}>>
     */
    private function evenements(array $lignes): array {
        $evenements = [];
        $courant = null;
        $profondeur = 0;
        foreach ($lignes as $ligne) {
            $ligne = rtrim($ligne);
            if ($ligne === 'BEGIN:VEVENT' && $courant === null) {
                $courant = [];
                $profondeur = 0;
                continue;
            }
            if ($courant === null) {
                continue;
            }
            if ($ligne === 'END:VEVENT' && $profondeur === 0) {
                $evenements[] = $courant;
                $courant = null;
                continue;
            }
            if (str_starts_with($ligne, 'BEGIN:')) {
                $profondeur++;
                continue;
            }
            if (str_starts_with($ligne, 'END:')) {
                $profondeur = max(0, $profondeur - 1);
                continue;
            }
            if ($profondeur > 0) {
                continue;
            }
            // NOM;PARAM=valeur;PARAM="valeur : citée":valeur
            if (!preg_match('/^([A-Za-z0-9-]+)((?:;(?:[^;:"]|"[^"]*")*)*):(.*)$/', $ligne, $m)) {
                continue;
            }
            $nom = strtoupper($m[1]);
            if (isset($courant[$nom])) {
                continue;
            }
            $params = [];
            foreach (explode(';', ltrim($m[2], ';')) as $param) {
                if (str_contains($param, '=')) {
                    [$cle, $valeur] = explode('=', $param, 2);
                    $params[strtoupper($cle)] = trim($valeur, '"');
                }
            }
            $courant[$nom] = ['valeur' => $m[3], 'params' => $params];
        }
        return $evenements;
    }

    private function fiche(array $p, array $source, int $position, Resultat $resultat): ?Fiche {
        $titre = Fiche::nettoyer_texte($this->texte($p['SUMMARY']['valeur'] ?? ''));
        if ($titre === '' || !isset($p['DTSTART'])) {
            $resultat->erreurs[] = sprintf('Événement %d sans titre ou date de début', $position);
            return null;
        }

        $debut = $this->date($p['DTSTART']);
        if ($debut === null) {
            $resultat->erreurs[] = sprintf('Événement %d : date de début illisible', $position);
            return null;
        }
        $fin = isset($p['DTEND']) ? $this->date($p['DTEND']) : null;
        if (isset($p['DTEND']) && $fin === null) {
            $resultat->erreurs[] = sprintf('Événement %d : date de fin illisible', $position);
            return null;
        }

        $fiche = new Fiche();
        $fiche->type = 'evenement';
        $fiche->titre = $titre;
        $fiche->debut = $debut['date'];
        $fiche->heure = $debut['journee'] ? '' : $debut['heure'];
        $fin_date = $fin['date'] ?? $debut['date'];
        // En journée entière, DTEND est exclusif : le dernier jour est la veille.
        if ($fin !== null && $fin['journee']) {
            $fin_date = (new \DateTimeImmutable($fin['date']))->modify('-1 day')->format('Y-m-d');
        }
        $fiche->fin = max($fin_date, $debut['date']);

        $fiche->lieu = Fiche::nettoyer_texte($this->texte($p['LOCATION']['valeur'] ?? ''));
        $fiche->organisateur = $this->chaine($source['organisateur'] ?? null) ?: $this->chaine($source['nom'] ?? null);
        $fiche->resume = Fiche::court(Fiche::nettoyer_texte($this->texte($p['DESCRIPTION']['valeur'] ?? '')));
        $fiche->format = Fiche::deviner_format($fiche->titre . ' ' . $fiche->resume . ' ' . $fiche->lieu);
        $fiche->region = Fiche::deviner_region($fiche->ville, $fiche->lieu, $fiche->format);
        $fiche->langue = (string) ($source['langue'] ?? 'fr');
        $fiche->url = trim($p['URL']['valeur'] ?? '');
        $fiche->source = (string) ($source['nom'] ?? '');
        $fiche->niveau = (string) ($source['niveau'] ?? '');

        $uid = trim($p['UID']['valeur'] ?? '');
        $fiche->cle_source = ($source['id'] ?? '') . ':' . ($uid !== '' ? $uid : md5($fiche->debut . $fiche->titre));
        $fiche->cles_source = [$fiche->cle_source];
        return $fiche;
    }

    /**
     * Date d'une propriété DTSTART ou DTEND, en heure de Montréal.
     *
     * @param array{valeur: string, params: array<string, string>} $propriete
     * @return array{date: string, heure: string, journee: bool}|null
     */
    private function date(array $propriete): ?array {
        $valeur = trim($propriete['valeur']);
        $params = $propriete['params'];
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})(?:T(\d{2})(\d{2})(\d{2})?(Z)?)?$/', $valeur, $m)) {
            return null;
        }
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        $jour = sprintf('%s-%s-%s', $m[1], $m[2], $m[3]);
        if (!isset($m[4]) || $m[4] === '' || strtoupper($params['VALUE'] ?? '') === 'DATE') {
            return ['date' => $jour, 'heure' => '', 'journee' => true];
        }

        try {
            $montreal = new \DateTimeZone(self::FUSEAU);
            if (($m[7] ?? '') === 'Z') {
                $origine = new \DateTimeZone('UTC');
            } elseif (isset($params['TZID'])) {
                try {
                    $origine = new \DateTimeZone($params['TZID']);
                } catch (\Exception $e) {
                    $origine = $montreal; // Fuseau inconnu : heure prise telle quelle.
                }
            } else {
                $origine = $montreal;
            }
            $moment = new \DateTimeImmutable($jour . sprintf(' %s:%s:%s', $m[4], $m[5], $m[6] ?? '00'), $origine);
            $moment = $moment->setTimezone($montreal);
        } catch (\Exception $e) {
            return null;
        }
        return ['date' => $moment->format('Y-m-d'), 'heure' => $moment->format('H:i'), 'journee' => false];
    }

    /** Décode les échappements iCal : \, \; \\ et \n (ou \N) en saut de ligne. */
    private function texte(string $valeur): string {
        return preg_replace_callback('/\\\\(.)/s', static fn(array $m): string => in_array($m[1], ['n', 'N'], true) ? "\n" : $m[1], $valeur) ?? $valeur;
    }

    private function chaine(mixed $valeur): string {
        return is_string($valeur) ? Fiche::nettoyer_texte($valeur) : '';
    }
}

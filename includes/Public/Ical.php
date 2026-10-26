<?php
declare(strict_types=1);

namespace RSN\Veille\Public;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Calendrier iCal (RFC 5545) des événements publiés, servi à `/veille.ics`.
 *
 * Classe pure : aucune fonction WordPress.
 */
final class Ical {
    /** Transient du calendrier généré (une heure), vidé avec le JSON public. */
    public const TRANSIENT = 'rsn_veille_ics';

    private const FUSEAU = 'America/Toronto';

    /** Durée par défaut d'un événement qui a une heure de début, en secondes. */
    private const DUREE_PAR_DEFAUT = 7200;

    /** Longueur maximale d'une ligne, en octets, sans la fin de ligne. */
    private const LARGEUR = 75;

    /** Description du fuseau de Montréal (règles nord-américaines depuis 2007), exigée par les TZID. */
    private const VTIMEZONE = [
        'BEGIN:VTIMEZONE',
        'TZID:America/Toronto',
        'X-LIC-LOCATION:America/Toronto',
        'BEGIN:DAYLIGHT',
        'TZOFFSETFROM:-0500',
        'TZOFFSETTO:-0400',
        'TZNAME:EDT',
        'DTSTART:19700308T020000',
        'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=2SU',
        'END:DAYLIGHT',
        'BEGIN:STANDARD',
        'TZOFFSETFROM:-0400',
        'TZOFFSETTO:-0500',
        'TZNAME:EST',
        'DTSTART:19701101T020000',
        'RRULE:FREQ=YEARLY;BYMONTH=11;BYDAY=1SU',
        'END:STANDARD',
        'END:VTIMEZONE',
    ];

    /**
     * Le calendrier : un VEVENT par événement publié et daté (les appels et les fiches non publiées sont
     * ignorés). Journée entière (`VALUE=DATE`, fin exclusive) sans heure ; sinon heure de Montréal et
     * deux heures de durée. Lignes pliées à 75 octets, fins de ligne CRLF.
     *
     * @param array<int,array<string,mixed>> $items Fiches au format `Fiche::vers_tableau()`.
     */
    public static function generer(array $items, string $aujourdhui): string {
        $lignes = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//RSN//Veille//FR',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            "X-WR-CALNAME:Veille de l'écosystème RSN",
            'X-WR-TIMEZONE:' . self::FUSEAU,
            'REFRESH-INTERVAL;VALUE=DURATION:PT12H',
            'X-PUBLISHED-TTL:PT12H',
            ...self::VTIMEZONE,
        ];
        $horodatage = self::date_valide($aujourdhui) ? str_replace('-', '', $aujourdhui) . 'T000000Z' : gmdate('Ymd\THis\Z');
        foreach ($items as $item) {
            if (is_array($item)) {
                array_push($lignes, ...self::vevent($item, $horodatage));
            }
        }
        $lignes[] = 'END:VCALENDAR';
        return implode('', array_map(static fn(string $l): string => self::plier($l) . "\r\n", $lignes));
    }

    /**
     * @param array<string,mixed> $item
     * @return string[] Lignes du VEVENT, vide si l'élément ne va pas au calendrier.
     */
    private static function vevent(array $item, string $horodatage): array {
        $debut = (string) ($item['debut'] ?? '');
        if (($item['type'] ?? '') !== 'evenement' || ($item['statut'] ?? 'publie') !== 'publie' || !self::date_valide($debut)) {
            return [];
        }
        $fin = (string) ($item['fin'] ?? '');
        if (!self::date_valide($fin) || $fin < $debut) {
            $fin = $debut;
        }

        $lignes = [
            'BEGIN:VEVENT',
            'UID:' . self::uid($item),
            'DTSTAMP:' . $horodatage,
        ];
        $heure = trim((string) ($item['heure'] ?? ''));
        if (preg_match('/^(\d{1,2})[:h](\d{2})$/', $heure, $m) === 1 && (int) $m[1] < 24 && (int) $m[2] < 60) {
            $fuseau = new \DateTimeZone(self::FUSEAU);
            $depart = new \DateTimeImmutable(sprintf('%s %02d:%02d:00', $debut, (int) $m[1], (int) $m[2]), $fuseau);
            $arrivee = new \DateTimeImmutable(sprintf('%s %02d:%02d:00', $fin, (int) $m[1], (int) $m[2]), $fuseau);
            // Heure murale + 2 h (pas d'arithmétique en secondes : le changement d'heure ne décale rien).
            $arrivee = $arrivee->modify('+' . (self::DUREE_PAR_DEFAUT / 3600) . ' hours');
            $lignes[] = 'DTSTART;TZID=' . self::FUSEAU . ':' . $depart->format('Ymd\THis');
            $lignes[] = 'DTEND;TZID=' . self::FUSEAU . ':' . $arrivee->format('Ymd\THis');
        } else {
            $lendemain = (new \DateTimeImmutable($fin . ' 00:00:00', new \DateTimeZone('UTC')))->modify('+1 day');
            $lignes[] = 'DTSTART;VALUE=DATE:' . str_replace('-', '', $debut);
            $lignes[] = 'DTEND;VALUE=DATE:' . $lendemain->format('Ymd');
        }

        $lignes[] = 'SUMMARY:' . self::texte((string) ($item['titre'] ?? ''));
        $lieu = array_values(array_unique(array_filter([trim((string) ($item['lieu'] ?? '')), trim((string) ($item['ville'] ?? ''))], 'strlen')));
        if ($lieu !== []) {
            $lignes[] = 'LOCATION:' . self::texte(implode(', ', $lieu));
        }
        $url = self::sans_saut((string) ($item['url'] ?? ''));
        $description = implode("\n\n", array_filter([trim((string) ($item['resume'] ?? '')), $url], 'strlen'));
        if ($description !== '') {
            $lignes[] = 'DESCRIPTION:' . self::texte($description);
        }
        if ($url !== '') {
            $lignes[] = 'URL:' . $url;
        }
        $lignes[] = 'END:VEVENT';
        return $lignes;
    }

    /** UID stable : empreinte de la clé de source, sinon de l'URL, sinon du titre et de la date. */
    private static function uid(array $item): string {
        $base = (string) ($item['cle_source'] ?? '');
        if ($base === '') {
            $base = (string) ($item['url'] ?? '');
        }
        if ($base === '') {
            $base = ($item['debut'] ?? '') . '|' . ($item['titre'] ?? '');
        }
        return md5($base) . '@rsn.quebec';
    }

    /** Valeur TEXT (RFC 5545 § 3.3.11) : `\` `;` `,` et sauts de ligne échappés. */
    private static function texte(string $texte): string {
        $texte = str_replace(["\r\n", "\r"], "\n", trim($texte));
        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\\,', '\\n'], $texte);
    }

    private static function sans_saut(string $texte): string {
        return trim(str_replace(["\r", "\n"], '', $texte));
    }

    private static function date_valide(string $date): bool {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** Pliage à 75 octets (la continuation commence par une espace, comptée), sans couper un caractère UTF-8. */
    private static function plier(string $ligne): string {
        if (strlen($ligne) <= self::LARGEUR) {
            return $ligne;
        }
        $morceaux = [];
        $courant = '';
        $limite = self::LARGEUR;
        foreach (mb_str_split($ligne, 1, 'UTF-8') as $caractere) {
            if (strlen($courant) + strlen($caractere) > $limite) {
                $morceaux[] = $courant;
                $courant = '';
                $limite = self::LARGEUR - 1;
            }
            $courant .= $caractere;
        }
        $morceaux[] = $courant;
        return implode("\r\n ", $morceaux);
    }
}

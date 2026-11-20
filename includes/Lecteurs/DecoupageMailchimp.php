<?php
declare(strict_types=1);

namespace RSN\Veille\Lecteurs;

use RSN\Veille\Fiche;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Découpe une infolettre Mailchimp au gabarit standard (blocs `<table class="mcn…Block">`) : ses encadrés avec image
 * (`mcnImageCardBlock`), qui deviennent des nouvelles du Radar, et les lignes de son bloc « À l'agenda », qui deviennent
 * des événements à approuver. Lit aussi le flux d'archive (`…campaign-archive.com/feed?u=…&id=…`), qui donne les dix
 * derniers envois avec leur HTML complet.
 *
 * Classe pure : aucune fonction WordPress, aucune requête. Une infolettre d'un autre gabarit ne donne rien (ni encadré
 * ni ligne) : c'est à l'appelant de le signaler.
 */
final class DecoupageMailchimp {
    private const MOIS = [
        'janvier' => 1, 'février' => 2, 'fevrier' => 2, 'mars' => 3, 'avril' => 4, 'mai' => 5, 'juin' => 6, 'juillet' => 7,
        'août' => 8, 'aout' => 8, 'septembre' => 9, 'octobre' => 10, 'novembre' => 11, 'décembre' => 12, 'decembre' => 12,
    ];

    /** Abréviations courantes des mois (« 24 nov. »), avec ou sans point. */
    private const MOIS_ABREGES = [
        'janv' => 1, 'févr' => 2, 'fevr' => 2, 'fév' => 2, 'fev' => 2, 'avr' => 4, 'juil' => 7, 'sept' => 9, 'oct' => 10,
        'nov' => 11, 'déc' => 12, 'dec' => 12,
    ];

    /** Encadrés : balises de gras examinées au plus pour trouver le titre, et longueur maximale de ce titre. */
    private const GRAS_MAX = 20;
    private const TITRE_GRAS_MAX = 200;

    /** Liens examinés au plus dans un encadré ou une ligne pour trouver une adresse http(s). */
    private const LIENS_MAX = 20;

    /** Paramètres de suivi publicitaire retirés des liens (les préfixes `utm_` et `gad_` le sont aussi). */
    private const SUIVI = ['gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid', 'mc_cid', 'mc_eid', '_hsenc', '_hsmi'];

    /** Une date sans année tombée depuis plus longtemps que cela avant l'envoi est celle de l'année suivante. */
    private const JOURS_PASSES = 92;

    /** Longueur maximale du titre tiré du début d'un encadré sans gras. */
    private const TITRE_MAX = 120;

    /** Numéros précédents qu'il faut pour connaître l'habitude d'une infolettre (`rupture`). */
    private const NUMEROS_HABITUELS = 3;

    /** En dessous de cette habitude, un numéro plus court que les autres n'est pas signalé. */
    private const HABITUDE_MIN = 6;

    /**
     * Numéros du flux d'archive, dans l'ordre du flux (le plus récent d'abord).
     * Lecture sans analyseur XML : un flux tronqué en route (il pèse plusieurs mégaoctets) garde ses numéros complets.
     *
     * @return array<int,array{titre: string, url: string, date: string, html: string}>
     */
    public static function numeros(string $xml): array {
        $numeros = [];
        $morceaux = explode('<item>', $xml);
        array_shift($morceaux);
        foreach ($morceaux as $morceau) {
            $fin = strrpos($morceau, '</item>');
            if ($fin === false) {
                continue;
            }
            $item = substr($morceau, 0, $fin);
            // Le HTML du numéro (description, puis content:encoded) contient lui-même un <title> : le titre et le lien
            // se lisent avant lui, la date après.
            $coupe = strpos($item, '<description>');
            $tete = $coupe === false ? $item : substr($item, 0, $coupe);
            $suite = strrpos($item, '</content:encoded>');
            $suite = $suite !== false ? $suite : strrpos($item, '</description>');
            $pied = $suite === false ? '' : substr($item, $suite);
            $html = self::champ($item, 'description');
            if (trim($html) === '') {
                $html = self::champ($item, 'content:encoded');
            }
            $titre = Fiche::nettoyer_texte(self::champ($tete, 'title'));
            $url = self::nettoyer_url(self::champ($tete, 'link'));
            $date = self::jour(self::champ($pied, 'pubDate'));
            if ($date === '') {
                $date = self::jour(self::champ($tete, 'pubDate'));
            }
            if ($titre === '' || $url === '') {
                continue;
            }
            $numeros[] = ['titre' => $titre, 'url' => $url, 'date' => $date, 'html' => $html];
        }
        return $numeros;
    }

    /**
     * Encadrés avec image d'un numéro, dans l'ordre de la page. Titre : premier texte en gras, sinon la première phrase.
     * Un encadré sans lien http(s) n'est pas retenu.
     *
     * @return array<int,array{titre: string, url: string, resume: string}>
     */
    public static function cartes(string $html): array {
        $cartes = [];
        foreach (self::blocs($html) as $bloc) {
            if ($bloc['classe'] !== 'mcnImageCardBlock') {
                continue;
            }
            $url = self::premier_lien($bloc['html']);
            $zone = self::zone_de_texte($bloc['html']);
            $texte = Fiche::nettoyer_texte($zone);
            if ($url === '' || $texte === '') {
                continue;
            }
            $titre = self::titre_en_gras($zone);
            if ($titre === '') {
                $titre = preg_match('/^(.{15,' . self::TITRE_MAX . '}?[.!?])(?:\s|$)/us', $texte, $m) === 1 ? rtrim($m[1], '.') : Fiche::court($texte, self::TITRE_MAX);
                $resume = $texte;
            } else {
                $position = mb_strpos($texte, $titre);
                $resume = $position === false ? $texte : trim(mb_substr($texte, 0, $position) . ' ' . mb_substr($texte, $position + mb_strlen($titre)));
            }
            $cartes[] = ['titre' => $titre, 'url' => $url, 'resume' => Fiche::court(self::sans_bouton_final($resume, $zone))];
        }
        return $cartes;
    }

    /**
     * Lignes datées du bloc « À l'agenda » : « titre > 24 et 25 novembre à Québec ». Une ligne sans date lisible est
     * laissée. Une date sans année se lit par rapport à la date d'envoi du numéro.
     *
     * @param string $date_numero Date d'envoi, `Y-m-d`.
     * @return array<int,array{titre: string, url: string, debut: string, fin: string, lieu: string}>
     */
    public static function agenda(string $html, string $date_numero): array {
        $lignes = [];
        foreach (self::blocs($html) as $bloc) {
            if (preg_match('/À\s+L[\'’]\s*AGENDA/iu', Fiche::nettoyer_texte($bloc['html'])) !== 1) {
                continue;
            }
            $zone = self::zone_de_texte($bloc['html']);
            foreach (preg_split('~<li\b[^>]*>|</li>|<br\b[^>]*>|</div>|</p>~i', $zone) ?: [] as $morceau) {
                $ligne = self::ligne_agenda($morceau, $date_numero);
                if ($ligne !== null) {
                    $lignes[] = $ligne;
                }
            }
        }
        return $lignes;
    }

    /** « 20 novembre 2026 », « 1er mai 2026 » ; vide si la date n'est pas `Y-m-d`. */
    public static function date_en_clair(string $date): string {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return '';
        }
        $mois = array_search((int) $m[2], self::MOIS, true);
        return ((int) $m[3] === 1 ? '1er' : (string) (int) $m[3]) . ' ' . $mois . ' ' . $m[1];
    }

    /** Texte d'un numéro entier, sans son en-tête, ses styles ni ses scripts (résumé d'un envoi sans encadré). */
    public static function texte(string $html): string {
        $corps = preg_replace('~<head\b.*?</head>|<style\b.*?</style>|<script\b.*?</script>|<!--.*?-->~is', ' ', $html) ?? '';
        return Fiche::nettoyer_texte($corps);
    }

    /**
     * Le dernier numéro rompt-il avec l'habitude des précédents ? Oui s'il ne donne rien alors qu'il porte un titre
     * comme les autres (un envoi spécial a un autre titre, et un autre gabarit : il n'est pas signalé), ou s'il donne
     * moins du tiers de ce que donnent les précédents. Il faut au moins trois numéros précédents qui donnent quelque
     * chose pour connaître l'habitude (leur médiane).
     *
     * @param array<int,array{titre: string, n: int}> $numeros Du plus récent au plus ancien : titre et nombre d'éléments reconnus.
     * @return array{titre: string, n: int, habitude: int}|null Null si rien n'est à signaler.
     */
    public static function rupture(array $numeros): ?array {
        $dernier = array_shift($numeros);
        $habituels = array_values(array_filter($numeros, static fn(array $n): bool => $n['n'] > 0));
        if ($dernier === null || count($habituels) < self::NUMEROS_HABITUELS) {
            return null;
        }
        $comptes = array_column($habituels, 'n');
        sort($comptes);
        $habitude = $comptes[intdiv(count($comptes), 2)];
        if ($dernier['n'] === 0) {
            $mots = array_count_values(array_map([self::class, 'premier_mot'], array_column($habituels, 'titre')));
            arsort($mots);
            if (self::premier_mot($dernier['titre']) !== (string) array_key_first($mots)) {
                return null;
            }
        } elseif ($habitude < self::HABITUDE_MIN || $dernier['n'] * 3 >= $habitude) {
            return null;
        }
        return ['titre' => $dernier['titre'], 'n' => $dernier['n'], 'habitude' => $habitude];
    }

    /**
     * Adresse http(s) débarrassée de ce qui ne sert pas à la lire : enveloppe « Safe Links » d'Outlook, paramètres de
     * suivi publicitaire, identifiant d'abonné de Mailchimp, identifiant de partage de YouTube. Vide si ce n'est pas une
     * adresse http(s).
     */
    public static function nettoyer_url(string $url, bool $deballer = true): string {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        // Une barre oblique inverse est lue comme « / » par les navigateurs mais pas par parse_url : refusée.
        if (preg_match('~^https?://[^\s<>"\\\\]+$~i', $url) !== 1) {
            return '';
        }
        $parties = parse_url($url);
        if (!is_array($parties) || empty($parties['host'])) {
            return '';
        }
        $hote = strtolower((string) $parties['host']);
        $parametres = [];
        foreach (explode('&', (string) ($parties['query'] ?? '')) as $paire) {
            if ($paire !== '') {
                $parametres[] = [strtolower(rawurldecode(explode('=', $paire, 2)[0])), $paire];
            }
        }
        if ($deballer && str_ends_with($hote, 'safelinks.protection.outlook.com')) {
            foreach ($parametres as [$nom, $paire]) {
                if ($nom === 'url') {
                    return self::nettoyer_url(rawurldecode((string) (explode('=', $paire, 2)[1] ?? '')), false);
                }
            }
            return '';
        }
        $mailchimp = preg_match('~(?:^|\.)(?:mailchi\.mp|campaign-archive\.com|list-manage\.com)$~', $hote) === 1;
        $youtube = preg_match('~(?:^|\.)(?:youtu\.be|youtube\.com)$~', $hote) === 1;
        $gardes = [];
        foreach ($parametres as [$nom, $paire]) {
            $suivi = str_starts_with($nom, 'utm_') || str_starts_with($nom, 'gad_') || in_array($nom, self::SUIVI, true) || ($mailchimp && $nom === 'e') || ($youtube && $nom === 'si');
            if (!$suivi) {
                $gardes[] = $paire;
            }
        }
        $avant = strcspn($url, '?#');
        $ancre = isset($parties['fragment']) && $parties['fragment'] !== '' ? '#' . $parties['fragment'] : '';
        return substr($url, 0, $avant) . ($gardes !== [] ? '?' . implode('&', $gardes) : '') . $ancre;
    }

    /**
     * Blocs du gabarit, dans l'ordre : chacun va de sa balise `<table class="mcn…Block">` à la suivante.
     *
     * @return array<int,array{classe: string, html: string}>
     */
    private static function blocs(string $html): array {
        if (preg_match_all('~<table\b[^>]*\bclass="(mcn[A-Za-z]+Block)"~', $html, $trouves, PREG_OFFSET_CAPTURE) < 1) {
            return [];
        }
        $blocs = [];
        $nombre = count($trouves[0]);
        for ($i = 0; $i < $nombre; $i++) {
            $debut = $trouves[0][$i][1];
            $fin = $i + 1 < $nombre ? $trouves[0][$i + 1][1] : strlen($html);
            // Commentaires retirés : ceux d'Outlook (« <!--[if mso]> ») contiennent des balises de tableau.
            $blocs[] = ['classe' => $trouves[1][$i][0], 'html' => preg_replace('~<!--.*?-->~s', '', substr($html, $debut, $fin - $debut)) ?? ''];
        }
        return $blocs;
    }

    private static function premier_mot(string $titre): string {
        return preg_match('/[\p{L}\d]+/u', $titre, $m) === 1 ? mb_strtolower($m[0]) : '';
    }

    /** HTML de la zone de texte d'un bloc (`mcnTextContent`) ; tout le bloc s'il n'en a pas. */
    private static function zone_de_texte(string $bloc): string {
        if (preg_match('~class="mcnTextContent"[^>]*>~', $bloc, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return $bloc;
        }
        return substr($bloc, $m[0][1] + strlen($m[0][0]));
    }

    /** Première adresse http(s) valable parmi les premiers liens du morceau ; vide s'il n'y en a pas. */
    private static function premier_lien(string $html): string {
        $position = 0;
        for ($essai = 0; $essai < self::LIENS_MAX; $essai++) {
            if (preg_match('~<a\b[^>]*\bhref="([^"]+)"~i', $html, $lien, PREG_OFFSET_CAPTURE, $position) !== 1) {
                break;
            }
            $position = $lien[0][1] + strlen($lien[0][0]);
            $url = self::nettoyer_url($lien[1][0]);
            if ($url !== '') {
                return $url;
            }
        }
        return '';
    }

    /**
     * Premier texte en gras de plus de trois caractères ; vide s'il n'y en a pas. Recherche linéaire (pas d'expression
     * à retour arrière) et bornée aux premières balises : un HTML rempli de balises jamais fermées ne coûte ni temps
     * ni mémoire.
     */
    private static function titre_en_gras(string $zone): string {
        $sans_fermeture = [];
        $position = 0;
        for ($essai = 0; $essai < self::GRAS_MAX; $essai++) {
            if (preg_match('~<(strong|b)\b[^>]*>~i', $zone, $ouverture, PREG_OFFSET_CAPTURE, $position) !== 1) {
                break;
            }
            $position = $ouverture[0][1] + strlen($ouverture[0][0]);
            $balise = strtolower($ouverture[1][0]);
            if (isset($sans_fermeture[$balise])) {
                continue;
            }
            $fin = stripos($zone, '</' . $balise . '>', $position);
            if ($fin === false) {
                $sans_fermeture[$balise] = true;
                continue;
            }
            $titre = trim(Fiche::nettoyer_texte(substr($zone, $position, $fin - $position)), " \t:|-–");
            if (mb_strlen($titre) > 3) {
                return Fiche::court($titre, self::TITRE_GRAS_MAX);
            }
        }
        return '';
    }

    /** Résumé sans le libellé du lien qui le termine (« Lire la suite », « Pour en savoir plus »…), s'il est court. */
    private static function sans_bouton_final(string $resume, string $zone): string {
        $debut = strripos($zone, '<a ');
        $fin = $debut === false ? false : stripos($zone, '</a>', $debut);
        if ($debut !== false && $fin !== false) {
            $libelle = Fiche::nettoyer_texte(substr($zone, $debut, $fin - $debut));
            if ($libelle !== '' && mb_strlen($libelle) <= 40 && $libelle !== $resume && str_ends_with($resume, $libelle)) {
                $resume = rtrim(mb_substr($resume, 0, mb_strlen($resume) - mb_strlen($libelle)), " \t>|:-–");
            }
        }
        return trim($resume);
    }

    /** @return array{titre: string, url: string, debut: string, fin: string, lieu: string}|null */
    private static function ligne_agenda(string $morceau, string $date_numero): ?array {
        $texte = Fiche::nettoyer_texte($morceau);
        // « titre > date lieu » : la coupe est le dernier « > » suivi d'une date (un « > » de trop peut suivre la date).
        $coupes = [];
        $position = -1;
        while (($position = mb_strpos($texte, '>', $position + 1)) !== false) {
            $coupes[] = $position;
        }
        foreach (array_reverse($coupes) as $coupe) {
            $date = self::date_dans(mb_substr($texte, $coupe + 1), $date_numero);
            if ($date !== null) {
                return self::ligne(mb_substr($texte, 0, $coupe), $date['avant'] . ' ' . $date['apres'], $date, $morceau);
            }
        }
        // Sans « > » : le titre est ce qui précède la date (« Webinaire le 27 novembre en ligne »).
        $date = self::date_dans($texte, $date_numero);
        if ($date === null) {
            return null;
        }
        $titre = preg_replace('/\s+(?:dès\s+le|le|les|du)$/iu', '', rtrim($date['avant'], " \t:|,;>-–")) ?? '';
        return self::ligne($titre, $date['apres'], $date, $morceau);
    }

    /**
     * @param array{debut: string, fin: string} $date
     * @return array{titre: string, url: string, debut: string, fin: string, lieu: string}|null Null si le titre manque.
     */
    private static function ligne(string $titre, string $lieu, array $date, string $morceau): ?array {
        $titre = trim($titre, " \t:|,;>-–");
        if (mb_strlen($titre) < 4) {
            return null;
        }
        $lieu = trim(preg_replace('/\s+/u', ' ', $lieu) ?? '', " \t:|,;.>-–");
        return ['titre' => $titre, 'url' => self::premier_lien($morceau), 'debut' => $date['debut'], 'fin' => $date['fin'], 'lieu' => $lieu];
    }

    /**
     * Première date d'un texte : « 24 novembre », « 1er mai », « 24 nov. », « 24 et 25 novembre », « 24, 25 et
     * 26 novembre », « 26 au 28 octobre », « 30 novembre au 2 décembre », « 30 décembre au 2 janvier 2027 », avec ou
     * sans année.
     *
     * @return array{debut: string, fin: string, avant: string, apres: string}|null
     */
    private static function date_dans(string $texte, string $date_numero): ?array {
        // Les noms entiers d'abord : « novembre » ne doit pas être lu « nov » suivi de « embre ».
        $mois = implode('|', array_keys(self::MOIS)) . '|(?:' . implode('|', array_keys(self::MOIS_ABREGES)) . ')\.?';
        $jour = '(\d{1,2})(?:er)?';
        $annee = '(?:\s+(\d{4}))?(?![\p{L}\d])';
        // Deux mois : 30 novembre au 2 décembre, 30 décembre 2026 au 2 janvier 2027.
        if (preg_match('~(?<![\d/])' . $jour . '\s+(' . $mois . ')' . '(?:\s+(\d{4}))?' . '\s*(?:au|et|-|–)\s*' . $jour . '\s+(' . $mois . ')' . $annee . '~iu', $texte, $m, PREG_OFFSET_CAPTURE) === 1) {
            $m1 = self::numero_du_mois($m[2][0]);
            $m2 = self::numero_du_mois($m[5][0]);
            [$j1, $j2, $a1, $a2] = [(int) $m[1][0], (int) $m[4][0], (string) ($m[3][0] ?? ''), (string) ($m[6][0] ?? '')];
            if ($a1 === '' && $a2 !== '') {
                // L'année écrite est celle de la fin : le début la précède (décembre de l'année d'avant pour une fin en janvier).
                $a1 = (string) ((int) $a2 - ([$m1, $j1] > [$m2, $j2] ? 1 : 0));
            }
            $debut = self::jour_du_mois($j1, $m1, $a1, $date_numero);
            if ($debut !== null) {
                $an = (int) substr($debut, 0, 4);
                $fin = self::jour_du_mois($j2, $m2, $a2 !== '' ? $a2 : (string) $an, $date_numero);
                if ($fin !== null && $fin < $debut && $a2 === '') {
                    // Série à cheval sur deux années : 29 décembre au 2 janvier.
                    $fin = self::jour_du_mois($j2, $m2, (string) ($an + 1), $date_numero);
                }
                if ($fin !== null && $fin >= $debut) {
                    return self::date_trouvee($debut, $fin, $texte, $m[0][1], strlen($m[0][0]));
                }
            }
        }
        // Un mois : 24 novembre, 24 et 25 novembre, 24, 25 et 26 novembre, 26 au 28 octobre.
        $jours = '((?:\d{1,2}(?:er)?\s*,\s*)+\d{1,2}(?:er)?\s+et\s+\d{1,2}(?:er)?|\d{1,2}(?:er)?(?:\s*(?:au|et|-|–)\s*\d{1,2}(?:er)?)?)';
        if (preg_match_all('~(?<![\d/])' . $jours . '\s+(' . $mois . ')' . $annee . '~iu', $texte, $trouvees, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) > 0) {
            foreach ($trouvees as $m) {
                preg_match_all('/\d{1,2}/', $m[1][0], $nombres);
                $numero = self::numero_du_mois($m[2][0]);
                $debut = self::jour_du_mois((int) $nombres[0][0], $numero, (string) ($m[3][0] ?? ''), $date_numero);
                if ($debut === null) {
                    continue;
                }
                $fin = self::jour_du_mois((int) end($nombres[0]), $numero, substr($debut, 0, 4), $date_numero);
                // Fin avant le début dans le même mois : une coquille, on garde le premier jour.
                return self::date_trouvee($debut, $fin !== null && $fin >= $debut ? $fin : $debut, $texte, $m[0][1], strlen($m[0][0]));
            }
        }
        return null;
    }

    /** @return array{debut: string, fin: string, avant: string, apres: string} */
    private static function date_trouvee(string $debut, string $fin, string $texte, int $position, int $longueur): array {
        return ['debut' => $debut, 'fin' => $fin, 'avant' => trim(substr($texte, 0, $position)), 'apres' => trim(substr($texte, $position + $longueur))];
    }

    /** Numéro d'un mois écrit en entier ou en abrégé (« nov. »). */
    private static function numero_du_mois(string $mois): int {
        $mois = rtrim(mb_strtolower($mois), '.');
        return self::MOIS[$mois] ?? self::MOIS_ABREGES[$mois] ?? 0;
    }

    /** Date `Y-m-d` ; sans année, celle de l'envoi, ou la suivante si le jour est passé depuis plus de trois mois. */
    private static function jour_du_mois(int $jour, int $mois, string $annee, string $date_numero): ?string {
        if ($mois < 1) {
            return null;
        }
        if ($annee !== '') {
            return checkdate($mois, $jour, (int) $annee) ? sprintf('%04d-%02d-%02d', (int) $annee, $mois, $jour) : null;
        }
        $envoi = \DateTimeImmutable::createFromFormat('!Y-m-d', $date_numero);
        if ($envoi === false) {
            return null;
        }
        $an = (int) $envoi->format('Y');
        if (!checkdate($mois, $jour, $an)) {
            return checkdate($mois, $jour, $an + 1) ? sprintf('%04d-%02d-%02d', $an + 1, $mois, $jour) : null;
        }
        $date = sprintf('%04d-%02d-%02d', $an, $mois, $jour);
        if ($date < $envoi->modify('-' . self::JOURS_PASSES . ' days')->format('Y-m-d') && checkdate($mois, $jour, $an + 1)) {
            return sprintf('%04d-%02d-%02d', $an + 1, $mois, $jour);
        }
        return $date;
    }

    /** Contenu du premier élément `<nom>…</nom>` d'un morceau du flux, section CDATA dépliée ; vide s'il manque. */
    private static function champ(string $item, string $nom): string {
        $debut = strpos($item, '<' . $nom . '>');
        $fin = $debut === false ? false : strpos($item, '</' . $nom . '>', $debut);
        if ($debut === false || $fin === false) {
            return '';
        }
        $valeur = trim(substr($item, $debut + strlen($nom) + 2, $fin - $debut - strlen($nom) - 2));
        if (str_starts_with($valeur, '<![CDATA[') && str_ends_with($valeur, ']]>')) {
            return substr($valeur, 9, -3);
        }
        return html_entity_decode($valeur, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** Jour `Y-m-d` d'une date de flux (RFC 2822), dans son propre fuseau ; vide si elle est illisible. */
    private static function jour(string $texte): string {
        if (trim($texte) === '') {
            return '';
        }
        try {
            return (new \DateTimeImmutable(trim($texte)))->format('Y-m-d');
        } catch (\Exception $e) {
            return '';
        }
    }
}

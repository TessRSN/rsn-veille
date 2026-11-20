<?php
declare(strict_types=1);

/**
 * Fabrique d'infolettres Mailchimp pour les tests : le gabarit standard (blocs `mcn…Block`) de l'infolettre de l'Unité
 * de soutien SSA Québec (2026), et le flux d'archive qui en porte les numéros.
 */
final class GabaritMailchimp {
    public static function carte(string $titre, string $texte, string $lien): string {
        return '<table class="mcnImageCardBlock"><tbody><tr><td><img src="https://mcusercontent.com/x.png" alt="">'
            . '<table><tr><td class="mcnTextContent"><strong>' . $titre . '</strong><br>' . $texte
            . ($lien !== '' ? ' <a href="' . $lien . '" target="_blank">Lire la suite</a>' : '') . '</td></tr></table></td></tr></tbody></table>';
    }

    public static function section(string $titre): string {
        return '<table class="mcnBoxedTextBlock"><tbody><tr><td class="mcnTextContent"><span>' . $titre . '</span></td></tr></tbody></table>';
    }

    /** @param string[] $lignes Lignes HTML de l'agenda (contenu d'un <li>). */
    public static function agenda(array $lignes): string {
        return '<table class="mcnBoxedTextBlock"><tbody><tr><td class="mcnTextContent"><div><span><span>À L\'AGENDA</span></span><br> &nbsp;</div><ul><li>'
            . implode('</li><li>', $lignes) . '</li></ul></td></tr></tbody></table>';
    }

    public static function numero(array $blocs): string {
        return '<style>.x{color:red}</style><table class="mcnTextBlock"><tbody><tr><td class="mcnTextContent">Voir l\'infolettre dans le navigateur</td></tr></tbody></table>'
            . implode('', $blocs)
            . '<table class="mcnFollowBlock"><tbody><tr><td><a href="https://www.linkedin.com/company/x">LinkedIn</a></td></tr></tbody></table>';
    }

    /** @param array<int,array{0: string, 1: string, 2: string, 3: string}> $numeros [titre, lien, date RSS, html] */
    public static function flux(array $numeros): string {
        $items = '';
        foreach ($numeros as [$titre, $lien, $date, $html]) {
            $items .= '<item><title>' . $titre . '</title><link>' . $lien . '</link><guid>' . $lien . '</guid><description><![CDATA[<html><head><title>' . $titre . '</title></head><body>' . $html . '</body></html>]]></description>'
                . '<content:encoded><![CDATA[<html><head><title>' . $titre . '</title></head><body>' . $html . '</body></html>]]></content:encoded><pubDate>' . $date . '</pubDate></item>';
        }
        return "\n" . '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>Infolettre Archive Feed</title><pubDate>Fri, 01 Jan 2021 10:00:00 +0000</pubDate>' . $items . '</channel></rss>';
    }

    /**
     * Un numéro ordinaire : `$cartes` est une liste de [titre, lien] ou de liens seuls ; `$agenda`, des lignes HTML.
     *
     * @param array<int,array{0: string, 1: string}|string> $cartes
     * @param string[] $agenda
     */
    public static function numero_de(array $cartes, array $agenda = []): string {
        $blocs = [self::section('Développer des savoirs')];
        foreach ($cartes as $rang => $carte) {
            [$titre, $lien] = is_array($carte) ? $carte : ['Encadré ' . ($rang + 1) . ' ' . basename($carte), $carte];
            $blocs[] = self::carte($titre, 'Texte de présentation.', $lien);
        }
        if ($agenda !== []) {
            $blocs[] = self::agenda($agenda);
        }
        return self::numero($blocs);
    }
}

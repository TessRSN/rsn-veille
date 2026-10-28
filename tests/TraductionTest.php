<?php
declare(strict_types=1);

require_once __DIR__ . '/bouchons-wordpress.php';

use PHPUnit\Framework\TestCase;

/**
 * Les textes publics sont traduits par un fichier gettext livré avec l'extension :
 * languages/rsn-veille-en_US.po (source) et .mo (compilé par msgfmt, lu par WordPress).
 */
final class TraductionTest extends TestCase {
    private const RACINE = __DIR__ . '/..';
    private const PO = self::RACINE . '/languages/rsn-veille-en_US.po';
    private const MO = self::RACINE . '/languages/rsn-veille-en_US.mo';

    /** Séparateur contexte / texte, comme dans un .mo. */
    private const CTXT = "\x04";

    public function test_po_couvre_toutes_les_chaines_publiques(): void {
        $this->assertFileExists(self::PO);
        $po = $this->lire_po(self::PO);
        $manquantes = [];
        foreach ($this->chaines_publiques() as $cle => $pluriel) {
            $entree = $po[$cle] ?? null;
            if ($entree === null) {
                $manquantes[] = $this->afficher($cle) . ' (absente)';
                continue;
            }
            if ($entree['fuzzy']) {
                $manquantes[] = $this->afficher($cle) . ' (fuzzy)';
            }
            if ($pluriel !== false && $entree['pluriel'] !== $pluriel) {
                $manquantes[] = $this->afficher($cle) . ' (msgid_plural différent du PHP)';
            }
            $attendues = $pluriel !== false ? 2 : 1;
            for ($n = 0; $n < $attendues; $n++) {
                if (trim($entree['msgstr'][$n] ?? '') === '') {
                    $manquantes[] = $this->afficher($cle) . " (traduction $n vide)";
                }
            }
        }
        $this->assertSame([], $manquantes, "Chaînes publiques sans traduction anglaise :\n" . implode("\n", $manquantes));
    }

    public function test_les_chaines_extraites_sont_nombreuses(): void {
        // Garde-fou : une expression régulière cassée ne doit pas faire passer le test de couverture à vide.
        $this->assertGreaterThan(150, count($this->chaines_publiques()));
    }

    public function test_les_marqueurs_de_format_sont_conserves_dans_la_traduction(): void {
        $po = $this->lire_po(self::PO);
        $erreurs = [];
        foreach ($this->chaines_publiques() as $cle => $pluriel) {
            $entree = $po[$cle] ?? null;
            if ($entree === null) {
                continue;
            }
            $source = $this->marqueurs($cle);
            foreach ($entree['msgstr'] as $traduction) {
                if ($this->marqueurs($traduction) !== $source) {
                    $erreurs[] = $this->afficher($cle) . ' -> ' . $traduction;
                }
            }
        }
        $this->assertSame([], $erreurs, "Marqueurs de format différents :\n" . implode("\n", $erreurs));
    }

    public function test_mo_compile_a_jour(): void {
        $this->assertFileExists(self::MO, 'Le .mo doit être compilé : msgfmt --check --statistics -o languages/rsn-veille-en_US.mo languages/rsn-veille-en_US.po');
        $octets = (string) file_get_contents(self::MO);
        $this->assertGreaterThanOrEqual(28, strlen($octets));
        $entete = unpack('Vmagic/Vrevision/Vnombre/Voriginaux/Vtraductions', $octets);
        $this->assertSame(0x950412de, $entete['magic'], 'Un .mo petit-boutien');
        $po = $this->lire_po(self::PO);
        // Le .mo compte aussi l'entrée vide (l'en-tête), qui est dans le .po.
        $this->assertSame(count($po), $entete['nombre'], 'Le .mo annonce autant d\'entrées que le .po : recompiler avec msgfmt');

        // Contenu : chaque entrée du .po s'y retrouve, telle quelle.
        $mo = [];
        for ($i = 0; $i < $entete['nombre']; $i++) {
            $o = unpack('Vlongueur/Vposition', $octets, $entete['originaux'] + 8 * $i);
            $t = unpack('Vlongueur/Vposition', $octets, $entete['traductions'] + 8 * $i);
            $mo[substr($octets, $o['position'], $o['longueur'])] = substr($octets, $t['position'], $t['longueur']);
        }
        $differences = [];
        foreach ($po as $cle => $entree) {
            $original = $cle;
            if ($entree['pluriel'] !== null) {
                $original .= "\0" . $entree['pluriel'];
            }
            $attendue = implode("\0", $entree['msgstr']);
            if (!array_key_exists($original, $mo)) {
                $differences[] = $this->afficher($cle) . ' (absente du .mo)';
            } elseif ($cle !== '' && $mo[$original] !== $attendue) {
                $differences[] = $this->afficher($cle) . ' (traduction différente dans le .mo)';
            }
        }
        $this->assertSame([], $differences, "Le .mo n'est pas à jour :\n" . implode("\n", $differences));
    }

    public function test_entete_du_po(): void {
        $po = $this->lire_po(self::PO);
        $this->assertArrayHasKey('', $po);
        $entete = $po['']['msgstr'][0];
        $this->assertStringContainsString('Language: en_US', $entete);
        $this->assertStringContainsString('charset=UTF-8', $entete);
        $this->assertStringContainsString('Plural-Forms: nplurals=2; plural=(n != 1);', $entete);
    }

    public function test_le_domaine_est_charge_au_demarrage_depuis_le_dossier_languages(): void {
        rsn_veille_bouchons_reinitialiser();
        $GLOBALS['rsn_veille_wp']['domaines'] = [];
        rsn_veille_charger_traductions();
        $appels = $GLOBALS['rsn_veille_wp']['domaines'];
        $this->assertCount(1, $appels);
        $this->assertSame('rsn-veille', $appels[0]['domaine']);
        $this->assertFalse($appels[0]['obsolete']);
        $this->assertSame(dirname(plugin_basename(RSN_VEILLE_DOSSIER . '/rsn-veille.php')) . '/languages', $appels[0]['chemin']);

        $principal = (string) file_get_contents(self::RACINE . '/rsn-veille.php');
        $this->assertMatchesRegularExpression("/add_action\\('init',\\s*'rsn_veille_charger_traductions'\\)/", $principal);
    }

    // --- Extraction des chaînes du PHP ---

    /**
     * Chaînes traduisibles du côté public : clé (« contexte \x04 texte » ou « texte ») => false, ou le pluriel
     * (msgid_plural) pour `_n`, dont le singulier est la clé.
     *
     * @return array<string,false|string>
     */
    private function chaines_publiques(): array {
        $fichiers = array_merge(glob(self::RACINE . '/includes/Public/*.php') ?: [], [self::RACINE . '/includes/Sources.php']);
        $litteral = '(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")';
        $motif = '/(?<![A-Za-z0-9_>:$])(esc_html__|esc_attr__|__|_x)\(\s*' . $litteral . '(?:\s*,\s*' . $litteral . ')?(?:\s*,\s*' . $litteral . ')?(?:\s*,\s*' . $litteral . ')?/s';
        $chaines = [];
        foreach ($fichiers as $fichier) {
            $code = (string) file_get_contents($fichier);
            preg_match_all($motif, $code, $trouvees, PREG_SET_ORDER);
            foreach ($trouvees as $m) {
                $fonction = $m[1];
                $arguments = array_map([$this, 'decoder'], array_values(array_filter(array_slice($m, 2), static fn(string $a): bool => $a !== '')));
                if ($fonction === '_x') {
                    // _x(texte, contexte, domaine)
                    if (($arguments[2] ?? null) === 'rsn-veille') {
                        $chaines[$arguments[1] . self::CTXT . $arguments[0]] = false;
                    }
                } elseif (($arguments[1] ?? null) === 'rsn-veille') {
                    $chaines[$arguments[0]] = false;
                }
            }
            // _n(singulier, pluriel, nombre, domaine) : le nombre est une expression, le domaine clôt l'appel.
            $motif_n = '/(?<![A-Za-z0-9_>:$])_n\(\s*' . $litteral . '\s*,\s*' . $litteral . '\s*,(?:(?!_n\()[^;])*?,\s*\'rsn-veille\'\s*\)/s';
            preg_match_all($motif_n, $code, $pluriels, PREG_SET_ORDER);
            foreach ($pluriels as $m) {
                $chaines[$this->decoder($m[1])] = $this->decoder($m[2]);
            }
        }
        return $chaines;
    }

    /** Valeur d'un littéral PHP entre apostrophes ou entre guillemets. */
    private function decoder(string $litteral): string {
        $corps = substr($litteral, 1, -1);
        if ($litteral[0] === "'") {
            return strtr($corps, ["\\\\" => "\\", "\\'" => "'"]);
        }
        return strtr($corps, ["\\\\" => "\\", '\\"' => '"', "\\n" => "\n", "\\t" => "\t", "\\\$" => '$']);
    }

    // --- Analyse du .po ---

    /**
     * @return array<string,array{pluriel:?string,msgstr:array<int,string>,fuzzy:bool}>
     */
    private function lire_po(string $chemin): array {
        $entrees = [];
        $courante = null;
        $champ = null;
        $indice = 0;
        $fuzzy = false;
        $fermer = function () use (&$entrees, &$courante, &$champ, &$fuzzy): void {
            if ($courante !== null && isset($courante['msgid'])) {
                $cle = (isset($courante['msgctxt']) ? $courante['msgctxt'] . self::CTXT : '') . $courante['msgid'];
                $entrees[$cle] = [
                    'pluriel' => $courante['msgid_plural'] ?? null,
                    'msgstr' => $courante['msgstr'] ?? [],
                    'fuzzy' => $fuzzy,
                ];
            }
            $courante = null;
            $champ = null;
            $fuzzy = false;
        };
        foreach (preg_split('/\R/', (string) file_get_contents($chemin)) ?: [] as $ligne) {
            $ligne = trim($ligne);
            if ($ligne === '') {
                $fermer();
                continue;
            }
            if ($ligne[0] === '#') {
                if (str_starts_with($ligne, '#,') && str_contains($ligne, 'fuzzy')) {
                    $fuzzy = true;
                }
                continue;
            }
            if (preg_match('/^(msgctxt|msgid_plural|msgid|msgstr)(?:\[(\d+)\])?\s+(".*")$/', $ligne, $m) === 1) {
                if ($m[1] === 'msgctxt' && $courante !== null && isset($courante['msgstr'])) {
                    $fermer();
                }
                if ($m[1] === 'msgid' && $courante !== null && isset($courante['msgstr'])) {
                    $fermer();
                }
                $courante ??= [];
                $champ = $m[1];
                $indice = $m[2] === '' ? 0 : (int) $m[2];
                $valeur = $this->decoder_po($m[3]);
                if ($champ === 'msgstr') {
                    $courante['msgstr'][$indice] = $valeur;
                } else {
                    $courante[$champ] = $valeur;
                }
                continue;
            }
            if ($ligne[0] === '"' && $champ !== null && $courante !== null) {
                $valeur = $this->decoder_po($ligne);
                if ($champ === 'msgstr') {
                    $courante['msgstr'][$indice] .= $valeur;
                } else {
                    $courante[$champ] .= $valeur;
                }
            }
        }
        $fermer();
        return $entrees;
    }

    private function decoder_po(string $litteral): string {
        return strtr(substr($litteral, 1, -1), ['\\\\' => '\\', '\\"' => '"', '\\n' => "\n", '\\t' => "\t"]);
    }

    // --- Outils ---

    private function afficher(string $cle): string {
        return str_replace(self::CTXT, ' | ', $cle);
    }

    /** Marqueurs de format (%s, %d, %1$s…) d'une chaîne, triés. */
    private function marqueurs(string $texte): array {
        preg_match_all('/%(?:\d+\$)?[sd]/', $texte, $m);
        sort($m[0]);
        return $m[0];
    }
}

# Plan de construction : extension WordPress rsn-veille

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Une extension WordPress `rsn-veille` qui collecte chaque nuit les événements et appels de l'écosystème de la santé numérique, les range dans une file d'approbation dans wp-admin, et affiche sur rsn.quebec une page Veille (agenda, calendrier, appels) dans le style du site, avec export pour l'infolettre et courriel hebdomadaire optionnel.

**Architecture :** Un type de contenu `rsn_veille` porte les fiches (statut WordPress = statut d'approbation). Des *lecteurs* purs (JSON, RSS, iCal, HTML) transforment un contenu brut en objets `Fiche` ; une classe `Pertinence` décide du statut initial ; un `Depot` fusionne et écrit ; `Collecte` enchaîne les sources une par une via WP-Cron. Le rendu public est un raccourci qui incorpore un JSON et réutilise le CSS et le JS de la maquette v6.1 ; l'administration ajoute un menu « Veille » réservé aux rôles autorisés.

**Tech Stack :** PHP ≥ 8.0 (compatible 8.0 à 8.3), WordPress (API des types de contenu, options, WP-Cron, REST, `fetch_feed`/SimplePie, `DOMDocument`, `wp_mail`), JavaScript et CSS sans dépendance, PHPUnit 9 via Composer pour les classes pures, `@wordpress/env` (wp-env, Docker) pour les essais dans un WordPress local.

**Spec :** `docs/superpowers/specs/2026-10-25-veille-ecosysteme-design.md` (révision 2 du 2026-10-26). Inventaire des sources : `docs/inventaire-sources-2026-10-25.md`. Maquette validée : `docs/maquette/gabarit-v6.1.html`. Fichiers d'exemple réels (collecte du 2026-10-25) : `tests/fixtures/`.

## Global Constraints

- Tout en français : noms de classes, de fonctions, de variables, commentaires, messages, commits, documentation. Préfixe `rsn_veille_` pour les fonctions globales, options, métadonnées, actions AJAX ; espace de noms PHP `RSN\Veille`.
- Les commits portent uniquement le nom de Tess (`TessRSN`), sans ligne `Co-Authored-By` ni mention de Claude. Messages de commit en français, préfixés `feat:`, `fix:`, `test:`, `docs:`, `chore:`.
- Aucune dépendance externe à l'exécution (pas de bibliothèque Composer chargée en production ; PHPUnit en `require-dev` seulement). Aucun secret dans le dépôt.
- Compatibilité : PHP 8.0 à 8.3, WordPress 6.x, Elementor, WPML, The Events Calendar, LiteSpeed Cache. Toute route REST maison appelle `do_action('litespeed_control_set_nocache')`. Toute écriture de fiches purge la page Veille (`do_action('litespeed_purge_post', $id_page)`) et supprime la méta `_elementor_element_cache` de cette page.
- Toutes les chaînes visibles passent par `__('…', 'rsn-veille')` ou `esc_html__`, pour la version anglaise à venir (WPML) ; textes français par défaut.
- Heures et dates en heure de Montréal (`America/Toronto`), stockées au format `Y-m-d` (dates) et `H:i` (heure) dans les métadonnées. Aujourd'hui = `wp_date('Y-m-d')`.
- Pièges du site à respecter : une source qui renvoie du HTML en code 200 à la place d'un flux n'est pas un flux (vérifier le `Content-Type` et le début du corps) ; délai d'attente de 15 s par requête ; au plus une source traitée par exécution de tâche planifiée ; on ne contourne jamais une protection anti-robot (un 403 est une erreur consignée, point).
- Style public : polices DM Serif Display (titres) et Plus Jakarta Sans 400 à 600 (texte), couleurs `#003469` (marine), `#3D6A99` (traits), `#FFCC4E` et `#E3A800` (jaune), `#101828` (encre), `#DCE1E8` (filets) ; thème clair seulement ; pas de carte à contour, pas de pastille, pas de bordure latérale épaisse. Le CSS et le JS de `docs/maquette/gabarit-v6.1.html` sont la référence et sont repris tels quels sauf adaptation nécessaire.
- Règles de confiance (devis § 4.2), priorité des niveaux `A > C > M > B`, champs factuels seuls mis à jour après création (dates, heure, URL, image, prix, lieu), drapeau « absent » après deux collectes réussies consécutives, jamais de dépublication automatique.

## Review Focus

1. **Un flux qui renvoie une page HTML (code 200)** ou un flux vide de gabarit (« Hello world ! ») : le lecteur doit rendre zéro fiche et une erreur lisible, jamais une fiche fantôme ni une exception. Test ajouté à la tâche 5.
2. **Dates exotiques** : `pubDate` INSPQ « jeu, 10/22/2026 - 08:25 », iCal en UTC, événement « toute la journée » de The Events Calendar, série du 4 septembre au 18 décembre : la fiche doit porter des dates `Y-m-d` justes en heure de Montréal. Tests ajoutés aux tâches 2, 4 et 7.
3. **Titres avec entités HTML, tirets typographiques et émojis** (« Colloque &#8211; ACFAS », « ✨Journées scientifiques 2027 du RQRV✨ ») : affichage décodé, et la clé de dédoublonnage ignore ponctuation, accents et émojis. Test ajouté à la tâche 2.
4. **Source en panne une nuit** (403, 429, délai) : ses fiches publiées ne doivent pas être marquées absentes ; seules deux collectes **réussies** consécutives sans l'élément le font. Test ajouté à la tâche 8.
5. **Formulaire public malveillant ou bavard** : champ piège rempli, dépassement de la limite de débit, titre de 5 000 caractères, lien `javascript:` : rejet silencieux ou troncature, jamais de fiche créée avec du contenu dangereux. Test ajouté à la tâche 14.

---

## Structure des fichiers

```
rsn-veille/
  rsn-veille.php                         en-tête d'extension, constantes, autochargement, activation/désactivation (tâches planifiées)
  composer.json                          PHPUnit en dev, autoload PSR-4 RSN\Veille\ → includes/
  .wp-env.json                           WordPress local avec l'extension montée
  includes/
    Fiche.php                            objet valeur : champs du devis § 3.2, normalisation, clé de dédoublonnage, région, format
    Pertinence.php                       mots-clés, thèmes, statut initial selon le type de source
    Sources.php                          registre des sources (config par défaut fusionnée avec les réglages)
    Lecteurs/Lecteur.php                 interface : lire(string $contenu, array $source): Resultat
    Lecteurs/Resultat.php                fiches + erreurs + éléments ignorés
    Lecteurs/LecteurTec.php              JSON The Events Calendar
    Lecteurs/LecteurRss.php              RSS/Atom : événements, appels par catégorie, nouvelles
    Lecteurs/LecteurIcal.php             iCal
    Lecteurs/LecteurHtml.php             base DOMDocument + sous-classes Obvia, Mila, FrqEvenements, Ivado
    Lecteurs/Echeance.php                extraction d'une date limite dans un texte français
    Entrepot.php                         interface de persistance (trouver, créer, fusionner, mettre à jour, marquer)
    EntrepotMemoire.php                  implémentation en mémoire pour les tests
    EntrepotWordPress.php                implémentation sur le type de contenu rsn_veille
    Depot.php                            logique de fusion et de mise à jour, indépendante de WordPress
    Http.php                             wp_remote_get encapsulé : délai 15 s, vérification du type de contenu
    TypeContenu.php                      enregistrement du CPT rsn_veille, de la taxonomie rsn_theme, des métadonnées
    Collecte.php                         file de sources, tâche planifiée, journal, purge des caches
    Journal.php                          option rsn_veille_journal
    Admin/Menu.php                       menu Veille et capacités
    Admin/PageFile.php                   file d'approbation, À vérifier, actions AJAX
    Admin/PageRadar.php                  nouvelles à la volée, garder/ignorer
    Admin/PageInfolettre.php             trois blocs HTML et boutons de copie
    Admin/PageReglages.php               sources, mots-clés, thèmes, rôles, courriel, heure, import, lancer
    Admin/PageJournal.php                journal de collecte
    Public/Donnees.php                   JSON de la page : fiches publiées + événements TEC du RSN
    Public/Raccourci.php                 [rsn_veille]
    Public/Rest.php                      /wp-json/rsn/v1/veille et /veille.ics
    Public/Formulaire.php                proposition d'événement
    Courriel.php                         courriel hebdomadaire
  assets/veille.css  assets/veille.js    repris de la maquette v6.1
  assets/admin.css   assets/admin.js
  tests/bootstrap.php                    autoload + bouchons minimaux des fonctions WordPress utilisées par les classes pures
  tests/*Test.php                        un fichier de test par classe pure
  tests/fixtures/                        contenus réels du 2026-10-25
  LISEZMOI.md                            installation, réglages, bascule, dépannage
```

Les classes `Fiche`, `Pertinence`, `Lecteurs/*`, `Depot`, `Echeance`, `Sources` ne doivent appeler aucune fonction WordPress autre que celles bouchonnées dans `tests/bootstrap.php` (`esc_html`, `wp_strip_all_tags`, `sanitize_text_field`, `__`, `wp_date`), pour rester testables en PHPUnit pur. Tout ce qui touche la base, l'administration, le rendu et le réseau passe par les classes WordPress (`EntrepotWordPress`, `Http`, `TypeContenu`, `Collecte`, `Admin/*`, `Public/*`) et se vérifie dans wp-env.

---

### Tâche 1 : squelette de l'extension et outillage

**Fichiers :**
- Créer : `rsn-veille.php`, `composer.json`, `.wp-env.json`, `.gitignore`, `phpunit.xml`, `tests/bootstrap.php`, `tests/SqueletteTest.php`, `LISEZMOI.md`
- Existants : `docs/`, `tests/fixtures/` (déjà dans le dépôt)

**Interfaces :**
- Produit : constante `RSN_VEILLE_VERSION` (`'0.1.0'`), `RSN_VEILLE_DOSSIER` (chemin), `RSN_VEILLE_URL` ; autochargement PSR-4 `RSN\Veille\` vers `includes/` ; fonction `rsn_veille_aujourdhui(): string` (`wp_date('Y-m-d')`, bouchonnée en test par une variable globale `$rsn_veille_test_aujourdhui = '2026-10-25'`).

- [ ] **Étape 1 : écrire le test**

```php
final class SqueletteTest extends \PHPUnit\Framework\TestCase {
    public function test_autochargement_et_version(): void {
        $this->assertSame('0.1.0', RSN_VEILLE_VERSION);
        $this->assertSame('2026-10-25', rsn_veille_aujourdhui());
        $this->assertFileExists(RSN_VEILLE_DOSSIER . '/tests/fixtures/dhc.json');
    }
}
```

- [ ] **Étape 2 : lancer le test, vérifier qu'il échoue** — `composer install && vendor/bin/phpunit` → échec « RSN_VEILLE_VERSION non définie ».

- [ ] **Étape 3 : écrire `rsn-veille.php`** (en-tête d'extension : nom « RSN : veille de l'écosystème », version, auteur « Réseau santé numérique », `Requires PHP: 8.0`, `Text Domain: rsn-veille`), les constantes, l'autochargeur PSR-4 maison (pas celui de Composer en production), `rsn_veille_aujourdhui()`, et un `tests/bootstrap.php` qui définit `ABSPATH` factice, les bouchons `esc_html`, `wp_strip_all_tags`, `sanitize_text_field`, `__`, `wp_date` (renvoie `$rsn_veille_test_aujourdhui` pour le format `Y-m-d`), puis inclut `rsn-veille.php`. `composer.json` : `phpunit/phpunit ^9`, script `test`. `.wp-env.json` : WordPress stable, PHP 8.2, `"plugins": ["."]`. `.gitignore` : `vendor/`, `.env*`, `node_modules/`.

- [ ] **Étape 4 : lancer le test, vérifier qu'il passe** — `composer test` → `OK (1 test)`.

- [ ] **Étape 5 : LISEZMOI.md** : trois paragraphes (ce que fait l'extension, comment lancer les tests, comment lancer wp-env), à compléter au fil des tâches.

- [ ] **Étape 6 : premier commit et dépôt distant**

```bash
git add -A && git commit -m "chore: squelette de l'extension rsn-veille et outillage de test"
gh auth status            # le compte ACTIF doit être TessRSN ; sinon : gh auth switch --user TessRSN
gh api user --jq .login   # doit afficher TessRSN
gh repo create TessRSN/rsn-veille --private --source=. --push
```
La machine a plusieurs comptes GitHub (forkin-hell, TessRSN) : tout dépôt du RSN appartient à **TessRSN**, jamais à un autre compte. Si `gh` refuse, laisser le dépôt local et le signaler à Tess.

---

### Tâche 2 : objet Fiche et normalisation

**Fichiers :**
- Créer : `includes/Fiche.php`, `tests/FicheTest.php`

**Interfaces :**
- Produit : `final class Fiche` avec propriétés publiques typées : `string $type` (`evenement|appel|nouvelle`), `string $titre`, `?string $debut`, `?string $fin`, `string $heure = ''`, `string $organisateur`, `string $categorie_rsn = ''`, `string $lieu = ''`, `string $ville = ''`, `string $region = ''`, `string $format = ''`, `string $prix = ''`, `string $langue = 'fr'`, `array $themes = []`, `string $resume = ''`, `string $url`, `string $image_url = ''`, `string $source`, `string $niveau` (`A|B|C|M`), `string $statut = 'a_approuver'`, `string $cle_source` (identifiant stable `source:id`), `array $cles_source = []` (toutes les clés fusionnées), `string $categorie = ''` (appels), `?string $echeance = null` (appels), `?int $id = null`, `?string $absent_depuis = null`, `int $absences = 0`, `bool $manuel = false`.
- Méthodes : `static nettoyer_texte(string $html): string` (entités décodées, balises retirées, espaces réduits) ; `static court(string $texte, int $max = 260): string` (coupe au mot, ajoute « … ») ; `static deviner_format(string $texte, bool $virtuel = false): string` ; `static deviner_region(string $ville, string $lieu, string $format): string` ; `cle_dedoublonnage(): string` (`debut|22 premiers caractères du titre en minuscules sans accents, ponctuation ni émojis`) ; `vers_tableau(): array` et `static depuis_tableau(array $a): Fiche`.

- [ ] **Étape 1 : écrire les tests**

```php
public function test_nettoyage_entites_et_emojis(): void {
    $f = new Fiche(); $f->type = 'evenement'; $f->debut = '2027-04-14';
    $f->titre = Fiche::nettoyer_texte('✨Journées scientifiques 2027 du RQRV✨');
    $this->assertSame('✨Journées scientifiques 2027 du RQRV✨', $f->titre);
    $this->assertSame('2027-04-14|journeesscientifiques2', $f->cle_dedoublonnage());
    $this->assertSame('Colloque – ACFAS 2026', Fiche::nettoyer_texte('Colloque &#8211; ACFAS 2026'));
}
public function test_court_coupe_au_mot(): void {
    $this->assertSame('Le Réseau québécois de recherche sur le sommeil vous…', Fiche::court('Le Réseau québécois de recherche sur le sommeil vous invite', 55));
}
public function test_format_et_region(): void {
    $this->assertSame('Virtuel', Fiche::deviner_format('Conférence en ligne par Zoom'));
    $this->assertSame('Hybride', Fiche::deviner_format('Journée hybride'));
    $this->assertSame('Présentiel', Fiche::deviner_format('HEC Montréal'));
    $this->assertSame('En ligne', Fiche::deviner_region('', 'Online', 'Virtuel'));
    $this->assertSame('Québec', Fiche::deviner_region('Montreal', 'Plaza Centre-Ville', 'Présentiel'));
    $this->assertSame('Hors Québec', Fiche::deviner_region('Calgary', '', 'Présentiel'));
}
public function test_aller_retour_tableau(): void { /* depuis_tableau(vers_tableau()) == original */ }
```

- [ ] **Étape 2 : lancer, vérifier l'échec** — `composer test` → « Class Fiche not found ».
- [ ] **Étape 3 : implémenter `includes/Fiche.php`** selon les signatures ci-dessus. Villes du Québec reconnues pour la région : Montréal, Montreal, Québec, Quebec, Laval, Sherbrooke, Trois-Rivières, Drummondville, Gatineau, Longueuil, Brossard, Rimouski, Saguenay, Chicoutimi.
- [ ] **Étape 4 : lancer, vérifier le succès.**
- [ ] **Étape 5 : commit** — `feat: objet Fiche, normalisation et clé de dédoublonnage`.

---

### Tâche 3 : Pertinence (mots-clés, thèmes, statut initial)

**Fichiers :**
- Créer : `includes/Pertinence.php`, `tests/PertinenceTest.php`

**Interfaces :**
- Produit : `final class Pertinence` construite avec `array $config` (`mots_sante`, `mots_numerique`, `themes` = liste de `['nom' => ..., 'mots' => [...]]`) et `static config_par_defaut(): array` (listes du collecteur prototype, voir `docs/maquette/` et le devis). Méthodes : `touche(string $texte): array{sante: bool, numerique: bool}` ; `themes(string $texte, int $max = 2): array` ; `statut(string $type_source, string $texte): string` où `$type_source ∈ {sante_numerique, sante, numerique, generaliste, proposition}` et le retour ∈ `{publie, a_approuver, ecarte}` selon le devis § 4.2.

- [ ] **Étape 1 : tests**

```php
public function test_statut_selon_type_de_source(): void {
    $p = new Pertinence(Pertinence::config_par_defaut());
    $this->assertSame('publie', $p->statut('sante_numerique', 'Office Hours Online'));
    $this->assertSame('publie', $p->statut('sante', 'Mini-retraite sur l’intelligence artificielle et les technologies du sommeil'));
    $this->assertSame('a_approuver', $p->statut('sante', 'Somnoclub : insomnie chez les personnes âgées'));
    $this->assertSame('publie', $p->statut('numerique', 'Humains, IA et santé au travail'));
    $this->assertSame('a_approuver', $p->statut('numerique', 'Hackathon EcoHull'));
    $this->assertSame('ecarte', $p->statut('generaliste', 'Vulnérabilité critique de PHP'));
    $this->assertSame('a_approuver', $p->statut('generaliste', 'Mise en garde : Listeria dans du pain'));
    $this->assertSame('a_approuver', $p->statut('proposition', 'Colloque IA en santé'));
}
public function test_themes(): void {
    $p = new Pertinence(Pertinence::config_par_defaut());
    $this->assertSame(['IA', 'Vieillissement'], $p->themes("L'IA au service de l'autonomie des aînés"));
}
```

- [ ] **Étape 2 : échec attendu.** — [ ] **Étape 3 : implémenter** (recherche de sous-chaînes en minuscules, texte encadré d'espaces ; thèmes dans l'ordre de la configuration, coupés à `$max`). — [ ] **Étape 4 : succès.** — [ ] **Étape 5 : commit** `feat: règles de pertinence, thèmes et statut initial`.

---

### Tâche 4 : lecteur The Events Calendar (JSON)

**Fichiers :**
- Créer : `includes/Lecteurs/Lecteur.php`, `includes/Lecteurs/Resultat.php`, `includes/Lecteurs/LecteurTec.php`, `tests/LecteurTecTest.php`

**Interfaces :**
- Produit : `interface Lecteur { public function lire(string $contenu, array $source): Resultat; }` ; `final class Resultat { /** @var Fiche[] */ public array $fiches = []; /** @var string[] */ public array $erreurs = []; public int $ignores = 0; }`. `$source` est l'entrée du registre (tâche 8) : au minimum `nom`, `url`, `type` (`tec|rss|ical|html_obvia|…`), `niveau`, `type_source`, `organisateur`, et pour TEC `inclure_passes` (bool).
- `LecteurTec::lire()` : pour chaque `events[]`, `Fiche` type `evenement`, `debut`/`fin` = 10 premiers caractères de `start_date`/`end_date`, `heure` = `start_date` 11:16 sauf si `all_day`, `lieu`/`ville` depuis `venue` (objet ou tableau), `organisateur` depuis `organizer[0].organizer` sinon `$source['organisateur']`, `image_url` = `image.sizes.medium_large.url` ou `medium` ou `image.url`, `categorie_rsn` = première catégorie si `$source['nom'] === 'RSN'`, `prix` = `cost`, `format` via `deviner_format(..., is_virtual)`, `langue` = `$source['langue'] ?? 'fr'`, `cle_source` = `"{$source['id']}:{$e['id']}"`. Les événements finis avant aujourd'hui sont ignorés sauf `inclure_passes`.

- [ ] **Étape 1 : tests** (fixtures `dhc.json`, `rqrv.json`, `rsn_ev.json`)

```php
public function test_dhc_18_evenements_a_venir(): void {
    $r = (new LecteurTec())->lire(file_get_contents(FIXTURES . '/dhc.json'), ['id' => 'dhc', 'nom' => 'Digital Health Canada', 'niveau' => 'A', 'type_source' => 'sante_numerique', 'organisateur' => 'Digital Health Canada', 'langue' => 'en']);
    $this->assertCount(18, $r->fiches);
    $f = $r->fiches[0];
    $this->assertSame('Digital Health Canada/RSN Montreal 2026 Breakfast', $f->titre);
    $this->assertSame(['2026-10-27', '2026-10-27', '08:00', 'Plaza Centre-Ville', 'Montreal'], [$f->debut, $f->fin, $f->heure, $f->lieu, $f->ville]);
    $this->assertStringStartsWith('https://digitalhealthcanada.com/wp-content/uploads/', $f->image_url);
    $this->assertSame('dhc:' . json_decode(file_get_contents(FIXTURES . '/dhc.json'))->events[0]->id, $f->cle_source);
}
public function test_rsn_passes_inclus_et_categorie(): void {
    $r = (new LecteurTec())->lire(file_get_contents(FIXTURES . '/rsn_ev.json'), ['id' => 'rsn', 'nom' => 'RSN', 'niveau' => 'A', 'type_source' => 'sante_numerique', 'organisateur' => 'RSN', 'inclure_passes' => true]);
    $this->assertCount(16, $r->fiches);
    $this->assertSame('Événements RSN', $r->fiches[0]->categorie_rsn);
}
public function test_toute_la_journee_sans_heure(): void {
    $json = '{"events":[{"id":7,"title":"Journ\u00e9e","all_day":true,"start_date":"2026-11-04 00:00:00","end_date":"2026-11-04 23:59:59","url":"https://x.test/7","description":"","venue":[],"categories":[],"cost":""}]}';
    $f = (new LecteurTec())->lire($json, ['id' => 'x', 'nom' => 'X', 'niveau' => 'A', 'type_source' => 'sante'])->fiches[0];
    $this->assertSame(['2026-11-04', '2026-11-04', ''], [$f->debut, $f->fin, $f->heure]);
}
public function test_json_invalide(): void {
    $r = (new LecteurTec())->lire('<html>Pas un JSON</html>', ['id' => 'x', 'nom' => 'X', 'niveau' => 'A', 'type_source' => 'sante']);
    $this->assertSame([], $r->fiches); $this->assertCount(1, $r->erreurs);
}
```

- [ ] **Étape 2 : échec.** — [ ] **Étape 3 : implémenter.** — [ ] **Étape 4 : succès.** — [ ] **Étape 5 : commit** `feat: lecteur The Events Calendar`.

---

### Tâche 5 : lecteur RSS (événements, appels, nouvelles) et extraction d'échéance

**Fichiers :**
- Créer : `includes/Lecteurs/LecteurRss.php`, `includes/Lecteurs/Echeance.php`, `tests/LecteurRssTest.php`, `tests/EcheanceTest.php`

**Interfaces :**
- `Echeance::trouver(string $texte, string $aujourdhui): ?string` : cherche « date limite », « échéance », « avant le », « jusqu'au », « d'ici le », « deadline » suivi d'une date française (`20 octobre 2026`, `1er octobre`), année déduite si absente (année courante si le mois est ≥ au mois courant, sinon suivante). Renvoie `Y-m-d` ou `null`.
- `LecteurRss::lire()` analyse le XML avec `SimpleXMLElement` (pas SimplePie, pour rester pur) : RSS 2.0 et Atom. Mode selon `$source['mode']` : `evenements` (pubDate = date de l'événement, fiche `evenement`, ignorer si passé), `appels` (ne garder que les items dont une `<category>` ∈ `$source['categories']` ou dont le titre correspond à `$source['motif_titre']`, fiche `appel`, `debut` = date de publication, `echeance` via `Echeance`, `categorie` = première catégorie retenue), `nouvelles` (fiche `nouvelle`, `debut` = date de publication, au plus `$source['limite'] ?? 8`). Les dates `pubDate` RFC 2822 et les dates Drupal `jeu, 10/22/2026 - 08:25` sont toutes deux acceptées. Un contenu qui n'est pas du XML, ou un flux sans `<item>`/`<entry>`, donne zéro fiche et une erreur « Flux vide ou invalide ».

- [ ] **Étape 1 : tests**

```php
public function test_sommeil_evenements(): void {
    $r = (new LecteurRss())->lire(file_get_contents(FIXTURES . '/sommeil.xml'), ['id' => 'sommeil', 'nom' => 'Réseau sommeil', 'mode' => 'evenements', 'niveau' => 'A', 'type_source' => 'sante', 'organisateur' => 'Réseau québécois de recherche sur le sommeil']);
    $this->assertCount(3, $r->fiches);
    $this->assertSame(['2026-10-28', 'Virtuel'], [$r->fiches[0]->debut, $r->fiches[0]->format]);
}
public function test_frq_appels_par_categorie(): void {
    $r = (new LecteurRss())->lire(file_get_contents(FIXTURES . '/frq.xml'), ['id' => 'frq', 'nom' => 'FRQ', 'mode' => 'appels', 'categories' => ['Appel'], 'niveau' => 'B', 'type_source' => 'sante_numerique', 'organisateur' => 'Fonds de recherche du Québec']);
    $this->assertCount(5, $r->fiches);
    $this->assertSame('appel', $r->fiches[0]->type);
}
public function test_crm_echeance_extraite(): void {
    $r = (new LecteurRss())->lire(file_get_contents(FIXTURES . '/crm.xml'), ['id' => 'crm', 'nom' => 'CRM', 'mode' => 'appels', 'categories' => ['Appel', 'Concours ouvert'], 'niveau' => 'B', 'type_source' => 'generaliste', 'organisateur' => 'CRM']);
    $stages = array_values(array_filter($r->fiches, fn($f) => str_starts_with($f->titre, 'Stages postdoctoraux')));
    $this->assertSame('2026-11-30', $stages[0]->echeance);
}
public function test_inspq_date_drupal(): void {
    $r = (new LecteurRss())->lire(file_get_contents(FIXTURES . '/inspq.xml'), ['id' => 'inspq', 'nom' => 'INSPQ', 'mode' => 'nouvelles', 'niveau' => 'B', 'type_source' => 'sante', 'organisateur' => 'INSPQ']);
    $this->assertMatchesRegularExpression('/^2026-\d\d-\d\d$/', $r->fiches[0]->debut);
}
public function test_html_a_la_place_du_flux(): void {
    $r = (new LecteurRss())->lire('<!doctype html><html><body>Accueil</body></html>', ['id' => 'x', 'nom' => 'X', 'mode' => 'nouvelles', 'niveau' => 'B', 'type_source' => 'sante']);
    $this->assertSame([], $r->fiches); $this->assertSame(['Flux vide ou invalide'], $r->erreurs);
}
// EcheanceTest : "Date limite de soumission : 20 octobre 2026" → 2026-10-20 ; "jusqu'au 3 septembre" avec aujourd'hui 2026-10-25 → 2027-09-03 ; "aucune date" → null.
```

- [ ] **Étape 2 : échec.** — [ ] **Étape 3 : implémenter `Echeance` puis `LecteurRss`.** — [ ] **Étape 4 : succès.** — [ ] **Étape 5 : commit** `feat: lecteur RSS (événements, appels, nouvelles) et extraction d'échéance`.

---

### Tâche 6 : lecteurs HTML (Obvia, Mila, FRQ, IVADO)

**Fichiers :**
- Créer : `includes/Lecteurs/LecteurHtml.php` (classe abstraite : charge le HTML dans `DOMDocument` avec `libxml_use_internal_errors(true)`, méthodes `texte(DOMNode)`, `attribut`, `xpath`), `includes/Lecteurs/LecteurObvia.php`, `LecteurMila.php`, `LecteurFrqEvenements.php`, `LecteurIvado.php`, `tests/LecteursHtmlTest.php`

**Interfaces :**
- Chaque sous-classe implémente `Lecteur::lire()`. Repères, tirés du collecteur prototype : Obvia = `article.node--type-event` (`time[datetime]` début et fin, `h2 a`, `.inner-summary`, première `img`, `span.label-image`) ; Mila = `div[data-nid] > div.node--type-event` (`.field-name-node-title a`, `time[datetime]`, `.field-name-field-text1` = lieu, `.field-name-body`, `img[src^="/sites/default/files/"]`) ; FRQ = `article.events__list__el` (`time[datetime]`, `h3 a`, `p`, `img[data-lazy-src]`) ; IVADO = éléments `.calendarEvents[data-event-id][data-event-start][data-event-end]` regroupés par identifiant (début minimal, fin maximale, nombre de séances) → fiches **incomplètes** (`titre` vide, `url` = `https://ivado.ca/?p={id}`), que `Collecte` complète ensuite en résolvant l'URL (tâche 10) : `LecteurIvado` expose aussi `static completer(Fiche $f, string $html_page, string $url_finale): void` (titre = `<title>` sans « | IVADO », url finale) et accepte dans `$source['flux_evenements']` le XML de `/evenements/feed/` pour récupérer les résumés par URL.
- URLs relatives résolues avec `$source['base']`.
- CRCHUM et Conseil de l'innovation (niveau C dans l'inventaire) ne sont pas dans 0.1.0 : ils s'ajoutent plus tard par la procédure du LISEZMOI (lecteur + fixture + test + entrée dans `Sources`).

- [ ] **Étape 1 : tests** (fixtures `obvia.html`, `mila.html`, `frq_ev.html`, `ivado.html`, `ivado_ev.xml`)

```php
public function test_obvia(): void { /* 10 fiches à venir ; première : 'Présentation de l\'exposition À l’heure de l’IA au Cégep Edouard-Montpetit', debut 2026-10-07, fin 2026-10-27, url commence par https://www.obvia.ca/evenements/, image_url commence par https://www.obvia.ca/sites/ */ }
public function test_mila(): void { /* 3 fiches ; 'Communauté de pratique de Mila : L’IA agentique' le 2026-11-10, heure 15:00, lieu 'Agora de Mila', ville 'Montréal' */ }
public function test_frq_evenements(): void { /* 5 fiches ; première du 2026-09-04 au 2026-12-18, format Virtuel */ }
public function test_ivado_calendrier_puis_completion(): void {
    $r = (new LecteurIvado())->lire(file_get_contents(FIXTURES . '/ivado.html'), ['id' => 'ivado', 'nom' => 'IVADO', 'base' => 'https://ivado.ca', 'niveau' => 'C', 'type_source' => 'numerique', 'organisateur' => 'IVADO', 'flux_evenements' => file_get_contents(FIXTURES . '/ivado_ev.xml')]);
    $this->assertGreaterThanOrEqual(5, count($r->fiches));
    $f = $r->fiches[0]; $this->assertSame('', $f->titre); $this->assertStringStartsWith('https://ivado.ca/?p=', $f->url);
    LecteurIvado::completer($f, '<html><head><title>IA en santé 2026 | IVADO</title></head></html>', 'https://ivado.ca/evenements/ia-en-sante-2026/');
    $this->assertSame(['IA en santé 2026', 'https://ivado.ca/evenements/ia-en-sante-2026/'], [$f->titre, $f->url]);
}
public function test_html_sans_evenement(): void { /* '<html></html>' → 0 fiche, 0 erreur (page vide n'est pas une panne) */ }
```

- [ ] **Étape 2 : échec.** — [ ] **Étape 3 : implémenter les cinq classes.** — [ ] **Étape 4 : succès.** — [ ] **Étape 5 : commit** `feat: lecteurs HTML Obvia, Mila, FRQ et IVADO`.

---

### Tâche 7 : lecteur iCal

**Fichiers :**
- Créer : `includes/Lecteurs/LecteurIcal.php`, `tests/LecteurIcalTest.php`

**Interfaces :**
- `LecteurIcal::lire()` : déplie les lignes continuées (espace en début de ligne), lit chaque `VEVENT` (`SUMMARY`, `DTSTART`, `DTEND`, `LOCATION`, `DESCRIPTION`, `URL`, `UID`), gère `DTSTART;VALUE=DATE` (journée entière, `DTEND` exclusif → fin = veille), `DTSTART;TZID=…` et `DTSTART` en UTC (`Z`) converti en heure de Montréal ; `cle_source` = `"{$source['id']}:{UID}"` ; ignore les événements finis sauf `inclure_passes`. Échappements `\,` `\n` décodés.

- [ ] **Étape 1 : tests** (fixture `dhc.ics`, 4 VEVENT à venir — l'inventaire annonçait 18 pour le flux complet, mais la fixture enregistrée vient de `/events/?ical=1` qui n'en liste que 4 —, et une chaîne iCal écrite dans le test avec `DTSTART:20261104T170000Z` → `2026-11-04`, `heure` `12:00`, et `DTSTART;VALUE=DATE:20270330` / `DTEND;VALUE=DATE:20270401` → `2027-03-30` à `2027-03-31`, `heure` vide).
- [ ] **Étape 2 : échec.** — [ ] **Étape 3 : implémenter.** — [ ] **Étape 4 : succès.** — [ ] **Étape 5 : commit** `feat: lecteur iCal`.

---

### Tâche 8 : registre des sources et Dépôt (fusion, mise à jour, absence)

**Fichiers :**
- Créer : `includes/Sources.php`, `includes/Entrepot.php`, `includes/EntrepotMemoire.php`, `includes/Depot.php`, `tests/SourcesTest.php`, `tests/DepotTest.php`

**Interfaces :**
- `Sources::par_defaut(): array` : liste indexée par `id` des sources de niveau A, C et B exploitables de l'inventaire (au minimum : `rsn`, `dhc`, `rqrv`, `sommeil`, `obvia`, `mila`, `frq_evenements`, `ivado`, `frq_appels`, `crm_appels`, `rsn_appels`, `cares_appels`, `hippocrate_appels`, et les flux de nouvelles `frq`, `ivado_nouvelles`, `mila_nouvelles`, `inspq`, `ssa`, `conseil_innovation`, `genome`, `rqrm`, `rqrd`, `cmdo`, `acfas`, `quebec_ca`), chacune avec `nom, url, type, mode?, categories?, niveau, type_source, organisateur, langue, actif = true, base?`. `Sources::fusionner(array $defaut, array $reglages): array` applique `actif` et `niveau` venant des réglages.
- `interface Entrepot { public function trouver_par_cle_source(string $cle): ?Fiche; public function trouver_par_dedoublonnage(string $cle): ?Fiche; public function trouver_par_url(string $url): ?Fiche; public function creer(Fiche $f): int; public function mettre_a_jour(int $id, array $champs): void; public function lister(string $statut = 'publie', string $type = ''): array; public function ids_publies_de_source(string $id_source): array; public function marquer_absent(int $id, string $depuis): void; public function lever_absent(int $id): void; }` — `Fiche` porte en plus `?int $id` et `array $cles_source` et `?string $absent_depuis` (définis à la tâche 2).
- `Depot::__construct(Entrepot $e)` ; `Depot::integrer(Fiche $f): string` renvoie `cree|fusionne|mis_a_jour|inchange` selon : fiche trouvée par clé source → mise à jour des seuls champs factuels (`debut, fin, heure, url, image_url, prix, lieu, ville`) ; sinon trouvée par clé de dédoublonnage ou URL → fusion (ajout de la clé source ; si niveau entrant > niveau existant, les champs factuels et `image_url` vide sont pris de l'entrant, `niveau` et `source` passent à l'entrant) ; sinon création. `Depot::annuler_si_necessaire(Fiche $f)` : titre contenant « annulé », « reporté », « cancelled », « postponed » → statut `a_approuver` (sur une fiche publiée). `Depot::signaler_absents(string $id_source, array $cles_vues, bool $collecte_reussie, string $aujourdhui): void` : incrémente un compteur `absences` par fiche publiée de cette source non vue si la collecte a réussi ; à 2, pose `absent_depuis` ; remet à zéro et lève le drapeau quand la fiche est revue ; ne fait rien si la collecte a échoué.
- Ordre des niveaux : `Depot::rang(string $niveau): int` avec A=0, C=1, M=2, B=3.

- [ ] **Étape 1 : tests Dépôt** (avec `EntrepotMemoire`)

```php
public function test_creation_puis_mise_a_jour_factuelle_seulement(): void {
    $e = new EntrepotMemoire(); $d = new Depot($e);
    $f = fiche(['titre' => 'FHIR North 2026', 'debut' => '2026-10-27', 'fin' => '2026-10-28', 'cle_source' => 'dhc:1', 'niveau' => 'A', 'statut' => 'publie']);
    $this->assertSame('cree', $d->integrer($f));
    $e->mettre_a_jour(1, ['titre' => 'FHIR North (titre corrigé par l’équipe)', 'statut' => 'ecarte']);
    $f2 = clone $f; $f2->fin = '2026-10-29'; $f2->titre = 'FHIR North 2026 (nouveau titre source)';
    $this->assertSame('mis_a_jour', $d->integrer($f2));
    $x = $e->trouver_par_cle_source('dhc:1');
    $this->assertSame(['FHIR North (titre corrigé par l’équipe)', '2026-10-29', 'ecarte'], [$x->titre, $x->fin, $x->statut]);
}
public function test_fusion_niveau_superieur_fournit_image_et_dates(): void {
    // fiche M (infolettre) 'Hackathon en santé numérique 2025' 2025-11-13 sans image, puis fiche A (rsn) même titre même date avec image → 'fusionne', une seule fiche, image prise, niveau 'A', cles_source contient les deux
}
public function test_absence_apres_deux_collectes_reussies_seulement(): void {
    // fiche publiée dhc:1 ; signaler_absents('dhc', [], false) → pas de drapeau ; signaler_absents('dhc', [], true) → pas encore ; une seconde fois true → absent_depuis = aujourd'hui ; puis signaler_absents('dhc', ['dhc:1'], true) → drapeau levé
}
public function test_annulation_repasse_a_approuver(): void { /* titre 'Atelier ANNULÉ : …' sur une fiche publiée → a_approuver */ }
```
et `SourcesTest` : `par_defaut()` contient `dhc` de niveau `A` et type `tec` ; `fusionner` avec `['dhc' => ['actif' => false]]` désactive `dhc`.

- [ ] **Étape 2 : échec.** — [ ] **Étape 3 : implémenter `Sources`, `Entrepot`, `EntrepotMemoire`, `Depot`.** — [ ] **Étape 4 : succès.** — [ ] **Étape 5 : commit** `feat: registre des sources et dépôt de fiches (fusion, mise à jour, absence)`.

---

### Tâche 9 : type de contenu WordPress et Entrepot WordPress

**Fichiers :**
- Créer : `includes/TypeContenu.php`, `includes/EntrepotWordPress.php`, `includes/Http.php`
- Modifier : `rsn-veille.php` (accrocher `TypeContenu::enregistrer()` sur `init`)

**Interfaces :**
- `TypeContenu::enregistrer()` : CPT `rsn_veille` (non public, `show_ui = false` car l'interface est la nôtre, `supports = ['title', 'editor', 'thumbnail']`, REST désactivé) ; taxonomie `rsn_theme` ; métadonnées préfixées `rsn_veille_` pour chaque champ de `Fiche` non porté par le post (`type, debut, fin, heure, organisateur, categorie_rsn, lieu, ville, region, format, prix, langue, url, image_url, source, niveau, cles_source (tableau), categorie, echeance, absent_depuis, absences, manuel (bool), courriel_proposant (privé)`). Correspondance statut : `publie → publish`, `a_approuver → pending`, `ecarte → draft`. Le résumé = `post_excerpt`, le titre = `post_title`, la date de début sert de `post_date` pour les tris.
- `EntrepotWordPress implements Entrepot` avec `WP_Query` et `get_post_meta` ; `trouver_par_dedoublonnage` s'appuie sur une méta `rsn_veille_cle_dedoublonnage` écrite à la création et à chaque mise à jour de titre ou de date.
- `Http::obtenir(string $url, array $options = []): array{corps: string, type: string, code: int, url_finale: string, erreur: ?string}` : `wp_remote_get` avec `timeout 15`, `redirection 5`, agent « RSN-Veille/0.1 (+https://rsn.quebec) » ; renvoie `erreur` si code ≥ 400 ou réponse réseau en erreur. `Http::est_flux(array $reponse): bool` (type `xml`, `rss`, `atom` ou corps commençant par `<?xml`/`<rss`/`<feed`) ; `Http::est_json`.

- [ ] **Étape 1 : écrire un script de vérification manuelle** `tests/manuel/verifier-cpt.php` exécutable par `wp eval-file` dans wp-env : crée une fiche via `EntrepotWordPress`, la retrouve par clé source, par clé de dédoublonnage et par URL, met à jour `fin`, marque absente, affiche « OK » à chaque étape.
- [ ] **Étape 2 : implémenter les trois classes.**
- [ ] **Étape 3 : vérifier** — `npx wp-env start && npx wp-env run cli wp eval-file wp-content/plugins/rsn-veille/tests/manuel/verifier-cpt.php` → cinq lignes « OK ». `composer test` toujours vert.
- [ ] **Étape 4 : commit** `feat: type de contenu rsn_veille, entrepôt WordPress et client HTTP`.

---

### Tâche 10 : orchestration de la collecte (WP-Cron, journal, purge)

**Fichiers :**
- Créer : `includes/Collecte.php`, `includes/Journal.php`, `tests/CollecteTest.php`
- Modifier : `rsn-veille.php` (activation : planifier `rsn_veille_nuit` quotidien à l'heure des réglages, défaut 03:00 Montréal ; désactivation : tout déplanifier)

**Interfaces :**
- `Collecte::lecteur_pour(array $source): Lecteur` (fabrique selon `type`) ; `Collecte::pertinence(): Pertinence` construite avec `Pertinence::config_par_defaut()` écrasée par `mots_sante`, `mots_numerique`, `themes` de l'option `rsn_veille_reglages` (tâche 12) ; `Collecte::sources(): array` = `Sources::fusionner(Sources::par_defaut(), reglages['sources'])`.
- `Collecte::mettre_en_file(): void` : écrit l'option `rsn_veille_file` = liste des `id` de sources actives, et planifie `rsn_veille_source` dans 1 minute.
- `Collecte::traiter_une_source(): void` : dépile un `id`, `Http::obtenir`, vérifie le type de contenu (`est_flux`/`est_json` selon le type), lit, pour chaque fiche : `Pertinence::statut` si fiche nouvelle, `themes`, puis `Depot::integrer`, `annuler_si_necessaire` ; pour IVADO, résout chaque `?p=` par `Http::obtenir` (au plus 25 par source) et `LecteurIvado::completer` ; appelle `signaler_absents` ; écrit dans `Journal` ; replanifie `rsn_veille_source` dans 2 minutes s'il reste des sources, sinon `Collecte::terminer()` (purge LiteSpeed et Elementor de la page Veille, option `rsn_veille_derniere_collecte`).
- `Journal::consigner(string $id_source, array $ligne)` avec `date, lus, crees, fusionnes, mis_a_jour, ignores, erreur` ; `Journal::tout(): array` ; au plus 60 entrées par source.
- La partie testable en pur : `Collecte::integrer_resultat(Resultat $r, array $source, Depot $d, Pertinence $p): array` (compteurs) — c'est elle que `CollecteTest` couvre avec `EntrepotMemoire` et la fixture `dhc.json`.

- [ ] **Étape 1 : test** — `integrer_resultat` sur `dhc.json` → `['crees' => 18, 'fusionnes' => 0, 'mis_a_jour' => 0]` puis une seconde fois → `['crees' => 0, 'mis_a_jour' => 18]` ; une fiche 'Hackathon EcoHull' de source `numerique` reçoit le statut `a_approuver`.
- [ ] **Étape 2 : échec.** — [ ] **Étape 3 : implémenter.** — [ ] **Étape 4 : succès, puis essai dans wp-env** : `wp eval 'RSN\Veille\Collecte::mettre_en_file();'` puis `wp cron event run rsn_veille_source` plusieurs fois → le journal montre les sources une à une ; `wp post list --post_type=rsn_veille --post_status=pending,publish --format=count` > 60.
- [ ] **Étape 5 : commit** `feat: collecte nocturne par tâches planifiées, journal et purge des caches`.

---

### Tâche 11 : administration — menu, file d'approbation, À vérifier, journal

**Fichiers :**
- Créer : `includes/Admin/Menu.php`, `includes/Admin/PageFile.php`, `includes/Admin/PageJournal.php`, `assets/admin.css`, `assets/admin.js`

**Interfaces :**
- `Menu::enregistrer()` : capacité `rsn_veille_gerer` ajoutée aux rôles de l'option `rsn_veille_roles` (défaut `['administrator', 'editor']`) ; menu « Veille » (icône `dashicons-rss`) avec sous-pages File d'approbation, Radar, Infolettre, Journal, Réglages.
- `PageFile` : tableau (titre, type, dates, organisateur, source et niveau, extrait, lien) filtrable par statut (`a_approuver` par défaut, `publie`, `ecarte`, `a_verifier` = `absent_depuis` non vide) et par type ; boutons **Publier**, **Écarter**, **Retirer** ; actions AJAX `rsn_veille_changer_statut` (`id`, `statut`, nonce `rsn_veille_file`, vérification `current_user_can('rsn_veille_gerer')`) qui posent aussi `manuel = true` et purgent la page Veille ; lien « Modifier » vers l'éditeur WordPress de la fiche (le CPT a `show_ui = false` mais `show_in_menu = false` + capacité suffit pour `post.php?post=ID&action=edit` : régler `show_ui = true, show_in_menu = false`).
- `PageJournal` : tableau par source de la dernière entrée et déroulé des 60 dernières.

- [ ] **Étape 1 : implémenter** les trois classes, le CSS (sobre, polices de wp-admin) et le JS (fetch AJAX, retrait de la ligne, compteur).
- [ ] **Étape 2 : vérifier dans wp-env** avec un compte Éditeur : le menu apparaît ; un Abonné ne le voit pas ; Publier passe la fiche en `publish` et la retire de la liste ; Retirer passe en `draft` ; l'onglet « À vérifier » liste une fiche marquée absente par `wp eval`.
- [ ] **Étape 3 : commit** `feat: menu Veille, file d'approbation et journal dans wp-admin`.

---

### Tâche 12 : administration — réglages et import

**Fichiers :**
- Créer : `includes/Admin/PageReglages.php`

**Interfaces :**
- Option unique `rsn_veille_reglages` : `sources` (par id : `actif`, `niveau`), `mots_sante`, `mots_numerique`, `themes` (nom + mots), `roles`, `courriel` (`actif`, `destinataires`, `jour` 0-6), `heure_collecte` (`03:00`), `page_veille_id`. API Settings de WordPress, `sanitize_callback` qui nettoie chaque champ.
- Boutons : « Lancer la collecte maintenant » (appelle `Collecte::mettre_en_file()` et `wp_schedule_single_event(time(), 'rsn_veille_source')`), « Importer un fichier JSON » (format de `docs/maquette/evenements-infolettres-2025-2026.json` ; chaque entrée devient une `Fiche` niveau `M`, source `infolettre`, statut `publie`, passée par `Depot::integrer` pour sauter les doublons ; affiche créés/fusionnés).
- Changer `heure_collecte` replanifie `rsn_veille_nuit`.

- [ ] **Étape 1 : implémenter.**
- [ ] **Étape 2 : vérifier dans wp-env** : désactiver `quebec_ca`, lancer la collecte, le journal ne la montre pas ; importer le JSON des infolettres → « 24 lus, N créés, M fusionnés » avec M ≥ 1 (les événements RSN déjà collectés).
- [ ] **Étape 3 : commit** `feat: page de réglages, import des événements des infolettres et lancement manuel`.

---

### Tâche 13 : rendu public — données, raccourci, REST, iCal

**Fichiers :**
- Créer : `includes/Public/Donnees.php`, `includes/Public/Raccourci.php`, `includes/Public/Rest.php`, `assets/veille.css`, `assets/veille.js`, `tests/DonneesTest.php`
- Source : `docs/maquette/gabarit-v6.1.html` (CSS entre `<style>`, JS dans le dernier `<script>`, structure HTML de `<main>`)

**Interfaces :**
- `Donnees::construire(string $aujourdhui, Entrepot $e, array $evenements_rsn, bool $equipe = false): array` (fiches via `$e->lister('publie')`) renvoie exactement la structure que consomme le JS de la maquette : `['genere_le' => …, 'sources' => […], 'items' => [ …fiches publiées `vers_tableau()`… ]]`, les événements TEC du RSN (lus via `tribe_get_events` ou la REST locale, passés compris depuis 2025-09-01) ajoutés comme fiches `source = 'RSN'`, `categorie_rsn` renseignée, dédoublonnés contre les fiches par `cle_dedoublonnage()` (la fiche collectée gagne si elle a une image, sinon l'événement RSN). Seules les fiches `publie` sortent ; jamais `courriel_proposant`, `niveau` ni `source` technique (remplacés par `organisateur`), sauf si `$equipe = true`.
- `Raccourci` : `[rsn_veille]` rend le `<main>` de la maquette (bandeau exclu : le gabarit UiCore du site fournit le titre, comme pour `[rsn_evenements]`), incorpore le JSON dans `<script type="application/json" id="rsn-veille-donnees">`, met en file `veille.css` et `veille.js` (versionnés par `RSN_VEILLE_VERSION`), option `section="agenda|appels|tout"`. Le filtre « Qui organise » (RSN / Partenaires / Écosystème, d'après `categorie_rsn` : `Événements RSN`, `Événements partenaires`, le reste) est ajouté à la colonne de filtres, au-dessus de Format. Le mode équipe de la maquette est retiré du JS public (les actions se font dans wp-admin) ; le bouton « Proposer un événement » ouvre le formulaire de la tâche 14. Le JSON est mis en cache par transient 1 h, invalidé par `Collecte::terminer()` et par tout changement de statut.
- `Rest::enregistrer()` : `GET /wp-json/rsn/v1/veille` (même JSON, `litespeed_control_set_nocache`, en-tête `Cache-Control: max-age=3600`) ; `GET /veille.ics` via règle de réécriture → `text/calendar` (événements publiés, `UID = cle_source@rsn.quebec`, `DTSTART;VALUE=DATE` quand pas d'heure).

- [ ] **Étape 1 : test** `DonneesTest` : avec `EntrepotMemoire` contenant une fiche publiée, une `a_approuver` et un événement RSN en double de la publiée → `items` contient 1 élément, sans clé `courriel_proposant` ni `niveau`, avec `organisateur`.
- [ ] **Étape 2 : échec.** — [ ] **Étape 3 : implémenter `Donnees`, puis `Raccourci`, `Rest`, et adapter CSS/JS** (préfixer les sélecteurs par `.rsn-veille` pour cohabiter avec Elementor, comme `.rsn-ev`).
- [ ] **Étape 4 : succès, puis vérification dans wp-env** : page avec `[rsn_veille]` → agenda, calendrier, appels, filtres fonctionnent, aucune erreur console ; largeur mobile sans défilement horizontal ; `/wp-json/rsn/v1/veille` et `/veille.ics` répondent (vérifier l'iCal dans un client de calendrier).
- [ ] **Étape 5 : commit** `feat: page publique (raccourci, JSON, REST, iCal) dans le style du site`.

---

### Tâche 14 : formulaire « Proposer un événement »

**Fichiers :**
- Créer : `includes/Public/Formulaire.php`, `tests/FormulaireTest.php`
- Modifier : `assets/veille.js` (ouverture, envoi en `fetch` vers `admin-ajax.php`, message de confirmation)

**Interfaces :**
- `Formulaire::valider(array $champs, string $aujourdhui): array{fiche: ?Fiche, erreurs: string[]}` (pur) : titre obligatoire ≤ 200 caractères (tronqué), date de début obligatoire `Y-m-d` ≥ aujourd'hui − 1 an, fin ≥ début sinon = début, organisateur obligatoire ≤ 120, format ∈ {Présentiel, Virtuel, Hybride}, lieu ≤ 160, URL `http(s)` seulement (sinon vide), description ≤ 1 000, courriel validé ou vide ; champ piège `site_web` non vide → `fiche = null`, `erreurs = []` (rejet silencieux). Fiche : `type evenement`, `statut a_approuver`, `source proposition`, `niveau M`, `cle_source = 'proposition:' . uniqid()`.
- `Formulaire::traiter()` (action AJAX `rsn_veille_proposer`, nonce, sans connexion requise) : limite de débit 5 envois par heure par adresse (transient `rsn_veille_debit_` + hachage de l'IP), image facultative via `media_handle_upload` (types `jpg|png|webp`, ≤ 3 Mo) attachée à la fiche, courriel dans `rsn_veille_courriel_proposant`, réponse JSON `{ok: true}`.
- Rendu : le bloc `#dialogue` de la maquette, champs identiques plus `image`.

- [ ] **Étape 1 : tests** `valider` : jeu complet → fiche `a_approuver` ; `site_web = 'x'` → `null` sans erreur ; titre de 5 000 caractères → 200 ; `url = 'javascript:alert(1)'` → `''` ; date manquante → erreur « Date de début obligatoire ».
- [ ] **Étape 2 : échec.** — [ ] **Étape 3 : implémenter.** — [ ] **Étape 4 : succès, puis wp-env** : envoi depuis la page → fiche `pending` visible dans la file avec l'image ; sixième envoi dans l'heure → refus.
- [ ] **Étape 5 : commit** `feat: formulaire public de proposition d'événement`.

---

### Tâche 15 : radar des nouvelles

**Fichiers :**
- Créer : `includes/Admin/PageRadar.php`, `includes/Nouvelles.php`, `tests/NouvellesTest.php`

**Interfaces :**
- `Nouvelles::lire(array $sources_nouvelles, callable $obtenir, Pertinence $p, string $aujourdhui): array` (pur, `$obtenir(url): string`) : lit chaque flux en mode `nouvelles` avec `LecteurRss`, garde les fiches de statut `publie` ou `a_approuver`, date ≥ aujourd'hui − 60 jours, triées par date décroissante, avec `statut_radar` ∈ {`reperee`, `a_trier`} selon `publie`/`a_approuver`.
- `PageRadar` : met en cache le résultat 1 h (transient `rsn_veille_radar`) ; actions AJAX `rsn_veille_garder` (enregistre la nouvelle dans l'option `rsn_veille_nouvelles_gardees` : `url, titre, date, source, resume, gardee_le`) et `rsn_veille_ignorer` (ajoute l'URL à `rsn_veille_nouvelles_ignorees`, 500 dernières) ; les gardées apparaissent en tête avec « Retirer » ; bouton « Rafraîchir » vide le transient.

- [ ] **Étape 1 : test** `Nouvelles::lire` avec un `$obtenir` qui renvoie `frq.xml` et `inspq.xml` → aucune fiche de catégorie « Appel », toutes datées, triées décroissantes, et `statut_radar` défini.
- [ ] **Étape 2 : échec.** — [ ] **Étape 3 : implémenter.** — [ ] **Étape 4 : succès, puis wp-env** : garder deux nouvelles, les retrouver en tête après rechargement.
- [ ] **Étape 5 : commit** `feat: radar des nouvelles pour l'équipe`.

---

### Tâche 16 : page Infolettre (trois blocs copiables)

**Fichiers :**
- Créer : `includes/Admin/PageInfolettre.php`, `includes/Infolettre.php`, `tests/InfolettreTest.php`
- Modifier : `assets/admin.js` (copie enrichie via `ClipboardItem` `text/html` + `text/plain`, repli `execCommand`)

**Interfaces :**
- `Infolettre::bloc_agenda(array $fiches, int $nombre = 8): string`, `bloc_appels(array $appels_externes, array $programmes_rsn): string`, `bloc_nouvelles(array $gardees): string` (purs) : tableaux HTML aux styles en ligne, calqués sur les sections Agenda, Appels à projet et Dernières nouvelles de l'infolettre de septembre 2026 (voir le gabarit de la maquette pour l'agenda : colonne date 64 px, titre gras 16 px, organisateur · lieu, thèmes en capitales `#3D6A99`, résumé, « Plus d'information → », format – prix – langue).
- Programmes du RSN ouverts : articles de la catégorie 149 avec `rsn_appel_etat() === 'ouvert'` (fonction de l'extrait 45, appelée si elle existe, sinon liste vide) rendus comme appels (titre, « Date limite », extrait, lien).
- `PageInfolettre` : trois blocs avec aperçu, « Copier » et « Copier le code HTML », réglage du nombre d'événements (6, 8, 10, 12) et de l'horizon (date), bouton « Vider les nouvelles gardées ».

- [ ] **Étape 1 : test** : `bloc_agenda` avec deux fiches → contient les deux titres, `Plus d'information →`, aucune balise `<script>`, caractères HTML du titre échappés ; `bloc_appels` avec un programme RSN et un appel externe → deux lignes, « Date limite » présente quand `echeance` est renseignée.
- [ ] **Étape 2 : échec.** — [ ] **Étape 3 : implémenter.** — [ ] **Étape 4 : succès, puis wp-env** : copier le bloc Agenda et le coller dans un éditeur enrichi (LibreOffice ou un courriel) → mise en forme conservée.
- [ ] **Étape 5 : commit** `feat: page Infolettre avec blocs Agenda, Appels et Nouvelles copiables`.

---

### Tâche 17 : courriel hebdomadaire

**Fichiers :**
- Créer : `includes/Courriel.php`, `tests/CourrielTest.php`
- Modifier : `rsn-veille.php` (planification `rsn_veille_hebdo` selon les réglages)

**Interfaces :**
- `Courriel::composer(array $nouvelles_fiches, array $en_attente, array $nouvelles_radar, string $url_file): array{sujet: string, html: string}` (pur) : sujet « Veille de l'écosystème : N nouveautés cette semaine », sections « Publiées automatiquement », « À approuver » (lien vers la file), « Nouvelles repérées », pied « Envoyé par l'extension RSN : veille ; désactivez-le dans Veille › Réglages ».
- `Courriel::envoyer()` : si `reglages.courriel.actif`, compose avec les fiches créées depuis 7 jours, les `pending`, le radar, et `wp_mail` aux destinataires (en-tête `Content-Type: text/html; charset=UTF-8`).

- [ ] **Étape 1 : test** `composer` avec 2 publiées, 1 en attente, 0 nouvelle → sujet « … 3 nouveautés … », html contient le lien de la file et pas de section « Nouvelles repérées ».
- [ ] **Étape 2 : échec.** — [ ] **Étape 3 : implémenter.** — [ ] **Étape 4 : succès, puis wp-env** avec un intercepteur de courriel (`wp-env` + extension MailHog ou `wp eval` qui appelle `Courriel::envoyer()` et affiche le résultat de `wp_mail`).
- [ ] **Étape 5 : commit** `feat: courriel hebdomadaire optionnel`.

---

### Tâche 18 : documentation, vérification de bout en bout et livraison

**Fichiers :**
- Modifier : `LISEZMOI.md`, `rsn-veille.php` (version `0.1.0` confirmée)
- Créer : `docs/installation-et-bascule.md`

**Interfaces :** aucune.

- [ ] **Étape 1 : `docs/installation-et-bascule.md`** : prérequis (PHP de WHC, cron serveur vers `wp-cron.php` toutes les 5 minutes, `DISABLE_WP_CRON` si cron serveur), installation par zip téléversé **ou** depuis GitHub (WP Pusher ou Git Updater branchés sur `TessRSN/rsn-veille`, branche `main`, avec les avantages et limites de chaque voie et une recommandation), réglages à faire le premier jour (rôles, courriel, lancer la collecte, importer les infolettres), création de la page Veille en brouillon avec `[rsn_veille]`, et la bascule en trois temps du devis § 7 (page en brouillon, puis remplacement de `/calendrier-evenements/` et désactivation de l'extrait 61, puis page anglaise WPML). Section dépannage : source en erreur, 429, cache LiteSpeed.
- [ ] **Étape 2 : LISEZMOI.md** complété : structure des dossiers, comment ajouter une source (entrée dans `Sources::par_defaut()` + lecteur + fixture + test), comment lancer les tests et wp-env.
- [ ] **Étape 3 : parcours complet dans wp-env** : activation → réglages → collecte → file (publier, écarter) → page publique (liste, calendrier, appels, formulaire) → radar (garder) → infolettre (trois copies) → courriel. Noter les écarts et les corriger.
- [ ] **Étape 4 : `composer test` vert, `php -l` sur tous les fichiers, zip de l'extension** : `git archive --format=zip --prefix=rsn-veille/ -o rsn-veille-0.1.0.zip HEAD -- . ':!tests' ':!docs' ':!.wp-env.json'`.
- [ ] **Étape 5 : commit** `docs: guide d'installation et de bascule, LISEZMOI complété` et ouverture d'une PR vers `main` pour revue (titre « Extension rsn-veille 0.1.0 », description en français, sans mention de Claude).

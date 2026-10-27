# rsn-veille 0.2.0 — plan de mise en œuvre

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal :** livrer la version 0.2.0 de l'extension `rsn-veille` : la page publique « Événements et appels » de la maquette v8 et six corrections d'exploitation, en une PR vers `main`.

**Architecture :** l'extension 0.1.0 reste en place (collecte, entrepôt, file, REST, iCal). On ajoute deux propriétés de lecture à `Fiche`, on corrige `Donnees` (qui organise, image), on restructure le gabarit PHP du raccourci et ses textes, on reprend le CSS de la maquette v8 et on réécrit la partie rendu de `assets/veille.js` (liste unifiée, pastilles, bandes). Côté admin : tri et repère « Nouveau » dans la file, liste des pages privées, métabox d'image, lecteur IVADO.

**Tech Stack :** PHP 8.0 (WordPress 6+), JavaScript sans cadriciel, PHPUnit 9 contre `tests/bouchons-wordpress.php`, `php -l`, `node --check`.

**Spec :** `docs/superpowers/specs/2026-10-27-page-evenements-0.2-design.md` (et, pour tout le reste, `docs/superpowers/specs/2026-10-25-veille-ecosysteme-design.md`). Maquette : `docs/maquette/gabarit-v8.html`.

## Global Constraints

- PHP 8.0 minimum, WordPress 6.0 minimum, aucune bibliothèque à l'exécution, aucune requête réseau au chargement de la page.
- Tout en français : code, commentaires, textes, commits. Commits au seul nom de l'autrice, sans ligne Co-Authored-By.
- Préfixe `rsn_veille_` pour options, métas, transients ; namespace `RSN\Veille` ; sélecteurs CSS préfixés `.rsn-veille` (vérifié par `tests/SqueletteTest.php`).
- Textes visibles : `esc_html__` / `esc_attr__` en PHP, `Raccourci::textes()` pour le JS, domaine `rsn-veille`.
- Le JSON public garde toutes ses clés de 0.1.0 (`CLES_PUBLIQUES` de `Donnees`) ; on ajoute, on ne retire pas.
- `assets/veille.js` : toute adresse passe par `lien()` (http(s) seulement), tout texte par `esc()`, y compris dans le code repris de la maquette.
- Règles mobiles doublées : `@media (max-width:820px)` et `@container rsn-veille (max-width:760px)`.
- Avant chaque commit : `vendor/bin/phpunit` vert, `php -l` sur chaque PHP touché, `node --check` sur chaque JS touché.
- Numéro de version : 0.2.0, posé à la dernière tâche (en-tête de `rsn-veille.php`, `RSN_VEILLE_VERSION`, CHANGELOG).

## Review Focus

- Un appel en cours sans date limite ni fin distincte : absent de la bande « À la une » et de la bande « Dates limites », présent dans la liste au mois de sa publication avec la tuile grise « publié » (tâches 9 et 10 ; vérification manuelle dans PARCOURS, tâche 11).
- `[rsn_veille section="agenda"]` ou `une="0"` : les nœuds `#unes`, `#limites`, `#f-type` peuvent être absents ; chaque fonction de rendu JS teste la présence du nœud avant d'écrire (tâches 9 et 10).
- Image mise en avant retirée après coup : `image_manuelle` vide, la sortie retombe sur `image_url` (tâche 1).
- Post dont `post_date_gmt` vaut `0000-00-00 00:00:00` : `cree_a` null, pas de repère « Nouveau », fiche en fin de tri d'arrivée (tâches 1 et 4).
- Deux raccourcis sur une même page : chaque bloc `.rsn-veille` se rend à partir de son propre nœud de données ; aucune recherche par `document.getElementById` dans le code ajouté (tâches 9 et 10).

---

### Task 1 : `Fiche::$cree_a`, `Fiche::$image_manuelle`, lecture dans `EntrepotWordPress`

**Files:**
- Modify: `includes/Fiche.php` (propriétés, `vers_tableau`)
- Modify: `includes/EntrepotWordPress.php` (`vers_fiche`, vers la ligne 335)
- Modify: `tests/bouchons-wordpress.php` si `has_post_thumbnail` / `get_the_post_thumbnail_url` / `get_post_thumbnail_id` y manquent (imitation minimale : méta `_thumbnail_id` → adresse de la pièce jointe)
- Test: `tests/FicheTest.php`, `tests/EntrepotContratTest.php`

**Interfaces:**
- Produces: `public ?int $cree_a = null;` (secondes UTC de `post_date_gmt`, null si la date est vide ou `0000-00-00 00:00:00`) ; `public string $image_manuelle = '';` (adresse de l'image mise en avant, taille `large`, `''` sinon). Ni l'une ni l'autre n'est une méta : `EntrepotWordPress` ne les écrit jamais ; `vers_tableau()` les inclut.

- [ ] **Step 1 : tests qui échouent**

```php
// FicheTest
public function test_vers_tableau_expose_cree_a_et_image_manuelle(): void {
    $f = new Fiche(); $f->titre = 'X'; $f->debut = '2026-11-01'; $f->cree_a = 1761000000; $f->image_manuelle = 'https://rsn.quebec/wp-content/uploads/a.jpg';
    $t = $f->vers_tableau();
    $this->assertSame(1761000000, $t['cree_a']);
    $this->assertSame('https://rsn.quebec/wp-content/uploads/a.jpg', $t['image_manuelle']);
}
// EntrepotContratTest (entrepôt WordPress seulement)
public function test_lecture_remplit_cree_a_depuis_post_date_gmt(): void   // post créé avec post_date_gmt '2026-10-27 14:00:00' → cree_a === strtotime('2026-10-27 14:00:00 UTC')
public function test_cree_a_null_sans_date_gmt(): void                       // post_date_gmt '0000-00-00 00:00:00' → null
public function test_image_manuelle_depuis_l_image_mise_en_avant(): void     // _thumbnail_id posé → image_manuelle = adresse de la pièce ; retiré → ''
```

- [ ] **Step 2 : vérifier l'échec** — `vendor/bin/phpunit --filter 'cree_a|image_manuelle'`.
- [ ] **Step 3 : implémenter** dans `Fiche` et `vers_fiche()` ; s'assurer que la boucle d'écriture des métas (`ecrire_meta`, vers la ligne 87) ignore les deux propriétés (elles ne figurent pas dans le schéma de `TypeContenu`).
- [ ] **Step 4 : `vendor/bin/phpunit`** vert, `php -l` sur les fichiers touchés.
- [ ] **Step 5 : commit** `feat(fiche): cree_a et image_manuelle lus depuis le post`.

### Task 2 : `Donnees` — qui organise, image publique (corrections 1 et 2, spec 5.5 b)

**Files:**
- Modify: `includes/Public/Donnees.php` (`construire`, `organise_par`)
- Test: `tests/DonneesTest.php`

**Interfaces:**
- Consumes: `Fiche::$image_manuelle` (tâche 1).
- Produces: `Donnees::organise_par(string $categorie): string` reconnaît en plus les slugs exacts `rsn`, `partenaires`, `ecosysteme`, `digital-ecosystem` ; dans `construire`, `organise_par` vaut `rsn` si `cle_source` commence par `rsn:` ou `rsn_appels:`, ou si `organisateur_canonique()` donne « RSN » ; l'item public `image_url` vaut `image_manuelle` si non vide, sinon `image_url`.

- [ ] **Step 1 : tests qui échouent**

```php
public function test_organise_par_reconnait_les_slugs_nus(): void {
    $this->assertSame('rsn', Donnees::organise_par('rsn'));
    $this->assertSame('partenaires', Donnees::organise_par('partenaires'));
    $this->assertSame('ecosysteme', Donnees::organise_par('ecosysteme'));
    $this->assertSame('ecosysteme', Donnees::organise_par('digital-ecosystem'));
    $this->assertSame('rsn', Donnees::organise_par('RSN Events'));
}
public function test_appel_du_flux_rsn_est_organise_par_le_rsn(): void      // fiche appel, cle_source 'rsn_appels:123', organisateur 'RSN' → organise_par 'rsn'
public function test_organisateur_rsn_canonique_donne_rsn(): void           // organisateur 'Réseau santé numérique', cle_source 'proposition:…' → 'rsn'
public function test_image_manuelle_prime_sur_image_url(): void             // image_manuelle posée → item image_url = manuelle ; vide → image de la source
```

- [ ] **Step 2 : vérifier l'échec.** — [ ] **Step 3 : implémenter.** — [ ] **Step 4 : phpunit vert, `php -l`.**
- [ ] **Step 5 : commit** `fix(donnees): appels du RSN organisés par le RSN, slugs TEC nus, image mise en avant`.

### Task 3 : pages privées dans « Page Veille » (correction 3)

**Files:**
- Modify: `includes/Admin/PageReglages.php` (vers la ligne 273 : `get_pages(['post_status' => 'publish,draft,private', …])`)
- Test: `tests/PageReglagesTest.php`

- [ ] **Step 1 : test qui échoue** — `test_la_liste_des_pages_inclut_les_privees()` : trois pages (publish, draft, private) dans le bouchon → les trois titres dans le `<select id="rsn-veille-page">` du rendu.
- [ ] **Step 2 : vérifier l'échec.** — [ ] **Step 3 : implémenter.** — [ ] **Step 4 : phpunit vert, `php -l`.**
- [ ] **Step 5 : commit** `fix(reglages): les pages privées sont proposées comme page Veille`.

### Task 4 : file d'approbation — tri par arrivée et repère « Nouveau » (correction 4)

**Files:**
- Modify: `includes/Admin/PageFile.php` (`filtrer()`, vers la ligne 170 ; rendu d'une fiche)
- Modify: `assets/admin.css` (`.rsn-veille-nouveau` : pastille, fond `#FFCC4E`, texte `#003469`, 11 px, majuscules)
- Test: `tests/PageFileTest.php`

**Interfaces:**
- Consumes: `Fiche::$cree_le` (jour d'arrivée, `AAAA-MM-JJ`, déjà présent en 0.1.0 ; `post_date` ne convient pas, elle suit la date de l'événement).
- Produces: `PageFile::filtrer(array $fiches, string $statut, string $type, string $source): array` — pour `a_approuver` et `a_verifier` : tri par `cree_le` décroissant (null en dernier), puis `id` décroissant ; sinon l'ordre de 0.1.0. `PageFile::est_nouvelle(Fiche $f, string $aujourdhui): bool` — `cree_le !== null && $f->cree_le >= <aujourd'hui − 1 jour>`. Le rendu ajoute `<span class="rsn-veille-nouveau">Nouveau</span>` après le titre dans ces deux onglets quand `est_nouvelle` est vrai (`$aujourdhui` = `rsn_veille_aujourdhui()`).

- [ ] **Step 1 : tests qui échouent**

```php
public function test_a_approuver_trie_par_arrivee_decroissante(): void   // cree_le 2026-10-20 (id 1), 2026-10-27 (id 2), 2026-10-27 (id 3), null (id 4) → ordre 3, 2, 1, 4
public function test_publiees_gardent_le_tri_par_date_d_evenement(): void
public function test_repere_nouveau_aujourd_hui_ou_hier(): void       // aujourd'hui 2026-10-27 ; cree_le 2026-10-27 et 2026-10-26 → nouvelle ; 2026-10-25 → non ; null → non
public function test_rendu_a_approuver_affiche_nouveau(): void          // la chaîne 'rsn-veille-nouveau' apparaît pour la fiche récente, pas pour l'ancienne
```

- [ ] **Step 2 : vérifier l'échec.** — [ ] **Step 3 : implémenter.** — [ ] **Step 4 : phpunit vert, `php -l`.**
- [ ] **Step 5 : commit** `feat(file): tri par arrivée et repère Nouveau dans À approuver et À vérifier`.

### Task 5 : métabox « Image de la fiche » (correction 5)

**Files:**
- Create: `includes/Admin/MetaboxImage.php`
- Modify: `rsn-veille.php` (appel de `MetaboxImage::accrocher()` à côté des autres `accrocher()` de l'admin)
- Test: `tests/MetaboxImageTest.php`

**Interfaces:**
- Produces: `final class MetaboxImage` (namespace `RSN\Veille\Admin`) : `public static function accrocher(): void` (`add_meta_boxes` → `enregistrer`) ; `public static function enregistrer(): void` (`add_meta_box('rsn_veille_image', __('Image de la fiche', 'rsn-veille'), [self::class, 'rendre'], TypeContenu::TYPE, 'side', 'default')`) ; `public static function rendre(\WP_Post $post): void` (echo `self::html(meta image_url)`) ; `public static function html(string $image_url): string` — pure : `<img>` de l'adresse (via `esc_url`, http(s) seulement) ou le texte « Aucune image de la source », puis le paragraphe « Pour la remplacer, choisissez une image mise en avant : elle prime et la collecte ne l'écrase jamais. »

- [ ] **Step 1 : tests qui échouent** — `test_html_avec_image_de_la_source()`, `test_html_sans_image()`, `test_html_refuse_une_adresse_javascript()` (aucun `<img` dans le rendu), `test_enregistre_la_metabox_sur_le_type()` (le bouchon `add_meta_box` reçoit l'id `rsn_veille_image` et le type `rsn_veille`).
- [ ] **Step 2 : vérifier l'échec.** — [ ] **Step 3 : implémenter.** — [ ] **Step 4 : phpunit vert, `php -l`.**
- [ ] **Step 5 : commit** `feat(admin): métabox Image de la fiche, image mise en avant prioritaire`.

### Task 6 : lecteur IVADO — pages de redirection (correction 6)

**Files:**
- Modify: `includes/Lecteurs/LecteurIvado.php` (`completer()`, vers la ligne 73)
- Create: `tests/fixtures/ivado_redirection.html` — capture : `curl -sL -A 'Mozilla/5.0' https://ivado.ca/evenements/ia-en-sante-2026/ > tests/fixtures/ivado_redirection.html` (si la page en ligne n'est plus une redirection, écrire une fixture minimale avec `<title>IA en santé 2026 | IVADO</title>`, un `<h1>Redirection/Redirecting...</h1>` et un `<script>window.location = "https://exemple.test/ia-sante-2026"</script>`, et le dire dans le rapport)
- Test: `tests/LecteursHtmlTest.php`

**Interfaces:**
- Produces: `public static function redirection(string $html): ?array{cible: string}` — null si la page n'est pas une redirection ; sinon `['cible' => <adresse http(s) ou ''>]`. Redirection si `<title>` ou `<h1>` contient « redirect » (insensible à la casse) ou si la page a `meta http-equiv="refresh"` ou `window.location` / `location.href =` dans un script. Cible : l'adresse du `refresh` (`url=`) ou de l'affectation `location`, si http(s). Dans `completer()` : si redirection, ne pas toucher au titre ni au résumé ; `url` = cible si non vide, sinon `$url_finale`.

- [ ] **Step 1 : tests qui échouent** — `test_ivado_redirection_detectee_et_cible_lue()` (fixture synthétique `meta refresh` → cible), `test_ivado_redirection_garde_titre_et_resume_de_la_liste()` (fiche titre « IA en santé 2026 », résumé '' ; après `completer` avec la fixture réelle : titre inchangé, résumé '', url = cible ou url IVADO), `test_ivado_page_normale_toujours_completee()` (fixture `ivado.html` existante : comportement 0.1.0 inchangé).
- [ ] **Step 2 : vérifier l'échec.** — [ ] **Step 3 : implémenter.** — [ ] **Step 4 : phpunit vert, `php -l`.**
- [ ] **Step 5 : commit** `fix(ivado): pages de redirection reconnues, adresse cible conservée`.

### Task 7 : raccourci — gabarit v8, textes, attribut `une`

**Files:**
- Modify: `includes/Public/Raccourci.php` (`rendre` : attribut `une`, par défaut `'1'` ; `textes()` : nouvelles clés)
- Modify: `includes/Public/gabarit-veille.php`
- Test: `tests/RaccourciTest.php`

**Interfaces:**
- Produces, dans le gabarit, dans cet ordre pour `section="tout"` : `div.actions` (p d'intro + `div.act` : `a.btn.sobre` vers `esc_url(home_url('/veille.ics'))` « S'abonner au calendrier », `button#btn-proposer.btn` « Proposer un événement ») ; `section#dialogue.encadre` (formulaire, inchangé) ; `section.alaune[hidden]` avec `div.wrap` > `div.tete` (`span.titre` « À la une du RSN », `a#lien-rsn.tout` « Tout ce que le RSN organise et finance → ») + `div#unes.unes` — seulement si `section === 'tout'` et `une !== '0'` ; `div.limites[hidden]` (`span.lab` « Dates limites » + `div#limites.defile`) — sections `tout` et `appels` ; `div.deux#vue-agenda` — sections `tout` et `agenda` — dont la colonne : recherche, `div.rubs#f-type` (section `tout` seulement), `div.rubs#f-qui` (remplace `div.tags#f-qui`), `#f-mois`, `#f-format`, `#f-region`, `#f-theme`, `#f-orga`, `#f-reinit` ; la barre, `#liste-agenda`, `#calendrier` (légende + `<span><i class="appel"></i>Date limite d'un appel</span>`) ; `div#vue-appels` + `#liste-appels` — section `appels` seulement ; `section.sources` — `tout`. Plus de `nav.onglets`. Attributs sur `.rsn-veille` : `data-section`, `data-une`.
- Produces, dans `textes()` : `a_la_une`, `tout_le_rsn`, `evenement_rsn`, `nouveau`, `ferme_dans` (tableau singulier/pluriel avec `%d`), `ferme_demain`, `ferme_aujourdhui`, `date_limite_le` (« Date limite : %s »), `dates_limites`, `afficher`, `evenements`, `appels`, `evenement`, `partenaire` (« Partenaire · %s »), `nb_evenements_et_appels` (« %1$s et %2$s à venir »), `nb_elements` (tableau), `date_limite_puce` (« Date limite · »), `legende_date_limite`, `s_abonner`, `aucun_element_filtres` ; `tous` existe déjà.

- [ ] **Step 1 : tests qui échouent / à adapter** — adapter `test_filtre_qui_organise_au_dessus_de_format` (`#f-qui` est un `div.rubs`) et `test_sections` ; ajouter `test_tout_contient_actions_alaune_limites_et_pas_d_onglets()`, `test_une_0_retire_la_bande_a_la_une()`, `test_section_agenda_sans_f_type_ni_limites_ni_alaune()`, `test_section_appels_avec_limites_et_liste_appels_sans_filtres()`, `test_textes_contiennent_les_nouvelles_cles()` (les 20 clés ci-dessus présentes).
- [ ] **Step 2 : vérifier l'échec.** — [ ] **Step 3 : implémenter.** — [ ] **Step 4 : phpunit vert, `php -l`.**
- [ ] **Step 5 : commit** `feat(page): gabarit v8 du raccourci, textes, attribut une`.

### Task 8 : `assets/veille.css` — styles de la v8

**Files:**
- Modify: `assets/veille.css`
- Test: `tests/SqueletteTest.php` (préfixe des sélecteurs, déjà en place)

**Interfaces:**
- Produces: les règles du second bloc `<style>` de `docs/maquette/gabarit-v8.html` (« v8 : ajouts… ») reprises telles quelles, chaque sélecteur préfixé `.rsn-veille ` : `.actions`, `.btn.jaune`, `.alaune` et enfants (`.tete`, `.titre`, `.tout`, `.unes`, `.une`, `.date`, `.etat`, `.etat.nouveau`, `.etat.ev`, `.m`, `.fl`), `.limites` (`.lab`, `.defile`, `.lim`, `.p`), `.pills`/`.pill` et variantes (`type`, `type.appel`, `rsn`, `part`, `orga`, `theme`), `.ev.rsn > .hex`, `.ev.appel-ev` (+ `.tuile`), `.ev .quand`, `.cal .puce.appel` (fond `--yellow-tint`, bord gauche `--yellow-ink`) et `.cal-legende i.appel`. Débordement de la bande : `.rsn-veille .alaune{margin-inline:calc(50% - 50vw)}` et `.rsn-veille .alaune .wrap{max-width:1140px;margin:0 auto;padding-inline:max(16px,env(safe-area-inset-left,0px))}`. Les règles de `.onglets`/`.onglet` sont retirées. Règles étroites (`.unes` en une colonne, `.limites` en colonne, `.ev.appel-ev` à 52 px) ajoutées aux deux blocs `@media (max-width:820px)` et `@container rsn-veille (max-width:760px)`.

- [ ] **Step 1 : écrire le CSS.** — [ ] **Step 2 : `vendor/bin/phpunit --filter Squelette`** vert ; vérifier à la main que chaque accolade ouverte est fermée (`node -e "const c=require('fs').readFileSync('assets/veille.css','utf8');if((c.match(/{/g)||[]).length!==(c.match(/}/g)||[]).length)process.exit(1)"`).
- [ ] **Step 3 : commit** `feat(page): styles v8 (à la une, dates limites, pastilles, appels dans la frise)`.

### Task 9 : `assets/veille.js` — pastilles, liste unifiée, filtres Afficher et Qui organise

**Files:**
- Modify: `assets/veille.js`

**Interfaces:**
- Consumes: ids et textes de la tâche 7 ; JSON public (clés `type`, `debut`, `fin`, `echeance`, `organise_par`, `organisateur`, `format`, `langue`, `themes`, `image_url`, `resume`, `url`, `categorie`, `region`, `lieu`, `ville`, `prix`).
- Produces : `pastilles(k)` (spec 4.5 : type, organisateur selon `qui(k)` — « RSN » / `t('partenaire', nom)` / nom —, format, « En anglais », thèmes) ; `cleTri(k)` = `limite(k) || k.debut` pour un appel, `k.debut` sinon ; `estPasse(k)` ; `aVenir()` et `passes()` sur les deux types ; `carteAppel(k)` rendu en `div.ev.appel-ev` (tuile à la place du bloc date, pastilles, ligne meta, résumé, « Consulter l'appel ») ; `carteEv`/`ligneEv` avec `pastilles(k)` à la place des lignes meta/étiquettes et la classe `rsn` sur `.ev` quand `estRSN(k)` ; `filtres.type` (`''`, `evenement`, `appel`) verrouillé à `evenement` quand `racine.dataset.section === 'agenda'` ; `filtrer()` applique `type` et `qui` ; `rendreAgenda()` groupe par mois de `cleTri`, en-tête « N événements · M appels » (`n('nb_evenements')`, `n('nb_appels')`), compteur `t('nb_evenements_et_appels', n('nb_evenements', nE), n('nb_appels', nA))`, message vide `aucun_element_filtres` ; `rendreRubs()` remplit `#f-type` et `#f-qui` (boutons `.rub` `data-cle`/`data-val`, compteurs selon spec 4.4) ; `rendreMois()` compte les appels à leur mois de date limite ; le gestionnaire de clic des `.rub` couvre `type`, `qui` et `mois` ; `#f-reinit` remet `type` (sauf verrou) et `qui`.

- [ ] **Step 1 : implémenter** en gardant `lien()` et `esc()` partout ; aucune recherche par `document.getElementById`.
- [ ] **Step 2 : `node --check assets/veille.js`** ; `vendor/bin/phpunit` vert (rien ne doit casser côté PHP).
- [ ] **Step 3 : vérification à la main** si wp-env tourne (`http://localhost:8888/veille/`, l'extension y est montée) : la page se charge sans erreur de console, les filtres Afficher et Qui organise changent la liste ; sinon le dire dans le rapport.
- [ ] **Step 4 : commit** `feat(page): liste unifiée, pastilles, filtres Afficher et Qui organise`.

### Task 10 : `assets/veille.js` — bandes À la une et Dates limites, calendrier, sections

**Files:**
- Modify: `assets/veille.js`

**Interfaces:**
- Consumes: tâches 7 et 9.
- Produces : `rendreUnes()` (spec 4.2 : trois premiers éléments `estRSN` avec `cleTri` ≥ AUJ, appels seulement avec `limite(k)` ; étiquettes `evenement_rsn` / `nouveau` (`debut` ≥ AUJ − 7 j) / `ferme_dans` (≤ 21 j ; `ferme_demain`, `ferme_aujourdhui`) / `date_limite_le` ; détail : `periode(k)` + format, ou première phrase du résumé coupée à 70 ; `section.alaune` `hidden` si aucun) ; `rendreLimites()` (spec 4.3 ; `div.limites` `hidden` si aucun) ; clic `#lien-rsn` → `filtres.qui = 'rsn'`, `filtres.type = ''`, `filtres.mois = ''`, re-rendu, `scrollIntoView` sur `#vue-agenda` ; calendrier : un appel compte le jour de `limite(k)`, puce `class="puce appel"` texte `t('date_limite_puce') + titre`, le détail du jour liste les événements puis les appels (`carteEv` puis `carteAppel`) ; section `appels` : `rendreAppels()` trie par `limite(k)` puis `debut` et appelle `rendreLimites()` ; `tout()` appelle `rendreUnes()` et `rendreLimites()` quand les nœuds existent.

- [ ] **Step 1 : implémenter.** — [ ] **Step 2 : `node --check`, phpunit vert.**
- [ ] **Step 3 : vérification à la main** sur wp-env si disponible : bande À la une avec les trois éléments du RSN, puce jaune d'un appel dans le calendrier, `section="appels"` et `une="0"` sur une page d'essai ; sinon le dire.
- [ ] **Step 4 : commit** `feat(page): bandes À la une et Dates limites, appels dans le calendrier`.

### Task 11 : version 0.2.0, documentation, parcours

**Files:**
- Modify: `rsn-veille.php` (en-tête `Version: 0.2.0`, `RSN_VEILLE_VERSION`), `CHANGELOG.md` (section « 0.2.0 (2026-10-27) » : la page, les six corrections, les nouvelles propriétés), `LISEZMOI.md` (description de la page, attribut `une`, limites connues relues), `docs/installation-et-bascule.md` (section 4 : page privée acceptée, attribut `une`, bande pleine largeur dans Elementor ; catégories TEC par slug nu ; section 5 inchangée sinon), `tests/manuel/PARCOURS.md` (étapes : bande À la une avec un appel « Nouveau » et un « Ferme dans N jours », bande Dates limites, filtre Afficher, appel dans le calendrier, `une="0"`, `section="appels"`, image mise en avant qui prime, tri de la file et repère Nouveau, page privée dans la liste, appel sans date limite visible dans la liste seulement), `tests/manuel/verifier-admin.md` si la file y est décrite.
- Test: `tests/SqueletteTest.php` si elle vérifie la cohérence des numéros de version.

- [ ] **Step 1 : écrire.** — [ ] **Step 2 : `vendor/bin/phpunit`** vert, `php -l rsn-veille.php`, `git grep -n "0\.1\.0"` ne montre plus que l'historique du CHANGELOG et des spécifications.
- [ ] **Step 3 : commit** `chore: version 0.2.0, changelog, guide et parcours`.

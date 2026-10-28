# Plan d'implémentation — rsn-veille 0.4.0, la page en anglais

> **Pour les agents :** SOUS-COMPÉTENCE REQUISE : superpowers:subagent-driven-development (recommandé) ou
> superpowers:executing-plans, tâche par tâche. Les étapes sont des cases à cocher (`- [ ]`).

**But :** la page « Événements et appels » existe en anglais sur `/en/` : interface traduite, événements et appels du
RSN dans leur version anglaise, Mila et IVADO appariés dans les deux langues, le reste dans sa langue avec une
pastille ; la page française perd ses doublons anglais.

**Architecture :** un fichier gettext (`languages/rsn-veille-en_US.po/.mo`) livré avec l'extension et chargé par
`load_plugin_textdomain` ; `Donnees` connaît la langue de la page (`langue_courante`) et construit le JSON dans cette
langue (transients déjà par langue) ; `EvenementsRsn` ne garde qu'un article par groupe de traduction WPML ;
`Donnees::elements` dédoublonne les événements du RSN par groupe de traduction et traduit les appels du RSN par
WPML ; la `Fiche` reçoit `titre_en`, `resume_en`, `url_en`, remplis à la collecte par appariement des pages
française et anglaise de Mila et d'IVADO ; le script affiche la pastille de langue et les dates à l'anglaise.

**Pile :** PHP ≥ 8.0, WordPress + WPML + The Events Calendar (bouchons `tests/bouchons-wordpress.php` pour
PHPUnit 9), gettext (`msgfmt` sur le poste), JavaScript sans dépendance. Vérification : `vendor/bin/phpunit`,
`php -l`, `node --check assets/veille.js`, `msgfmt --check`.

**Conception :** `docs/superpowers/specs/2026-10-27-page-anglaise-0.4-design.md` (fait foi).

## Contraintes globales

- Tout en français (code, commentaires, commits, documents) ; commits au seul nom de l'auteur git, jamais de
  `Co-Authored-By` ni de mention d'assistant.
- Deux langues seulement : `fr` et `en` ; toute autre valeur vaut `fr` pour le contenu.
- Les champs `titre_en`, `resume_en`, `url_en` ne sortent jamais tels quels dans le JSON public : ils sont appliqués
  (ou ignorés) par `Donnees::elements`.
- Une valeur anglaise vide n'efface jamais une valeur anglaise existante.
- `esc()`/`lien()` côté JS, `esc_html__`/`esc_attr__` côté PHP ; textes via le domaine `rsn-veille`.
- Rien ne change dans l'administration, l'infolettre, le courriel (hors les deux clés de journal de la Tâche 7).
- `vendor/bin/phpunit` vert à la fin de chaque tâche (541 tests au départ) ; `php -l` sur chaque PHP modifié.

## Points de vigilance

1. **Sans WPML** (wp-env, tests) : `wpml_*` rendent `null`, `langue_courante()` vaut `fr`, `EvenementsRsn` garde
   tous les articles, le dédoublonnage par groupe retombe sur l'identifiant, `lang=en` sur l'API rend le contenu
   français. Tests : Tâches 2, 3, 4 (cas « sans WPML »).
2. **Article anglais sans article français dans son groupe** (traduction orpheline) : gardé tel quel, `titre_fr` =
   son propre titre. Test : Tâche 3.
3. **Appel du RSN dont `url_to_postid` rend 0** (permalien inconnu, ancienne adresse) : fiche inchangée, pastille
   « In French ». Test : Tâche 4.
4. **Fiche IVADO appariée mais page anglaise illisible** (titre anglais vide) : rien n'est écrit, la fiche reste
   française ; la nuit suivante retente. Test : Tâche 7.
5. **Deux événements Mila le même jour** : appariement dans l'ordre d'apparition, jamais deux fois la même fiche
   anglaise. Test : Tâche 7.

---

### Task 1 : textes en anglais (gettext)

**Fichiers :**
- Modifier : `rsn-veille.php` (chargement du domaine), `includes/Public/Raccourci.php` (clés `textes()`),
  `includes/Sources.php` (`nom` et `flux` dans `__()`)
- Créer : `languages/rsn-veille-en_US.po`, `languages/rsn-veille-en_US.mo`
- Test : `tests/TraductionTest.php` (nouveau), `tests/RaccourciTest.php` (clés), `tests/bouchons-wordpress.php`
  (`load_plugin_textdomain`, `plugin_basename` s'ils manquent : bouchons inertes qui rendent `true` / le chemin)

**Interfaces :**
- Produit : clés de `Raccourci::textes()` : `en_francais` « En français » ; `date_longue` « %1$s %2$s %3$s »
  (jour, mois long, année) ; `heure` « %1$s h %2$s » (heures, minutes) ; `du_au_meme_mois` et `du_au` inchangées en
  français. `premier_du_mois` existe déjà (« 1er »).
- Produit : `languages/rsn-veille-en_US.mo` lu par WordPress quand la locale est `en_US` ; valeurs anglaises :
  `en_francais` « In French », `en_anglais` « In English », `date_longue` « %2$s %1$s, %3$s », `premier_du_mois`
  « 1 », `heure` « %1$s:%2$s », `du_au_meme_mois` « %3$s %1$s–%2$s, %4$s », `du_au` « %1$s to %2$s », mois courts
  « Jan. », « Feb. », « Mar. », « Apr. », « May », « June », « July », « Aug. », « Sept. », « Oct. », « Nov. »,
  « Dec. », mois longs anglais, et toutes les autres chaînes publiques (gabarit, `Raccourci`, `Formulaire`,
  `Donnees`, `Rest`, `Sources`) en anglais canadien soigné (« Subscribe to the calendar », « Suggest an event »,
  « Featured by the RSN », « Everything the RSN organizes and funds → », « Deadlines », « Show », « Who organizes »,
  « Month », « Format », « Location », « Themes », « Organizer », « Reset filters », « Event », « Call »,
  « Partner · %s », « Ongoing », « Deadline to come », « New », « Closes in %d day » / « Closes in %d days »,
  « Closes tomorrow », « Closes today », « Deadline: %s », « More information », « View the call », « Today »,
  « %1$s and %2$s to come », « %d event » / « %d events », « %d call » / « %d calls », « %d item » / « %d items »,
  « Source », « What we read », « Items », « collected on %s », « collected on %1$s at %2$s »…).

- [ ] **Étape 1 : test de couverture** `tests/TraductionTest.php` :
  - `test_po_couvre_toutes_les_chaines_publiques` : extrait par expression régulière, de `includes/Public/*.php`,
    `includes/Public/gabarit-veille.php` et `includes/Sources.php`, tous les premiers arguments littéraux de `__(`,
    `esc_html__(`, `esc_attr__(`, `_x(` (avec contexte), `_n(` (singulier et pluriel), domaine `rsn-veille` ;
    lit `languages/rsn-veille-en_US.po` (analyse `msgctxt`/`msgid`/`msgid_plural`/`msgstr`/`msgstr[n]`, lignes
    repliées entre guillemets, échappements `\"` et `\n`) ; assertion : chaque chaîne extraite a une entrée dont
    la traduction n'est pas vide ; message d'échec listant les manquantes.
  - `test_mo_compile_a_jour` : `languages/rsn-veille-en_US.mo` existe ; son en-tête (petit-boutien, magic
    `0x950412de`, nombre de chaînes à l'octet 8) annonce autant d'entrées que le `.po` (en-tête vide du `.po`
    compris : le `.mo` compte l'entrée vide, en tenir compte).
  - Dans `RaccourciTest::test_textes_contiennent_les_nouvelles_cles` : `en_francais`, `date_longue`, `heure`, avec
    `%1$s`, `%2$s`, `%3$s` dans `date_longue`.
- [ ] **Étape 2 : lancer, échec** (`vendor/bin/phpunit --filter 'Traduction|nouvelles_cles'`).
- [ ] **Étape 3 : code** : `rsn-veille.php` : sur `init`, `load_plugin_textdomain('rsn-veille', false,
  dirname(plugin_basename(__FILE__)) . '/languages')` ; clés dans `Raccourci::textes()` ; `Sources::par_defaut()`
  et `Sources::nouvelles()` : `nom` et `flux` enveloppés dans `__(…, 'rsn-veille')` (chaînes littérales : adapter
  `entree()` pour recevoir le nom déjà traduit, le `flux` idem).
- [ ] **Étape 4 : le `.po`** : en-tête (`Language: en_US`, `Plural-Forms: nplurals=2; plural=(n != 1);`,
  `Content-Type: text/plain; charset=UTF-8`), une entrée par chaîne, traductions anglaises ; `msgfmt --check
  --statistics -o languages/rsn-veille-en_US.mo languages/rsn-veille-en_US.po` sans erreur.
- [ ] **Étape 5 : suite verte** ; commit `feat(langue): textes de la page en anglais (gettext) et noms des sources
  traduisibles`.

---

### Task 2 : la langue de la page et des sorties

**Fichiers :**
- Modifier : `includes/Public/Donnees.php`, `includes/Public/Rest.php`, `includes/Public/gabarit-veille.php`,
  `includes/Public/Formulaire.php`
- Test : `tests/DonneesTest.php`, `tests/RestTest.php`, `tests/RaccourciTest.php`, `tests/FormulaireTest.php`

**Interfaces :**
- Produit : `Donnees::langue_courante(): string` (`apply_filters('wpml_current_language', null)` ∈ {fr, en},
  sinon `fr`) ; `Donnees::construire(?string $langue = null, …)` et `Donnees::elements(Entrepot $e, array
  $evenements_rsn, ?string $aujourdhui = null, ?string $langue = null)` (défaut : `langue_courante()`) ; le JSON
  porte `langue` au premier niveau ; `Donnees::en_cache()` inchangé (transient par langue courante).
- Produit : `Rest::repondre` lit `lang` (`fr`|`en`) : si WPML est là (`wpml_current_language` non null) et que
  `lang` diffère de la langue courante, `do_action('wpml_switch_language', $lang)` avant `Donnees::en_cache()`,
  puis `do_action('wpml_switch_language', $origine)` ; sans WPML, `lang` est ignoré.
- Produit : `div.rsn-veille` porte `lang="fr"` ou `lang="en"` ; le formulaire a `<input type="hidden" name="langue"
  value="fr|en">` ; `Formulaire` enregistre `langue` = valeur reçue si `fr`|`en`, sinon `fr`.

- [ ] **Étape 1 : tests** : `test_langue_courante_fr_sans_wpml_et_en_avec_wpml` (filtre `wpml_current_language` →
  `en` ; valeur `de` → `fr`) ; `test_json_porte_la_langue` ; `RestTest` : `lang=en` avec WPML déclenche
  `wpml_switch_language` deux fois (`rsn_veille_bouchons_declenchements`), `en` puis `fr` ; sans WPML, aucune ;
  `RaccourciTest` : `lang="fr"` sur le conteneur, champ caché `langue` ; `FormulaireTest` : `langue=en` → fiche
  `en` ; absent ou `xx` → `fr`.
- [ ] **Étape 2 : lancer, échec.** **Étape 3 : code.** **Étape 4 : suite verte.**
- [ ] **Étape 5 : commit** `feat(langue): langue de la page dans le JSON, l'API (lang=) et le formulaire`.

---

### Task 3 : un seul article par groupe de traduction dans `EvenementsRsn`

**Fichiers :**
- Modifier : `includes/Public/EvenementsRsn.php`
- Test : `tests/EvenementsRsnTest.php` (nouveau)

**Interfaces :**
- Produit : `EvenementsRsn::lire(string $depuis = '2025-09-01', ?string $langue = null): array` (défaut
  `Donnees::langue_courante()`) ; chaque tableau rendu a en plus `tec_trid` (int|null) et `titre_fr` (string), et
  `langue` = langue de l'article gardé (`fr`|`en`).
- Produit : `EvenementsRsn::choisir_par_groupe(array $articles, string $langue, callable $trid, callable
  $langue_de): array` — pure, testable : `$articles` = liste de `WP_Post` (ou d'objets avec `ID`), `$trid(int
  $id): ?int`, `$langue_de(int $id): ?string` ; rend, par groupe (clé `trid`, ou `'id:' . $id` sans groupe), un
  tableau `['garde' => WP_Post, 'francais' => ?WP_Post, 'trid' => ?int]` : gardé = article en `$langue`, sinon en
  `fr`, sinon le premier ; `francais` = l'article `fr` du groupe s'il existe.
- `lire()` : `$trid` = `apply_filters('wpml_element_trid', null, $id, 'post_tribe_events')` (int ou null) ;
  `$langue_de` = `apply_filters('wpml_post_language_details', null, $id)` → `['language_code']` si tableau, null
  si `WP_Error`/null ; `titre_fr` = titre de `francais` sinon du gardé ; `cle_source` = `rsn-tec:<id gardé>`.

- [ ] **Étape 1 : tests** `choisir_par_groupe` : (a) groupe {12 fr, 45 en}, langue `en` → gardé 45, francais 12 ;
  langue `fr` → gardé 12 ; (b) {12 fr} seul, langue `en` → gardé 12 ; (c) {45 en} orphelin, langue `fr` → gardé 45,
  francais null ; (d) sans WPML (`trid` et `langue_de` rendent null) → chaque article son groupe, tous gardés ;
  ordre de sortie = ordre d'apparition des groupes.
- [ ] **Étape 2 : lancer, échec.** **Étape 3 : code** (`vers_fiche` reçoit l'article gardé + `titre_fr` + `trid`).
  **Étape 4 : suite verte** (`RestTest::… EvenementsRsn::lire()` rend toujours `[]` sans TEC).
- [ ] **Étape 5 : commit** `fix(rsn): un seul événement par groupe de traduction, dans la langue de la page`.

---

### Task 4 : dédoublonnage par groupe de traduction et appels du RSN traduits

**Fichiers :**
- Modifier : `includes/Public/Donnees.php`
- Test : `tests/DonneesTest.php`, `tests/bouchons-wordpress.php` (`url_to_postid` lisant une table
  `$GLOBALS['rsn_veille_wp']['urls']` url ⇒ id ; `get_the_title`, `get_permalink`, `get_the_excerpt`,
  `get_the_category` lisant les articles du bouchon `wp_insert_post` — n'ajouter que ce qui manque)

**Interfaces :**
- Consomme : `tec_trid`, `titre_fr`, `langue` des événements de la Tâche 3 ; `$langue` de la Tâche 2.
- Produit, dans `elements()` :
  - **clé de dédoublonnage** d'une fiche collectée : `cle_dedoublonnage()` sur son titre (français), calculée
    avant toute traduction ; d'un événement du RSN : `debut|titre_fr` (même fonction, sur `titre_fr`) ;
  - **groupe** d'une fiche collectée dont une clé de source commence par `rsn:` : `apply_filters('wpml_element_trid',
    null, (int) id, 'post_tribe_events')` sinon l'identifiant ; d'un événement du RSN : `tec_trid` sinon l'id de
    `rsn-tec:<id>` ; deux éléments de même groupe sont un doublon (avant la clé par titre) ;
  - fusion comme en 0.3.0 ; quand la fiche collectée gagne (image) et que l'événement gardé est en `$langue` ≠ langue
    de la fiche, elle prend `titre`, `resume`, `lieu`, `ville`, `url`, `langue` de l'événement ;
  - **appels `rsn_appels:`**, si `$langue !== 'fr'` : `url_to_postid($url)` > 0 → `apply_filters('wpml_object_id',
    $id, 'post', false, $langue)` entier ≠ null → `titre` = `get_the_title`, `resume` = extrait nettoyé
    (`Fiche::nettoyer_texte(get_the_excerpt(id))`, gardé français si vide), `url` = `get_permalink`, `categorie` =
    nom de la première catégorie (`get_the_category(id)[0]->name`, gardée si vide), `langue` = `$langue`.

- [ ] **Étape 1 : tests** : `test_evenement_rsn_et_fiche_collectee_du_meme_groupe_sont_un_doublon_en_anglais`
  (fiche `rsn:12` « Journées scientifiques » avec image, événement gardé 45 `tec_trid` 7, filtre
  `wpml_element_trid` 12 → 7 : un seul élément, image de la fiche, titre/url/langue de l'événement anglais) ;
  `test_sans_wpml_le_groupe_est_l_identifiant` (fiche `rsn:12`, événement 12 → doublon ; événement 13 → deux
  éléments) ; `test_titres_differents_mais_meme_groupe` ; `test_appel_rsn_traduit_en_anglais` (url ⇒ 2710,
  `wpml_object_id` → 2749, titre/extrait/permalien/catégorie anglais, échéance inchangée) ;
  `test_appel_rsn_sans_traduction_reste_francais` (`wpml_object_id` null) ; `test_appel_rsn_url_inconnue`
  (`url_to_postid` 0) ; `test_appel_rsn_extrait_vide_garde_le_resume` ; en `fr`, aucune traduction tentée.
- [ ] **Étape 2 : lancer, échec.** **Étape 3 : code.** **Étape 4 : suite verte.**
- [ ] **Étape 5 : commit** `feat(langue): événements du RSN dédoublonnés par groupe de traduction, appels du RSN
  traduits par WPML`.

---

### Task 5 : script public (pastille de langue, dates à l'anglaise)

**Fichiers :**
- Modifier : `assets/veille.js`

**Interfaces :** consomme `D.langue` (Tâche 2) et les clés `en_francais`, `date_longue`, `heure`,
`premier_du_mois`, `du_au_meme_mois`, `du_au` (Tâche 1).

- [ ] **Étape 1** : `pastilles` : `const L = D.langue === 'en' ? 'en' : 'fr'; if ((k.langue === 'fr' || k.langue
  === 'en') && k.langue !== L) p.push(`<span class="pill">${esc(t(k.langue === 'en' ? 'en_anglais' :
  'en_francais'))}</span>`)`.
- [ ] **Étape 2** : `dateLongue` = `t('date_longue', premier(x.j), MOIS_LONG[x.m - 1], x.a)` ; `periode` : l'heure
  par `t('heure', h, mm)` (séparer `k.heure` sur `:`, `h` sans zéro de tête en français : « 8 h 00 » ; en anglais
  « 8:00 » — garder les minutes telles quelles) ; `du_au_meme_mois` et `du_au` déjà via `t()` : vérifier que leurs
  arguments permettent la forme anglaise « %3$s %1$s–%2$s, %4$s » (jour début, jour fin, mois, année) et
  « %1$s to %2$s ».
- [ ] **Étape 3** : `node --check` ; faux DOM (bac à sable, voir Tâche 5 du plan 0.3) avec `D.langue = 'en'` et
  `T` contenant les gabarits anglais : « October 1, 2026 », « October 4–18, 2026 », pastille « In French » sur une
  fiche `fr`, aucune sur `en` ; puis `D.langue = 'fr'` : comportement 0.3.0 inchangé.
- [ ] **Étape 4 : commit** `feat(js): pastille de langue et dates dans la langue de la page`.

---

### Task 6 : champs anglais de la fiche et leur application

**Fichiers :**
- Modifier : `includes/Fiche.php`, `includes/TypeContenu.php` (`METAS`), `includes/Depot.php`
  (`CHAMPS_FACTUELS`), `includes/Public/Donnees.php` (application en `en`)
- Test : `tests/FicheTest.php`, `tests/DepotTest.php`, `tests/DonneesTest.php`, `tests/EntrepotContratTest.php`

**Interfaces :**
- Produit : `Fiche::$titre_en`, `$resume_en`, `$url_en` (string, `''`), dans `vers_tableau`/`depuis_tableau`,
  `METAS` (`string`), `CHAMPS_FACTUELS` ; `Depot::completer_anglais(Fiche $connue, string $titre_en, string
  $resume_en, string $url_en): bool` : écrit par `$this->entrepot->mettre_a_jour` les champs non vides et différents
  de l'existant, rend vrai si quelque chose a été écrit.
- Produit : `Donnees::elements` en `en` : fiche avec `titre_en !== ''` → `titre`, `resume` (si `resume_en` non
  vide), `url` (si `url_en` non vide) remplacés, `langue` = `en` ; en `fr` rien. Les trois clés ne sont jamais dans
  la sortie (`CLES_PUBLIQUES` inchangée).

- [ ] **Étape 1 : tests** : aller-retour tableau avec les trois champs ; `Depot::integrer` d'une fiche connue
  portant `titre_en` → écrit (champ factuel) ; `completer_anglais` vide → rien d'écrit, rend faux ; doublon d'une
  autre source : champs anglais passés à la fiche gardée si elle ne les a pas ; `elements` en `en` applique, en
  `fr` ignore, JSON sans `titre_en`.
- [ ] **Étape 2 : lancer, échec.** **Étape 3 : code.** **Étape 4 : suite verte.**
- [ ] **Étape 5 : commit** `feat(langue): titre, résumé et lien anglais sur la fiche, appliqués sur la page anglaise`.

---

### Task 7 : appariement Mila et IVADO à la collecte

**Fichiers :**
- Créer : `includes/Appariement.php`
- Modifier : `includes/Sources.php` (`url_en`, `flux_evenements_url_en`), `includes/Collecte.php`
  (`collecter_source`), `includes/Admin/PageJournal.php` (affichage des deux compteurs s'il affiche la ligne)
- Test : `tests/AppariementTest.php` (nouveau), `tests/CollecteTest.php`, `tests/SourcesTest.php`

**Interfaces :**
- Produit : `Appariement::apparier(array $francaises, array $anglaises): array` — `Fiche[]` × `Fiche[]` ⇒ liste
  de paires `[Fiche fr, Fiche en]` : pour chaque française dans l'ordre, la première anglaise non encore prise de
  mêmes `debut` et `fin` (`fin` null ≡ `debut`) ; rend aussi les anglaises orphelines : `['paires' => …,
  'orphelines' => Fiche[]]`.
- Produit, dans `Collecte::collecter_source`, après l'intégration française (toutes sources), si
  `$source['url_en']` non vide : lecture de `url_en` (même `obtenir`, mêmes options, `anti_cache` si présent) avec
  le même lecteur et une copie de la source (`url` ⇒ `url_en`, `flux_evenements_url` ⇒ `flux_evenements_url_en`,
  `langue` ⇒ `en`) ; appariement ; pour chaque paire : `$connue = $depot->fiche_de_cle($fr)` ; si `$connue` est
  null, rien ; pour IVADO, si `$connue->titre_en === ''` ou `$connue->url_en !== $en->url`, complète l'anglaise par
  `ResolutionIvado::resoudre([$en], $source_en, $depot, obtenir, $recueillir, MAX restant, BUDGET − écoulé)` où
  `$recueillir` garde la fiche résolue ; puis `$depot->completer_anglais($connue, $en->titre, $en->resume,
  $en->url)`. `$ligne['appariees_en']` = nombre de paires écrites ou déjà à jour, `$ligne['orphelines_en']` =
  nombre d'anglaises sans équivalent ; une erreur de lecture anglaise va dans `$ligne['erreurs']` sans faire échouer
  la source.
- `Sources` : `mila` → `url_en` `https://mila.quebec/en/events` ; `ivado` → `url_en` `https://ivado.ca/en/events/`,
  `flux_evenements_url_en` `https://ivado.ca/en/events/feed/` (vérifier l'existence ; sinon ne pas le mettre).

- [ ] **Étape 1 : tests** `Appariement` : dates identiques ; deux le même jour → ordre ; `fin` null ; orpheline ;
  `Collecte` (client HTTP de test) : source Mila avec `url_en` : deuxième requête, `titre_en`/`url_en` écrits sur la
  fiche connue, compteurs ; lecture anglaise en échec → erreur, fiches françaises intactes ; IVADO : page anglaise
  d'événement lue seulement si `titre_en` vide ; `titre_en` déjà présent → aucune requête de page anglaise.
- [ ] **Étape 2 : lancer, échec.** **Étape 3 : code.** **Étape 4 : suite verte ; `php -l`.**
- [ ] **Étape 5 : commit** `feat(collecte): Mila et IVADO appariés dans les deux langues`.

---

### Task 8 : version et documents

**Fichiers :** `rsn-veille.php` (`0.4.0`), `tests/SqueletteTest.php`, `CHANGELOG.md`, `LISEZMOI.md`,
`docs/installation-et-bascule.md` (Temps 3), `tests/manuel/PARCOURS.md`, `tests/manuel/verifier-public.md`
(`?ver=0.4.0`), `tests/manuel/verifier-admin.md`.

- [ ] **Étape 1** : test de version en échec, version `0.4.0`, vert.
- [ ] **Étape 2** : `CHANGELOG` 0.4.0 (une ligne par point du § 3 de la conception, plus la correction des doublons
  anglais sur la page française ; aucune migration ; la traduction de la page à créer dans WPML).
- [ ] **Étape 3** : `LISEZMOI` (page publique en deux langues ; limites : thèmes et admin en français, Mila et
  IVADO seuls appariés, `lang=en` sans WPML, champs anglais invisibles dans l'admin).
- [ ] **Étape 4** : guide, Temps 3 réécrit (créer la traduction, même code court, « Purger tout », vérifier
  `/en/veille.ics`, la pastille, les appels, Mila/IVADO) ; retirer « la version anglaise s'affiche en français ».
- [ ] **Étape 5** : `PARCOURS` (titre 0.4.0 ; étapes : page anglaise créée, textes et dates, événements et appels
  du RSN en anglais, fiche sans traduction marquée « In French », Mila et IVADO appariés (JSON `?lang=en`), iCal
  anglais, page française sans doublon anglais, formulaire anglais → proposition `en`, `?ver=0.4.0`).
- [ ] **Étape 6 : commit** `docs: version 0.4.0, journal, guide et parcours`.

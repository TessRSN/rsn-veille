# Plan d'implémentation — rsn-veille 0.5.0, la bande « Prochains événements » de l'accueil

> **Pour les agents :** SOUS-COMPÉTENCE REQUISE : superpowers:subagent-driven-development. Étapes en cases à cocher.

**But :** un code court `[rsn_veille_accueil]` qui rend la variante A de la maquette (calendrier du mois, événements du
RSN, quatre de l'écosystème), en français et en anglais, à poser dans le widget « Code court » des deux accueils.

**Architecture :** une classe `Public\Accueil` (sélection pure testée, rendu d'un gabarit minimal avec les données et
les textes en JSON incorporé, chargement des ressources) ; `assets/accueil.css` et `assets/accueil.js` (rendu et
interactions) ; `Collecte::purger_caches` purge aussi l'accueil et sa traduction.

**Pile :** PHP ≥ 8.0, WordPress/WPML (bouchons `tests/bouchons-wordpress.php`, PHPUnit 9), JavaScript sans
dépendance, gettext (`languages/rsn-veille-en_US.po/.mo`, `msgfmt`).

**Conception :** `docs/superpowers/specs/2026-10-28-bande-accueil-0.5-design.md` ; maquette `docs/maquette/accueil-v1.html`
(variante A : bloc `.A`, valeurs CSS et comportement à reprendre).

## Contraintes globales

- Tout en français (hors traductions) ; commits au seul nom de l'auteur git, jamais de `Co-Authored-By` ni de mention
  d'assistant.
- Fonctionne à l'identique en français et en anglais (langue WPML de la page) ; toute chaîne publique au `.po`, `.mo`
  recompilé (`TraductionTest` l'exige ; `includes/Public/*.php` est dans son périmètre).
- `esc()` sur toute valeur insérée côté JS ; liens `https?://` seulement ; `esc_html`/`esc_attr` côté PHP.
- CSS préfixé `.rsn-veille-accueil`, sans police chargée, aucune collision avec `veille.css` (pas de classe nue `.rsn`).
- `vendor/bin/phpunit` vert à chaque tâche (625 tests au départ), `php -l`, `node --check`.

## Points de vigilance

1. **Aucun événement du RSN** et **aucun de l'écosystème** : phrases de remplacement, pas d'exception. Test : T1, T2.
2. **Série de plus de sept jours déjà commencée** (FRQ France-Québec, 4 sept. → 18 déc.) : absente des deux colonnes,
   un seul point au calendrier, à son premier jour (donc aucun en octobre-novembre). Test : T1.
3. **Organisateur répété** (Digital Health Canada) : un seul dans la liste par défaut ; tous le jour choisi. Test : T1, T2.
4. **Page d'accueil traduite** : ressources en tête et purge sur 152 et sa traduction. Test : T1.
5. **Mois sans événement** entre deux mois qui en ont : la navigation y passe ; pas avant le mois courant. Test : T2.

---

### Task 1 : classe `Accueil` (sélection, rendu, ressources, purge, textes)

**Fichiers :** créer `includes/Public/Accueil.php`, `tests/AccueilTest.php` ; modifier `rsn-veille.php` (accrochage),
`includes/Collecte.php` (`purger_caches` : accueil et traductions), `languages/rsn-veille-en_US.po` et `.mo`,
`tests/bouchons-wordpress.php` (`is_front_page` lisant `$GLOBALS['rsn_veille_wp']['accueil']` ; `get_option('page_on_front')`
existe déjà par `get_option`).

**Interfaces produites :**
- `Accueil::NOM = 'rsn_veille_accueil'` ; `Accueil::accrocher(): void` (`init` → `add_shortcode` ; `wp_enqueue_scripts`
  → `charger_si_present`).
- `Accueil::selection(array $items, string $aujourdhui, int $nb_eco = 4): array` → `['rsn' => list, 'eco' => list,
  'calendrier' => list]` ; chaque élément réduit aux champs `id`, `titre`, `debut`, `fin`, `organisateur`,
  `organise_par`, `format`, `url`, `ville`, `langue`, `image_url`. Règles : événements seulement ; à venir = (fin ou
  début) ≥ aujourd'hui ; « longue » = plus de sept jours entre début et fin ; longue déjà commencée exclue de `rsn` et
  `eco` ; `rsn` = tous les `organise_par === 'rsn'` triés par début puis titre ; `eco` = les autres, triés, le premier
  de chaque organisateur, `$nb_eco` au plus ; `calendrier` = tous les événements (RSN compris) dont la fin est ≥ le
  premier jour du mois de `$aujourdhui`, triés.
- `Accueil::textes(): array` : clés de dates reprises de `Raccourci::textes()` (`mois`, `mois_longs`, `jours_courts`,
  `premier_du_mois`, `date_longue`, `du_au_meme_mois`, `du_au`, `formats`, `en_anglais`, `en_francais`) + clés du § 4.1
  de la conception (valeurs exactes françaises du § 4.1, anglaises au `.po`) ; `autres_rsn` est un tableau à deux formes
  comme `ferme_dans`.
- `Accueil::rendre(mixed $attributs = []): string` : `<div class="rsn-veille-accueil" lang="…" data-aujourdhui="Y-m-d">`
  avec `<div class="rsna-cal"></div><div class="rsna-rsn"><p class="rsna-sous">…Au RSN…</p><div data-rsn></div></div>
  <div class="rsna-eco"><p class="rsna-sous"><span data-sous>…</span><button type="button" data-tous hidden>…</button></p>
  <div data-liste></div></div>`, un `<noscript>` (lien « Voir l'agenda de l'écosystème » / anglais : chaîne existante
  si elle existe, sinon nouvelle chaîne au `.po`) et deux `<script type="application/json">` (`data-rsn-accueil-donnees`
  = sélection + `agenda` = permalien de la page Veille dans la langue courante via `Collecte::pages_veille()` ou
  `home_url`, `data-rsn-accueil-textes`), JSON par `Raccourci::json`. Met en file les ressources si elles ne le sont pas.
- Ressources : `rsn-veille-accueil` (style et script), `RSN_VEILLE_URL . 'assets/accueil.css'|'.js'`, version
  `RSN_VEILLE_VERSION`, script en pied de page ; en tête quand `is_front_page()` ou que le contenu de l'article demandé
  contient le code court (`has_shortcode`), filtre `rsn_veille_charger_accueil`.
- `Collecte::pages_accueil(): int[]` : `page_on_front` (> 0) et ses traductions WPML (comme `pages_veille`) ;
  `purger_caches()` purge aussi celles-ci (LiteSpeed et `_elementor_element_cache`).

- [ ] **Étape 1 : tests** `AccueilTest` : sélection (un par organisateur ; 4 au plus ; série longue commencée exclue des
  deux colonnes mais présente au calendrier ; RSN triés et tous gardés ; fin ≥ aujourd'hui ; calendrier à partir du
  premier du mois ; champs réduits ; aucun RSN ; liste vide) ; `rendre` (classe, `lang` fr puis en avec le filtre
  `wpml_current_language`, nœuds JSON, `noscript`, ressources en file) ; chargement en tête sur l'accueil
  (`is_front_page`), sur une page contenant le code court, rien ailleurs ; `pages_accueil` avec et sans WPML ;
  `purger_caches` purge l'accueil et sa traduction (`rsn_veille_bouchons_declenchements`, méta supprimée) ;
  `textes()` a les nouvelles clés. `TraductionTest` exigera les nouvelles entrées du `.po`.
- [ ] **Étape 2 : échec.** **Étape 3 : code**, `.po` (traductions anglaises du § 4.1) et `msgfmt`. **Étape 4 : suite verte.**
- [ ] **Étape 5 : commit** `feat(accueil): code court rsn_veille_accueil, sélection, ressources et purge de l'accueil`.

### Task 2 : `assets/accueil.css` et `assets/accueil.js`

**Fichiers :** créer `assets/accueil.css`, `assets/accueil.js`.

**Interfaces consommées :** le balisage et les deux nœuds JSON de la Tâche 1 ; clés de textes ; `data-aujourdhui`.

- [ ] **Étape 1 : CSS** depuis le bloc `.A` de `docs/maquette/accueil-v1.html` (calendrier étiré, en-têtes de colonne
  de 28 px, carte RSN grande et compacte, image absente en marine avec hexagone, lien « + N autres », liste, tuiles,
  points `i.r`/`i`, réactif 980 px et 640 px, focus visible, mouvement réduit), préfixé `.rsn-veille-accueil`, sans
  `.rsn` nu (utiliser `.rsna-carte`).
- [ ] **Étape 2 : JS** (une IIFE par bande trouvée, plusieurs bandes possibles sur une page) : lecture des deux JSON,
  calendrier (mois de `data-aujourdhui`, flèches désactivées avant le mois courant et après le dernier mois du
  calendrier, points RSN/écosystème, série longue à son premier jour seulement, jour cliquable filtre l'écosystème,
  « Tous »), colonne RSN (0, 1, 2, 3+ selon la conception, lien vers `agenda`), colonne écosystème (liste par défaut ou
  jour choisi), dates avec `date_longue`/`du_au*`/`premier_du_mois`, formats traduits, pastille de langue si
  `langue` ≠ langue de la bande, `esc()` partout, liens `https?://` seulement, ouverture dans un nouvel onglet seulement
  si l'hôte n'est pas celui du site.
- [ ] **Étape 3 : vérifier** : `node --check` ; faux DOM (bac à sable, hors dépôt) avec des données en français puis
  en anglais : 0, 1, 2 et 3 événements du RSN, filtre par jour, navigation, aucune exception ; `vendor/bin/phpunit` vert.
- [ ] **Étape 4 : commit** `feat(accueil): rendu de la bande (calendrier, RSN, écosystème)`.

### Task 3 : version et documents

**Fichiers :** `rsn-veille.php` (0.5.0), `tests/SqueletteTest.php`, `CHANGELOG.md`, `LISEZMOI.md`,
`docs/installation-et-bascule.md` (section « Bande de l'accueil » : remplacer `[rsn_events_cards limit="3"]` par
`[rsn_veille_accueil]` dans le widget Code court de l'accueil 152 et de sa traduction 1147 ; marges conseillées de la
section d'en-tête ; LiteSpeed « Purger tout »), `tests/manuel/PARCOURS.md` (une étape « Bande de l'accueil », FR et EN),
`tests/manuel/verifier-public.md` (`?ver=0.5.0`).

- [ ] **Étape 1** : test de version en échec, version, vert. **Étape 2** : documents. **Étape 3 : commit**
  `docs: version 0.5.0, bande de l'accueil`.

# Conception 0.5.0 — la bande « Prochains événements » de l'accueil

Date : 2026-10-28. Maquette validée par Tess : variante A de `docs/maquette/accueil-v1.html` (publiée à
https://claude.ai/artifact/8HDAYGPUg6fMTpr4jTLWf8, version 3 : calendrier aligné, point jaune rond, plusieurs événements
du RSN).

## 1. Intention

Sur l'accueil de rsn.quebec, la bande « Prochains événements » mesure environ 1 040 px sur un écran de 1 440 px et ne
montre que les événements du site (aujourd'hui, une seule grande carte). Tess veut « plus de choses de l'écosystème mais
les nôtres plus visibles, peut-être un petit calendrier, et que ça prenne moins de place en hauteur ». Elle veut garder
la bande dans Elementor pour que son collègue puisse la modifier.

## 2. Ce que montre la bande (variante A)

Trois colonnes, sous l'en-tête Elementor existant (petite ligne, titre, intro, lien) :

1. **Calendrier du mois** : mois courant au départ, flèches pour changer de mois (pas avant le mois courant, pas au-delà
   du dernier mois qui a un événement), jours passés grisés, aujourd'hui entouré, un point jaune (`--yellow-ink`) les
   jours qui ont un événement du RSN, un point marine (`#3D6A99`) ceux qui ont un événement de l'écosystème ; une
   série de plus de sept jours ne pointe que son premier jour (même règle que l'agenda). Cliquer un jour pointé filtre
   la colonne de l'écosystème sur ce jour (« Le 4 novembre », bouton « Tous » pour revenir) ; recliquer le désélectionne.
   Légende « RSN » / « Écosystème ». La colonne s'étire sur toute la hauteur de la bande (semaines étirées, légende en
   bas), et ses trois en-têtes de colonne ont la même hauteur (28 px) pour que le haut des trois contenus s'aligne.
2. **Au RSN** : les événements du RSN à venir (`organise_par` = `rsn`, fin ≥ aujourd'hui, sans série de plus de sept
   jours déjà commencée), du plus proche au plus lointain :
   - un seul : grande carte, image en haut (150 px), pastille « RSN », date, titre, ville · format ;
   - deux : deux cartes compactes l'une sous l'autre (image à gauche, 96 px ; titre sur deux lignes au plus) ;
   - trois ou plus : les deux prochains en cartes compactes, puis le lien « + N autre(s) événement(s) du RSN → » vers
     l'agenda de la langue de la page ;
   - aucun : la phrase « Pas d'événement du RSN à venir pour le moment. Les prochains seront annoncés ici. »
   Cartes au langage des fiches RSN de l'agenda : fond `#F6F8FA`, trait jaune de 4 px à gauche, coins de 14 px. Sans
   image, la zone d'image est marine avec l'hexagone jaune du RSN.
3. **Dans l'écosystème** : les quatre prochains événements qui ne sont pas du RSN (fin ≥ aujourd'hui, sans série de
   plus de sept jours déjà commencée), **un seul par organisateur** (le plus proche), chacun en ligne : tuile de date
   (jour et mois court ; la date du jour pour un événement en cours), titre sur deux lignes au plus, organisateur ·
   format, et « En anglais » / « In French » quand la langue de la fiche diffère de celle de la page. Avec un jour choisi
   dans le calendrier : tous les événements de l'écosystème de ce jour, sans limite ni règle d'organisateur.

Tout dans la langue de la page (WPML), sur l'accueil français comme sur l'accueil anglais : textes, dates (`date_longue`, `du_au*`, mois courts et longs, jours courts),
formats traduits, titres et liens des événements comme sur l'agenda (`Donnees::elements` de la langue courante).
Chaque titre mène à la page de l'événement (nouvel onglet pour un lien externe, même onglet pour rsn.quebec).

Largeurs : trois colonnes `260px / 1fr / 1.15fr`, écart 28 px ; sous 980 px, le calendrier passe au-dessus sur toute la
largeur et les deux autres colonnes restent côte à côte ; sous 640 px, une colonne. Valeurs de la maquette.

## 3. Ce que fait l'équipe dans Elementor

- L'en-tête reste celui de la section Elementor existante (widgets Texte, Titre, Texte, Lien animé) : le collègue le
  modifie comme aujourd'hui.
- Le widget « Code court » de la section suivante passe de `[rsn_events_cards limit="3"]` (thème enfant) à
  `[rsn_veille_accueil]`, **sur les deux accueils** : la page 152 (français) et sa traduction 1147 (anglais). Le même
  code court rend la bande en anglais sur `/en/` (demande explicite de Tess : « il faut que ça marche en EN aussi »). Les pastilles de catégorie actuelles (vers les archives de The Events Calendar) disparaissent.
- Rien d'autre à régler : la bande prend la largeur de sa colonne Elementor.

## 4. Comment

### 4.1 Le code court

- Nouvelle classe `RSN\Veille\Public\Accueil` (`includes/Public/Accueil.php`), accrochée depuis `rsn-veille.php`
  comme `Raccourci` : `add_shortcode('rsn_veille_accueil', [Accueil::class, 'rendre'])`.
- `Accueil::selection(array $items, string $aujourdhui, int $nb_eco = 4): array{rsn: array, eco: array, calendrier:
  array}` — **pure**, testée : à partir des éléments de `Donnees` (événements seulement), rend `rsn` (tous les
  événements du RSN retenus, triés), `eco` (les quatre retenus) et `calendrier` (tous les événements, RSN compris, du
  premier jour du mois courant à la fin des données, avec seulement les champs utiles : `debut`, `fin`, `titre`,
  `organisateur`, `organise_par`, `format`, `url`, `ville`, `langue`, `image_url`, `id`).
- `Accueil::rendre(): string` : sélection sur `Donnees::en_cache()` (cache JSON par langue, déjà purgé par la collecte)
  et `rsn_veille_aujourdhui()`, puis un gabarit court : `<div class="rsn-veille-accueil" lang="fr|en">` avec trois
  colonnes vides que le script remplit, un nœud `<script type="application/json" data-rsn-accueil-donnees>` (même
  échappement que `Raccourci::json`) et un nœud de textes. Sans JavaScript, un `<noscript>` lien vers l'agenda.
- Textes : les clés de dates de `Raccourci::textes()` (réutilisées, pas dupliquées : `mois`, `mois_longs`,
  `jours_courts`, `premier_du_mois`, `date_longue`, `du_au_meme_mois`, `du_au`, `formats`, `en_anglais`,
  `en_francais`) plus les clés propres : `au_rsn` « Au RSN », `dans_ecosysteme` « Dans l'écosystème », `le_jour`
  « Le %s », `tous` « Tous », `aucun_jour` « Aucun événement ce jour-là. », `aucun_rsn` (phrase du § 2),
  `autres_rsn` (pluriel `_n` : « + %d autre événement du RSN → » / « + %d autres événements du RSN → »),
  `mois_precedent`, `mois_suivant`, `legende_rsn` « RSN », `legende_ecosysteme` « Écosystème », `rsn` « RSN ».
  Toutes au `.po` (anglais : « At the RSN », « In the ecosystem », « On %s », « All », « No events that day. »,
  « No upcoming RSN event for now. The next ones will be announced here. », « + %d more RSN event → » / « + %d more
  RSN events → », « Previous month », « Next month », « RSN », « Ecosystem »), `.mo` recompilé.

### 4.2 Ressources

- `assets/accueil.css` et `assets/accueil.js` (petits, sans dépendance, distincts de `veille.css`/`veille.js`), versionnés
  par `RSN_VEILLE_VERSION`.
- Chargées dans `<head>` sur la page d'accueil (`is_front_page()`, et la traduction WPML de la page d'accueil, qui est
  aussi `is_front_page()` sous WPML) et sur toute page dont le contenu contient `[rsn_veille_accueil]` ; sinon mises en
  file au rendu. Filtre `rsn_veille_charger_accueil` pour forcer.
- CSS préfixé `.rsn-veille-accueil`, valeurs de la maquette (variante A), polices et couleurs du site (pas de chargement
  de police).

### 4.3 Caches

- `Collecte::purger_caches()` purge aussi l'accueil : `get_option('page_on_front')` et ses traductions WPML (même
  mécanique que `pages_veille()`), `litespeed_purge_post` et suppression de `_elementor_element_cache` pour chacune.
  Ainsi la bande suit la collecte de nuit et la mise à jour de l'extension (purge au changement de version).
- Le cache LiteSpeed de l'accueil expire de toute façon à minuit (extrait 61) : rien d'autre.

### 4.4 Version et documents

0.5.0 ; CHANGELOG ; LISEZMOI (le code court) ; guide (section « Bande de l'accueil » : remplacer le code court dans
Elementor, FR et EN) ; PARCOURS (une étape : bande sur l'accueil FR et EN, calendrier, filtre par jour, mois, cas
1/2/3+/0 événements du RSN avec `wp` ou des événements de test, hauteur nettement plus basse, mobile).

## 5. Non-buts

- Pas de modification du thème enfant ni de `[rsn_events_cards]` (il reste disponible).
- Pas de nouvelle page ; pas de modification de l'agenda.
- L'en-tête de la bande et ses marges restent dans Elementor (l'équipe les ajuste ; la conception recommande de réduire
  la marge haute de la section d'en-tête de 100 à 60 px et la marge basse de 50 à 24 px, à faire dans Elementor).

## 6. Vérification

PHPUnit : `Accueil::selection` (un par organisateur, quatre au plus, séries longues commencées écartées, RSN triés et
tous gardés, calendrier à partir du premier jour du mois, champs réduits, fin ≥ aujourd'hui), `rendre` (structure,
`lang`, nœuds de données, `noscript`), chargement des ressources (accueil, page contenant le code court, autre page :
rien), purge de l'accueil et de sa traduction ; textes au `.po`. `node --check` ; le script exécuté contre un faux DOM
pour les quatre cas d'événements du RSN et le filtre par jour. Rendu réel sur rsn.quebec après la mise à jour.

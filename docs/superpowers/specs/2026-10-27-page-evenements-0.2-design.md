# rsn-veille 0.2.0 : page « Événements et appels » et corrections d'exploitation

Conception validée par Tess (RSN) le 2026-10-27 sur la maquette `docs/maquette/gabarit-v8.html`, après installation réelle de la 0.1.0 sur rsn.quebec. Elle complète `2026-10-25-veille-ecosysteme-design.md` (la 0.1.0), qui reste la référence pour tout ce qui n'est pas dit ici : collecte, règles de pertinence, file d'approbation, radar, infolettre, courriel, REST, iCal.

## 1. Intention

La page publique de l'extension doit remplacer la page Événements de rsn.quebec. Pour cela, elle doit mettre les événements et les appels du RSN en avant sans cacher ceux de l'écosystème, montrer les appels avec les événements plutôt que dans un onglet à part, laisser la personne trier ce qui l'intéresse, et garder l'historique (événements passés). Côté équipe, trois irritants vus à l'usage : la file trie par date d'événement et non par arrivée, l'image d'une fiche ne se modifie pas, et quelques fiches ont un classement ou un lien faux. Tout cela sort dans une seule version, 0.2.0, que Tess installera d'un coup par WP Pusher.

## 2. Objectifs, non-objectifs, anti-objectifs

Objectifs :
- la page `[rsn_veille]` prend la forme de la maquette v8 (section 4) ;
- les six corrections de la section 5 sont faites ;
- tout ce qui existait en 0.1.0 continue de marcher : attribut `section`, calendrier, formulaire, JSON, iCal, caches, WPML.

Non-objectifs (pas dans cette version) : fichiers de traduction anglais, pagination de la file, pagination de l'API TEC, purge automatique du courriel des proposants, bascule réelle sur `/calendrier-evenements/` (c'est une opération de Tess, décrite dans `docs/installation-et-bascule.md`).

Anti-objectifs (ce qui ferait échouer la version même si elle marche) : changer le style de la page au-delà de la maquette (Tess a refusé une refonte plus large en v7) ; perdre des données de la file ; obliger l'équipe à réinstaller ou à refaire les réglages ; une page qui dépend d'un réseau externe au chargement (toutes les données restent incorporées dans la page).

## 3. Contraintes

- Celles de la 0.1.0 : PHP 8.0, WordPress 6.0, aucune bibliothèque à l'exécution, tout en français, préfixe `rsn_veille_`, tests PHPUnit contre `tests/bouchons-wordpress.php`, `php -l` et `node --check`.
- Numéro de version 0.2.0 partout où 0.1.0 figure (`rsn-veille.php` en-tête et `RSN_VEILLE_VERSION`, qui versionne `veille.css` et `veille.js`).
- Le JSON public `/wp-json/rsn/v1/veille` garde ses clés ; on peut en ajouter, jamais en retirer ni en changer le sens. `assets/veille.js` lit ce JSON incorporé dans la page.
- Tous les textes visibles passent par `Raccourci::textes()` (JS) ou par `esc_html__` (PHP), domaine `rsn-veille`.
- Tous les sélecteurs CSS restent préfixés `.rsn-veille` ; les règles mobiles existent en `@media (max-width:820px)` et en `@container rsn-veille (max-width:760px)` (page Elementor à colonnes).
- Le raccourci peut figurer deux fois sur une page (suffixe des nœuds de données) : rien de nouveau ne doit casser ça.
- Aucune requête réseau au chargement de la page, aucune image incorporée en `data:` dans le vrai site (les images sont des adresses, comme en 0.1.0).

## 4. La page publique

Référence visuelle : `docs/maquette/gabarit-v8.html` (son bandeau de titre est celui du thème ; le raccourci commence sous lui). Les classes et le CSS de la maquette sont à reprendre tels quels, préfixés `.rsn-veille`, à la place de ce que `assets/veille.css` a repris du gabarit v6.1.

### 4.1 Ordre des blocs rendus par `[rsn_veille]` (section « tout »)

1. **Barre d'actions** : le paragraphe d'introduction (texte de la maquette : « Les événements du RSN, ceux de nos partenaires et tout ce qui bouge en santé numérique au Québec… ») et, à droite, deux boutons : « S'abonner au calendrier » (lien vers `/veille.ics`, style `btn sobre`) et « Proposer un événement » (ouvre l'encadré du formulaire, inchangé).
2. **Bande « À la une du RSN »** (4.2).
3. **Bande « Dates limites »** (4.3).
4. **Deux colonnes** : filtres à gauche (4.4), à droite la barre (compte, boutons Liste / Calendrier) puis la liste unifiée (4.5) ou le calendrier (4.6).
5. **Sources lues** : inchangé.

Les onglets Agenda / Appels disparaissent. L'attribut `section` garde trois valeurs : `tout` (tout ce qui précède) ; `agenda` (barre d'actions, deux colonnes avec le filtre « Afficher » verrouillé sur Événements et sans la bande des dates limites) ; `appels` (bande des dates limites puis la liste des appels seuls, triés par date limite, sans colonne de filtres). Pas de bande « À la une » hors de `tout`. Un nouvel attribut `une="0"` retire la bande « À la une » de `tout` (page où le thème met déjà le RSN en avant).

### 4.2 Bande « À la une du RSN »

Dessin de la bande « Appels de financement ouverts » de l'accueil de rsn.quebec, tel que repris dans la maquette (`.alaune`, `.unes`, `.une`) : fond marine foncé qui s'étend sur toute la largeur de la fenêtre (débordement du conteneur par `margin-inline: calc(50% - 50vw)`, contenu centré sur `max-width:1140px` comme le reste), titre « À la une du RSN » en petites capitales dorées avec un point vert, lien « Tout ce que le RSN organise et finance → » à droite, trois cartes au plus.

Contenu : les trois prochains éléments dont `organise_par` vaut `rsn`, événements (à venir, par date de début) et appels (en cours **avec une date limite**, par date limite) confondus, triés par cette date. Un appel sans date limite n'entre pas dans la bande (il n'a pas de date à venir à montrer) ; il reste dans la liste. Chaque carte : tuile de date jaune (jour, mois abrégé), une étiquette, le titre, une ligne de détail, une flèche. L'étiquette :
- événement : « Événement RSN » ;
- appel publié depuis 7 jours ou moins (date de publication = `debut` de l'appel, la même règle que la bande de l'accueil, extrait 44) : « Nouveau » (fond rouge) ;
- appel à 21 jours ou moins de sa date limite : « Ferme dans N jours » (« Ferme demain » à 1, « Ferme aujourd'hui » à 0) ;
- autre appel : « Date limite : 13 déc. ».
La ligne de détail : pour un événement, la période et le format ; pour un appel, le résumé coupé à la première phrase (70 caractères au plus). Le texte de la carte est calé en haut, aligné sur la tuile ; la flèche est centrée verticalement. Le lien « Tout ce que le RSN organise et finance » applique le filtre « Qui organise = RSN » et fait défiler jusqu'à la liste. Sans aucun élément du RSN à venir, la bande ne s'affiche pas.

### 4.3 Bande « Dates limites »

Fond jaune pâle (`--yellow-tint`), libellé « Dates limites » avec une icône d'horloge, puis une rangée de puces qui défile horizontalement si elle dépasse. Une puce par appel en cours qui a une date limite (`echeance`, sinon `fin` distincte de `debut`, règle `limite()` de `veille.js`), triées par date limite : date courte en gras, titre coupé après « : » et à 44 caractères, pastille de l'organisateur (« RSN » en jaune, sinon le nom, « Fonds de recherche du Québec » abrégé en « FRQ »). Les appels sans date limite n'y figurent pas (ils restent dans la liste). Sans appel à date limite, la bande ne s'affiche pas.

### 4.4 Filtres (colonne de gauche)

Dans cet ordre : Recherche (titre, organisateur, résumé, thèmes) ; **Afficher** (Tout, Événements, Appels, avec les compteurs) ; **Qui organise** (Tous, RSN, Partenaires, Écosystème, avec les compteurs, en rubriques comme « Mois » et non plus en étiquettes) ; Mois (comme en 0.1.0 : « Tous les mois à venir », les quatre prochains mois, « Plus tard », « Événements passés ») ; Format ; Lieu ; Thèmes ; Organisateur ; Réinitialiser. Les compteurs de « Afficher » et « Qui organise » comptent les éléments de la vue courante (à venir ou passés) en appliquant les autres filtres de ces deux groupes, pas la recherche. Les compteurs des mois comptent les appels à leur mois de date limite.

### 4.5 Liste unifiée

Un seul flux, groupé par mois, dans la frise à hexagones de 0.1.0. Clé de tri et de regroupement : `debut` pour un événement, date limite pour un appel (sinon `debut`). L'en-tête de mois indique « N événements · M appels ». Dans un mois, l'ordre est chronologique sur cette clé, les événements et les appels mêlés.

- **Événement à venir** : la fiche de 0.1.0 (image à gauche si `image_url`, bloc date, titre, lignes date et lieu, résumé, « Plus d'information »), avec une rangée de **pastilles** au-dessus du titre, à la place de la ligne « organisateur · catégorie » et de la ligne de thèmes en capitales : « Événement » (marine), puis l'organisateur (« RSN » en jaune ; « Partenaire · Nom » en bleu pâle ; sinon le nom en gris-bleu), puis le format, « En anglais » si `langue` vaut `en`, puis les thèmes (pastilles blanches à contour).
- **Appel** : même ligne de frise, avec la tuile jaune de date limite (ou grise « publié » sans date limite) à la place du bloc date, les pastilles (« Appel » en jaune, organisateur, thèmes), le titre, la ligne « organisateur · catégorie · publié le … », le résumé, « Consulter l'appel ».
- **Événement passé** (filtre « Événements passés ») : la ligne compacte de 0.1.0, avec les pastilles.
- L'hexagone de la frise est doré pour un élément du RSN, comme la puce du calendrier.

Le compteur de la barre dit « N événements et M appels à venir » (ou la forme passée de 0.1.0).

### 4.6 Calendrier

Inchangé, à une chose près : un appel apparaît le jour de sa date limite, avec une puce jaune (`.puce.appel`) préfixée « Date limite · ». Le détail du jour sous la grille liste les événements du jour puis les appels de ce jour, avec leurs cartes respectives. La légende gagne « Date limite d'un appel ».

### 4.7 Mobile et colonnes étroites

Comme en 0.1.0 (820 px et conteneur 760 px) : les cartes « À la une » s'empilent, la bande des dates limites passe le libellé au-dessus des puces, la colonne de filtres passe au-dessus de la liste, l'image d'un événement passe au-dessus du texte.

### 4.8 Textes

Nouvelles clés de `Raccourci::textes()` (toutes traduisibles) : `a_la_une`, `tout_le_rsn`, `evenement_rsn`, `nouveau`, `ferme_dans` ([« Ferme dans %d jour », « Ferme dans %d jours »]), `ferme_demain`, `ferme_aujourdhui`, `date_limite_le` (« Date limite : %s »), `dates_limites`, `afficher`, `tout`, `evenements`, `appels`, `evenement`, `partenaire` (« Partenaire · %s »), `nb_evenements_et_appels` (« %1$s et %2$s à venir », composé avec `nb_evenements` et `nb_appels`), `nb_elements` ([« %d élément », « %d éléments »]), `date_limite_puce` (« Date limite · »), `legende_date_limite`. Les textes PHP du gabarit (intro, boutons, libellés de filtres, « Sources lues ») passent par `esc_html__`.

## 5. Corrections d'exploitation

Toutes relevées le 2026-10-27 sur rsn.quebec. Chacune a son test.

1. **« Qui organise » des appels du RSN.** Les fiches de la source `rsn_appels` sortent `organise_par = ecosysteme`. Règle : `organise_par` vaut `rsn` quand la clé de source commence par `rsn:` ou `rsn_appels:`, ou quand l'organisateur canonique est « RSN » (`Donnees::construire`).
2. **Catégories TEC par slug nu.** Sur le site, les catégories ont les slugs `rsn`, `partenaires`, `ecosysteme`. `Donnees::organise_par()` reconnaît en plus ces slugs exacts (et `rsn-events`, `partner-events`, `digital-ecosystem` déjà couverts ou non par les noms). Le guide d'installation cesse de parler de `evenements-rsn` / `evenements-partenaires`.
3. **Liste « Page Veille ».** `PageReglages` liste aussi les pages privées (`post_status` publish, draft, private). Le guide dit qu'une page privée convient pour l'essai.
4. **Tri de la file.** Onglets « À approuver » et « À vérifier » : tri par arrivée décroissante. L'arrivée est l'horodatage de création du post (`post_date_gmt`), exposé sur la fiche par une nouvelle propriété `cree_a` (entier, secondes UTC) remplie par `EntrepotWordPress` à la lecture ; `cree_le` (le jour) ne suffit pas pour un délai en heures. « Publiées » et « Écartées » : tri par date d'événement comme avant. Une fiche dont `cree_a` a moins de 24 h porte un repère « Nouveau » dans « À approuver » et « À vérifier ».
5. **Image modifiable.** La collecte réécrit `image_url` à chaque passage quand la source fournit une image (`Depot::champs_factuels`) : une adresse saisie à la main ne tiendrait qu'une nuit. La seule image durable est donc l'**image mise en avant** de WordPress (`_thumbnail_id`), que la collecte ne touche jamais et que le type `rsn_veille` prend déjà en charge (`supports` contient `thumbnail`). Correction : (a) `EntrepotWordPress` remplit une nouvelle propriété `image_manuelle` de la fiche avec l'adresse du fichier de l'image mise en avant, s'il y en a une ; `image_url` reste l'image de la source ; (b) toute sortie publique (`Donnees`, donc page, JSON, iCal) montre `image_manuelle` quand elle existe, sinon `image_url` ; (c) une métabox « Image de la fiche », en colonne latérale de l'éditeur, montre l'image de la source (aperçu de `image_url`, ou « aucune ») et explique en une phrase : « Pour la remplacer, choisissez une image mise en avant : elle prime et la collecte ne l'écrase jamais. » Pas de champ texte, pas de nouvelle écriture dans `Depot`.
6. **Pages IVADO de redirection.** Quand la page d'un événement IVADO est une page de redirection (balise `<title>` ou `<h1>` contenant « Redirect », ou `meta http-equiv="refresh"`, ou `window.location` / `location.href` dans un script), `LecteurIvado` garde le titre lu dans la liste des événements, ne complète ni le résumé ni les autres champs à partir de cette page, et remplace l'adresse de la fiche par l'adresse cible quand elle figure dans la page (`refresh` ou `location`), sinon garde l'adresse IVADO. Pas de migration des fiches déjà en base : la collecte suivante relit la page et corrige l'adresse par la règle habituelle de `Depot`. Fixture : la page `https://ivado.ca/evenements/ia-en-sante-2026/` capturée en ligne au moment de la tâche et enregistrée dans `tests/fixtures/` (le dépôt n'en a pas encore).

## 6. Documentation et livraison

- `CHANGELOG.md` : section « 0.2.0 » avec la page et les six corrections ; `LISEZMOI.md` : description de la page mise à jour, limites connues relues.
- `docs/installation-et-bascule.md` : section 4 (page privée acceptée, nouvel attribut `une`), section 5 (la page remplace `/calendrier-evenements/` avec le raccourci `[rsn_veille]` : rien de nouveau, mais vérifier que la bande « À la une » déborde bien sur toute la largeur dans une section Elementor pleine largeur ; sinon `une="0"`), corrections 2 et 3.
- `tests/manuel/PARCOURS.md` : étapes de vérification de la nouvelle page (bande À la une avec un appel « Nouveau », dates limites, filtre Afficher, appel dans le calendrier, image changée par la métabox, tri de la file).
- Une PR vers `main`, « rsn-veille 0.2.0 : page Événements et appels, corrections d'exploitation », non fusionnée sans l'accord de Tess.

## 7. Ce qui est laissé au constructeur

Le découpage exact du JS (une fonction de rendu par bande), le détail des sélecteurs CSS à condition de reprendre la maquette, l'écriture des fixtures, la façon de tester le JS (au minimum `node --check` ; un test de rendu avec un DOM factice est bienvenu mais pas exigé), et l'ordre des tâches.

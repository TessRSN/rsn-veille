# Journal des modifications

## 0.5.1 (2026-10-28)

L'Agenda de l'écosystème (`[rsn_veille]`, en français et en anglais) : le bouton « S'abonner au calendrier » s'abonne vraiment, et un point « Comment ça marche » explique d'où viennent les événements (conception : `docs/superpowers/specs/2026-10-28-abonnement-info-0.5.1-design.md`, maquette : `docs/maquette/agenda-info-v1.html`). Aucune migration : rien à refaire dans les réglages, et les caches sont vidés au premier chargement de wp-admin avec la nouvelle version. Le fichier iCal lui-même (contenu, rythme) ne change pas, sauf le nom du calendrier.

### Abonnement au calendrier
- **Lien `webcal://`** : le bouton pointait vers `https://…/veille.ics` ; un clic téléchargeait le fichier, importé une fois, et les nouveaux événements n'arrivaient jamais. Il pointe maintenant vers l'adresse du calendrier de la langue de la page (`/veille.ics`, `/en/veille.ics` avec WPML) en `webcal://` : Calendrier (Mac, iPhone) et Outlook ouvrent un abonnement qui se met à jour.
- **Google Agenda**, qui ne comprend pas `webcal://` : sous les boutons, « Google Agenda : ajouter » ouvre la page d'ajout de Google (nouvel onglet, adresse encodée), et « Copier l'adresse » copie l'adresse `https://…/veille.ics` de la langue (retour « Adresse copiée » 2,5 s ; si le presse-papiers est refusé, le retour affiche l'adresse elle-même).
- **Nom du calendrier** (`X-WR-CALNAME`) : « Agenda de l'écosystème RSN » (« RSN ecosystem calendar » en anglais), au lieu de « Veille de l'écosystème RSN ». Le libellé du conteneur (`aria-label`) devient « Agenda de l'écosystème ».
- La disposition du bandeau : les boutons restent sur une ligne, la ligne « Google Agenda » dessous ; alignés à droite, à gauche sous 820 px et dans un conteneur de 760 px.

### Point « Comment ça marche »
- Un bouton « Comment ça marche » (« How it works ») au bout de l'intro du bandeau déplie, dans le bandeau, un court texte : l'agenda est tenu par une veille semi-automatique qui lit chaque nuit les sources structurées, l'équipe vérifie et complète à la main, et un organisme qui n'y figure pas peut proposer son événement. Deux liens : « Voir les sources lues ↓ » (défilement jusqu'à la table, sans animation si le mouvement est réduit, titre surligné un instant ; la section reçoit `id="sources"`) et « Proposer un événement » (ferme le panneau et ouvre le formulaire, comme le bouton jaune).
- Clic pour ouvrir ou fermer (ça marche au téléphone et au clavier) ; la croix ou la touche Échap (quand le focus est dans le panneau ou sur son bouton, pas ailleurs : la recherche garde son Échap) ferment et rendent le focus au bouton ; à l'ouverture, le focus va au titre du panneau. `aria-expanded` et `aria-controls` sur le bouton.
- Seulement dans la section « tout » : le panneau mène à la table des sources, qui n'existe que là. Avec `section="agenda"`, le bandeau n'a que l'abonnement et « Proposer un événement ».

### Livraison
- Numéro de version 0.5.1 (`rsn-veille.php`, `RSN_VEILLE_VERSION`) ; `LISEZMOI.md` et guide d'installation à jour (bandeau, version, section « Après la mise à jour de la 0.5.0 vers la 0.5.1») ; textes anglais ajoutés au `.po`, `.mo` recompilé.
- Bouchon de test `esc_url` : second paramètre (liste de protocoles) pris en charge, comme dans WordPress ; sans lui, le bouchon n'accepte toujours que http, https et mailto.
- Parcours de vérification manuelle (`tests/manuel/PARCOURS.md`, étape 33) et `tests/manuel/verifier-public.md` mis à jour (`?ver=0.5.1`).
- 664 tests PHPUnit ; le script (ouverture, fermeture, Échap, focus, copie y compris refus du presse-papiers, lien vers les sources) a été essayé dans un faux DOM, hors du dépôt. Le rendu réel et l'abonnement dans Calendrier, Outlook et Google ne sont vérifiables que sur rsn.quebec : le parcours manuel n'a pas encore été déroulé.

## 0.5.0 (2026-10-28)

La bande « Prochains événements » de l'accueil de rsn.quebec, en français et en anglais, devient un code court de l'extension : `[rsn_veille_accueil]` (conception : `docs/superpowers/specs/2026-10-28-bande-accueil-0.5-design.md`, maquette : variante A de `docs/maquette/accueil-v1.html`). Elle remplace `[rsn_events_cards limit="3"]` du thème enfant, qui reste disponible. Aucune migration : rien à refaire dans les réglages, et les caches de la page Veille, de l'accueil et de leurs traductions sont vidés au premier chargement de wp-admin avec la nouvelle version. **Le remplacement du code court est à faire par l'équipe dans Elementor**, sur l'accueil français (page 152) et sur sa traduction anglaise (page 1147) ; le guide d'installation, section 6, dit comment. Les clés du JSON public `/wp-json/rsn/v1/veille` sont inchangées.

### Bande de l'accueil
- **Trois colonnes** : un calendrier du mois, « Au RSN » et « Dans l'écosystème ». Sous 980 px, le calendrier passe au-dessus et les deux autres colonnes restent côte à côte ; sous 640 px, une colonne.
- **Calendrier du mois** : mois courant au départ, deux flèches pour changer de mois (pas avant le mois courant, pas au-delà du dernier mois qui a un événement), jours passés grisés, aujourd'hui entouré, un point jaune les jours qui ont un événement du RSN, un point marine ceux qui ont un événement de l'écosystème. Une série de plus de sept jours ne pointe que son premier jour (même règle que l'agenda). Cliquer un jour pointé affiche dans la colonne de droite tous les événements de ce jour, ceux du RSN compris, avec la pastille « RSN » sur leur ligne (« Le 4 novembre », sans limite et sans la règle d'un événement par organisateur) ; recliquer le jour ou le bouton « Tous » revient à la liste. Quand la liste par défaut est vide, la colonne dit « Aucun événement de l'écosystème à venir. ». Changer de mois défait le jour choisi.
- **Au RSN**, selon le nombre d'événements du RSN à venir : aucun, une phrase (« Pas d'événement du RSN à venir pour le moment… ») ; un, une grande carte avec son image ; deux, deux cartes compactes ; trois ou plus, les deux prochains en cartes compactes puis le lien « + N autres événements du RSN → » vers l'agenda de la langue de la page. Sans image, la carte montre une zone marine avec l'hexagone jaune du RSN.
- **Dans l'écosystème** : les quatre prochains événements qui ne sont pas du RSN, **un seul par organisateur** (le plus proche), sans série de plus de sept jours déjà commencée. Chaque ligne montre une tuile de date (la date du jour pour un événement en cours), le titre, l'organisateur, le format et « En anglais » / « In French » quand la langue de la fiche diffère de celle de la page.
- **Deux langues** : français ou anglais selon la langue WPML de la page (`Donnees::langue_courante()`), textes, dates, formats, titres et liens comme sur l'agenda. Les textes viennent du fichier `languages/rsn-veille-en_US.po` (et du `.mo` recompilé).
- **Date du jour** : la sortie du code court peut être servie par un cache (Elementor, LiteSpeed) un jour plus tard. Le script prend donc comme « aujourd'hui » la plus tardive de la date du serveur et de la date de Montréal du navigateur, entoure ce jour dans le calendrier et recalcule les colonnes « Au RSN » et « Dans l'écosystème » à partir du calendrier, avec les règles de `Accueil::selection` (qui reste la référence testée).
- **Pastilles de catégorie** : celles de l'ancienne bande (qui menaient aux archives de The Events Calendar) disparaissent.
- **Données et caches** : les événements viennent de `Donnees` (le même cache JSON par langue que la page Veille, une heure au plus). Le serveur choisit les événements et les met en JSON dans la page ; `assets/accueil.js` dessine la bande. `Collecte::purger_caches()` vide aussi LiteSpeed et le cache Elementor de la page d'accueil (`page_on_front`) et de ses traductions WPML : la bande suit donc la collecte de nuit, toute modification de fiche et la mise à jour de l'extension. Sans JavaScript, un lien vers l'agenda remplace la bande.
- **Ressources** : `assets/accueil.css` et `assets/accueil.js`, distincts de `veille.css` et `veille.js`, versionnés par `RSN_VEILLE_VERSION` (`?ver=0.5.0`). Le CSS se charge dans l'en-tête et le script en pied de page, sur la page d'accueil et sur toute page dont le contenu contient le code court ; sinon, au rendu du code court. Un code court posé dans un widget Elementor d'une autre page que l'accueil n'a donc ses ressources qu'au rendu ; cette page n'est pas purgée par la collecte, la bande y suit les caches de LiteSpeed et d'Elementor. Le filtre `rsn_veille_charger_accueil` force ou empêche le chargement en tête.

### Livraison
- Numéro de version 0.5.0 (`rsn-veille.php`, `RSN_VEILLE_VERSION`).
- `LISEZMOI.md`, guide d'installation (section 6 « Bande de l'accueil » ; les sections suivantes sont décalées d'un numéro), parcours de vérification manuelle (`tests/manuel/PARCOURS.md`, étape 7 bis) et `tests/manuel/verifier-public.md` mis à jour.
- 660 tests PHPUnit. Le rendu réel de la bande n'est vérifiable que sur rsn.quebec, une fois le code court remplacé dans Elementor : le parcours manuel n'a pas encore été déroulé.

## 0.4.0 (2026-10-27)

La page « Événements et appels » existe en anglais (conception : `docs/superpowers/specs/2026-10-27-page-anglaise-0.4-design.md`). Aucune migration : rien à refaire dans les réglages, et les caches du JSON public et de l'iCal, ceux de LiteSpeed et d'Elementor pour la page Veille et ses traductions, sont vidés au premier chargement de wp-admin avec la nouvelle version. **La traduction anglaise de la page est à créer dans WPML par l'équipe** (même code court `[rsn_veille]`, titre « Events and calls ») ; le guide d'installation, Temps 3, dit comment. Les clés du JSON public `/wp-json/rsn/v1/veille` sont inchangées, une clé `langue` s'y ajoute.

### Page publique en anglais
- **Textes de l'interface** : un fichier de traduction livré avec l'extension (`languages/rsn-veille-en_US.po` et `.mo`, 194 chaînes, chargé sur `init` par `load_plugin_textdomain`) traduit le bandeau, les bandes « À la une » et « Dates limites », les filtres, les en-têtes de mois, les pastilles, les boutons, le calendrier, les compteurs (pluriel anglais : « 0 events »), la table des sources, le formulaire et ses messages, et les messages d'erreur du script. Les noms des sources sont traduits à l'appel. Un test PHPUnit compare le `.po` aux chaînes du côté public et le `.mo` au `.po`.
- **Dates à l'anglaise** : « October 1, 2026 », « October 4–18, 2026 », « Nov. 5, 2026 », heure « 8:00 ». Les mois courts anglais sont « Jan. », « Feb. », « Mar. », « Apr. », « May », « June », « July », « Aug. », « Sept. », « Oct. », « Nov. », « Dec. ». En français, la seule différence : « 08 h 00 » s'écrit « 8 h 00 ».
- **Pastille de langue** : « In French » sur la page anglaise pour une fiche en français, « En anglais » sur la page française pour une fiche en anglais ; rien quand la fiche est dans la langue de la page. Digital Health Canada, déjà en anglais, n'a pas de pastille sur la page anglaise.
- **Langue de la page** : `Donnees::langue_courante()` la lit de WPML (`fr` ou `en`, `fr` sans WPML). Le conteneur de la page porte `lang`, le JSON porte `langue`, l'API accepte `?lang=fr|en` (ignoré sans WPML), et le calendrier `/en/veille.ics` suit la langue du préfixe (`/veille.ics` reste en français).
- **Formulaire** : un champ caché `langue` ; une proposition envoyée depuis la page anglaise est enregistrée avec `langue = en`.
- **Événements du RSN** : un seul article par groupe de traduction WPML (`EvenementsRsn`), dans la langue de la page, sinon en français : titre, résumé, lieu, lien vers `/en/evenement/…` et image. Le dédoublonnage avec les fiches collectées du site se fait par groupe de traduction (`trid:` ou `id:`) ; la clé par début et titre reste celle du titre français. Quand la fiche collectée l'emporte (pour son image), elle prend le titre, le lien et la langue de la version anglaise, et son résumé, son lieu et sa ville s'ils ne sont pas vides.
- **Appels du RSN** : traduits par WPML (l'identifiant de l'article vient du guid `?p=N` de la clé `rsn_appels:`, sinon de `url_to_postid`, puis `wpml_object_id`) : titre, extrait saisi de l'article traduit (`post_excerpt`, nettoyé et raccourci ; le résumé français est gardé s'il est vide), lien et catégorie (le nom lu sur l'article traduit, par exemple « Funding calls »). L'échéance ne change pas. Un appel sans traduction reste en français, marqué « In French ».
- **Mila et IVADO** : titre, résumé et lien anglais sur la fiche (champs `titre_en`, `resume_en`, `url_en`, jamais écrasés par une valeur vide, ni affichés ni modifiables dans l'administration), remplis à la collecte en appariant la page française et la page anglaise par leurs dates (`Appariement`). En pratique, IVADO ne lit la page anglaise d'un événement que si son titre anglais manque (ou si son adresse finale diffère), dans le même plafond de requêtes. Le Journal indique « N appariées en anglais, M orphelines » pour ces deux sources (une orpheline est un événement de la page anglaise sans équivalent français : il n'est jamais enregistré). Une fiche française sans équivalent anglais reste en français, marquée.
- **Retouches de la relecture finale** : le format (Présentiel, Virtuel, Hybride) est traduit sur les pastilles, les cartes et la bande « À la une » ; le formulaire répond dans la langue envoyée (`switch_to_locale('en_US')` le temps de la requête) ; l'API `?lang=` bascule la locale avec la langue de WPML ; le nom du calendrier iCal est traduit (« RSN ecosystem monitoring ») ; le texte d'une fiche dans l'autre langue que la page porte `lang` ; les mois des tuiles sont échappés ; un événement Mila ou IVADO non traduit, listé par la page anglaise sous son adresse française (IVADO : ou son identifiant français), n'est plus apparié à sa propre fiche ; l'erreur du flux anglais d'IVADO porte le préfixe « Version anglaise » ; quelques libellés anglais retouchés (« Show less », « Sources monitored », « Calls for proposals… », « Invalid start date », « Deadline to be announced »).
- **Autres sources** (Obvia, FRQ, RQRV, Réseau sommeil, CRM, CARES, Prix Hippocrate, propositions du public) : une seule langue, celle de la fiche.
- **Infolettre** : toujours en français (`PageInfolettre` force `fr`).

### Correction
- **Doublons anglais sur la page française** : la page française montrait aussi la version anglaise des événements du RSN, par exemple « RSN Scientific Days » à côté de « Journées scientifiques RSN » (la version anglaise portait même la langue `fr`). Un seul article par groupe de traduction est maintenant gardé, dans la langue de la page.

### Livraison
- Numéro de version 0.4.0 (`rsn-veille.php`, `RSN_VEILLE_VERSION`, donc `?ver=0.4.0` sur `veille.css` et `veille.js`).
- `LISEZMOI.md`, guide d'installation (Temps 3 réécrit : créer la traduction, purger LiteSpeed, vérifier la page anglaise) et parcours de vérification manuelle (`tests/manuel/PARCOURS.md`, `verifier-public.md`, `verifier-admin.md`) mis à jour.
- 625 tests PHPUnit. Le rendu réel de la page anglaise n'est vérifiable que sur rsn.quebec, une fois la traduction de la page créée dans WPML : le parcours manuel n'a pas encore été déroulé.

### Limites connues
Voir « Limites connues de la version 0.4.0 » dans `LISEZMOI.md`. Principales : l'administration, les courriels, l'infolettre et le Journal restent en français ; les thèmes et les organisateurs ne sont pas traduits ; seuls Mila et IVADO sont appariés ; une collecte lancée depuis l'administration en locale anglaise stockerait des noms de source en anglais dans les fiches (la collecte de nuit et l'administration de rsn.quebec sont en français).

## 0.3.0 (2026-10-27)

Retouches demandées après le premier passage de la 0.2.0 sur la page de test de rsn.quebec (maquette `docs/maquette/gabarit-v9.html`). Le reste de la page ne change pas. Aucune migration : rien à refaire dans les réglages, et la purge automatique des caches se fait au premier chargement de wp-admin. La page WordPress est à renommer « Événements et appels » par l'équipe (le titre est celui de la page, l'extension n'y touche pas). Les clés du JSON public `/wp-json/rsn/v1/veille` sont inchangées.

### Page publique
- **Bandeau continu** : le bandeau bleu de l'extension (l'intro et les boutons « S'abonner au calendrier » et « Proposer un événement ») remplace la barre d'actions et se place en tête du raccourci, du même bleu `#003469` que l'en-tête du thème. Sur le thème Vault (UiCore) de rsn.quebec, l'en-tête, l'intro et « À la une » se lisent comme un seul bloc, sans écart blanc, un filet fin séparant l'intro de « À la une ». Quand « À la une » n'est pas affichée (`une="0"`, `section="agenda"`, aucun élément du RSN à venir), le bandeau finit le bleu tout seul. Le formulaire « Proposer un événement » passe après « À la une », pour ne pas couper le bleu quand il s'ouvre.
- **Classe `rsn-veille-page`** : l'extension ajoute cette classe au `<body>` quand la page contient `[rsn_veille]`, est la page choisie dans les réglages ou l'une de ses traductions WPML. Sur le thème Vault, elle sert à rendre l'en-tête de page moins haut (remplissage de 44 px en haut et de 0 en bas, 30 px en haut et 0 en bas sous 820 px ; l'intro du bandeau suit le titre à 12 px), à aligner le bloc sur le titre et à laisser le titre prendre toute la largeur (donc sur une ligne sur écran large une fois la page renommée). Sans la classe, ou avec un autre thème, ces règles ne s'appliquent à rien. Elle vaut pour une page ordinaire (bloc « Code court ») ; sur une page Elementor, les marges des sections empêchent le bandeau de toucher l'en-tête.
- **« À la une » en `#003469`** (au lieu de `#07285A`), du même bleu que le bandeau et l'en-tête du thème.
- **Dates limites : des flèches** à la place de la barre de défilement (une flèche à gauche, une à droite, désactivées quand il n'y a plus rien à faire défiler ou quand tout tient ; défilement doux, sans animation si le visiteur demande moins de mouvement). Sous 820 px, les flèches se rangent à droite du libellé et les puces passent en dessous.
- **Fiches du RSN** : le corps de la fiche (image et texte, sans le bloc de date) est sur un fond gris très pâle (`#F6F8FA`) aux coins arrondis, avec un trait jaune de 4 px à gauche. Les autres fiches gardent leur aspect. Sur mobile, l'image reste au-dessus du texte, dans le fond gris.
- **Pastille « Appel »** : contour bleu marine sur fond blanc, plus de fond jaune (le jaune reste celui du RSN et des dates limites).
- **Appel sans date limite** : il reste visible **30 jours** après sa publication (90 jours auparavant), puis disparaît. Tant qu'il est visible, il se place à la date du jour dans la liste (après les événements d'aujourd'hui, et non plus en tête du mois) et porte la mention « Date limite à venir ». Cette règle (`Fiche::JOURS_APPEL_SANS_LIMITE`) est unique : **le JSON public, le bloc Appels de l'infolettre et le courriel hebdomadaire suivent la même règle** (l'iCal ne contient que des événements : il n'est pas touché), donc un appel sans date limite publié il y a plus de 30 jours n'est plus proposé nulle part.
- **Séries en cours** : un événement commencé et pas fini (par exemple la série de webinaires France-Québec du 4 septembre au 18 décembre) se place à la date du jour, sa tuile montre sa date de fin précédée d'une flèche (« →18 déc. », avec la mention « en cours ») et une pastille « En cours » suit la pastille de type. « À la une », le calendrier et la règle des séries de plus de 7 jours ne changent pas.
- Nouveaux textes, tous traduisibles (domaine `rsn-veille`) : « En cours », « en cours », « Date limite à venir », « Dates limites précédentes », « Dates limites suivantes ».

### Livraison
- Numéro de version 0.3.0 (`rsn-veille.php`, `RSN_VEILLE_VERSION`, donc `?ver=0.3.0` sur `veille.css` et `veille.js`).
- `LISEZMOI.md`, guide d'installation (marche à suivre après la mise à jour, section 4 : classe `rsn-veille-page`, structure de référence) et parcours de vérification manuelle (`tests/manuel/PARCOURS.md`) mis à jour.
- 540 tests PHPUnit. Le rendu réel (bandeau continu, hauteur de l'en-tête, flèches, fiches du RSN) n'est vérifiable que sur rsn.quebec : le parcours manuel de la section 7 n'a pas encore été déroulé dans un WordPress réel.

### Limites connues
Voir « Limites connues de la version 0.3.0 » dans `LISEZMOI.md`.

## 0.2.0 (2026-10-27)

La page publique devient la page « Événements et appels » (maquette `docs/maquette/gabarit-v8.html`) et six corrections d'exploitation, relevées à l'usage de la 0.1.0 sur rsn.quebec, sont faites. Les clés du JSON public `/wp-json/rsn/v1/veille` sont inchangées ; l'attribut `section`, le calendrier, le formulaire, l'iCal, les caches et WPML continuent de fonctionner comme en 0.1.0. Aucune migration : rien à refaire dans les réglages.

### Page publique
- Les onglets Agenda et Appels disparaissent : une seule liste, groupée par mois, mêle événements et appels (clé de tri : début d'un événement, date limite d'un appel). L'en-tête de mois indique ce que le mois contient, sans les compteurs à zéro (« 2 événements », « 1 appel » ou « 2 événements · 1 appel »).
- Bande « À la une du RSN » : les trois prochains éléments organisés par le RSN, événements à venir ou en cours et appels en cours **avec une date limite**, triés par date. Étiquettes : « Événement RSN », « Nouveau » (appel publié depuis 7 jours ou moins), « Ferme dans N jours » (21 jours ou moins avant la date limite, « Ferme demain », « Ferme aujourd'hui »), sinon « Date limite : … ». Le lien « Tout ce que le RSN organise et finance » applique le filtre Qui organise › RSN et mène à la liste. Son fond marine est peint jusqu'aux bords de la fenêtre par une ombre étalée rognée verticalement (aucune marge négative : la page ne s'élargit pas et n'a aucun défilement horizontal) ; sans élément du RSN, elle ne s'affiche pas. Le résumé d'un appel y est coupé à la première vraie fin de phrase.
- Bande « Dates limites » : une puce par appel en cours qui a une date limite, triées par date, avec l'organisateur.
- Un appel **sans date limite** reste visible dans la liste seulement : classé dans le mois courant, jamais « passé », absent de « À la une » et de « Dates limites » (la règle des 90 jours de la 0.1.0 est inchangée).
- Un événement court du RSN en cours (commencé, pas fini) reste à la une ; une série de plus de 7 jours (même règle que le calendrier) en sort dès qu'elle a commencé. Le lien « Tout ce que le RSN organise et finance » repasse en vue Liste.
- Filtres : nouvelle rubrique **Afficher** (Tout, Événements, Appels) et rubrique **Qui organise** (Tous, RSN, Partenaires, Écosystème) au-dessus du Mois, avec compteurs ; la recherche porte aussi sur les thèmes.
- Fiches : pastilles (Événement ou Appel, organisateur, format, « En anglais », thèmes) à la place de la ligne organisateur et de la ligne de thèmes en capitales ; l'hexagone de la frise est doré pour un élément du RSN.
- Calendrier : le titre du mois compte les éléments affichés (événements et appels : « N éléments »). Un appel apparaît le jour de sa date limite (puce jaune « Date limite · … »), et le détail du jour liste les événements puis les appels ; le filtre Afficher s'applique aussi au calendrier.
- Boutons « S'abonner au calendrier » (lien vers `/veille.ics`) et « Proposer un événement ».
- Nouvel attribut `une="0"` du raccourci : retire la bande « À la une ». Les valeurs de `section` restent `tout`, `agenda` (filtre Afficher verrouillé sur Événements, sans bande des dates limites) et `appels` (bande des dates limites puis appels seuls, triés par date limite).
- Mobile et colonnes étroites : les cartes « À la une » s'empilent, la bande des dates limites passe son libellé au-dessus des puces.
- Les nouveaux textes passent tous par la traduction de WordPress (domaine `rsn-veille`).

### Corrections d'exploitation
1. **Qui organise** : la **catégorie du site** décide quand elle est connue (« Événements partenaires » ou « Écosystème numérique » ne sont plus classés RSN, y compris pour les événements collectés depuis rsn.quebec : la catégorie est lue pour la source d'identifiant `rsn`, et non plus pour une source nommée « RSN »). Sans catégorie, les fiches des sources `rsn:` et `rsn_appels:`, et toute fiche dont l'organisateur canonique est « RSN », sont classées RSN (les appels du RSN sortaient « Écosystème »). Au dédoublonnage d'une fiche collectée et d'un événement de The Events Calendar, ce dernier fait foi pour « Qui organise » et la catégorie, même quand la fiche collectée est gardée pour son image.
2. **Catégories TEC par slug nu** : les catégories de The Events Calendar sont aussi reconnues par leurs slugs `rsn`, `partenaires` et `ecosysteme` (en plus de leurs noms français et anglais).
3. **Liste « Page Veille »** des réglages : elle propose aussi les pages privées (une page privée convient pour l'essai).
4. **Tri de la file** : « À approuver » et « À vérifier » sont triées par arrivée (jour `cree_le`, puis identifiant, du plus récent au plus ancien), avec un repère « Nouveau » pour une fiche arrivée aujourd'hui ou hier ; « Publiées » et « Écartées » restent triées par date d'événement.
5. **Image d'une fiche** : l'**image mise en avant** de WordPress prime sur l'image de la source (que la collecte réécrit chaque nuit) dans la page et le JSON public. Nouvelle propriété de fiche `image_manuelle` (lue, jamais écrite) et métabox « Image de la fiche » : elle montre l'image de la source et renvoie vers l'image mise en avant (elle n'a aucun champ).
6. **Pages IVADO de redirection** : quand la page d'un événement IVADO n'est qu'une redirection (titre ou `<h1>` « Redirect », `meta refresh`, ou affectation de `window.location` par une adresse), la fiche prend le titre de la balise `<title>` (sans le suffixe « | IVADO », et jamais un titre contenant « redirect »), reste sans résumé, et l'adresse cible (par exemple `event.fourwaves.com`) remplace l'adresse IVADO. Seules les fiches créées à partir de cette version en profitent : les fiches déjà en base ne sont pas relues (la collecte reprend leur adresse enregistrée) et gardent l'adresse IVADO, qui redirige elle-même le visiteur (pour corriger l'une d'elles : `wp post meta update <ID> rsn_veille_url <cible>`).
7. **Purge des caches à la mise à jour** : au premier chargement de wp-admin avec une autre version (option `rsn_veille_version`), l'extension vide le cache du JSON public, LiteSpeed et Elementor pour la page Veille. Au dédoublonnage, une fiche collectée qui a une image mise en avant l'emporte aussi sur l'événement de The Events Calendar.

### Livraison
- Numéro de version 0.2.0 (`rsn-veille.php`, `RSN_VEILLE_VERSION`, donc `?ver=0.2.0` sur `veille.css` et `veille.js`).
- Guide d'installation (section 4 : page privée, attribut `une`, fond de la bande ; catégories TEC par slug nu ; marche à suivre après la mise à jour), `LISEZMOI.md` et parcours de vérification manuelle (`tests/manuel/PARCOURS.md`) mis à jour.
- 534 tests PHPUnit. Le parcours manuel de la nouvelle page n'a pas encore été déroulé dans un WordPress réel : le fond de la bande (barre de défilement verticale) et l'éditeur Elementor restent à vérifier sur la copie de préproduction.

### Limites connues
Voir « Limites connues de la version 0.2.0 » dans `LISEZMOI.md`.

## 0.1.0 (2026-10-26)

Première version de l'extension.

### Collecte
- Type de contenu `rsn_veille` (fiches d'événements, d'appels et de nouvelles) et taxonomie « Thème ».
- Registre de 25 sources : 8 sources d'événements (The Events Calendar, RSS, pages HTML d'Obvia, Mila, FRQ et IVADO), 5 sources d'appels (flux RSS filtrés) et 12 flux de nouvelles réservés au Radar.
- Lecteurs TEC, RSS et Atom, iCal et HTML ; en-tête et début du corps vérifiés (une page HTML en code 200 n'est pas un flux), délai d'attente de 15 s, aucune protection anti-robot contournée.
- Règles de confiance par type de source, priorité des niveaux A, C, M, B, dédoublonnage et fusion, mise à jour des seuls champs factuels, drapeau « absent de la source » après deux collectes réussies, jamais de dépublication automatique.
- Collecte nocturne (3 h, heure de Montréal, modifiable) par tâches planifiées : une source à la fois, à chaque passage de WP-Cron (environ 5 minutes avec le cron du serveur), avec verrou et reprise ; journal par source.

### Administration (menu Veille)
- File d'approbation (à approuver, publiées, écartées, à vérifier), avec changement de statut sans rechargement.
- Radar des nouvelles : garder pour l'infolettre, ignorer, rafraîchir ; lecture source par source avec cache.
- Infolettre : blocs Agenda, Appels et Nouvelles gardées, à copier (collage enrichi ou code HTML) pour Mailchimp.
- Journal des collectes.
- Réglages : sources, niveaux, mots-clés et thèmes, rôles autorisés (capacité `rsn_veille_gerer`), heure de la collecte, page Veille, lancement manuel de la collecte, import des événements des infolettres (JSON).
- Courriel hebdomadaire optionnel, avec envoi d'essai.

### Page publique
- Raccourci `[rsn_veille]` (`section="agenda"` ou `section="appels"`) : agenda, calendrier, appels, filtres, événements passés, dans le style du site.
- Formulaire « Proposer un événement » (fiche « à approuver », image dans la médiathèque, champ piège, limite de débit, jeton).
- Sorties : `/wp-json/rsn/v1/veille` (JSON) et `/veille.ics` (calendrier iCal).
- Caches du JSON et de l'iCal par langue (WPML) ; purge du cache LiteSpeed et du cache de rendu Elementor de la page Veille à chaque écriture de fiche.

### Livraison
- `uninstall.php` : supprime options, transients, tâches planifiées et capacité ; garde les fiches et la taxonomie.
- Guide d'installation et de bascule (`docs/installation-et-bascule.md`), parcours de vérification manuelle (`tests/manuel/PARCOURS.md`).
- Exigences : PHP 8.0 ou plus (extensions `mbstring`, `dom`, `libxml`, `json`), WordPress 6.0 ou plus.

### Corrections après la revue finale
- Un appel sans date limite ni date de fin n'est plus « en cours » pour toujours : il est montré 90 jours après sa publication, puis retiré de la page, du JSON, de l'iCal, du bloc Appels de l'infolettre et du courriel ; les appels périmés ne sont plus envoyés au navigateur.
- La date limite d'un appel est aussi cherchée dans le contenu complet des flux RSS, et une date sans année est lue par rapport au jour de publication.
- La file d'approbation, la collecte, le courriel et la page publique ne chargent plus toute la base : compteurs sans charger les fiches, onglets « Publiées » et « Écartées » limités aux 200 fiches les plus récentes (avec une mention), seules les fiches de la source pour les absences.
- Le JSON de la page publique omet les événements finis depuis plus de 12 mois.
- Version anglaise (WPML) : la page Veille traduite est purgée elle aussi (LiteSpeed et Elementor), et ses ressources se chargent.
- Guide : régler `rsn_veille` et `rsn_theme` sur « Non traduisible » dans WPML.
- Courriel hebdomadaire : seules les fiches créées dans la semaine comptent ; les fiches plus anciennes de la file sont résumées en une ligne, et rien n'est envoyé si rien n'a été créé dans la semaine.
- Une fiche mise à la corbeille depuis l'éditeur compte comme écartée : la collecte ne la recrée plus et ne la republie plus.
- Une réponse de l'API des événements en plusieurs pages est signalée dans le Journal ; l'API du site RSN est demandée avec un paramètre anti-cache.
- Les réponses des sources sont plafonnées à 5 Mo.
- Les propositions du public reçoivent les thèmes des réglages et ne purgent plus aucun cache.
- Page Infolettre : l'état des programmes du RSN est lu par l'identifiant de l'article et d'après la clé `en-cours` du tableau rendu par la fonction `rsn_appel_etat` du site (sans quoi aucun programme n'était repris) ; une erreur de cette fonction ne casse plus la page.

### Vérification dans un vrai WordPress (wp-env, PHP 8.0)
- Parcours `tests/manuel/PARCOURS.md` déroulé de bout en bout (collecte réelle des 13 sources, file, page publique, formulaire, radar, infolettre, courriel, désinstallation) : aucune erreur PHP.
- Filtre Organisateur de la page : graphies fusionnées (« OBVIA » et « Obvia », « RSN » et « Réseau santé numérique »).
- « Qui organise » : une fiche collectée depuis le site du RSN est classée RSN, même sans catégorie TEC.
- Mise en page : le passage en une colonne suit la largeur du bloc (requête de conteneur), plus seulement celle de la fenêtre.
- Page Réglages : cadence réelle de la collecte (une source par passage de WP-Cron) au lieu de « deux minutes ».

### Limites connues
Voir « Limites connues de la version 0.1.0 » dans `LISEZMOI.md`.

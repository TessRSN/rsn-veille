# Parcours de vérification de bout en bout (version 0.6.2)

Ce parcours enchaîne, dans l'ordre d'un vrai usage, toutes les listes de vérification manuelles :
`verifier-admin.md` (administration), `verifier-public.md` (page publique, formulaire, REST et iCal) et
les deux scripts `verifier-cpt.php` et `lancer-collecte.php`. Il est à dérouler dans un WordPress réel dès
que Docker est installé (les tests PHPUnit n'utilisent qu'une imitation de WordPress). Chaque étape dit
quelle section ouvrir, dans quel ordre, et à quoi on reconnaît que l'étape est terminée. Les cases
détaillées ne sont pas recopiées ici : on les coche dans le fichier cité. **Noter chaque écart** dans le
tableau de la fin, puis le corriger ou le consigner.

Toutes les commandes `wp …` se lancent par `npx wp-env run cli wp …` (ou directement avec WP-CLI sur un
autre WordPress). Les chemins de scripts sont relatifs à `wp-content/plugins/rsn-veille/`.

## 0. Environnement

1. `npm`, Node et Docker installés ; dans le dépôt : `npx @wordpress/env start` (WordPress stable, PHP 8.2, extension
   montée depuis le dossier du dépôt), puis http://localhost:8888/wp-admin (`admin` / `password`, valeurs locales).
2. Dans le conteneur, activer le journal d'erreurs : `wp config set WP_DEBUG true --raw`, `wp config set WP_DEBUG_LOG true --raw`,
   `wp config set WP_DEBUG_DISPLAY false --raw`. Fuseau : `wp option update timezone_string America/Toronto`.
3. Optionnel mais recommandé pour la section « Page publique » : installer et activer The Events Calendar
   (`wp plugin install the-events-calendar --activate`), Elementor (`wp plugin install elementor --activate`) et
   LiteSpeed Cache. Pour le courriel : MailHog (conteneur `mailhog/mailhog`, SMTP 1025, interface 8025) et un
   `mu-plugin` qui règle PHPMailer sur `host.docker.internal:1025`. Sous wp-env le site est servi sur `localhost` :
   PHPMailer refuse alors l'expéditeur `wordpress@localhost` (domaine sans point) et `wp_mail` rend `false`. Le même
   `mu-plugin` doit donc aussi filtrer `wp_mail_from` vers une adresse avec un vrai domaine (par exemple
   `veille@exemple.test`). Sur rsn.quebec, l'expéditeur `wordpress@rsn.quebec` est valide sans rien faire.
4. **Conserver `wp-content/debug.log` ouvert** pendant tout le parcours : toute erreur PHP est un écart.

**Terminé quand** : le site local répond, l'extension est active, `debug.log` est vide.

## 1. Type de contenu et entrepôt WordPress

`wp eval-file wp-content/plugins/rsn-veille/tests/manuel/verifier-cpt.php`

**Terminé quand** : cinq lignes « OK » s'affichent et la fiche de test a disparu
(`wp post list --post_type=rsn_veille --post_status=any` ne la montre pas).

## 2. Activation

Fichier `verifier-admin.md`, section **Préparation**, points 1 à 3, et fichier `verifier-public.md`, section
**Préparation**, point 1 :

- désactiver puis réactiver l'extension ; `wp cron event list` montre `rsn_veille_nuit` (une seule fois) ;
- la capacité `rsn_veille_gerer` est donnée à `administrator` et `editor`, pas à `author` ni à `subscriber` ;
- créer les comptes `redaction` (éditeur) et `lecteur` (abonné) ;
- `wp rewrite list --match=/veille.ics` affiche la règle ;
- **désactivation complète** : `wp plugin deactivate rsn-veille` retire `rsn_veille_nuit` (et
  `rsn_veille_source`, `rsn_veille_hebdo` s'ils existaient) et la règle `/veille.ics` ; réactiver ensuite.

**Terminé quand** : menu Veille visible pour `admin` et `redaction`, absent pour `lecteur`.

## 3. Réglages (avant la collecte)

Fichier `verifier-admin.md`, section **Réglages (tâche 12)**, **toutes les cases sauf** « Sources › Lancer la
collecte maintenant » (étape 4) et « Import » (étape 5). Cela couvre : accès éditeur/abonné, état de la collecte,
avertissement `DISABLE_WP_CRON`, sources décochées, niveau, mots-clés bordés, thèmes, valeurs invalides, rôles,
heure de collecte (replanification), choix de la page Veille (créer d'abord une page vide), jeton expiré, mobile.
En plus : **la liste « Page Veille » propose aussi les pages privées** (créer une page en visibilité « Privée » et
une en brouillon : les deux apparaissent dans la liste ; la choisir et enregistrer ne la perd pas au rechargement).
Dans la même étape, la case **Rôles** de la section Vérifications (« Réglage des rôles ») et les cases
« Éditeur », « Abonné » et « Les fichiers `admin.css` et `admin.js` ne sont chargés que sur les pages Veille ».

**Terminé quand** : aucune case de cette section ne reste décochée, sauf les deux renvoyées plus loin.

## 4. Collecte

1. `wp eval-file wp-content/plugins/rsn-veille/tests/manuel/lancer-collecte.php` : une ligne par source,
   plus de 60 fiches en attente et publiées (le script traite les 13 sources en boucle, sans attendre WP-Cron).
2. Ensuite, la case « Sources › **Lancer la collecte maintenant** » de la section Réglages (message « Collecte
   lancée : N sources en file », tâche `rsn_veille_source` échue immédiatement, aucune ligne pour une source décochée),
   puis laisser WP-Cron (`wp cron event run --due-now`, répété) vider la file.
3. `verifier-admin.md`, case **Journal** : « Dernière collecte terminée », une ligne par source, erreurs repliées.

4. Si l'événement « IA en santé 2026 » est encore dans la source IVADO et que sa fiche est **nouvelle** (les fiches
   déjà en base ne sont pas relues : sur un site déjà collecté, supprimer la fiche avec
   `wp post delete <ID> --force`, puis relancer la collecte) : elle a pour titre le texte de la balise `<title>` de la
   page (« IA en santé 2026 », sans « | IVADO »), aucun résumé, et pour adresse la cible
   (`https://event.fourwaves.com/aihealthsymposium`) et non l'adresse `ivado.ca` de la page de redirection
   (`wp post meta get <ID> rsn_veille_url`). Une fiche IVADO plus ancienne garde son adresse `ivado.ca`, qui redirige
   elle-même le visiteur. Les autres fiches IVADO gardent leur adresse `ivado.ca` et leur résumé complété.

5. **Mila et IVADO en anglais** (version 0.4.0) : dans le Journal, les lignes de Mila et d'IVADO portent « N appariées en
   anglais, M orphelines » (« orphelines » compte les événements de la page anglaise sans équivalent français ; une
   fiche française sans page anglaise n'est pas comptée). Sur les pages lues le 27 octobre : Mila 3 appariées, IVADO 6,
   aucune orpheline ; ce relevé date de ce jour-là, et un écart de compte n'est pas un défaut de l'extension. Une
   erreur de lecture de la page anglaise est consignée sans faire échouer la source. Les fiches déjà en base
   reçoivent leurs champs anglais à ce passage (`wp post meta get <ID> rsn_veille_titre_en`). Le résultat sur la
   page est vérifié à l'étape 7, point 29.

**Terminé quand** : le Journal montre les 13 sources, au plus quelques erreurs de réseau expliquées (un 403 est
une erreur consignée, pas un défaut), et la file `rsn_veille_file` est vide.

## 5. Import des événements des infolettres

`verifier-admin.md`, section Réglages : les trois cases **Import**, **Une fiche importée** et **Import refusé**
(24 lus, aucun doublon, réimport sans création, fichier `.csv`, fichier trop gros, JSON illisible). À faire
**après** la collecte du RSN, pour que des fusions se produisent.

**Terminé quand** : « 0 créé, 0 fusionné, 24 inchangés » au second import.

## 6. File d'approbation

`verifier-admin.md`, section **Préparation** point 4 (trois fiches de vérification, si la collecte n'a pas
donné assez de fiches à approuver), puis section **Vérifications** : titre `<script>` en texte, onglet
« À approuver » (**Publier**, **Écarter**), onglet « Publiées » (**Retirer**), onglet « Écartées » (**Publier**),
filtres, onglet « À vérifier » (**Garder publiée**, **Retirer**), jeton expiré, largeur mobile.
**Tri et repère « Nouveau »** (créer au moins deux fiches à approuver à des jours d'arrivée différents :
`wp post meta update <ID> rsn_veille_cree_le AAAA-MM-JJ`, avec la date du jour, celle d'hier et une date plus
ancienne ; l'une doit aussi avoir une date d'événement plus lointaine que les autres) :
- « À approuver » et « À vérifier » sont triés par jour d'arrivée décroissant, pas par date d'événement : la fiche
  arrivée aujourd'hui est en tête même si son événement est le plus lointain ;
- le repère jaune « Nouveau » suit le titre des fiches arrivées aujourd'hui ou hier, et seulement celles-là, dans
  ces deux onglets ; il n'existe pas dans « Publiées » ni dans « Écartées », qui restent triés par date d'événement ;
- une fiche sans `rsn_veille_cree_le` (`wp post meta delete <ID> rsn_veille_cree_le`) prend, à la lecture, le jour de
  son `post_date` (qui suit la date de l'événement) : elle se range selon ce jour et n'a le repère « Nouveau » que si
  ce jour est aujourd'hui ou hier.

**Image de la fiche** (ouvrir une fiche dans l'éditeur WordPress) :
- la boîte « Image de la fiche » est dans la colonne latérale ; elle montre l'image de la source (ou « Aucune image
  de la source ») et dit : « Pour la remplacer, choisissez une image mise en avant : elle prime et la collecte ne
  l'écrase jamais. » Elle n'a aucun champ à remplir ;
- choisir une **image mise en avant**, mettre à jour la fiche : la page publique (étape 7) et le JSON
  (`/wp-json/rsn/v1/veille`, clé `image_url` de la fiche) montrent l'image mise en avant ; lancer la collecte de la
  source de cette fiche (étape 4) : l'image mise en avant ne change pas ; retirer l'image mise en avant : la page
  revient à l'image de la source.

Contrôler au passage la purge : après un changement de statut, `wp post meta get <page> _elementor_element_cache`
est vide pour la page Veille choisie.

**Terminé quand** : toutes les cases de « Vérifications » sont cochées, y compris « Aucune erreur PHP dans
`debug.log` ni dans la console du navigateur ».

## 7. Page publique (page « Événements et appels »)

1. `verifier-public.md`, section **Préparation**, points 2 à 4 (fiches publiées, à approuver, passées, avec image,
   avec échéance ; événements TEC ; page « Veille » contenant `[rsn_veille]`, bloc Code court puis widget
   Code court d'Elementor, choisie dans Veille › Réglages). Pour essayer la page sans la publier, la mettre en
   visibilité « Privée » (étape 3) et la regarder connecté.
2. Section **Page publique** de `verifier-public.md` : bandeau, bandes, liste unifiée, filtres, calendrier,
   événements passés, style de la maquette v9, absence du mode équipe, titre piégé en texte, mobile,
   `section="agenda"`, `section="appels"` et `une="0"`.
3. Jeu de fiches pour les étapes suivantes (à créer dans la file, publiées, avec `wp post meta update` ou l'éditeur ; pour un
   appel, la date de publication est `rsn_veille_debut` et la date limite `rsn_veille_echeance` ; « du RSN » veut dire `rsn_veille_organisateur` = `RSN`) :
   - un **appel du RSN** publié il y a 3 jours, avec une date limite dans 30 jours (étiquette « Nouveau ») ;
   - un **appel du RSN** publié il y a 40 jours, avec une date limite dans 10 jours (« Ferme dans 10 jours ») ;
   - un **appel du RSN** publié il y a 40 jours, avec une date limite dans 60 jours (« Date limite : … ») ;
   - un **appel d'un autre organisateur** (Obvia, par exemple) avec une date limite dans 20 jours ;
   - un **appel du RSN sans aucune date limite** (ni `rsn_veille_echeance` ni date de fin), publié il y a 5 jours ;
   - un **événement du RSN commencé hier et qui finit demain** ;
   - un **événement du RSN daté d'aujourd'hui** (le point 19 vérifie la place de l'appel « après les événements d'aujourd'hui ») ;
   - une **série du RSN de plus de 7 jours, commencée hier** (webinaires, par exemple) ;
   - un événement du RSN dans 90 jours (au-delà des autres éléments de la bande), un événement de partenaire et un
     événement de l'écosystème ; un **événement de rsn.quebec classé « Événements partenaires »** dans The Events
     Calendar (collecté par la source « RSN : événements » et présent aussi dans le calendrier du site).
   Purger le cache de la page (`wp transient delete rsn_veille_json_fr`) après chaque changement de fiche.
4. **Bande « À la une du RSN »** : trois cartes au plus, que des éléments du RSN, triées par date (la tuile jaune
   donne le jour et le mois) ; l'appel « Nouveau » porte l'étiquette rouge « Nouveau » ; l'appel dont la date limite
   est dans 10 jours porte « Ferme dans 10 jours » (« Ferme demain » à 1 jour, « Ferme aujourd'hui » à 0, et
   « Date limite : … » au-delà de 21 jours : la bande ne garde que les trois éléments les plus proches ; pour voir
   cette dernière étiquette, repousser temporairement la date limite de l'appel de 10 jours à 40 jours) ; **l'événement du RSN commencé hier et qui finit demain est à la une**
   (il compte parmi les trois cartes, avec son jour de début, étiquette « Événement RSN ») ; **la série de plus de
   7 jours commencée hier n'y est pas** (elle reste dans la liste) ; l'événement classé « Événements partenaires »
   n'y est pas non plus ; l'appel d'un autre organisateur n'y est pas ;
   **l'appel du RSN sans date limite n'y est pas**. Cliquer « Tout ce que le RSN organise et finance → » : la page
   bascule en vue Liste (même depuis le calendrier) et défile jusqu'à la liste avec « Qui organise › RSN » actif. Sans aucun élément du RSN à venir, la bande disparaît.
5. **Bande « Dates limites »** : une puce par appel en cours qui a une date limite (les quatre appels à date
   limite), triées par date, avec l'organisateur (« RSN » en jaune) ; **l'appel sans date limite n'y est pas** ; la
   rangée défile avec les flèches (point 17) si elle dépasse. Sans appel à date limite, la bande disparaît.
6. **Appel sans date limite** : il est dans la liste, **seulement** (ni dans « À la une », ni dans « Dates limites »),
   placé dans le **mois courant**, à la date du jour (point 19), et jamais dans « Événements passés ».
7. **Filtre Afficher** : Tout / Événements / Appels, avec les compteurs ; « Appels » ne montre que les appels (liste,
   compteur de la barre « N appels », calendrier) ; « Événements » que les événements. Le filtre **Qui organise**
   (rubrique, comme Mois) : l'appel du RSN est compté et listé sous RSN (et non sous Écosystème) ; les compteurs
   croisent les deux filtres ; Réinitialiser remet tout à « Tout » et « Tous ».
8. **Liste unifiée** : événements et appels mêlés par mois, en-tête qui n'affiche que les compteurs non nuls (« 2 événements », « 1 appel » ou « 2 événements · 1 appel ») ; pastilles de
   type, d'organisateur, de format, de langue et de thèmes ; hexagone doré pour un élément du RSN.
9. **Calendrier** : l'appel apparaît le jour de sa date limite, en puce jaune « Date limite · … » ; cliquer le jour :
   les événements du jour puis les appels de ce jour ; le titre du mois compte événements **et** appels affichés
   (« N éléments ») ; la série de plus de 7 jours ne figure qu'à son premier jour ; la légende contient « Date limite d'un appel ». Avec
   « Afficher › Appels », seuls les appels restent dans la grille.
10. **Attributs** : `[rsn_veille une="0"]` : pas de bande « À la une », le reste intact ;
    `[rsn_veille section="appels"]` : bande « Dates limites » puis les appels seuls, triés par date limite (sans date
    limite en dernier), sans bande « À la une » ni colonne de filtres ; `[rsn_veille section="agenda"]` : événements
    seuls, filtre Afficher absent, ni « À la une » ni « Dates limites ».
11. **Fond de la bande dans le thème** (page Elementor, widget Code court, dans une section pleine largeur puis dans une
    section à largeur limitée ; sur une telle page le bandeau ne touche pas l'en-tête du thème, c'est attendu : voir
    le point 16) : le fond marine de « À la une » va d'un bord à l'autre de la fenêtre alors que les
    cartes restent alignées sur le contenu de la page (1140 px au plus). Contrôler **avec une barre de défilement
    verticale visible** (Windows, ou macOS avec « Toujours afficher les barres de défilement ») :
    `document.documentElement.scrollWidth === document.documentElement.clientWidth` dans la console du navigateur
    (la bande ne doit causer aucun défilement horizontal). Consigner tout écart dans le tableau des écarts.
    Vérifier aussi à 375 px (cartes empilées).
12. Section **Ressources et cohabitation** de `verifier-public.md` : ressources seulement sur la page Veille
    (chargées avec `?ver=0.6.2`), polices, Elementor, cache (publier une fiche puis recharger hors connexion), WPML
    si installé (la page anglaise a ses propres points, 24 à 32).
13. Section **REST et iCal** : `/wp-json/rsn/v1/veille` (aucune clé retirée), `/veille.ics` (CRLF, en-têtes,
    validateur iCal, abonnement dans un agenda).
14. **Qui organise des catégories TEC** : une catégorie TEC de slug `rsn` classe ses événements sous RSN, `partenaires`
    sous Partenaires, `ecosysteme` (ou toute autre) sous Écosystème ; renommer la catégorie sans changer le slug ne
    change rien. Un événement de rsn.quebec classé « Événements partenaires » ou « Écosystème numérique » sort sous
    Partenaires ou Écosystème, y compris quand la fiche collectée et l'événement du calendrier du site sont fusionnés,
    et il n'est pas à la une du RSN.
15. **Purge à la mise à jour** : `wp option delete rsn_veille_version`, puis charger une page de wp-admin ; le transient
    `rsn_veille_json_fr` a disparu (`wp transient get rsn_veille_json_fr` ne rend rien) et
    `wp option get rsn_veille_version` rend `0.6.2`. Un second chargement ne purge plus.
16. **Bandeau continu** (version 0.3.0 ; exige le thème Vault, absent de wp-env : à faire sur rsn.quebec, sur la page
    ordinaire avec un bloc Code court, renommée « Événements et appels », fenêtre large) :
    - aucun écart blanc entre l'en-tête du thème (fil d'Ariane et titre) et l'intro du bandeau, ni entre l'intro, les
      boutons et « À la une » : le même bleu `#003469` de haut en bas, avec un filet fin entre l'intro et « À la une » ;
    - le `<body>` porte la classe `rsn-veille-page` (inspecter l'élément) ; elle n'est pas posée sur une autre page du
      site ;
    - l'en-tête du thème est moins haut qu'avant (remplissage d'environ 44 px au-dessus du fil d'Ariane et 0 sous le titre ; sous
      820 px, 30 px en haut et 0 en bas), l'intro suit le titre à environ 12 px, et le titre prend toute la largeur :
      « Événements et appels » tient sur une ligne sur écran large ;
    - le bord gauche de l'intro est aligné sur celui du titre ;
    - aucun filet clair aux raccords en-tête/bandeau et bandeau/« À la une » (zoomer à 100 % et à 200 %) ;
    - aucun défilement horizontal : `document.documentElement.scrollWidth === document.documentElement.clientWidth` ;
    - quand « À la une » est absente (`une="0"`, ou aucun élément du RSN à venir), le bandeau finit le bleu tout seul ;
    - sur une page Elementor (widget Code court), ou sur un autre thème, le bandeau garde son bleu et se place sous
      l'en-tête ; l'écart avec l'en-tête y est attendu (limite connue).
17. **Flèches des dates limites** : plus de barre de défilement sous les puces. Au chargement, la flèche de gauche est
    désactivée (et la flèche de droite active si les puces dépassent) ; un clic sur la flèche de droite fait défiler
    d'environ 70 % de la largeur visible ; à la dernière puce, la flèche de droite se désactive et celle de gauche
    s'active. Quand toutes les puces tiennent (ajuster le nombre d'appels ou élargir la fenêtre), les deux flèches
    sont désactivées. Au clavier : Tab atteint chaque flèche, Entrée ou Espace la déclenche, la flèche désactivée n'a pas
    le focus ; chaque flèche a un nom accessible (« Dates limites précédentes », « Dates limites suivantes »). Avec
    « Réduire les animations » activé dans le système, le défilement se fait sans animation. Redimensionner la fenêtre
    met les états à jour.
18. **Fiches du RSN** : le corps de la fiche (image et texte, pas le bloc de date) est sur un fond gris très pâle
    (`#F6F8FA`) aux coins arrondis, avec un trait jaune de 4 px à gauche ; le bloc de date et l'hexagone restent hors du
    fond. Contrôler une fiche RSN **avec image** (l'image est collée au bord gauche du fond, coins arrondis seulement à
    l'extérieur) et une fiche RSN **sans image** (texte seul sur le fond) ; une fiche d'un partenaire n'a ni fond ni
    trait. Les lignes compactes d'« Événements passés » ont le même fond pour une fiche du RSN.
19. **Appel sans date limite** : l'appel du RSN publié il y a 5 jours (point 3) est placé **après les événements
    d'aujourd'hui** dans le mois courant (et non en tête du mois), avec une tuile grise « publié » et la
    pastille « Date limite à venir ». Il disparaît 30 jours après sa publication : en changer la date de publication
    (`wp post meta update <ID> rsn_veille_debut AAAA-MM-JJ`, avec le jour d'il y a 29 puis 31 jours) et purger le cache
    (`wp transient delete rsn_veille_json_fr`) ; à 31 jours, il n'est plus sur la page, ni dans `/wp-json/rsn/v1/veille`,
    ni dans le bloc Appels de l'infolettre ni dans le courriel d'essai.
20. **Série en cours** : la série du RSN commencée hier (point 3) est placée à la date du jour, sa tuile montre sa
    date de fin précédée d'une flèche (« →jj mois ») avec la mention « en cours », et une pastille « En cours » suit la
    pastille de type ; elle n'est pas à la une. L'événement du RSN commencé hier et qui finit demain porte aussi la
    tuile « →jj mois » et la pastille « En cours » dans la liste, mais **reste à la une** avec son jour de début.
21. **Pastille « Appel »** : contour bleu marine sur fond blanc (plus de fond jaune), à côté d'« Événement » en bleu plein ;
    la pastille « RSN » reste jaune et ne se confond plus avec elle.
22. **375 px** (mobile ; exige le thème Vault, absent de wp-env : à faire sur rsn.quebec, avec une fenêtre de 375 px ou l'émulation des outils du navigateur) : le bandeau passe l'intro puis les
    boutons sur des lignes séparées, sans défilement horizontal ; les flèches des dates limites sont à droite du libellé
    « Dates limites » et les puces dessous, sur toute la largeur ; dans une fiche RSN avec image, l'image est
    **au-dessus du texte, à l'intérieur du fond gris**, avec le trait jaune à gauche ; l'en-tête du thème est resserré.
23. **`section="agenda"`** : le bandeau est là, sans la bande des dates limites ; **`une="0"`** : le bandeau seul en tête
    (pas de « À la une », pas de filet), le reste intact ; **`section="appels"`** : pas de bandeau.
24. **Page anglaise créée** (version 0.4.0 ; exige WPML, absent de wp-env : à faire sur rsn.quebec) : dans WPML, la
    page « Événements et appels » a une traduction anglaise, titre « Events and calls », contenu `[rsn_veille]` (hors
    menu pour l'essai). Après la mise à jour : LiteSpeed › Purger tout. `/en/…` répond 200, le `<body>` porte la
    classe `rsn-veille-page`, `div.rsn-veille` porte `lang="en"` (la page française, `lang="fr"`), et
    `wp transient get rsn_veille_json_en` se remplit à la première visite (distinct de `rsn_veille_json_fr`).
25. **Textes et dates en anglais** : bandeau (« Subscribe to the calendar », « Make a suggestion »), « Featured by the
    RSN », « Deadlines », filtres (« Show », « Who organizes », « Month », « Format », « Location », « Themes »,
    « Organizer », « Reset filters »), pastilles (« Event », « Call », « Ongoing », « Deadline to be announced », « New »,
    « Closes in N days »), boutons (« More information », « View the call »), calendrier (jours, légende,
    « Today »), compteurs (« 12 events and 3 calls to come » ; « 0 events » au pluriel), table des sources, formulaire
    et messages d'erreur du script. Dates : « October 1, 2026 », « October 4–18, 2026 », « Nov. 5, 2026 », heure
    « 8:00 », tuiles aux mois courts anglais (« Jan. », « Feb. », « Sept. »…). Le format (« In person », « Virtual »,
    « Hybrid ») est traduit sur les pastilles, les cartes et la bande « À la une ». Thèmes et organisateurs restent en
    français (limite connue). Le nom du calendrier `/en/veille.ics` est « RSN ecosystem calendar » (« Agenda de l'écosystème RSN » pour `/veille.ics`). **Page française** : aucun texte changé, sauf l'heure (« 8 h 00 » et non « 08 h 00 »).
26. **Événements du RSN en anglais** : un événement du RSN dont la traduction anglaise existe y paraît une seule
    fois, avec le titre, le résumé, le lieu et le lien `/en/evenement/…` de la version anglaise, l'image de la
    fiche, et sans pastille de langue ; l'événement collecté depuis rsn.quebec (fiche avec image) ne fait pas de
    doublon avec lui. Un événement sans traduction anglaise paraît en français avec la pastille « In French ».
27. **Appels du RSN en anglais** : un appel du RSN dont l'article a une traduction WPML y paraît avec le titre,
    l'extrait, le lien `/en/…` et la catégorie de la traduction (le nom lu sur l'article, par exemple « Funding calls »), et la même date limite (dans
    « Deadlines » aussi). Un appel dont l'extrait traduit est vide garde son résumé français sous le titre anglais.
28. **Fiche sans traduction, pastille** : une fiche française d'une autre source (Obvia, FRQ, RQRV, Réseau sommeil…)
    est inchangée sur la page anglaise et porte « In French » ; Digital Health Canada n'a pas de pastille. Sur la page
    française, une fiche anglaise (Digital Health Canada ; une proposition envoyée depuis la page anglaise, voir l'étape 8)
    porte « En anglais » et une fiche française n'a rien.
29. **Mila et IVADO appariés** : sur la page anglaise, leurs événements ont titre, résumé et lien anglais et pas de
    pastille ; sur la page française, rien n'a changé. Contrôler dans le JSON
    (`curl -s 'https://…/wp-json/rsn/v1/veille?lang=en'`) : ces fiches ont `langue` à `en`, un titre anglais et une
    adresse en général `mila.quebec/en/…` ou `ivado.ca/en/…` (une redirection vers un site externe est possible), alors que `?lang=fr` rend les mêmes fiches en français. Une
    fiche française sans équivalent anglais reste en français avec « In French ». Les champs anglais n'apparaissent pas
    dans l'éditeur d'une fiche.
30. **iCal anglais** : `/en/veille.ics` répond en 200 (CRLF, `Content-Type: text/calendar`), avec les titres et les
    liens anglais des événements du RSN ; `/veille.ics` reste en français. Le bouton « Subscribe to the calendar »
    de la page anglaise pointe vers `/en/veille.ics` (en `webcal://`, voir l'étape 33).
31. **Page française sans doublon anglais** : « RSN Scientific Days » n'est plus à côté de « Journées scientifiques
    RSN » (30 mars 2026), et aucun événement du RSN n'y paraît en double ; le JSON français (`?lang=fr`) n'a plus
    d'événement du RSN en `langue` `en`.
32. **JSON et API** : `/wp-json/rsn/v1/veille` porte `langue` (`fr` hors préfixe), `?lang=en` rend le contenu anglais et
    `?lang=fr` le contenu français depuis n'importe quelle adresse ; une valeur autre (`?lang=de`) est ignorée ; le
    changement de langue d'une requête n'affecte pas la page servie ensuite. L'infolettre (Veille › Infolettre)
    reste en français, même administrateur connecté en locale anglaise.
33. **Abonnement au calendrier et point « Comment ça marche »** (version 0.5.1), sur la page française puis sur la page
    anglaise (exiger un navigateur de bureau et, si possible, un iPhone) :
    - *Abonnement Apple / Outlook* : « S'abonner au calendrier » (« Subscribe to the calendar ») ne télécharge plus de
      fichier : le lien commence par `webcal://` (`…/veille.ics`, `…/en/veille.ics` en anglais) et ouvre Calendrier (Mac,
      iPhone) ou Outlook sur une demande d'abonnement. Le calendrier s'appelle « Agenda de l'écosystème RSN » (« RSN
      ecosystem calendar »), et de nouveaux événements y arrivent sans nouvelle importation.
    - *Google Agenda* : sous les boutons, « Google Agenda : ajouter » (« Google Calendar: add ») s'ouvre dans un nouvel
      onglet sur la page d'ajout de Google, avec l'adresse du calendrier de la langue déjà remplie.
    - *Copie* : « Copier l'adresse » (« Copy the address ») affiche « Adresse copiée » (« Address copied ») environ 2,5 s ;
      coller donne `https://…/veille.ics` (`/en/` en anglais). Pour voir le repli, refuser la permission du presse-papiers
      (sur wp-env, `http://localhost` est un contexte sûr : la copie y marche) : le retour montre alors l'adresse elle-même, et elle
      y reste jusqu'au clic suivant (elle se sélectionne).
    - *Point d'information* : « Comment ça marche » (« How it works »), sur sa propre ligne sous l'intro du bandeau, sans cadre et aligné sur le début du paragraphe (0.5.2), déplie un panneau
      dans le bandeau (texte, trois puces, deux liens) ; le focus va au titre du panneau. La croix et la touche Échap (le focus étant dans le panneau
      ou sur le bouton ; Échap dans la recherche ne ferme rien et ne déplace pas le focus) le
      ferment et rendent le focus au bouton (le clavier : Tab, Entrée ; Maj + Tab passe à la croix, puis aux commandes d'abonnement).
    - *Liens du panneau* : « Voir les sources lues ↓ » (« See the sources we read ↓ ») fait défiler jusqu'à « Sources lues »
      (sans animation avec « réduire les animations » activé dans le système) et surligne son titre un instant ;
      « Proposer un événement ou un appel » (« Suggest an event or a call », depuis la 0.6.0) ferme le panneau et ouvre le formulaire comme le bouton jaune.
    - *Autres sections* : sur une page avec `[rsn_veille section="agenda"]`, le point d'information et le panneau n'existent
      pas, l'abonnement (bouton, Google, copie) si ; sur `section="appels"`, pas de bandeau.
    - *Mise en page* : à droite du bandeau sur ordinateur, à gauche sous 820 px (essayer 375 px) ; aucun défilement
      horizontal ; le panneau n'est pas coupé par le fond bleu ; contour blanc au clavier.

34. **Événement ou appel** (version 0.6.0), d'abord en français puis en anglais (WPML) :
    - *Corriger une fiche en appel* : Veille › File d'approbation › **Modifier** sur la fiche de test. L'encadré
      « Détails de la fiche » est sous le texte, avec l'origine et l'état à droite du titre. Choisir **Appel** :
      les champs de l'événement se masquent, la date limite, la catégorie et la date de publication apparaissent
      (la date limite reprend la fin si elle était vide). Renseigner une date limite dans le futur, enregistrer :
      la fiche est un appel sur la page Veille (pastille « Appel », date limite, bande « Dates limites »). Les champs
      changés (ici la date limite) portent la pastille « corrigé à la main » et la note qui explique la règle.
    - *Protection* : lancer la collecte (Veille › Réglages) puis lire le Journal : la date limite (ou la date de début, la
      fin, le lien, le lieu) corrigée à la main n'est pas remise à la valeur de la source (`wp post meta get <ID>
      rsn_veille_champs_manuels` liste les champs). Repasser en « Événement » n'efface rien.
    - *Ajouter une fiche* : le bouton en tête de la file d'approbation ouvre un éditeur vide. Créer un événement
      (titre, date de début) puis **Publier** : la fiche paraît sur la page ; `wp post meta get <ID> rsn_veille_cle_source`
      rend `manuel:<ID>`, `rsn_veille_niveau` `M`, `rsn_veille_source` `manuel`. Un événement sans date de début valide
      garde sa date du jour et un avis le dit.
    - *Proposer un appel depuis la page* (hors connexion) : le bouton jaune dit « Faire une proposition » (0.6.1) ;
      « Vous proposez » bascule entre « Un événement » (par défaut) et « Un appel de financement ou de candidatures ».
      Avec l'appel : plus de dates, de format ni de lieu ; « Date limite » (aide « Dernier jour pour déposer. »),
      « Organisme » et « Lien de l'appel » ; le titre devient « Proposer un appel ». Une date limite **passée** est
      refusée (« La date limite est passée ») ; un **lien manquant** est refusé (« Lien obligatoire ») ; une
      date limite manquante est refusée (« Date limite obligatoire »). Un appel valide arrive dans « À approuver » en appel (`rsn_veille_type`
      `appel`, `rsn_veille_echeance`), sans lieu ni format.
    - *Anglais* : sur la page anglaise, bouton « Make a suggestion », « You are suggesting », « An event » /
      « A funding or applications call », « Suggest a call », « Deadline », « Organization », « Link to the call », et les
      messages « The deadline has passed » et « Link required ». La fiche reçue porte la langue `en`.
    - *Rien d'autre ne change* : une proposition d'événement se comporte comme avant (étape 8) ; une fiche
      collectée corrigée garde sa correction après la collecte de la nuit ; un appel ajouté à la main n'a pas de date
      de début à saisir (sa date de publication suffit).

**Terminé quand** : toutes les cases de ces sections de `verifier-public.md` sont cochées, sauf celles du formulaire
(étape 8), et chacun des points 4 à 34 ci-dessus donne le résultat attendu.

## 7 bis. Bande de l'accueil (`[rsn_veille_accueil]`, version 0.5.0, ressources chargées avec la version courante)

Exige une page d'accueil statique (Réglages › Lecture). Sur wp-env, créer une page « Accueil » avec un bloc Code court
contenant `[rsn_veille_accueil]` et la choisir comme page d'accueil ; sur rsn.quebec, c'est l'accueil français (page 152)
et sa traduction anglaise (page 1147), dont le widget Code court de la section qui suit l'en-tête passe de
`[rsn_events_cards limit="3"]` à `[rsn_veille_accueil]` (guide d'installation, section 6). Purger le cache après chaque
changement de fiche (`wp transient delete rsn_veille_json_fr` et `rsn_veille_json_en`), puis LiteSpeed › Purger tout sur le site.

1. **Rendu et hauteur** (hors connexion, fenêtre de 1 440 px) : trois colonnes, le calendrier du mois, « Au RSN » et
   « Dans l'écosystème ». La bande est nettement plus basse qu'avant (environ 1 040 px pour l'ancienne bande ; mesurer
   la section avec les outils du navigateur et la noter dans le tableau des écarts). `accueil.css` et `accueil.js`
   sont chargés avec `?ver=0.6.2` (le CSS dans l'en-tête, le script en pied de page) sur l'accueil ; ni `veille.css` ni `veille.js`. Aucune erreur dans la
   console, aucun défilement horizontal (`document.documentElement.scrollWidth === document.documentElement.clientWidth`).
   **Charger l'accueil sans toucher la souris ni le clavier** : la bande doit se dessiner d'elle-même ; si elle reste vide,
   exclure `accueil.js` du délai du JavaScript de LiteSpeed (LiteSpeed › Optimisation de page › Réglages JS).
2. **Calendrier** : mois courant au départ, la flèche précédente désactivée ; la flèche suivante va jusqu'au dernier
   mois qui a un événement, puis se désactive. Jours passés grisés, aujourd'hui entouré. Point jaune les jours qui ont
   un événement du RSN, point marine ceux qui ont un événement de l'écosystème ; une série de plus de 7 jours ne
   pointe que son premier jour.
3. **Filtre par jour** : cliquer un jour pointé ; la colonne de droite devient « Le 4 novembre » (ou la date choisie)
   avec le bouton « Tous » et **tous** les événements de ce jour, ceux du RSN compris (plus de quatre si on en crée autant,
   deux d'un même organisateur compris) ; un jour qui n'a qu'un événement du RSN l'affiche, avec la pastille « RSN » sur sa ligne (dans l'ordre du début puis du titre). La liste par défaut, quand aucun événement de l'écosystème n'est à venir, dit « Aucun événement de l'écosystème à venir. ».
   Recliquer le jour, ou « Tous », revient à la liste. Changer de mois défait le jour choisi.
4. **Au RSN, quatre cas** (créer ou retirer des événements du RSN à venir, `rsn_veille_organisateur` = `RSN`, et purger) :
   - **aucun** : la phrase « Pas d'événement du RSN à venir pour le moment. Les prochains seront annoncés ici. » ;
   - **un** : une grande carte, image en haut (sans image, zone marine avec l'hexagone jaune) ;
   - **deux** : deux cartes compactes l'une sous l'autre, image à gauche ;
   - **trois ou plus** : deux cartes compactes puis « + 1 autre événement du RSN → » (« + 2 autres événements du RSN → » avec quatre), qui mène à l'agenda.
   Une série du RSN de plus de 7 jours déjà commencée n'est pas comptée.
5. **Dans l'écosystème** : au plus quatre événements qui ne sont pas du RSN, un seul par organisateur (le plus proche),
   sans série de plus de 7 jours déjà commencée ; tuile de date (la date du jour pour un événement en cours), titre,
   organisateur · format ; « En anglais » sur l'accueil français pour une fiche en anglais. Chaque titre mène à la page
   de l'événement (nouvel onglet hors de rsn.quebec).
6. **Accueil anglais** (WPML ; sur rsn.quebec seulement) : `/en/` montre la même bande en anglais : « At the RSN »,
   « In the ecosystem », « Previous month » / « Next month » (étiquettes des flèches), « On %s » ordonné comme « On November 4 »,
   dates (« November 4, 2026 »), formats traduits, « In French » sur une fiche en français, « + N more RSN events → » menant à
   l'agenda anglais. Le JSON incorporé porte `"langue":"en"`.
7. **Sans JavaScript** : un lien « Voir l'agenda de l'écosystème » remplace la bande.
8. **Purge** : modifier une fiche du RSN ou lancer la collecte ; l'accueil (et sa traduction) montre le changement après
   rechargement, sans purge manuelle de LiteSpeed. `wp option delete rsn_veille_version` puis une page de wp-admin purge aussi l'accueil.
9. **Mobile** : à 375 px, une colonne (calendrier, puis « Au RSN », puis « Dans l'écosystème ») ; à 800 px, le calendrier
   au-dessus sur toute la largeur et les deux autres colonnes côte à côte ; les flèches et les jours se touchent au doigt.
10. **Code court ailleurs que sur l'accueil** : dans un widget Code court d'une autre page Elementor, la bande s'affiche
    avec ses styles (ressources chargées au rendu, après le contenu). `add_filter('rsn_veille_charger_accueil', '__return_true')`
    les charge en tête. Cette page n'est pas purgée par la collecte : la bande y suit les caches de LiteSpeed et d'Elementor.
11. **Après minuit, sans purge** : afficher l'accueil le lendemain de la dernière purge, sans rien vider ; le jour entouré
    dans le calendrier et les colonnes « Au RSN » et « Dans l'écosystème » sont ceux du jour (un événement fini la veille
    n'y est plus), même si la page vient du cache.

**Terminé quand** : les points 1 à 11 donnent le résultat attendu sur l'accueil français et, avec WPML, sur l'accueil anglais.

## 8. Formulaire « Proposer un événement »

`verifier-public.md`, section **Formulaire « Proposer un événement »** (hors connexion, fenêtre privée) :
envoi complet avec image, entrée dans « À approuver » (source `proposition`, niveau M, courriel en méta privée),
absence de la fiche et du courriel sur la page, le JSON et l'iCal tant qu'elle n'est pas publiée, limite de
débit (sixième envoi refusé), champ piège, erreurs de saisie, doublon, jeton, administrateur connecté, image
(nom aléatoire, écart de la proposition supprime l'image), plafond du site. Entre deux séries, vider les
compteurs : `wp transient delete --all`.

**Formulaire anglais** (version 0.4.0 ; WPML) : sur la page anglaise, le formulaire est en anglais (champs, bouton, messages
d'erreur et de réussite). Envoyer une proposition : la fiche arrive dans « À approuver » avec la langue anglaise
(`wp post meta get <ID> rsn_veille_langue` rend `en`) ; publiée, elle porte « En anglais » sur la page française et
aucune pastille sur la page anglaise. Une proposition envoyée depuis la page française est enregistrée `fr`.

**Terminé quand** : toutes les cases de la section sont cochées, et la proposition publiée à la main apparaît
sur la page avec son image, sans courriel nulle part.

## 9. Radar

`verifier-admin.md`, section **Radar (tâche 15)** : ouverture sans erreur, nouvelles groupées par source,
sources en erreur repliées, **Garder** (deux nouvelles), **Retirer**, **Ignorer**, **Rafraîchir**, verrou,
Garder sans réseau, titre jamais pris de la requête, jeton et droits, mobile. Laisser **deux nouvelles gardées**
pour l'étape suivante.

**Terminé quand** : `wp option get rsn_veille_nouvelles_gardees --format=json` contient deux nouvelles.

## 10. Infolettre

`verifier-admin.md`, section **Infolettre (tâche 16)** : les **trois blocs** (Agenda, Appels, Nouvelles
gardées) copiés en collage enrichi et en code HTML (coller dans un éditeur enrichi et dans un éditeur de texte
brut), repli sans API moderne, nombre d'événements et horizon, titre piégé échappé, dates de plusieurs jours,
appels (échus absents, programmes du RSN si l'extrait 45 est actif), bloc vide, **Vider les nouvelles gardées**,
jeton et droits, aperçu sans script, mobile.

**Terminé quand** : les trois blocs ont été copiés et collés dans un éditeur sans perte de mise en forme.

## 11. Courriel hebdomadaire

`verifier-admin.md`, section **Courriel hebdomadaire (tâche 17)** : planification (mercredi, puis vendredi,
puis décoché), réactivation de l'extension, **Envoyer un essai maintenant** (MailHog : sujet, HTML et version
texte, destinataires), contenu (sections, fiche ancienne absente, titre piégé en texte, aucune requête réseau),
cas sans nouveauté / option décochée / sans destinataire, `wp cron event run rsn_veille_hebdo`, jeton et droits,
mobile.

**Terminé quand** : MailHog a reçu l'essai et `wp option get rsn_veille_dernier_courriel --format=json`
donne `"ok":true`.

## 12. Désinstallation

À faire en dernier, sur un site de vérification (les fiches sont conservées, tout le reste disparaît).

**Attention avec wp-env** : l'extension y est montée depuis le dossier du dépôt. **Ne jamais lancer
`wp plugin uninstall rsn-veille` sans `--skip-delete`** : sans cette option, WP-CLI supprimerait les fichiers de
l'extension, donc le dépôt.

1. Noter l'état avant : `wp option list --search='rsn_veille_*' --format=count`, `wp post list --post_type=rsn_veille --post_status=any --format=count`,
   `wp cap list editor | grep rsn_veille_gerer`.
2. `wp plugin deactivate rsn-veille`, puis `wp plugin uninstall rsn-veille --skip-delete`.
3. Vérifier :
   - [ ] `wp option list --search='rsn_veille_*'` ne retourne rien ;
   - [ ] `wp option list --search='_transient_rsn_veille_*'` et `wp option list --search='_transient_timeout_rsn_veille_*'` ne retournent rien ;
   - [ ] `wp cron event list` ne montre plus `rsn_veille_nuit`, `rsn_veille_source` ni `rsn_veille_hebdo` ;
   - [ ] `wp cap list editor | grep rsn_veille_gerer` et `wp cap list administrator | grep rsn_veille_gerer` n'affichent rien ;
   - [ ] `/veille.ics` ne répond plus en calendrier tant que l'extension est inactive.
4. Réactiver l'extension (`wp plugin activate rsn-veille`) : le menu, la planification (`rsn_veille_nuit`) et la
   capacité reviennent, et
   - [ ] les fiches existent toujours : `wp post list --post_type=rsn_veille --post_status=any --format=count`
     donne le même nombre qu'avant (le type de contenu n'est connu que lorsque l'extension est active).

**Terminé quand** : toutes les cases ci-dessus sont cochées.

## 13. Nettoyage

La section **Nettoyage** de `verifier-admin.md` (fiches de vérification, comptes de test, options du Radar et de
l'infolettre, dernier courriel). Puis `npx @wordpress/env stop` (ou `destroy` pour repartir de zéro).

## Écarts relevés

| Étape | Constat | Corrigé (commit) ou consigné |
|---|---|---|
| 0 | `wp_mail` rend `false` sous wp-env : expéditeur `wordpress@localhost` refusé par PHPMailer. Propre au site sur `localhost`. | consigné à l'étape 0 (filtre `wp_mail_from` dans le mu-plugin) |
| 3 | Page Réglages : « à deux minutes d'intervalle » contredisait le guide (≈ 5 min par passage de cron). | corrigé (texte de `RenduReglages`) |
| 4 | Première lecture de l'API TEC de rsn.quebec expirée après 15 s sans octet ; relance une minute plus tard : 16 lus en 1 s. Cache LiteSpeed froid côté rsn.quebec. | consigné : à surveiller dans le Journal |
| 5 | Import : 24 créés, 0 fusionné. Aucun événement importé ne partage titre et date avec les 15 fiches collectées de rsn.quebec : l'hypothèse « M ≥ 1 » de `verifier-admin.md` était fausse, pas le code. | corrigé (liste manuelle) |
| 7 | Filtre Organisateur : « Obvia » et « OBVIA », « RSN » et « Réseau santé numérique » en doublon. | corrigé (`Donnees::organisateur_canonique`) |
| 7 | Fiches collectées depuis la source `rsn` classées « Écosystème » dans « Qui organise ». | corrigé (`Donnees::construire` : clé `rsn:` → RSN) |
| 7 | Deux colonnes maintenues dans une colonne de contenu de ~650 px (le point de rupture ne regardait que la fenêtre) : fiches écrasées avec un thème étroit. | corrigé (requête de conteneur `@container rsn-veille`) |

Parcours déroulé le 2026-10-26 sous wp-env (WordPress 7.1.3, PHP 8.0.30, The Events Calendar 6.18.2, Elementor 4.3.5,
MailHog) : étapes 0 à 12 passées, `debug.log` resté absent du début à la fin, aucune erreur de l'extension dans la
console du navigateur. Non couvert : LiteSpeed Cache, WPML, l'éditeur Elementor (widget Code court) et l'abonnement
iCal dans un agenda, à vérifier sur la copie de préproduction de rsn.quebec.

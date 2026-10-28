# Installation et bascule sur rsn.quebec

Ce guide dit comment installer l'extension **RSN : veille de l'écosystème** (version 0.6.1) sur le site, quoi régler le premier jour, comment faire passer la page Veille à la place de la page Événements, et que faire quand quelque chose ne va pas. Rien n'est fait sur le site tant que la personne qui porte le projet ne l'a pas décidé : les étapes de la bascule se font une à une, avec un accord à chaque étape.

## 1. Prérequis

| Élément | Exigence |
|---|---|
| PHP | 8.0 ou plus, avec les extensions `mbstring`, `dom`, `libxml` et `json` (WHC : vérifier dans cPanel, « Sélecteur PHP » ou « MultiPHP Manager », ou dans WordPress sous Outils › Santé du site › Info › Serveur) |
| WordPress | 6.0 ou plus (6.x) |
| Fuseau du site | Réglages › Général › Fuseau horaire : **Montréal** (`America/Toronto`). « Aujourd'hui », l'heure de la collecte (3 h) et celle du courriel (8 h) suivent le fuseau du site |
| Compatibilité | Elementor, WPML, The Events Calendar et LiteSpeed Cache sont pris en compte ; aucun n'est obligatoire pour que l'extension fonctionne |
| Dépendances | Aucune bibliothèque externe à l'exécution (pas de `vendor/` à installer) |

### Tâche planifiée : la collecte exige un cron fiable

La collecte nocturne, le courriel hebdomadaire et le traitement des sources reposent sur **WP-Cron**. Par défaut, WP-Cron ne se déclenche que lorsqu'une page du site est chargée. Deux pièges sur rsn.quebec :

- le site reçoit peu de visites la nuit, donc la collecte de 3 h peut partir tard ;
- **LiteSpeed Cache sert les pages depuis son cache sans charger WordPress** : une visite servie par le cache ne déclenche pas WP-Cron. Une journée de visites « tout en cache » peut donc passer sans qu'aucune tâche ne parte.

La collecte traite **une source par passage de WP-Cron** : après chaque source, la suivante est planifiée à 2 minutes, mais elle ne part qu'au passage suivant de WP-Cron. Avec le cron du serveur toutes les 5 minutes (réglage ci-dessous), compter **environ 5 minutes par source**, soit **environ une heure pour les 13 sources collectées** (la collecte de 3 h se termine vers 4 h). Sans trafic ni cron du serveur, elle peut ne pas avancer du tout. Il faut donc une vraie tâche cron du serveur :

1. Dans `wp-config.php`, au-dessus de la ligne « That's all, stop editing! » :
   ```php
   define('DISABLE_WP_CRON', true);
   ```
2. Dans cPanel (WHC) › **Tâches Cron**, ajouter une tâche **toutes les 5 minutes** (`*/5 * * * *`) qui appelle `wp-cron.php`. L'une ou l'autre de ces commandes convient (adapter le chemin du compte et l'adresse ; en cas de doute, demander à WHC la commande exacte pour le compte) :
   ```
   */5 * * * * /usr/local/bin/php /home/COMPTE/public_html/wp-cron.php >/dev/null 2>&1
   */5 * * * * curl -s 'https://rsn.quebec/wp-cron.php?doing_wp_cron' >/dev/null 2>&1
   ```
3. Vérifier : Veille › Réglages affiche un avertissement dès que `DISABLE_WP_CRON` est défini ; il rappelle que la tâche cron du serveur doit exister, mais il ne détecte pas son absence. Le vrai test est de lancer une collecte (section 3, point 5) et de regarder si le Journal se remplit, une source environ toutes les 5 minutes.

Sans cron du serveur (c'est-à-dire sans `DISABLE_WP_CRON`), l'extension fonctionne quand même grâce aux visites, avec les réserves ci-dessus. Si le site tourne avec `DISABLE_WP_CRON` **sans** tâche cron du serveur, rien ne partira jamais : la page Réglages affiche l'avertissement ci-dessus (qui s'affiche de toute façon dès que `DISABLE_WP_CRON` est défini, que le cron du serveur existe ou non).

## 2. Deux façons d'installer

### Voie 1 : zip téléversé dans Extensions › Ajouter

À partir du dépôt, sur un poste avec Git (après avoir amené la branche voulue, par exemple `main`) :

```
git archive --format=zip --prefix=rsn-veille/ -o rsn-veille-0.6.1.zip HEAD -- . ':!tests' ':!docs' ':!.wp-env.json' ':!.superpowers' ':!composer.lock' ':!phpunit.xml'
```

Le zip contient `rsn-veille.php`, `uninstall.php`, `includes/`, `assets/` et `languages/` (la traduction anglaise de la page en dépend) (et les documents de racine), sans tests, sans documents de conception et sans `vendor/`. Dans wp-admin : **Extensions › Ajouter › Téléverser une extension**, choisir le zip, installer, puis activer.

- **Avantages** : rien d'autre à installer ni à payer ; aucun jeton à confier au site ; le contenu installé est exactement celui du zip, sans fichiers de développement.
- **Limites** : chaque correction demande de refaire un zip et de le téléverser par-dessus l'ancien (WordPress propose alors de remplacer l'extension) ; il faut penser à augmenter le numéro de version pour que les ressources en cache du navigateur soient renouvelées ; pas de retour en arrière en un clic.

### Voie 2 : depuis GitHub, avec WP Pusher ou Git Updater

Le dépôt est **privé** (`TessRSN/rsn-veille`) et la branche de livraison est `main`. Un greffon de déploiement installe l'extension directement depuis GitHub et propose les mises à jour en un clic (ou les applique à chaque fusion sur `main`).

- **WP Pusher** : installer le greffon, enregistrer un jeton GitHub (WP Pusher › Réglages › GitHub), puis WP Pusher › Install plugin › dépôt `TessRSN/rsn-veille`, branche `main`. Ne pas activer le déploiement automatique (« Push-to-Deploy ») sans l'avoir voulu (voir la recommandation). À vérifier à l'achat : l'offre en vigueur de WP Pusher peut exiger une licence payante pour les dépôts privés.
- **Git Updater** : installer le greffon (il s'installe lui-même depuis son propre dépôt), saisir le jeton GitHub dans Git Updater › Réglages › GitHub. L'en-tête de l'extension contient déjà `GitHub Plugin URI: TessRSN/rsn-veille` et `Primary Branch: main`, ce qui suffit à Git Updater pour la reconnaître une fois qu'elle est installée (la première installation se fait par le zip de la voie 1, ou par l'onglet « Install Plugin » de Git Updater).
- **Le jeton GitHub** : créer un jeton à portée limitée (accès en lecture seule au contenu du seul dépôt `rsn-veille`, par exemple un jeton « fine-grained »). Il se saisit **uniquement dans les réglages du greffon de déploiement**, jamais dans un fichier du dépôt ni dans `wp-config.php` versionné. Le révoquer sur GitHub si on cesse d'utiliser la voie.

- **Avantages** : une correction fusionnée sur `main` arrive sur le site en un clic ; l'historique de GitHub dit exactement ce qui est installé.
- **Limites** : un greffon de plus à maintenir, avec un jeton qui donne accès au code ; l'installation depuis GitHub dépose **tout le dépôt** dans `wp-content/plugins/rsn-veille/`, y compris `tests/`, `docs/` et `composer.json` (sans secret, mais des fichiers de développement inutiles sur un serveur de production : ne jamais y lancer Composer, et bloquer l'accès web à ces dossiers si le serveur le permet) ; tout ce qui est fusionné sur `main` devient installable : il faut donc ne fusionner sur `main` que du code relu.

### Recommandation

**Premier essai : le zip** (voie 1), pour installer, régler et valider la page en brouillon sans rien d'autre à configurer. **Pour suivre les corrections** pendant les premières semaines : **WP Pusher** (voie 2), avec les mises à jour manuelles (un clic après chaque correction relue) plutôt qu'automatiques. Si WP Pusher est écarté pour son coût, Git Updater fait le même travail avec la même méthode.

Quelle que soit la voie, l'activation lance les mêmes opérations (voir section 9).

### Après la mise à jour de la 0.6.0 vers la 0.6.1

Rien à régler. Le bouton jaune du bandeau devient « Faire une proposition » (« Make a suggestion » en anglais) : plus court, il laisse les deux boutons à droite de l'intro sur un écran d'ordinateur. Le lien du panneau « Comment ça marche » garde « Proposer un événement ou un appel ».

### Après la mise à jour de la 0.5.2 vers la 0.6.0

1. Remplacer l'extension (zip par-dessus l'ancienne, ou mise à jour WP Pusher / Git Updater). **Rien à régler** : aucune réactivation, aucune migration, aucun réglage à refaire.
2. **L'encadré « Détails de la fiche » apparaît** dans « Modifier » (Veille › File d'approbation › Modifier) : on y choisit Événement ou Appel et on corrige les dates, le lien et le lieu. Une valeur corrigée à la main est protégée de la collecte de nuit et porte la pastille « corrigé à la main ». Le bouton « Ajouter une fiche » est en tête de la file d'approbation.
3. **La fiche de test peut être passée en appel** : ouvrir « Modifier », choisir « Appel » dans l'encadré, renseigner la date limite, enregistrer ; la page Veille la montre comme appel dès l'enregistrement.
4. **Le bouton jaune change de nom** : « Proposer un événement » devient « Proposer un événement ou un appel » (« Suggest an event or a call » en anglais), et le formulaire propose de choisir entre un événement et un appel.
5. **Les caches sont purgés au premier chargement de wp-admin** avec la nouvelle version (ceux de l'extension, de LiteSpeed et d'Elementor pour la page Veille et ses traductions) ; les nouveaux textes et le nouveau script du formulaire (`?ver=0.6.1`) apparaissent ensuite.

### Après la mise à jour de la 0.5.1 vers la 0.5.2

Rien à régler. Seul le style du bouton « Comment ça marche » change (sans cadre, sur sa propre ligne sous l'intro) ; la nouvelle version de `veille.css` est chargée d'elle-même et les caches de la page Veille et de ses traductions sont purgés au premier chargement de wp-admin.

### Après la mise à jour de la 0.5.0 vers la 0.5.1

1. Remplacer l'extension (zip par-dessus l'ancienne, ou mise à jour WP Pusher / Git Updater). **Rien à régler** : aucune réactivation, aucune migration.
2. **La purge des caches est automatique** : au premier chargement de wp-admin avec la nouvelle version, l'extension vide ses caches, ceux de LiteSpeed et d'Elementor pour la page Veille et ses traductions WPML ; le bouton d'abonnement, la ligne Google Agenda et le point « Comment ça marche » apparaissent ensuite.
3. **Le calendrier est renommé** (« Agenda de l'écosystème RSN », « RSN ecosystem calendar » en anglais), mais **un abonnement déjà pris garde son ancien nom** dans le logiciel de calendrier. Les personnes qui avaient seulement **téléchargé le fichier** avec l'ancien bouton n'ont qu'une copie figée : elles doivent s'abonner avec le nouveau bouton (ou « Google Agenda : ajouter ») et pourront supprimer l'ancienne copie.

### Après la mise à jour de la 0.4.0 vers la 0.5.0

1. Remplacer l'extension (zip par-dessus l'ancienne, ou mise à jour WP Pusher / Git Updater). **Rien d'autre à faire côté extension** : aucune réactivation, aucune migration, aucun réglage à refaire.
2. **La purge des caches est automatique** : au premier chargement de wp-admin avec la nouvelle version, l'extension vide ses caches, ceux de LiteSpeed et d'Elementor pour la page Veille, **pour la page d'accueil** (réglage WordPress « Page d'accueil ») et pour leurs traductions WPML. Tant que le code court de l'accueil n'est pas remplacé, rien ne change pour les visiteurs.
3. **Remplacer le code court de l'accueil dans Elementor** (français et anglais), puis purger LiteSpeed : voir la section 6, « Bande de l'accueil ».

### Après la mise à jour de la 0.3.0 vers la 0.4.0

1. Remplacer l'extension (zip par-dessus l'ancienne, ou mise à jour WP Pusher / Git Updater). **Rien d'autre à faire côté extension** : aucune réactivation, aucune migration, aucun réglage à refaire. Le dossier `languages/` (traduction anglaise) vient avec l'extension.
2. **La purge des caches est automatique** (voir plus bas) : au premier chargement de wp-admin avec la nouvelle version, l'extension vide les caches du JSON public et de l'iCal de chaque langue, ainsi que ceux de LiteSpeed et d'Elementor pour la page Veille et ses traductions. **Ensuite, purger LiteSpeed › Gestion › Purger tout** : le HTML en cache de la page française contient encore les anciens doublons anglais des événements du RSN.
3. **Créer la traduction anglaise de la page** dans WPML : voir « Temps 3 : la version anglaise avec WPML » (section 5). Tant qu'elle n'existe pas, rien ne change pour les visiteurs, sauf deux détails sur la page française : elle ne montre plus de doublon anglais (« RSN Scientific Days » à côté de « Journées scientifiques RSN »), et l'heure s'écrit « 8 h 00 » au lieu de « 08 h 00 ».
4. **Mila et IVADO** : leur version anglaise est lue à la prochaine collecte de chaque source (une source par passage). Pour ne pas attendre, lancer la collecte depuis Veille › Réglages et lire le Journal : la ligne de chaque source dit « N appariées en anglais, M orphelines ». Les fiches déjà en base reçoivent leurs champs anglais à ce passage.
5. Vérifier avec la section 7 de `tests/manuel/PARCOURS.md` (étape 7, points 24 à 32, et étape 8).

### Après la mise à jour de la 0.2.0 vers la 0.3.0

1. Remplacer l'extension (zip par-dessus l'ancienne, ou mise à jour WP Pusher / Git Updater). **Rien d'autre à faire** : aucune réactivation, aucune migration, aucun réglage à refaire.
2. **La purge des caches est automatique** (comme de la 0.1.0 à la 0.2.0, voir plus bas) : au premier chargement de wp-admin avec la nouvelle version, l'extension vide ses caches et ceux de la page Veille. **Ensuite, purger LiteSpeed › Gestion › Purger tout** : à cette étape, ce n'est pas facultatif, car le HTML en cache de la page de test pointe encore vers l'ancien balisage et la purge automatique ne vise que la page des réglages.
3. **Renommer la page** : dans Pages, changer le titre de la page de test en « Événements et appels » (et son permalien si on le veut ; si l'adresse change, prévoir une redirection depuis l'ancienne). Le titre affiché sous le fil d'Ariane est celui de la page : l'extension n'y touche pas. Une fois renommée, le titre tient sur une ligne sur écran large.
4. **Un changement de règle à connaître** : un appel sans date limite reste visible **30 jours** après sa publication (90 jours en 0.2.0). Cette règle est la même pour la page, le JSON public, le bloc Appels de l'infolettre et le courriel hebdomadaire : un appel sans date limite publié il y a plus de 30 jours n'est plus proposé nulle part. Pour garder un appel plus longtemps, lui donner une date limite (`wp post meta update <ID> rsn_veille_echeance AAAA-MM-JJ`).
5. Vérifier la page avec la section 7 de `tests/manuel/PARCOURS.md` (bandeau continu, flèches des dates limites, fiches du RSN).

### Après la mise à jour de la 0.1.0 vers la 0.2.0

1. Remplacer l'extension (zip par-dessus l'ancienne, ou mise à jour WP Pusher / Git Updater). Aucune réactivation ni migration n'est nécessaire.
2. **La purge des caches est automatique** : au premier chargement de wp-admin avec la nouvelle version, l'extension vide le cache du JSON public (toutes les langues), la page Veille de LiteSpeed et le cache Elementor de cette page, puis retient le numéro de version (option `rsn_veille_version`). Au besoin, LiteSpeed › Gestion › Purger tout.
3. Une fiche **IVADO déjà en base** garde l'adresse `ivado.ca` (la collecte ne relit pas les fiches existantes). Pour la remplacer par la cible de la redirection : `wp post meta update <ID> rsn_veille_url <cible>` (par exemple `https://event.fourwaves.com/aihealthsymposium`).

## 3. Réglages du premier jour

Après l'activation, le menu **Veille** apparaît dans wp-admin (sous « Commentaires »), avec cinq pages : File d'approbation, Radar, Infolettre, Journal et Réglages. Dans **Veille › Réglages** :

1. **Accès › Rôles qui gèrent la veille** : par défaut, administrateur et éditeur. Cocher ou décocher les rôles voulus (l'administrateur reste toujours coché). Un rôle coché reçoit la capacité `rsn_veille_gerer` ; un utilisateur d'un autre rôle ne voit ni le menu ni les pages. Attention : un rôle qui peut modifier les articles des autres (éditeur et plus) peut aussi modifier les fiches dans l'éditeur WordPress.
2. **Collecte et page publique › Heure de la collecte quotidienne** : 03:00 (heure de Montréal) par défaut. La changer replanifie la collecte.
3. **Courriel hebdomadaire** : laisser **décoché** au début. Quand les fiches sont en place, cocher « Envoyer le courriel hebdomadaire de la veille », saisir les destinataires (une adresse par ligne), choisir le jour (l'envoi part à 8 h, heure de Montréal), enregistrer, puis cliquer **Envoyer un essai maintenant**. Si `wp_mail` ne livre pas les courriels du site, installer une extension SMTP avant de compter sur cette fonction.
4. **Sources, Pertinence** : les valeurs par défaut sont celles du devis (niveaux, mots-clés santé et numérique, thèmes). Rien à changer pour commencer.
5. **Lancer la collecte** : Veille › Réglages › **Lancer la collecte maintenant**. Les 13 sources collectées sont traitées une à une, à chaque passage de WP-Cron (environ 5 minutes par source avec le cron du serveur, donc environ une heure en tout). Suivre l'avancement dans **Veille › Journal** (une ligne par source ; les erreurs sont repliées sous « N erreurs »). Les 12 flux de nouvelles ne font pas partie de la collecte : le Radar les lit à la demande.
6. **Importer les infolettres** : une fois la collecte des événements du RSN terminée, Veille › Réglages › **Importer des événements**, choisir le fichier `docs/maquette/evenements-infolettres-2025-2026.json` (24 événements recopiés des infolettres ; le fichier n'est pas dans le zip, ni ce guide : ils sont dans le dépôt GitHub privé `TessRSN/rsn-veille`, dossier `docs/` (`docs/maquette/` pour ce fichier), accessible avec un compte ayant accès au dépôt ; le télécharger de là avant l'import). Le message d'importation donne le nombre de fiches créées, fusionnées et inchangées ; un second import du même fichier ne crée rien.
7. **Page Veille** : après avoir créé la page (section 4), la choisir dans Réglages › Collecte et page publique › **Page Veille**. Ainsi, à chaque changement de fiche, l'extension purge le cache LiteSpeed de cette page et son cache de rendu Elementor.
8. **File d'approbation** : ouvrir Veille › File d'approbation, onglet « À approuver », et publier ou écarter les fiches que les règles de confiance n'ont pas publiées d'office.

## 4. Créer la page Veille (en brouillon ou privée)

1. Pages › Ajouter. Titre : « Veille de l'écosystème » (ou le titre voulu).
2. Avec l'éditeur de blocs : un bloc **Code court** contenant `[rsn_veille]`. C'est la **structure de référence** de la 0.3.0 : une page ordinaire, sans Elementor (voir « Bandeau, en-tête du thème et classe `rsn-veille-page` » plus bas). Avec Elementor : le widget **Code court** (Shortcode) contenant `[rsn_veille]` fonctionne, mais le bandeau ne touche pas l'en-tête du thème (voir « Bande « À la une du RSN » : fond jusqu'aux bords » plus bas).
3. Enregistrer en **brouillon**, ou en **privée** (visibilité « Privée » : seules les personnes connectées avec les droits voient la page, ce qui permet d'essayer la page sans la rendre publique). Ne pas ajouter la page au menu.
4. Prévisualiser. Le raccourci rend, sans attribut (`section="tout"`) : le bandeau (une courte intro et les boutons « S'abonner au calendrier » et « Faire une proposition »), la bande « À la une du RSN », la bande « Dates limites » (avec ses deux flèches), puis les filtres (recherche, Afficher, Qui organise, Mois, Format, Lieu, Thèmes, Organisateur) à côté de la liste unifiée des événements et des appels (ou du calendrier), et « Sources lues ». Attributs :
   - `section="agenda"` : les événements seuls (filtre Afficher verrouillé sur Événements, sans bande des dates limites) ;
   - `section="appels"` : la bande des dates limites puis les appels seuls, triés par date limite, sans colonne de filtres ;
   - `une="0"` : retire la bande « À la une du RSN » de la page complète (à employer quand le thème ou la page met déjà le RSN en avant ; la bande n'existe de toute façon que dans `section="tout"`). Le bandeau reste, et finit le bleu tout seul. Exemple : `[rsn_veille une="0"]`.
5. Choisir cette page dans Veille › Réglages › Page Veille. La liste propose les pages publiées, en brouillon **et privées**.

**Bandeau, en-tête du thème et classe `rsn-veille-page`.** Sur rsn.quebec (thème Vault de UiCore), le bandeau bleu de l'en-tête de page (fil d'Ariane et titre) est fourni par le thème. L'extension ajoute la classe `rsn-veille-page` au `<body>` de la page qui affiche le raccourci, et c'est cette classe qui lui permet de resserrer cet en-tête : remplissage de 44 px en haut et de 0 en bas (30 px en haut et 0 en bas sous 820 px) au lieu de 50 à 100 px selon la largeur, et titre sur toute la largeur au lieu de la moitié ; l'intro du bandeau suit ensuite le titre à 12 px, et le bloc s'aligne sur le titre. Le bandeau de l'extension (intro et boutons) et « À la une » prennent le même bleu `#003469`, et le tout se lit comme un seul bloc : en-tête du thème, intro, boutons, filet, « À la une ». Le fil d'Ariane, la police et les couleurs du titre restent ceux du thème.
- **Quand la classe est posée** : la page affichée contient `[rsn_veille]` dans son contenu, ou c'est la page choisie dans Réglages › Page Veille, ou l'une de ses traductions WPML. Elle n'est **pas** posée quand le raccourci est rendu ailleurs que dans le contenu de la page, sur une page qui n'est pas celle des réglages (par exemple un widget Code court d'Elementor sur une page non choisie dans Réglages › Page Veille) : les ressources se chargent au rendu, mais sans la classe l'en-tête du thème reste tel quel. Elle n'est pas non plus posée quand le filtre `rsn_veille_charger_ressources` force le chargement de `veille.css` et `veille.js` sur une page.
- **Sur un autre thème** : la classe est posée de la même façon, mais les règles qu'elle active ne visent que l'en-tête de page de Vault (`.uicore-page-title`). Elles ne trouvent rien ailleurs et ne changent rien. Le bandeau de l'extension se place alors sous l'en-tête de ce thème, sans y toucher, et garde son bleu.
- **Structure de référence : une page ordinaire** (bloc « Code court »). L'adaptation est établie sur la page de test de rsn.quebec, qui est une page ordinaire. Sur une page **Elementor**, les sections et les colonnes ajoutent leurs propres marges : le bandeau ne touche pas l'en-tête et l'adaptation ne vaut pas. Il vaut donc mieux **garder la page de test, renommée** « Événements et appels » (et déplacée à l'adresse voulue), plutôt que d'en refaire une dans Elementor.

**Bande « À la une du RSN » : fond jusqu'aux bords.** La bande garde la largeur de la colonne où se trouve le raccourci, et son fond marine (`#003469`, le bleu du bandeau et de l'en-tête du thème) est peint jusqu'aux bords de la fenêtre sans élargir la page : aucune section pleine largeur n'est requise et aucun défilement horizontal n'apparaît, quelle que soit la barre de défilement. Le contenu reste aligné sur celui de la page (1140 px au plus). Pour ne pas avoir de bande, mettre `une="0"`.

**Ce que montre la page.**
- *Bandeau* : l'intro et deux boutons, « S'abonner au calendrier » (un lien `webcal://` vers `/veille.ics`, ou `/en/veille.ics` en anglais : Calendrier et Outlook ouvrent un abonnement qui se met à jour) et « Faire une proposition » (ouvre le formulaire, qui s'affiche sous « À la une » ; sa première question, « Vous proposez », bascule entre « Un événement » et « Un appel de financement ou de candidatures », qui demande une date limite et un lien). Sous les boutons, une ligne « Google Agenda : ajouter · Copier l'adresse » (Google ne comprend pas `webcal://` : le lien ouvre sa page d'ajout, et le bouton copie l'adresse `https://…/veille.ics`). À la fin de l'intro, **en section `tout` seulement** (le panneau mène à « Sources lues »), le point « Comment ça marche » déplie un court texte sur la veille semi-automatique, avec les liens « Voir les sources lues » et « Proposer un événement ou un appel ». Absent de `section="appels"`.
- *À la une du RSN* : les trois prochains éléments **organisés par le RSN**, événements (à venir ou en cours) et appels en cours **qui ont une date limite**, triés par date. Un appel sans date limite n'y figure pas (il n'a pas de date à montrer). Un événement court du RSN qui a commencé et n'est pas fini y reste ; une série de plus de 7 jours en sort dès qu'elle a commencé. Étiquettes : « Événement RSN » ; « Nouveau » pour un appel publié depuis 7 jours ou moins ; « Ferme dans N jours » (21 jours ou moins avant la date limite) ; sinon « Date limite : … ». Sans élément du RSN, la bande disparaît.
- *Dates limites* : une puce par appel en cours qui a une date limite, triées par date. Deux flèches (gauche et droite) font défiler les puces ; elles sont désactivées aux extrémités et quand tout tient. Sans appel à date limite, la bande disparaît.
- *Liste unifiée* : événements et appels groupés par mois (début d'un événement, date limite d'un appel). Les fiches du RSN ont leur corps (image et texte) sur un fond gris très pâle avec un trait jaune à gauche ; la pastille « Appel » est en contour bleu marine. Un appel sans date limite se trouve dans la liste seulement, **placé à la date du jour** (après les événements d'aujourd'hui), avec la mention « Date limite à venir ». Un événement commencé et pas fini (une série, par exemple des webinaires de septembre à décembre) est aussi placé à la date du jour : sa tuile montre sa date de fin précédée d'une flèche (« →18 déc. ») et une pastille « En cours » suit la pastille de type. Les appels apparaissent aussi dans le calendrier, à leur date limite.

Qui organise (RSN, Partenaires, Écosystème) vient de la catégorie de l'événement dans The Events Calendar, que l'extension reconnaît par son **slug** `rsn`, `partenaires` ou `ecosysteme` ou par son **nom** français ou anglais (par exemple « Événements RSN », « Événements partenaires », « RSN Events », « Partner Events »). Les anciens slugs `evenements-rsn*`, `rsn-events*`, `evenements-partenaires*` et `partner-events*` restent reconnus. Toute autre catégorie, dont `ecosysteme`, est classée Écosystème. Les fiches de la source du RSN (événements et appels) et celles dont l'organisateur est « RSN » sont classées RSN, quelle que soit leur catégorie.

L'onglet des appels n'existe plus : les appels sont dans la liste, et ne montrent que les appels en cours : date limite à venir (cherchée dans l'extrait, le contenu complet du flux et le titre), sinon date de fin à venir ; un appel sans aucune de ces dates est montré 30 jours après sa publication à la source, puis retiré (même règle pour le bloc Appels de l'infolettre et le courriel). Un appel encore ouvert après ce délai peut recevoir une date limite avec WP-CLI (`wp post meta update <ID> rsn_veille_echeance AAAA-MM-JJ`) ; il revient sur la page au plus tard une heure après (cache du JSON).

**File d'approbation.** Les onglets « À approuver » et « À vérifier » sont triés par arrivée (la plus récente en premier) ; une fiche arrivée aujourd'hui ou hier porte le repère « Nouveau ». « Publiées » et « Écartées » restent triées par date d'événement.

**Image d'une fiche.** La collecte réécrit l'image de la source à chaque passage. Pour garder une autre image, choisir une **image mise en avant** dans l'éditeur de la fiche (colonne latérale) : elle prime sur la page et dans le JSON, et la collecte n'y touche jamais. La boîte « Image de la fiche » de l'éditeur montre l'image de la source et rappelle cette règle ; elle n'a pas de champ à remplir. Pour revenir à l'image de la source, retirer l'image mise en avant.

Les ressources (`veille.css`, `veille.js`) ne se chargent que sur une page qui contient le raccourci (ou sur la page choisie dans les réglages). Les polices DM Serif Display et Plus Jakarta Sans sont déjà chargées par le site : l'extension ne les recharge pas (un autre thème peut les demander avec `add_filter('rsn_veille_charger_polices', '__return_true')`).

Sorties pour d'autres usages : `https://rsn.quebec/wp-json/rsn/v1/veille` (JSON public, sans les fiches à approuver) et `https://rsn.quebec/veille.ics` (calendrier iCal des événements publiés).

## 5. Bascule en trois temps

Chaque temps se fait seulement quand la personne qui porte le projet l'a demandé.

### Temps 1 : page de test, quelques jours

Installer l'extension, faire les réglages (section 3), lancer la collecte, importer les infolettres, puis créer la page Veille **en brouillon ou privée** (section 4) et la prévisualiser. Sur rsn.quebec, cette page existe déjà : c'est la **page de test**, une page ordinaire (bloc « Code court »), à renommer « Événements et appels » (voir « Après la mise à jour de la 0.2.0 vers la 0.3.0 »). Pendant quelques jours : vérifier le rendu (ordinateur et mobile), approuver des fiches, lire le Journal chaque matin (la collecte de 3 h s'est-elle faite ?), essayer le formulaire « Proposer un événement » et la page Infolettre. Les extraits Code Snippets existants (44, 45, 53, 61, 62) ne sont pas touchés.

### Temps 2 : la page Veille remplace `/calendrier-evenements/`

Deux voies. La **voie A** est recommandée : depuis la 0.3.0, la page de référence est une page ordinaire (section 4), où le bandeau touche l'en-tête du thème. La voie B garde l'ancienne page Elementor, avec un rendu moins bon.

**Voie A (recommandée) : la page de test devient la page publique.**
1. **Libérer l'adresse** : dans l'ancienne page Elementor `/calendrier-evenements/`, changer d'abord son adresse (permalien) pour `/calendrier-evenements-ancien/`, enregistrer, puis la passer en **brouillon**. (Une page en brouillon garde son adresse : sans ce changement, la page de test recevrait `/calendrier-evenements-2/`.) Elle sert de sortie de secours : rien n'est mis à la corbeille à ce temps. Entre cette étape et la suivante, l'adresse `/calendrier-evenements/` peut répondre 404 quelques instants : enchaîner les deux étapes sans attendre.
2. **Donner l'adresse à la page de test** : la page de test, déjà renommée « Événements et appels », prend l'adresse `/calendrier-evenements/`. La publier (elle passe de brouillon ou privée à publiée). **Purger tout de suite le cache LiteSpeed** (Gestion › Purger tout), pour qu'aucun 404 ni aucune ancienne version de l'adresse ne reste en cache.
3. Choisir cette page dans Veille › Réglages › **Page Veille** (pour que les purges de cache visent la page publique).
4. **Vérifier** la page publiée, hors connexion : l'agenda s'affiche, le filtre **Qui organise › RSN** retrouve l'usage de l'ancienne page, le calendrier, les appels, le formulaire, le bandeau qui touche l'en-tête du thème, et la bande **« À la une du RSN » a son fond jusqu'aux bords de la fenêtre** sans défilement horizontal de la page (section 4). Vérifier les menus et les liens des pages d'accueil qui pointent vers `/calendrier-evenements/` (l'adresse est la même ; un menu qui visait l'ancienne page par son identifiant, et non par son adresse, est à corriger).
5. **Seulement ensuite**, désactiver l'extrait Code Snippets **61** (désactiver avant ferait afficher le raccourci brut sur l'ancienne page).
6. Purger le cache LiteSpeed du site une fois.

**Retour en arrière (voie A)** : l'inverse. Remettre la page de test en brouillon, lui redonner son ancienne adresse, rendre l'adresse `/calendrier-evenements/` à l'ancienne page Elementor et la republier, réactiver l'extrait 61 s'il a été désactivé, choisir de nouveau la page de test (ou l'ancienne) dans Réglages › Page Veille, purger le cache. L'extension peut rester active : elle ne modifie pas l'ancienne page.

**Voie B : garder l'ancienne page Elementor.**
1. **Garder une sortie de secours** : dupliquer la page `/calendrier-evenements/` (ou noter précisément son contenu actuel : le raccourci de l'extrait 61 et sa mise en page Elementor).
2. Dans la page `/calendrier-evenements/` (adresse conservée), remplacer le raccourci de l'extrait 61 par `[rsn_veille]` (dans le même widget Code court d'Elementor), enregistrer. **Rendu moins bon** : les sections et les colonnes d'Elementor ajoutent leurs marges, donc le bandeau ne touche pas l'en-tête du thème et l'adaptation de la classe `rsn-veille-page` ne vaut pas (section 4).
3. Choisir cette page dans Veille › Réglages › **Page Veille**.
4. **Vérifier** comme à l'étape 4 de la voie A (le bandeau qui touche l'en-tête en moins).
5. **Seulement ensuite**, désactiver l'extrait Code Snippets **61**.
6. Purger le cache LiteSpeed du site une fois.
7. Mettre à la corbeille la page de test (ou la garder en brouillon pour des essais).

**Retour en arrière (voie B)** : réactiver l'extrait 61 et remettre l'ancien raccourci (ou restaurer la copie de la page), purger le cache. L'extension peut rester active : elle ne modifie pas l'ancienne page.

### Temps 3 : la version anglaise avec WPML

1. Dans WPML › Réglages › **Traduction des types de publication** et **Traduction des taxonomies**, régler « Fiches de veille » (`rsn_veille`) et « Thèmes » (`rsn_theme`) sur **Non traduisible** : les fiches restent communes aux deux langues et l'écran des fiches n'est pas filtré par langue.
2. **Créer la traduction anglaise de la page** publique (`/calendrier-evenements/` dans la voie A, la page de test tant qu'elle n'est pas publique ; dans la voie B, l'ancienne page Elementor) avec WPML : même code court `[rsn_veille]` dans le contenu (les mêmes attributs, s'il y en a), titre « Events and calls ». La laisser hors menu tant que c'est un essai. L'extension la reconnaît : elle y charge ses ressources, pose la classe `rsn-veille-page` et purge ses caches. Elle garde ses caches (JSON et iCal) **par langue** (`rsn_veille_json_fr`, `rsn_veille_json_en`) et les vide tous ensemble ; elle purge aussi LiteSpeed et le cache Elementor de la page traduite (retrouvée par WPML à partir de la page choisie dans Réglages › Page Veille, qui reste la page française).
3. Après la mise à jour de l'extension et la création de la page : **LiteSpeed › Gestion › Purger tout**.
4. **Rien à traduire** dans WPML › String Translation : les textes anglais viennent du fichier `languages/rsn-veille-en_US.mo` livré avec l'extension (domaine `rsn-veille`). Si l'équipe veut retoucher une phrase, elle peut le faire dans String Translation (sa traduction prime sur le `.mo`) ; sinon, modifier le `.po` puis recompiler le `.mo` (`msgfmt -o languages/rsn-veille-en_US.mo languages/rsn-veille-en_US.po`) : un test échoue si le `.mo` ne suit pas le `.po`.
5. **Vérifier la page anglaise**, hors connexion, sur `/en/…` :
   - les textes (bandeau, « Featured by the RSN », filtres, mois, pastilles, formulaire) et les dates sont en anglais (« October 4–18, 2026 », « Nov. 5, 2026 », « 8:00 ») ; le calendrier aussi ;
   - **calendrier** : le bouton « Subscribe to the calendar » est un lien `webcal://…/en/veille.ics`, dont l'adresse `https://` répond en 200 avec des titres et des liens anglais (`/veille.ics` reste en français) ;
   - **événements du RSN** : leur version anglaise (titre, résumé, lieu, lien `/en/evenement/…`) ; un événement sans traduction anglaise s'affiche en français avec la pastille « In French » ;
   - **appels du RSN** : titre, extrait, lien et catégorie (« Funding calls ») traduits, même date limite ; un appel sans traduction est en français avec « In French » ;
   - **Mila et IVADO** : titre, résumé et lien anglais une fois la collecte passée ; sinon le français, marqué « In French » (`/wp-json/rsn/v1/veille?lang=en` : `langue` vaut `en` pour les fiches appariées) ;
   - Obvia, le FRQ et les autres sources restent en français avec « In French » ; Digital Health Canada n'a pas de pastille ;
   - **page française** : plus de doublon anglais des événements du RSN ;
   - **formulaire** : une proposition envoyée depuis la page anglaise arrive dans « À approuver » ; sa langue n'est pas visible dans la file, elle se lit avec `wp post meta get <ID> rsn_veille_langue` (`en`), et la fiche porte « En anglais » sur la page française une fois publiée ;
   - retirer une fiche dans la file et vérifier qu'elle disparaît aussi de la page anglaise.
6. **Limites à connaître** : thèmes et noms d'organisateurs restent en français ; l'administration, les courriels, l'infolettre et le Journal sont en français ; seuls Mila et IVADO sont appariés dans les deux langues. Sans WPML, `?lang=en` ne change rien. Voir « Limites connues » du `LISEZMOI.md`.

## 6. Bande de l'accueil

Depuis la 0.5.0, la bande « Prochains événements » de l'accueil est le code court `[rsn_veille_accueil]` de l'extension : un calendrier du mois, les événements du RSN et quatre événements de l'écosystème, en français et en anglais. L'ancienne bande (`[rsn_events_cards limit="3"]`, du thème enfant) reste disponible. Rien ne change sur le site tant que l'équipe n'a pas remplacé le code court. Une fois remplacé, les pastilles de catégorie de l'ancienne bande (qui menaient aux archives de The Events Calendar) disparaissent.

**Remplacer le code court, dans Elementor, sur les deux accueils :**
1. Ouvrir l'accueil français (page 152) dans Elementor. Dans la section qui suit l'en-tête de la bande, trouver le widget **Code court** qui contient `[rsn_events_cards limit="3"]` et remplacer son contenu par `[rsn_veille_accueil]`. Mettre à jour.
2. Faire la même chose sur sa traduction anglaise (page 1147) : le même code court rend la bande en anglais, selon la langue WPML de la page.
3. **L'en-tête de la bande** (la petite ligne, le titre, l'intro et le lien) **reste dans Elementor** : le code court n'y touche pas et l'équipe le modifie comme avant.
4. **Marges conseillées de la section d'en-tête** : 60 px en haut au lieu de 100, et 24 px en bas au lieu de 50, pour réduire la hauteur de l'ensemble. C'est un réglage d'Elementor, que l'extension ne fait pas.
5. **LiteSpeed › Gestion › Purger tout**, puis regarder l'accueil (`/` et `/en/`) hors connexion.

**Ce que la bande montre.**
- *Calendrier du mois* : mois courant au départ, deux flèches pour changer de mois (pas avant le mois courant, pas au-delà du dernier mois qui a un événement). Un point jaune marque les jours qui ont un événement du RSN, un point marine ceux qui ont un événement de l'écosystème ; une série de plus de sept jours ne pointe que son premier jour. Cliquer un jour pointé affiche dans la colonne de droite **tous** les événements de ce jour, ceux du RSN compris (pastille « RSN » sur leur ligne) ; recliquer le jour, ou « Tous », revient à la liste.
- *Au RSN* : aucun événement du RSN à venir, une phrase ; un, une grande carte ; deux, deux cartes compactes ; trois ou plus, les deux prochains et le lien « + N autres événements du RSN → » vers l'agenda dans la langue de la page.
- *Dans l'écosystème* : les quatre prochains événements qui ne sont pas du RSN, un seul par organisateur, sans série de plus de sept jours déjà commencée.
- Les événements sont ceux de l'agenda de la page Veille (les mêmes données, dans la langue de la page). Les appels n'y figurent pas.

**Caches et ressources.**
- Les données viennent du cache JSON de la page Veille (par langue, une heure au plus). La collecte de nuit, toute modification de fiche et la mise à jour de l'extension purgent aussi LiteSpeed et le cache Elementor de la page d'accueil et de ses traductions WPML. La page d'accueil est celle du réglage WordPress « Page d'accueil » (`page_on_front`) ; si ce réglage n'est pas une page statique, il n'y a rien à purger de ce côté. Si l'accueil est servi par un cache un jour plus tard, le script de la bande recalcule le jour entouré et les colonnes selon la date du jour (celle de Montréal, lue dans le navigateur).
- `accueil.css` et `accueil.js` (`?ver=0.6.1`) se chargent sur la page d'accueil et sur toute page dont le contenu contient `[rsn_veille_accueil]` : le CSS dans l'en-tête, le script en pied de page. **Un code court posé dans un widget d'une autre page Elementor que l'accueil** n'a ses ressources qu'au rendu : elles arrivent après le contenu. Cette page n'est pas purgée par la collecte : la bande y suit les caches de LiteSpeed et d'Elementor (une purge manuelle la met à jour). Le filtre `rsn_veille_charger_accueil` force le chargement en tête d'une page : `add_filter('rsn_veille_charger_accueil', '__return_true')`.

**Retour en arrière.** Dans Elementor, remettre `[rsn_events_cards limit="3"]` dans le widget Code court de la page 152 et de la page 1147 (et rétablir les marges de 100 px et de 50 px si elles ont été changées), puis purger LiteSpeed. L'extension peut rester active : tant que `[rsn_veille_accueil]` n'est dans aucune page, elle est sans effet visible : ses ressources se chargent sur l'accueil et l'accueil est purgé dès la mise à jour, mais rien ne s'y dessine de nouveau.

**Vérifier.** `tests/manuel/PARCOURS.md`, section 7 bis : bande de l'accueil en français et en anglais, calendrier, filtre par jour, mois, cas de 0, 1, 2 et 3 événements du RSN, hauteur, mobile.

## 7. Dépannage

| Symptôme | Cause probable | Quoi faire |
|---|---|---|
| Une source est en erreur dans le **Journal** | Site source en panne ou changé : flux devenu une page HTML, code 403, gabarit HTML modifié (« 0 fiche ») | Lire le message de la ligne. Une erreur isolée disparaît à la collecte suivante. Une erreur qui revient chaque nuit : décocher la source dans Réglages › Sources en attendant un correctif du lecteur. L'extension ne contourne jamais une protection anti-robot : un 403 reste une erreur |
| Une source répond **429** (trop de requêtes) | La source limite les lectures | Ne pas multiplier « Lancer la collecte maintenant » ; attendre la collecte de la nuit, ou décocher la source |
| La collecte ne part pas, ou le Journal reste vide | WP-Cron ne s'est pas déclenché (cache LiteSpeed, peu de visites, `DISABLE_WP_CRON` sans cron du serveur) | Section 1 : tâche cron du serveur toutes les 5 minutes. Vérifier avec `wp cron event list` si WP-CLI est disponible, ou avec une extension de visualisation de WP-Cron |
| La page Veille montre d'anciennes données après une publication | Cache LiteSpeed ou cache Elementor | Vérifier que la page est bien choisie dans Réglages › Page Veille (c'est elle qu'on purge) ; purger LiteSpeed › Gestion › Purger tout ; l'extension supprime aussi `_elementor_element_cache` de cette page |
| `/veille.ics` redirige ou renvoie une page | Règles de réécriture non régénérées | Réglages › Permaliens › Enregistrer (sans rien changer), ou désactiver puis réactiver l'extension |
| Le menu **Veille** est absent juste après une mise à jour | L'extension donne la capacité aux rôles pendant `admin_init`, après la construction du menu | Recharger la page une fois ; le menu apparaît (les activations normales n'ont pas ce délai) |
| Les styles de la veille sont écrasés (boutons, titres, majuscules, fonds) | Le kit Elementor ou UiCore impose ses règles | Mettre le raccourci dans un widget **Code court** (pas un widget Texte) ; si un style fuit encore, noter l'élément et son thème de style et le signaler pour un correctif du CSS de l'extension |
| Le formulaire répond « Trop de propositions » | Limite de débit : 5 envois par heure et par adresse, 30 par heure pour tout le site | Attendre une heure, ou vider les transients `rsn_veille_debit_*` |
| La page Radar s'ouvre lentement | La première ouverture lit les 12 flux (8 s au plus chacun) puis garde le résultat une heure | Attendre ; le bouton « Rafraîchir » relit les flux |

## 8. Confidentialité et sécurité

- **Courriel des proposants** : le formulaire « Proposer un événement ou un appel » demande un courriel de contact. C'est une **donnée personnelle** : l'extension l'enregistre dans une méta privée de la fiche (`rsn_veille_courriel_proposant`), ne l'affiche jamais sur la page, dans le JSON ni dans le fichier iCal, et ne s'en sert pas pour écrire. **La version 0.6.1 ne prévoit aucune durée de conservation automatique** : l'adresse reste avec la fiche tant que la fiche existe. À supprimer à la main : `wp post meta delete <ID> rsn_veille_courriel_proposant` avec WP-CLI (le type de contenu n'affiche pas le métabox « Champs personnalisés »), ou en supprimant la fiche entière, selon la politique de confidentialité du RSN. Une suppression automatique (par exemple 90 jours après la décision) est prévue pour une version suivante.
- **Formulaire public** : protégé par un champ piège invisible, un jeton, une limite de débit et la vérification de l'image (JPEG, PNG ou WebP, 3 Mo au plus) ; les fiches reçues sont toujours « à approuver ». Le lien proposé n'est jamais chargé par le serveur.
- **Limite de débit et adresse IP** : elle repose sur `REMOTE_ADDR`, seule source d'adresse digne de confiance (`X-Forwarded-For` se falsifie). **Si le site est derrière un mandataire inverse ou un réseau de diffusion de contenu, vérifier que le serveur transmet l'adresse réelle du visiteur dans `REMOTE_ADDR`** (module de restauration d'adresse). Sinon, tous les visiteurs partagent la même adresse et la limite de 5 envois par heure frappe tout le monde à la fois. L'adresse n'est jamais stockée en clair : le compteur est un transient dont le nom dérive de l'adresse hachée.
- **Jeton GitHub** : voir la voie 2 (section 2). Aucun secret n'est stocké par l'extension elle-même.
- **Données de l'équipe** : les fiches `rsn_veille` sont des contenus WordPress (sauvegardées avec la base du site).

## 9. Ce que fait l'activation, la désactivation, la désinstallation

- **Activation** : planifie la collecte quotidienne (`rsn_veille_nuit`, à l'heure des réglages), donne la capacité `rsn_veille_gerer` aux rôles des réglages, ajoute la règle de réécriture de `/veille.ics` et régénère les règles, planifie le courriel hebdomadaire s'il est coché.
- **Désactivation** : déplanifie les trois tâches (`rsn_veille_nuit`, `rsn_veille_source`, `rsn_veille_hebdo`) et retire la règle de `/veille.ics`. Les fiches, les réglages et la capacité restent.
- **Désinstallation** (Extensions › Supprimer, après désactivation) : `uninstall.php` supprime les options `rsn_veille_*` (réglages, file, journal, nouvelles gardées et ignorées, dernier courriel, verrou), les transients `rsn_veille_*` stockés dans la base (y compris ceux par langue ; avec un cache d'objets persistant, Redis ou Memcached, les transients ne sont pas dans `wp_options` et expirent d'eux-mêmes), les trois tâches planifiées, et la capacité `rsn_veille_gerer` de tous les rôles.
- **Ce que la désinstallation ne supprime pas** : les **fiches** `rsn_veille` (toutes les fiches, avec leurs métadonnées, y compris les courriels des proposants), la taxonomie « Thème » et ses termes, et les images téléversées par le formulaire dans la médiathèque. Ce sont des données de l'équipe. Pour tout supprimer à la main, **avec WP-CLI, seulement quand on est sûr, et tant que l'extension est encore active** (le type de contenu doit être enregistré) :
  ```
  # images du formulaire rattachées aux fiches, puis les fiches, puis les thèmes
  for id in $(wp post list --post_type=rsn_veille --post_status=any --format=ids); do
    wp post delete $(wp post list --post_type=attachment --post_parent=$id --format=ids) --force || true
  done
  wp post delete $(wp post list --post_type=rsn_veille --post_status=any --format=ids) --force
  wp term list rsn_theme --field=term_id | xargs wp term delete rsn_theme
  ```
  Faire ce nettoyage **avant** de supprimer l'extension ; après coup, il faudrait la réinstaller le temps du nettoyage.

## 10. Limites connues de la version 0.6.1

Voir la section « Limites connues » du `LISEZMOI.md`.

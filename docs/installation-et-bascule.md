# Installation et bascule sur rsn.quebec

Ce guide dit comment installer l'extension **RSN : veille de l'écosystème** (version 0.2.0) sur le site, quoi régler le premier jour, comment faire passer la page Veille à la place de la page Événements, et que faire quand quelque chose ne va pas. Rien n'est fait sur le site tant que la personne qui porte le projet ne l'a pas décidé : les étapes de la bascule se font une à une, avec un accord à chaque étape.

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
git archive --format=zip --prefix=rsn-veille/ -o rsn-veille-0.2.0.zip HEAD -- . ':!tests' ':!docs' ':!.wp-env.json' ':!.superpowers' ':!composer.lock' ':!phpunit.xml'
```

Le zip contient `rsn-veille.php`, `uninstall.php`, `includes/` et `assets/` (et les documents de racine), sans tests, sans documents de conception et sans `vendor/`. Dans wp-admin : **Extensions › Ajouter › Téléverser une extension**, choisir le zip, installer, puis activer.

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

Quelle que soit la voie, l'activation lance les mêmes opérations (voir section 8).

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
2. Avec l'éditeur de blocs : un bloc **Code court** contenant `[rsn_veille]`. Avec Elementor : le widget **Code court** (Shortcode) contenant `[rsn_veille]`, (voir « Bande « À la une du RSN » : fond jusqu'aux bords » plus bas).
3. Enregistrer en **brouillon**, ou en **privée** (visibilité « Privée » : seules les personnes connectées avec les droits voient la page, ce qui permet d'essayer la page sans la rendre publique). Ne pas ajouter la page au menu.
4. Prévisualiser. Le raccourci rend, sans attribut (`section="tout"`) : la barre d'actions (« S'abonner au calendrier », « Proposer un événement »), la bande « À la une du RSN », la bande « Dates limites », puis les filtres (recherche, Afficher, Qui organise, Mois, Format, Lieu, Thèmes, Organisateur) à côté de la liste unifiée des événements et des appels (ou du calendrier), et « Sources lues ». Attributs :
   - `section="agenda"` : les événements seuls (filtre Afficher verrouillé sur Événements, sans bande des dates limites) ;
   - `section="appels"` : la bande des dates limites puis les appels seuls, triés par date limite, sans colonne de filtres ;
   - `une="0"` : retire la bande « À la une du RSN » de la page complète (à employer quand le thème ou la page met déjà le RSN en avant ; la bande n'existe de toute façon que dans `section="tout"`). Exemple : `[rsn_veille une="0"]`.
5. Choisir cette page dans Veille › Réglages › Page Veille. La liste propose les pages publiées, en brouillon **et privées**.

**Bande « À la une du RSN » : fond jusqu'aux bords.** La bande garde la largeur de la colonne où se trouve le raccourci, et son fond marine est peint jusqu'aux bords de la fenêtre sans élargir la page : aucune section pleine largeur n'est requise et aucun défilement horizontal n'apparaît, quelle que soit la barre de défilement. Le contenu reste aligné sur celui de la page (1140 px au plus). Pour ne pas avoir de bande, mettre `une="0"`.

**Ce que montre la page.**
- *À la une du RSN* : les trois prochains éléments **organisés par le RSN**, événements (à venir ou en cours) et appels en cours **qui ont une date limite**, triés par date. Un appel sans date limite n'y figure pas (il n'a pas de date à montrer). Un événement court du RSN qui a commencé et n'est pas fini y reste ; une série de plus de 7 jours en sort dès qu'elle a commencé. Étiquettes : « Événement RSN » ; « Nouveau » pour un appel publié depuis 7 jours ou moins ; « Ferme dans N jours » (21 jours ou moins avant la date limite) ; sinon « Date limite : … ». Sans élément du RSN, la bande disparaît.
- *Dates limites* : une puce par appel en cours qui a une date limite, triées par date. Sans appel à date limite, la bande disparaît.
- *Liste unifiée* : événements et appels groupés par mois (début d'un événement, date limite d'un appel). Un appel sans date limite se trouve dans la liste seulement, classé dans le mois courant. Les appels apparaissent aussi dans le calendrier, à leur date limite.

Qui organise (RSN, Partenaires, Écosystème) vient de la catégorie de l'événement dans The Events Calendar, que l'extension reconnaît par son **slug** `rsn`, `partenaires` ou `ecosysteme` ou par son **nom** français ou anglais (par exemple « Événements RSN », « Événements partenaires », « RSN Events », « Partner Events »). Les anciens slugs `evenements-rsn*`, `rsn-events*`, `evenements-partenaires*` et `partner-events*` restent reconnus. Toute autre catégorie, dont `ecosysteme`, est classée Écosystème. Les fiches de la source du RSN (événements et appels) et celles dont l'organisateur est « RSN » sont classées RSN, quelle que soit leur catégorie.

L'onglet des appels n'existe plus : les appels sont dans la liste, et ne montrent que les appels en cours : date limite à venir (cherchée dans l'extrait, le contenu complet du flux et le titre), sinon date de fin à venir ; un appel sans aucune de ces dates est montré 90 jours après sa publication à la source, puis retiré (même règle pour le bloc Appels de l'infolettre et le courriel). Un appel encore ouvert après ce délai peut recevoir une date limite avec WP-CLI (`wp post meta update <ID> rsn_veille_echeance AAAA-MM-JJ`) ; il revient sur la page au plus tard une heure après (cache du JSON).

**File d'approbation.** Les onglets « À approuver » et « À vérifier » sont triés par arrivée (la plus récente en premier) ; une fiche arrivée aujourd'hui ou hier porte le repère « Nouveau ». « Publiées » et « Écartées » restent triées par date d'événement.

**Image d'une fiche.** La collecte réécrit l'image de la source à chaque passage. Pour garder une autre image, choisir une **image mise en avant** dans l'éditeur de la fiche (colonne latérale) : elle prime sur la page et dans le JSON, et la collecte n'y touche jamais. La boîte « Image de la fiche » de l'éditeur montre l'image de la source et rappelle cette règle ; elle n'a pas de champ à remplir. Pour revenir à l'image de la source, retirer l'image mise en avant.

Les ressources (`veille.css`, `veille.js`) ne se chargent que sur une page qui contient le raccourci (ou sur la page choisie dans les réglages). Les polices DM Serif Display et Plus Jakarta Sans sont déjà chargées par le site : l'extension ne les recharge pas (un autre thème peut les demander avec `add_filter('rsn_veille_charger_polices', '__return_true')`).

Sorties pour d'autres usages : `https://rsn.quebec/wp-json/rsn/v1/veille` (JSON public, sans les fiches à approuver) et `https://rsn.quebec/veille.ics` (calendrier iCal des événements publiés).

## 5. Bascule en trois temps

Chaque temps se fait seulement quand la personne qui porte le projet l'a demandé.

### Temps 1 : page en brouillon, quelques jours

Installer l'extension, faire les réglages (section 3), lancer la collecte, importer les infolettres, créer la page Veille **en brouillon** (section 4) et la prévisualiser. Pendant quelques jours : vérifier le rendu (ordinateur et mobile), approuver des fiches, lire le Journal chaque matin (la collecte de 3 h s'est-elle faite ?), essayer le formulaire « Proposer un événement » et la page Infolettre. Les extraits Code Snippets existants (44, 45, 53, 61, 62) ne sont pas touchés.

### Temps 2 : la page Veille remplace `/calendrier-evenements/`

1. **Garder une sortie de secours** : dupliquer la page `/calendrier-evenements/` (ou noter précisément son contenu actuel : le raccourci de l'extrait 61 et sa mise en page Elementor).
2. Dans la page `/calendrier-evenements/` (adresse conservée), remplacer le raccourci de l'extrait 61 par `[rsn_veille]` (dans le même widget Code court d'Elementor), enregistrer.
3. Choisir cette page dans Veille › Réglages › **Page Veille** (pour que les purges de cache visent la page publique, pas le brouillon).
4. **Vérifier** la page publiée, hors connexion : l'agenda s'affiche, le filtre **Qui organise › RSN** retrouve l'usage de l'ancienne page, le calendrier, les appels, le formulaire, et la bande **« À la une du RSN » a son fond jusqu'aux bords de la fenêtre** sans défilement horizontal de la page (section 4). Vérifier les menus et les liens des pages d'accueil qui pointent vers `/calendrier-evenements/`.
5. **Seulement ensuite**, désactiver l'extrait Code Snippets **61** (désactiver avant ferait afficher le raccourci brut).
6. Purger le cache LiteSpeed du site une fois.
7. Mettre la page Veille en brouillon, créée au temps 1, à la corbeille (ou la garder en brouillon pour des essais).

**Retour en arrière** : réactiver l'extrait 61 et remettre l'ancien raccourci (ou restaurer la copie de la page), purger le cache. L'extension peut rester active : elle ne modifie pas l'ancienne page.

### Temps 3 : la version anglaise avec WPML

1. Dans WPML › Réglages › **Traduction des types de publication** et **Traduction des taxonomies**, régler « Fiches de veille » (`rsn_veille`) et « Thèmes » (`rsn_theme`) sur **Non traduisible** : les fiches restent communes aux deux langues et l'écran des fiches n'est pas filtré par langue.
2. Traduire la page `/calendrier-evenements/` avec WPML (le raccourci `[rsn_veille]` se retrouve tel quel dans la version anglaise). L'extension garde ses caches (JSON et iCal) **par langue** (`rsn_veille_json_fr`, `rsn_veille_json_en`) et les vide tous ensemble ; elle purge aussi LiteSpeed et le cache Elementor de la page traduite (retrouvée par WPML à partir de la page choisie dans Réglages › Page Veille, qui reste la page française).
3. Les textes de l'extension passent par la traduction de WordPress (domaine `rsn-veille`). La version 0.2.0 ne livre **aucun fichier de traduction** : la page anglaise affiche les textes français tant que les chaînes ne sont pas traduites. Cela vaut aussi pour les textes des bandes « À la une du RSN » et « Dates limites », écrits par le JavaScript de la page : ils sont dans la même liste de chaînes du domaine « rsn-veille ». Les traduire avec WPML › String Translation (chaînes du domaine « rsn-veille »), ou avec une extension comme Loco Translate.
4. Vérifier la page anglaise : les mêmes fiches, les dates, le formulaire, `veille.ics` ; retirer une fiche dans la file et vérifier qu'elle disparaît aussi de la page anglaise (hors connexion).

## 6. Dépannage

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

## 7. Confidentialité et sécurité

- **Courriel des proposants** : le formulaire « Proposer un événement » demande un courriel de contact. C'est une **donnée personnelle** : l'extension l'enregistre dans une méta privée de la fiche (`rsn_veille_courriel_proposant`), ne l'affiche jamais sur la page, dans le JSON ni dans le fichier iCal, et ne s'en sert pas pour écrire. **La version 0.2.0 ne prévoit aucune durée de conservation automatique** : l'adresse reste avec la fiche tant que la fiche existe. À supprimer à la main : `wp post meta delete <ID> rsn_veille_courriel_proposant` avec WP-CLI (le type de contenu n'affiche pas le métabox « Champs personnalisés »), ou en supprimant la fiche entière, selon la politique de confidentialité du RSN. Une suppression automatique (par exemple 90 jours après la décision) est prévue pour une version suivante.
- **Formulaire public** : protégé par un champ piège invisible, un jeton, une limite de débit et la vérification de l'image (JPEG, PNG ou WebP, 3 Mo au plus) ; les fiches reçues sont toujours « à approuver ». Le lien proposé n'est jamais chargé par le serveur.
- **Limite de débit et adresse IP** : elle repose sur `REMOTE_ADDR`, seule source d'adresse digne de confiance (`X-Forwarded-For` se falsifie). **Si le site est derrière un mandataire inverse ou un réseau de diffusion de contenu, vérifier que le serveur transmet l'adresse réelle du visiteur dans `REMOTE_ADDR`** (module de restauration d'adresse). Sinon, tous les visiteurs partagent la même adresse et la limite de 5 envois par heure frappe tout le monde à la fois. L'adresse n'est jamais stockée en clair : le compteur est un transient dont le nom dérive de l'adresse hachée.
- **Jeton GitHub** : voir la voie 2 (section 2). Aucun secret n'est stocké par l'extension elle-même.
- **Données de l'équipe** : les fiches `rsn_veille` sont des contenus WordPress (sauvegardées avec la base du site).

## 8. Ce que fait l'activation, la désactivation, la désinstallation

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

## 9. Limites connues de la version 0.2.0

Voir la section « Limites connues » du `LISEZMOI.md`.

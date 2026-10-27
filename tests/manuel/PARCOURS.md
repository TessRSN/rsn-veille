# Parcours de vérification de bout en bout (version 0.1.0)

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
Contrôler au passage la purge : après un changement de statut, `wp post meta get <page> _elementor_element_cache`
est vide pour la page Veille choisie.

**Terminé quand** : toutes les cases de « Vérifications » sont cochées, y compris « Aucune erreur PHP dans
`debug.log` ni dans la console du navigateur ».

## 7. Page publique

1. `verifier-public.md`, section **Préparation**, points 2 à 4 (fiches publiées, à approuver, passées, avec image,
   avec échéance ; événements TEC ; page « Veille » contenant `[rsn_veille]`, bloc Code court puis widget
   Code court d'Elementor, choisie dans Veille › Réglages).
2. Section **Page publique** : liste, filtres, calendrier, appels, événements passés, style de la maquette v6.1,
   absence du mode équipe, titre piégé en texte, mobile, `section="agenda"` et `section="appels"`.
3. Section **Ressources et cohabitation** : ressources seulement sur la page Veille, polices, Elementor, cache
   (publier une fiche puis recharger hors connexion), WPML si installé.
4. Section **REST et iCal** : `/wp-json/rsn/v1/veille`, `/veille.ics` (CRLF, en-têtes, validateur iCal,
   abonnement dans un agenda).

**Terminé quand** : toutes les cases de ces trois sections sont cochées, sauf celles du formulaire (étape 8).

## 8. Formulaire « Proposer un événement »

`verifier-public.md`, section **Formulaire « Proposer un événement »** (hors connexion, fenêtre privée) :
envoi complet avec image, entrée dans « À approuver » (source `proposition`, niveau M, courriel en méta privée),
absence de la fiche et du courriel sur la page, le JSON et l'iCal tant qu'elle n'est pas publiée, limite de
débit (sixième envoi refusé), champ piège, erreurs de saisie, doublon, jeton, administrateur connecté, image
(nom aléatoire, écart de la proposition supprime l'image), plafond du site. Entre deux séries, vider les
compteurs : `wp transient delete --all`.

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

# Vérification manuelle de l'administration (tâches 11, 12, 15, 16 et 17)

À faire dans un WordPress réel (`npx @wordpress/env start`, extension active), les tests PHPUnit
n'utilisant qu'une imitation de WordPress (`tests/bouchons-wordpress.php`). Les commandes `wp` se
lancent par `npx wp-env run cli wp …`.

## Préparation

1. Désactiver puis réactiver l'extension (l'activation donne la capacité `rsn_veille_gerer`) :
   `wp plugin deactivate rsn-veille && wp plugin activate rsn-veille`.
2. Vérifier les rôles : `wp cap list editor | grep rsn_veille_gerer` et
   `wp cap list administrator | grep rsn_veille_gerer` affichent la capacité ;
   `wp cap list author | grep rsn_veille_gerer` et `wp cap list subscriber | grep rsn_veille_gerer` n'affichent rien.
3. Créer deux comptes de test (WP-CLI affiche le mot de passe généré ; il ne sert que dans wp-env) :
   `wp user create redaction redaction@exemple.test --role=editor` et
   `wp user create lecteur lecteur@exemple.test --role=subscriber`.
4. Créer des fiches : `wp eval-file wp-content/plugins/rsn-veille/tests/manuel/lancer-collecte.php`
   (vraie collecte) ou, plus court, trois fiches à la main :

   ```
   wp eval '$e = new RSN\Veille\EntrepotWordPress();
   foreach (["a_approuver", "a_approuver", "publie"] as $i => $s) {
       $f = new RSN\Veille\Fiche(); $f->type = "evenement"; $f->titre = "Fiche admin " . $i . " <script>alert(1)</script>";
       $f->debut = gmdate("Y-m-d", time() + ($i + 5) * DAY_IN_SECONDS); $f->source = "Vérification"; $f->niveau = "A";
       $f->organisateur = "RSN"; $f->resume = "Fiche de vérification de la file."; $f->url = "https://exemple.test/" . $i;
       $f->statut = $s; $f->cle_source = "verif-admin:" . $i; $f->cles_source = [$f->cle_source];
       echo $e->creer($f), PHP_EOL;
   }'
   ```

## Vérifications

- [ ] **Éditeur** (compte `redaction`) : le menu « Veille » (icône de flux) apparaît sous « Commentaires »,
      avec File d'approbation, Radar, Infolettre, Journal, Réglages.
- [ ] **Abonné** (compte `lecteur`) : pas de menu « Veille » ; l'adresse
      `/wp-admin/admin.php?page=rsn-veille` répond « Désolé, vous n'avez pas l'autorisation… ».
- [ ] Le titre `<script>` s'affiche en texte (aucune alerte), avec le lien « Modifier » qui ouvre
      l'éditeur WordPress de la fiche et le lien « Page d'origine » dans un nouvel onglet.
- [ ] Onglet « À approuver » : **Publier** retire la ligne sans recharger, le compteur « À approuver »
      baisse de 1 et « Publiées » monte de 1 ; `wp post get <id> --field=post_status` donne `publish`
      et `wp post meta get <id> rsn_veille_manuel` donne `1`.
- [ ] **Écarter** retire la ligne ; statut `draft`.
- [ ] Onglet « Publiées » : **Retirer** retire la ligne ; statut `draft`.
- [ ] Onglet « Écartées » : **Publier** remet la fiche en `publish` ; les écartées sont triées de la
      plus récente à la plus ancienne.
- [ ] Filtres : « Appels » ne montre que les appels ; la liste des sources contient « Vérification » et
      la filtre ; les onglets gardent les filtres choisis.
- [ ] **À vérifier** : marquer une fiche publiée absente
      `wp eval '(new RSN\Veille\EntrepotWordPress())->marquer_absent(<id>, "2026-10-20");'`, puis
      recharger : l'onglet « À vérifier (1) » la liste avec « Absent de la source depuis le
      20 octobre 2026 ». **Garder publiée** la retire de l'onglet, `rsn_veille_absent_depuis` n'existe
      plus et `rsn_veille_absences` vaut 0. **Retirer** (sur une autre fiche marquée) la passe en `draft`.
- [ ] Jeton expiré : dans la console du navigateur, `rsnVeilleAdmin.nonce = 'x'`, puis un bouton :
      message « Jeton de sécurité expiré : rechargez la page. » dans la ligne, fiche inchangée.
- [ ] Réglage des rôles : `wp option patch insert rsn_veille_reglages roles '["author"]' --format=json`
      (ou `wp option update rsn_veille_reglages '{"roles":["author"]}' --format=json` si l'option n'existe
      pas), puis ouvrir une page d'administration : `wp cap list editor` n'a plus `rsn_veille_gerer`,
      `author` et `administrator` l'ont. Remettre ensuite `["administrator","editor"]`.
- [ ] **Journal** : après une collecte, « Dernière collecte terminée : … » en date lisible, une ligne
      par source (les sources jamais collectées : « jamais collectée »), erreurs repliées sous
      « N erreurs », et le déroulé de chaque source replié en bas de page.
- [ ] Les fichiers `admin.css` et `admin.js` ne sont chargés que sur les pages du menu Veille (onglet
      Réseau des outils du navigateur sur `/wp-admin/edit.php` : absents), avec `?ver=0.1.0`.
- [ ] Aucune erreur PHP dans `wp-content/debug.log` ni dans la console du navigateur.

## Réglages (tâche 12)

- [ ] **Éditeur** (compte `redaction`) : la page Réglages s'ouvre, et **Enregistrer les réglages** réussit
      (« Réglages enregistrés. »), sans « Désolé, vous n'avez pas l'autorisation » d'options.php.
- [ ] **Abonné** : `/wp-admin/admin.php?page=rsn-veille-reglages` refusé ; un envoi direct à
      `/wp-admin/admin-post.php` avec `action=rsn_veille_collecter` répond en 403.
- [ ] Haut de page : « Dernière collecte terminée » (ou « aucune pour l'instant ») et « Prochaine collecte
      planifiée » en heure de Montréal, identique à `wp cron event list --hook=rsn_veille_nuit` (heure UTC
      convertie).
- [ ] Avec `define('DISABLE_WP_CRON', true);` dans `wp-config.php` : avertissement « la tâche cron du
      serveur doit appeler wp-cron.php ». Le retirer ensuite.
- [ ] **Sources** : décocher « Gouvernement du Québec : fil de presse » (`quebec_ca`) et « Obvia :
      événements », enregistrer ; `wp option get rsn_veille_reglages --format=json` montre
      `"obvia":{"actif":false,…}`. **Lancer la collecte maintenant** → message « Collecte lancée : N sources
      en file » ; `wp option get rsn_veille_file` ne contient ni `obvia` ni `quebec_ca` ;
      `wp cron event list --hook=rsn_veille_source` montre une tâche échue (pas dans 60 s) ; quelques
      minutes plus tard, le Journal ne montre pas de nouvelle ligne pour Obvia. Recocher ensuite.
- [ ] Niveau : passer une source au niveau M, enregistrer, recharger : M reste choisi.
- [ ] **Mots-clés bordés** : la zone « Mots-clés numérique » montre ` ia ` (espaces visibles en
      sélectionnant la ligne). Ajouter une ligne ` iot ` (espace avant et après) et une ligne `Robotique`,
      enregistrer : `wp option get rsn_veille_reglages --format=json` contient `" iot "` (espaces gardées)
      et `"robotique"` (minuscules) ; recharger la page : la ligne ` iot ` a toujours ses deux espaces ;
      `wp eval 'var_dump(RSN\Veille\Collecte::pertinence()->touche("Atelier IoT en santé"), RSN\Veille\Collecte::pertinence()->touche("Patriote"));'`
      → numérique vrai pour le premier, faux pour le second (« iot » ne touche pas « Patriote »).
- [ ] **Thèmes** : ajouter la ligne `Objets connectés :  iot , capteur` (deux espaces avant `iot`),
      enregistrer, recharger : la ligne revient identique ; l'option contient
      `{"nom":"Objets connectés","mots":[" iot ","capteur"]}`. Une ligne sans « : » est signalée
      (« Thème ignoré (ligne N) ») et retirée.
- [ ] Valeurs invalides (outils du navigateur : changer `value` du champ heure en `25:00`, une adresse
      `pas-une-adresse` dans les destinataires) : enregistrer → messages d'erreur pour l'heure et
      l'adresse, l'heure revient à 03:00, l'adresse est retirée, les autres adresses gardées.
- [ ] **Rôles** : la case Administrateur est cochée et grisée. Cocher Auteur, enregistrer :
      `wp cap list author | grep rsn_veille_gerer` affiche la capacité ; décocher : elle disparaît.
      Décocher Éditeur : l'éditeur perd l'accès, l'administrateur le garde. Remettre Éditeur.
- [ ] **Heure** : passer à 04:30, enregistrer : `wp cron event list --hook=rsn_veille_nuit` montre une
      seule tâche, au prochain 04:30 de Montréal. Remettre 03:00.
- [ ] **Page Veille** : la liste montre les pages publiées et en brouillon ; en choisir une, enregistrer ;
      changer le statut d'une fiche dans la file : `wp post meta get <page> _elementor_element_cache` est vide.
- [ ] **Import** : importer `docs/maquette/evenements-infolettres-2025-2026.json` après une collecte du RSN →
      « Import terminé : 24 lus, N créés, M fusionnés, … » avec M ≥ 1 (le Hackathon en santé numérique 2025
      et les autres événements du RSN déjà collectés) et N + M = 24 ; aucun doublon dans la file
      (onglet Publiées). Réimporter le même fichier → « 0 créé, 0 fusionné, 24 inchangés ».
- [ ] Une fiche importée (onglet Publiées) : source `infolettre`, niveau M, organisateur réel, thèmes des
      étiquettes du fichier ; les événements d'un même numéro d'infolettre (même adresse) restent distincts.
- [ ] Import refusé : un fichier `.csv` → « Le fichier doit porter l'extension .json » ; un fichier
      `.json` de plus de 2 Mo → « Fichier trop gros » ; un `.json` invalide → « Fichier illisible » ;
      aucune fiche créée.
- [ ] Jeton : recharger la page, changer la valeur du champ caché `_wpnonce` d'un formulaire d'action
      dans les outils du navigateur, envoyer → page « Le lien que vous avez suivi a expiré », rien n'est fait.
- [ ] Largeur mobile (outils du navigateur, 375 px) : pas de défilement horizontal de la page ; les zones
      de mots-clés défilent seules.

## Radar (tâche 15)

Page Veille > Radar (`/wp-admin/admin.php?page=rsn-veille-radar`). Le premier affichage lit les 12
flux de nouvelles un à un (8 s au plus chacun), chaque source étant gardée une heure dès qu'elle est lue
(transients `rsn_veille_radar_{id}`), puis le résultat complet l'est aussi (`rsn_veille_radar`) avec une
copie de 24 h (`rsn_veille_radar_dernier`). Un verrou de 2 min (`rsn_veille_radar_lecture`) évite que deux
éditeurs relancent les flux ensemble.

- [ ] La page s'ouvre sans erreur PHP. En haut : bouton **Rafraîchir**, « 0 gardées », « Flux lus le … » ;
      section « Gardées pour l'infolettre » (message « Aucune nouvelle gardée pour l'instant. ») puis
      « Nouvelles du radar », groupées par source, la plus récente d'abord.
- [ ] Chaque nouvelle montre la date, le titre (lien qui s'ouvre dans un nouvel onglet), « Repérée » ou
      « À trier », un extrait, et les boutons **Garder pour l'infolettre** et **Ignorer**. Aucune nouvelle
      de plus de 60 jours. Une source généraliste (FRQ, Acfas) donne surtout des « À trier ».
- [ ] Les sources en erreur (un 403, un flux devenu une page HTML) sont listées en bas, repliées, sous
      « N sources en erreur », sans casser les autres sources.
- [ ] **Garder** deux nouvelles : chaque ligne disparaît du radar, le message « Nouvelle gardée pour
      l'infolettre. » est annoncé (zone `role="status"`) et le compteur passe à « 1 gardée », « 2 gardées ».
      Recharger : les deux sont en tête, la plus récemment gardée d'abord, avec « gardée le … » et un
      bouton **Retirer** ; elles ne sont plus dans le radar.
      `wp option get rsn_veille_nouvelles_gardees --format=json` : champs `url, titre, date, source,
      organisateur, resume, gardee_le`.
- [ ] **Retirer** une gardée : la ligne disparaît, le compteur baisse ; après rechargement la nouvelle
      réapparaît dans le radar (si elle est encore dans le flux).
- [ ] **Ignorer** une nouvelle : elle disparaît ; après rechargement et après **Rafraîchir**, elle ne
      reparaît pas. `wp option get rsn_veille_nouvelles_ignorees --format=json` contient son adresse.
- [ ] **Rafraîchir** : la page se recharge (lecture des flux de nouveau, un peu plus longue) et « Flux
      lus le … » indique la minute courante. `wp transient get rsn_veille_radar` existe ensuite.
- [ ] **Verrou** : `wp transient set rsn_veille_radar_lecture 1 120` puis `wp transient delete rsn_veille_radar`
      et supprimer les `rsn_veille_radar_{id}` : la page montre « Rafraîchissement en cours … » avec ce qui
      est déjà en cache, sans relire les flux. `wp transient delete rsn_veille_radar_lecture` : le
      rechargement relit les sources manquantes.
- [ ] **Garder sans réseau** : `wp transient delete rsn_veille_radar` et les `rsn_veille_radar_{id}`, puis
      Garder une nouvelle déjà affichée dans l'onglet resté ouvert : elle est gardée (copie de 24 h) sans
      aucune requête sortante ; une adresse inconnue → 404 « rafraîchissez la page ».
- [ ] Titre et résumé ne viennent jamais de la requête : dans la console du navigateur, envoyer
      `fetch(ajaxurl, {method:'POST', body:new URLSearchParams({action:'rsn_veille_garder', nonce:rsnVeilleAdmin.radar.nonce, url:'https://exemple.org/pirate', titre:'forgé'})})`
      → réponse 404 « Cette nouvelle n'est plus dans le radar : rafraîchissez la page », rien d'enregistré. Avec l'adresse d'une
      nouvelle du radar et un `titre` forgé, la gardée porte le vrai titre du flux.
- [ ] Jeton et droits : même requête sans `nonce` → 403 ; connecté comme abonné
      (`wp user create lecteur lecteur@exemple.test --role=subscriber`) → la page est refusée (403) et les
      actions AJAX répondent 403. Une adresse `javascript:alert(1)` ou `ftp://x` → 400.
- [ ] Largeur mobile (375 px) : pas de défilement horizontal de la page ; les boutons passent à la ligne.

## Infolettre (tâche 16)

Page Veille > Infolettre (`/wp-admin/admin.php?page=rsn-veille-infolettre`). Trois blocs HTML aux styles en
ligne (tableaux), calqués sur l'infolettre de septembre 2026 : **Agenda** (événements à venir publiés et
événements du RSN, comme la page publique), **Appels** (appels publiés non échus et programmes du RSN ouverts,
articles de la catégorie 149 pour lesquels `rsn_appel_etat()` répond « ouvert »), **Nouvelles gardées**
(option `rsn_veille_nouvelles_gardees`, la plus récente d'abord). Préparation : publier quelques fiches
(événements et un appel avec date limite), garder deux nouvelles dans le Radar, et, pour les programmes,
avoir des articles de la catégorie 149 avec l'extrait 45 du site actif.

- [ ] La page s'ouvre sans erreur PHP, avec trois sections (Agenda, Appels, Nouvelles gardées), chacune
      avec un compteur (« 8 événements »), un aperçu rendu dans un cadre, les boutons **Copier** et
      **Copier le code HTML**. L'aperçu montre un tableau lisible : colonne de date de 64 px (jour en
      bleu marine, mois en capitales), titre en gras, organisateur · lieu en gris, thèmes en capitales
      bleues, résumé, « Plus d'information → ».
- [ ] **Copier (collage enrichi)** sur le bloc Agenda, puis coller dans un éditeur enrichi (LibreOffice
      Writer, ou le corps d'un courriel) : la mise en forme est conservée (gras, couleurs, colonnes). Coller
      dans un éditeur de texte brut : la version texte (une information par ligne, adresse après chaque lien).
      Message « Bloc copié : collez-le dans Mailchimp. » annoncé sous le titre de la page.
- [ ] **Copier le code HTML** puis coller dans un bloc **Code** de Mailchimp (ou un fichier .html) : le code
      commence par le commentaire `<!-- Agenda — événements à venir (généré par la veille de l'écosystème, … ) -->`,
      ne contient ni `<script>` ni `<style>`, et l'aperçu Mailchimp reproduit celui de la page.
- [ ] Même vérification pour les blocs **Appels** (« Date limite » ou « Publié », jour, mois ; « Consulter
      l'appel → ») et **Nouvelles gardées** (« 20 oct. 2026 · Source », titre en gras, « Lire la suite → »).
- [ ] Repli sans API moderne : dans la console, `delete window.ClipboardItem` puis **Copier** : la copie
      fonctionne encore (sélection du rendu de l'aperçu, `execCommand`).
- [ ] **Nombre d'événements** : choisir 6, puis 12, **Appliquer** : l'adresse porte `&nombre=6` et l'agenda
      montre 6 événements au plus (le compteur suit). Une date dans « ou jusqu'au » remplace le nombre :
      tous les événements jusqu'à cette date (compris). Une valeur forgée dans l'adresse (`nombre=7`,
      `jusqu_au=pas-une-date`) est ignorée (8 événements, sans horizon).
- [ ] Un événement dont le titre contient `<b>gras</b> & <script>alert(1)</script>` (voir la préparation)
      s'affiche en texte dans l'aperçu, dans le code copié (`&lt;b&gt;…`) et dans le texte : aucune alerte.
- [ ] Dates : un événement de plusieurs jours du même mois montre « 1–3 » ; sur deux mois,
      « 28 nov. – 2 » avec le mois de fin. Un événement terminé hier n'apparaît pas ; un événement en cours,
      si.
- [ ] **Appels** : un appel dont la date limite est passée est absent ; l'ordre va de la date limite la plus
      proche à celle sans date limite. Un programme du RSN ouvert (catégorie 149) apparaît avec
      « RSN · Programme du RSN » ; avec sa période « date limite : … », « Date limite » + la date ; sans date
      lisible, « Publié » + sa date de publication. Un appel collecté qui pointe vers la même page n'est pas
      répété. Sans la fonction `rsn_appel_etat` (extrait désactivé), aucun programme et aucune erreur.
- [ ] Bloc sans élément : le message « Aucun élément à inclure pour le moment. » s'affiche dans l'aperçu et
      les deux boutons de copie sont grisés.
- [ ] **Vider les nouvelles gardées** : le bouton demande confirmation ; confirmer vide la liste
      (`wp option get rsn_veille_nouvelles_gardees --format=json` → `[]`), retourne sur la page avec
      « Nouvelles gardées vidées. » et un bloc Nouvelles vide. Annuler ne fait rien. Le Radar ne montre plus
      de gardées.
- [ ] Jeton et droits : changer la valeur du champ caché `_wpnonce` du formulaire « Vider » dans les outils du
      navigateur, envoyer → « Le lien que vous avez suivi a expiré », rien n'est vidé. Compte `lecteur` :
      `/wp-admin/admin.php?page=rsn-veille-infolettre` est refusé, et un POST direct vers `admin-post.php`
      (`action=rsn_veille_infolettre_vider`) répond 403.
- [ ] Le cadre d'aperçu n'exécute aucun script (attribut `sandbox` sans `allow-scripts`) ; un clic sur un
      lien de l'aperçu ouvre un nouvel onglet, la page ne change pas.
- [ ] Largeur mobile (375 px) : pas de défilement horizontal de la page.

## Courriel hebdomadaire (tâche 17)

Courriel optionnel (Veille › Réglages › Courriel hebdomadaire) : fiches publiées par la collecte depuis sept
jours, fiches à approuver, nouvelles repérées du Radar (lues dans le cache, aucune requête réseau). Tâche
`rsn_veille_hebdo`, récurrence hebdomadaire, le jour choisi à 8 h (heure de Montréal). Pour voir les courriels
sans les envoyer : MailHog (conteneur `mailhog/mailhog`, SMTP 1025, interface 8025) avec une extension SMTP
ou un `mu-plugin` qui règle PHPMailer sur `host.docker.internal:1025` ; à défaut, `wp eval` ci-dessous affiche
le résultat de `wp_mail`. Préparation : les trois fiches de la section Préparation (`cree_le` est posé à la
création), ouvrir le Radar une fois (cache), et créer une fiche ancienne :
`wp post meta update <id> rsn_veille_cree_le 2026-01-01` sur l'une des fiches publiées.

- [ ] Réglages › Courriel hebdomadaire : **Dernier envoi** affiche « aucun pour l'instant », **Prochain envoi
      planifié** « aucun ». Cocher l'option, deux adresses (une par ligne), jour **mercredi**, enregistrer :
      `wp cron event list --hook=rsn_veille_hebdo` montre un événement `1 week`, un mercredi à 08:00 heure de
      Montréal (l'heure UTC affichée par WP-CLI est 12:00 ou 13:00 selon la saison), et la page affiche le même
      moment. Changer le jour pour vendredi, enregistrer : un seul événement, le vendredi. Modifier seulement
      une adresse : l'événement ne bouge pas. Décocher l'option, enregistrer : `wp cron event list` n'a plus
      d'événement `rsn_veille_hebdo`.
- [ ] Désactiver puis réactiver l'extension avec l'option cochée : l'événement est retiré puis replanifié ;
      option décochée : aucun événement après réactivation.
- [ ] **Envoyer un essai maintenant** (option cochée, enregistrée) : message « Courriel d'essai envoyé (N
      nouveautés) à M destinataires. » ; MailHog reçoit un courriel au sujet « Veille de l'écosystème : N
      nouveautés cette semaine » (N = fiches publiées créées depuis 7 jours + toutes les fiches à approuver),
      en HTML avec une version texte (onglet « Plain text »), expédié aux adresses saisies. **Dernier envoi**
      affiche la date et « N nouveautés, M destinataires ». `wp option get rsn_veille_dernier_courriel --format=json`
      → `date`, `destinataires`, `nb`, `ok: true`.
- [ ] Contenu : sections « Publiées automatiquement », « À approuver » (avec le bouton jaune « Ouvrir la file
      d'approbation » qui mène à Veille › File), « Nouvelles repérées » (seulement si le Radar a un cache et
      des nouvelles de moins de 7 jours ni gardées ni ignorées) ; la fiche ancienne (`cree_le` 2026-01-01) et
      les fiches écartées sont absentes ; pied « Envoyé par l'extension RSN : veille de l'écosystème … ». Le
      titre `<script>alert(1)</script>` de la préparation s'affiche en texte, sans alerte. Aucune requête
      réseau pendant l'envoi (sources du Radar non relancées : vider le cache avec
      `wp transient delete rsn_veille_radar rsn_veille_radar_dernier` ; la section disparaît, l'envoi réussit).
- [ ] Sans nouveauté (passer toutes les fiches à `ecarte`, ou `cree_le` ancienne, et vider la file) : l'essai
      répond « Aucune nouveauté cette semaine : le courriel n'est pas envoyé. » et MailHog ne reçoit rien.
      Option décochée : « L'envoi est désactivé … » ; liste de destinataires vide : « Aucun destinataire … ».
- [ ] Même chose sans MailHog : `wp eval 'var_dump(RSN\Veille\Courriel::envoyer());'` affiche `bool(true)` si
      `wp_mail` a remis le courriel (`bool(false)` sinon, `wp_mail_failed` donne la raison).
- [ ] Tâche planifiée : `wp cron event run rsn_veille_hebdo` envoie le même courriel ; l'événement suivant
      reste planifié, au même jour à 08:00 (après le changement d'heure, `recaler` le ramène à 8 h).
- [ ] Jeton et droits : changer `_wpnonce` du formulaire d'essai dans les outils du navigateur, envoyer → « Le
      lien que vous avez suivi a expiré », rien n'est envoyé. Compte `lecteur` : un POST direct vers
      `admin-post.php` (`action=rsn_veille_courriel_essai`) répond 403.
- [ ] Largeur mobile (375 px) : le bouton « Envoyer un essai maintenant » reste lisible, pas de défilement
      horizontal ; le courriel s'affiche sans défilement horizontal dans MailHog (aperçu mobile).

## Nettoyage

`wp post delete $(wp post list --post_type=rsn_veille --meta_key=rsn_veille_source --meta_value=Vérification --format=ids --post_status=any) --force`
puis `wp user delete redaction lecteur --yes`. Radar et Infolettre : `wp option delete rsn_veille_nouvelles_gardees rsn_veille_nouvelles_ignorees`
et `wp transient delete rsn_veille_radar rsn_veille_radar_dernier rsn_veille_radar_lecture`. Fiches importées :
`wp post delete $(wp post list --post_type=rsn_veille --meta_key=rsn_veille_source --meta_value=infolettre --format=ids --post_status=any) --force`.

Courriel hebdomadaire : `wp option delete rsn_veille_dernier_courriel`, puis décocher l'option dans les réglages (ou `wp cron event delete rsn_veille_hebdo`).

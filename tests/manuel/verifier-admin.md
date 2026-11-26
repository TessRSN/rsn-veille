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
- [ ] Onglet « À approuver » : **Publier + infolettre** (0.15.0 ; « Publier » avant) retire la ligne sans recharger,
      le compteur « À approuver » baisse de 1 et « Publiées » monte de 1 ; `wp post get <id> --field=post_status`
      donne `publish` et `wp post meta get <id> rsn_veille_manuel` donne `1`.
- [ ] **Écarter** retire la ligne ; statut `draft`.
- [ ] Onglet « Publiées » : **Retirer de la page** (0.15.0 ; « Retirer » avant) retire la ligne ; statut `draft`.
- [ ] **Type et niveaux** (0.15.0) : la colonne Type montre une pastille bleue « Événement » (icône de calendrier)
      ou violette « Appel » (porte-voix). Sous le nom de la source : « niveau A : flux structuré » (ou C, M, B en
      clair). Sous le tableau, « Que veulent dire les niveaux ? » se déplie sur quatre lignes.
- [ ] **Rangement par date** (0.15.0) : « À approuver » et « Publiées » vont de la date la plus proche à la plus
      lointaine ; un appel est rangé à sa **date limite**, pas à sa date de publication ; une fiche sans date est
      à la fin.
- [ ] **Archivées** (0.15.0) : l'onglet « Archivées » liste les événements passés et les appels échus, du plus
      récent au plus ancien, avec « rien n'est supprimé » en tête. Aucun d'eux n'est dans « Publiées » ni dans
      « À approuver ». Passer la date d'un événement publié à hier (éditeur de la fiche) : il passe dans
      « Archivées » ; les nombres des onglets suivent ; il reste sur le calendrier public. Une fiche en attente
      dépassée y porte « Jamais publiée » et n'a que le bouton **Écarter**.
- [ ] **Infolettre du RSN** (0.15.0) : approuver une fiche par **Publier seulement** : dans « Publiées », sa
      colonne « Infolettre du RSN » dit « Pas dans l'infolettre », et Veille > Infolettre ne la reprend pas (note
      « 1 événement publié est tenu hors de l'infolettre » sous le bloc). **Mettre dans l'infolettre** : la
      pastille devient verte sans recharger, et la fiche revient dans le bloc. Une fiche approuvée par
      **Publier + infolettre**, ou publiée par la collecte, est « Dans l'infolettre ».
      `wp option get rsn_veille_hors_infolettre --format=json` liste les identifiants tenus hors de l'infolettre.
- [ ] Onglet « Écartées » : **Publier** remet la fiche en `publish` ; les écartées sont triées de la
      plus récente à la plus ancienne.
- [ ] **Tri par arrivée** : dans « À vérifier » seulement depuis la 0.15.0 (« À approuver » aussi avant), les
      fiches vont de la dernière arrivée (jour `rsn_veille_cree_le`, puis identifiant) à la plus ancienne ; une
      fiche sans `rsn_veille_cree_le` prend le jour de son `post_date`.
- [ ] **Repère « Nouveau »** (jaune, après le titre) : seulement sur les fiches arrivées aujourd'hui ou hier,
      et seulement dans « À approuver » et « À vérifier ».
- [ ] **Image de la fiche** (éditeur d'une fiche, colonne latérale) : la boîte montre l'image de la source
      (ou « Aucune image de la source ») et le texte « Pour la remplacer, choisissez une image mise en avant : elle
      prime et la collecte ne l'écrase jamais. » ; aucun champ à remplir. Une image mise en avant choisie
      remplace l'image de la source sur la page publique et dans le JSON, et une collecte ne la change pas.
- [ ] Filtres : « Appels » ne montre que les appels ; la liste des sources contient « Vérification » et
      la filtre ; les onglets gardent les filtres choisis.
- [ ] **À vérifier** : marquer une fiche publiée absente
      `wp eval '(new RSN\Veille\EntrepotWordPress())->marquer_absent(<id>, "2026-10-20");'`, puis
      recharger : l'onglet « À vérifier (1) » la liste avec « Absent de la source depuis le
      20 octobre 2026 ». **Garder publiée** la retire de l'onglet, `rsn_veille_absent_depuis` n'existe
      plus et `rsn_veille_absences` vaut 0. **Retirer** (sur une autre fiche marquée) la passe en `draft`.
- [ ] **Encadré « Détails de la fiche »** (version 0.6.0) : le lien « Modifier » d'une fiche ouvre l'éditeur avec
      l'encadré sous le texte, l'origine et l'état à droite du titre. Choisir **Appel** : les champs de
      l'événement se masquent, la date limite, la catégorie et la date de publication apparaissent ; choisir
      **Événement** : l'inverse. Changer la date limite (ou le lieu, le lien…) et enregistrer : la valeur est
      gardée, le champ porte la pastille « corrigé à la main » et `wp post meta get <id> rsn_veille_champs_manuels`
      le liste ; une collecte (Réglages) ne la remet pas. Une date de début vidée sur un événement garde l'ancienne
      date, avec un avis rouge. Compte `lecteur` : pas d'accès à l'éditeur d'une fiche.
- [ ] **Origine des fiches** (0.11.0) : proposer un événement par le formulaire de la page (avec un courriel), puis
      ouvrir la file. Sa ligne a un trait jaune à gauche et, dans la colonne Source, la pastille « Proposition du
      public », « Formulaire de la page » et le courriel en lien `mailto:`. Une fiche collectée montre « Collecte
      automatique », une fiche ajoutée à la main « Saisie par l'équipe ». Au-dessus du tableau : « 1 proposition du
      public à traiter » ; le lien et le filtre « Propositions du public » ne montrent qu'elle ; changer d'onglet
      garde le filtre.
- [ ] **Infolettre découpée** (0.12.0) : Veille > Radar > Rafraîchir. La source « Unité de soutien SSA Québec :
      infolettre » (filtre par source, 0.14.0) liste les encadrés des derniers numéros (titre, lien propre à chacun, extrait), pas un bloc par
      numéro. « Boîte à outils SSA », « Formations gratuites », « Grand public » et « Bulletin de veille SSA » portent
      l'étiquette orange « Déjà paru dans n autres numéros » et un liseré orange ; les articles du mois, non. Écarter
      une « Déjà paru » : elle passe dans « Écartées » et ne revient pas après Rafraîchir. Après une collecte
      (Réglages), la file « À approuver » montre les événements à venir de « À l'agenda », source « Unité de soutien
      SSA Québec : agenda (infolettre) », organisateur vide (sauf les webinaires de l'Unité), résumé « Annoncé dans
      l'infolettre du … » ; une seconde collecte n'en crée aucun (Journal : « Inchangés »).
- [ ] **Peut-être déjà dans l'agenda** (0.13.0) : proposer par le formulaire de la page un événement déjà publié,
      le même jour, avec un titre un peu différent (un mot en plus, « 2e » devant). Dans la file « À approuver », sa
      ligne a un liseré orange et, sous le titre, l'étiquette orange « Peut-être déjà dans l'agenda », le titre de la
      fiche publiée en lien (il ouvre sa page de modification) et ses dates. Une proposition sans rapport n'a rien.
      Proposer deux fois le même événement inédit : chaque ligne dit « Ressemble à une autre fiche en attente » et
      mène à l'autre. Les onglets « Publiées » et « Écartées » ne montrent jamais l'étiquette. Après une collecte, un
      événement de l'agenda de l'infolettre annoncé dans plusieurs numéros affiche « Annoncé dans n numéros » dans la
      colonne Source.
- [ ] **Gabarit à vérifier** (0.12.0) : après au moins trois collectes d'une source (Journal), simuler une lecture
      vide : `wp eval 'RSN\Veille\Journal::consigner("obvia", ["date" => wp_date("Y-m-d H:i"), "lus" => 0, "ignores" => 0, "erreurs" => []]);'`.
      Le Journal montre en tête l'encadré jaune « Gabarit à vérifier » avec « Obvia : événements : Rien lu, alors que
      la source donne d'habitude environ n éléments… » et l'étiquette dans la ligne d'Obvia ; la file « À approuver »
      rappelle « 1 source à vérifier (gabarit peut-être changé) : Obvia : événements » avec « Voir le journal ».
      Relancer la collecte d'Obvia : la nouvelle ligne n'a plus d'alerte, l'encadré et le rappel disparaissent.
- [ ] **Ajouter une fiche** (0.6.0) : le bouton en tête de la file d'approbation (à droite du titre) ouvre un
      éditeur vide avec l'encadré (type « Événement » par défaut) ; avec un titre et une date de début, **Publier**
      crée la fiche : `rsn_veille_source` vaut `manuel`, `rsn_veille_niveau` `M`, `rsn_veille_cle_source`
      `manuel:<id>` ; elle est dans l'onglet « Publiées » et sur la page. « Enregistrer le brouillon » la laisse
      hors de la page. Compte `lecteur` : pas de bouton.
- [ ] Jeton expiré : dans la console du navigateur, `rsnVeilleAdmin.nonce = 'x'`, puis un bouton :
      message « Jeton de sécurité expiré : rechargez la page. » dans la ligne, fiche inchangée.
- [ ] Réglage des rôles : `wp option patch insert rsn_veille_reglages roles '["author"]' --format=json`
      (ou `wp option update rsn_veille_reglages '{"roles":["author"]}' --format=json` si l'option n'existe
      pas), puis ouvrir une page d'administration : `wp cap list editor` n'a plus `rsn_veille_gerer`,
      `author` et `administrator` l'ont. Remettre ensuite `["administrator","editor"]`.
- [ ] **Journal** : après une collecte, « Dernière collecte terminée : … » en date lisible, une ligne
      par source (les sources jamais collectées : « jamais collectée »), pour Mila et IVADO la mention
      « N appariées en anglais, M orphelines » au-dessus des erreurs, erreurs repliées sous
      « N erreurs », et le déroulé de chaque source replié en bas de page.
- [ ] Les fichiers `admin.css` et `admin.js` ne sont chargés que sur les pages du menu Veille (onglet
      Réseau des outils du navigateur sur `/wp-admin/edit.php` : absents), avec `?ver=0.18.0`.
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
- [ ] **Page Veille** : la liste montre les pages publiées, en brouillon et privées ; en choisir une, enregistrer ;
      changer le statut d'une fiche dans la file : `wp post meta get <page> _elementor_element_cache` est vide.
- [ ] **Import** : importer `docs/maquette/evenements-infolettres-2025-2026.json` après une collecte du RSN →
      « Import terminé : 24 lus, N créés, M fusionnés, … » avec N + M = 24 (M > 0 seulement si un événement
      importé a exactement le même titre et la même date qu'une fiche déjà collectée ; avec l'API TEC de
      rsn.quebec d'octobre 2026, M = 0 est normal) ; aucun doublon dans la file
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

Page Veille > Radar (`/wp-admin/admin.php?page=rsn-veille-radar`). Le premier affichage lit les 13
flux de nouvelles un à un (8 s au plus chacun), chaque source étant gardée une heure dès qu'elle est lue
(transients `rsn_veille_radar_{id}`), puis le résultat complet l'est aussi (`rsn_veille_radar`) avec une
copie de 24 h (`rsn_veille_radar_dernier`). Un verrou de 2 min (`rsn_veille_radar_lecture`) évite que deux
éditeurs relancent les flux ensemble.

- [ ] **Bouton vert et archivées** (0.15.0) : une nouvelle « Santé + numérique » a une pastille verte et un bouton
      « Garder pour l'infolettre » vert ; les autres gardent le bouton bleu. Un quatrième onglet, « Archivées »,
      reçoit les nouvelles gardées publiées depuis plus de 60 jours : **Remettre dans l'infolettre** la renvoie
      dans « Gardées pour l'infolettre », **Retirer** l'enlève. Veille > Infolettre ne reprend pas une archivée.
- [ ] La page s'ouvre sans erreur PHP. En haut : bouton **Rafraîchir**, « Flux lus le … », puis les
      onglets (0.9.0) « À trier (n) », « Gardées pour l'infolettre (0) », « Écartées (0) » et le filtre
      « Publiées du … au … ». L'onglet « À trier » est un tableau (0.14.0 ; des groupes par source avant) : une
      ligne par nouvelle, de la plus récente à la plus ancienne, toutes sources mêlées.
- [ ] **Tableau** (0.14.0) : colonnes « Publiée le », « Nouvelle » (titre et extrait), « Source », « Repères »,
      « Actions ». Deux nouvelles du même jour et de sources différentes se suivent ; aucune ligne plus récente
      sous une plus ancienne ; une nouvelle sans date est à la fin. Une « Déjà paru » garde son liseré orange à
      gauche. Dans « Gardées pour l'infolettre » et « Écartées », la quatrième colonne s'appelle « Gardée le » et
      « Écartée le ». Garder ou écarter la dernière ligne d'un onglet : le tableau disparaît, « Aucune nouvelle… »
      paraît.
- [ ] **Filtre par source** (0.14.0) : le menu « Toutes les sources » liste les sources de l'onglet en ordre
      alphabétique. En choisir une puis **Filtrer** : seules ses nouvelles restent, « n nouvelles de « … » » et
      « Tout afficher » paraissent ; les nombres des onglets ne changent pas ; changer d'onglet garde la source ;
      avec des dates en plus : « n nouvelles publiées du … au …, de « … » ».
- [ ] Chaque ligne montre la date, le titre (lien qui s'ouvre dans un nouvel onglet), la source, ce qu'elle touche
      (0.12.0 : « Santé + numérique » en gras, « Santé seulement » ou « Numérique seulement » ; « Repérée » et
      « À trier » avant), un extrait, et les boutons **Garder pour l'infolettre** et **Écarter**. Aucune nouvelle
      de plus de 60 jours. Une source généraliste (FRQ, Acfas) donne surtout des « … seulement ».
- [ ] Les sources en erreur (un 403, un flux devenu une page HTML) sont listées en bas, repliées, sous
      « N sources en erreur », sans casser les autres sources.
- [ ] **Garder** deux nouvelles : chaque ligne disparaît du radar, le message « Nouvelle gardée pour
      l'infolettre. » est annoncé (zone `role="status"`) et le nombre de l'onglet « Gardées pour l'infolettre » passe à 1, puis 2.
      Onglet « Gardées pour l'infolettre » : les deux y sont, la plus récemment gardée d'abord, avec
      sa date dans la colonne « Gardée le » et un bouton **Retirer** ; elles ne sont plus dans « À trier ».
      `wp option get rsn_veille_nouvelles_gardees --format=json` : champs `url, titre, date, source,
      organisateur, resume, gardee_le`.
- [ ] **Retirer** une gardée : la ligne disparaît, le compteur baisse ; après rechargement la nouvelle
      réapparaît dans le radar (si elle est encore dans le flux).
- [ ] **Écarter** une nouvelle (bouton « Ignorer » avant la 0.9.0) : elle disparaît ; après rechargement et après **Rafraîchir**, elle ne
      reparaît pas. `wp option get rsn_veille_nouvelles_ignorees --format=json` contient son adresse.
- [ ] **Onglets** (0.9.0) : la page a trois onglets avec leur nombre, « À trier », « Gardées pour l'infolettre »
      et « Écartées » ; la section repliée de la 0.8.0 n'existe plus. **Écarter** une nouvelle : elle quitte
      « À trier », le nombre de « Écartées » monte d'un. Dans « Écartées », **Remettre dans le radar** : le
      nombre de « À trier » remonte. Dans « Gardées », **Retirer** : le nombre baisse.
- [ ] **Filtre par dates** (0.9.0) : « Publiées du 1er au 10 » puis **Filtrer** ne montre que les nouvelles de
      l'onglet publiées entre ces dates, avec « n nouvelles publiées du … au … » ; changer d'onglet garde les
      dates ; **Tout afficher** enlève le filtre. Les nombres des onglets ne changent pas avec le filtre.
- [ ] **Source protégée contre les robots** (0.8.0) : une source dont le site répond par une page de vérification
      (INSPQ en novembre 2026) apparaît dans « sources en erreur » avec « Le site refuse les lectures
      automatiques (protection contre les robots, réponse HTTP 403). ».
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

Page Veille > Infolettre (`/wp-admin/admin.php?page=rsn-veille-infolettre`). Six blocs HTML aux styles en
ligne (tableaux), au style de l'infolettre du RSN de l'automne 2026 (0.16.0 ; six blocs depuis la 0.17.0). Ce que le RSN
publie et organise est à part, en billets avec image : **Nouvelles du RSN** (0.17.0 : articles de la catégorie 10,
« Actualités du RSN », parus depuis la date choisie, par défaut les 30 derniers jours), **Événements du RSN** (événements à venir dont « qui organise »
est le RSN) et **Programmes de financement du RSN** (articles de la catégorie 149 pour lesquels
`rsn_appel_etat()` répond « En cours » (clé `en-cours`), et appels publiés que le RSN organise). Le reste est en
listes : **Agenda de l'écosystème**, **Appels de l'écosystème** (appels publiés non échus), puis **Nouvelles
gardées** (option `rsn_veille_nouvelles_gardees`, la plus récente d'abord, réunies dans une carte compacte depuis la 0.16.2).
Préparation : publier quelques fiches (événements et un appel avec date limite), avoir un événement du RSN à
venir avec une image mise en avant, garder deux nouvelles dans le Radar, et, pour les programmes, avoir des
articles de la catégorie 149 avec l'extrait 45 du site actif.

- [ ] La page s'ouvre sans erreur PHP, avec six sections dans cet ordre (Nouvelles du RSN, Événements du RSN, Programmes de
      financement du RSN, Agenda de l'écosystème, Appels de l'écosystème, Nouvelles gardées), chacune avec un
      compteur (« 8 événements », « 3 programmes »), un aperçu rendu dans un cadre, les boutons **Copier** et
      **Copier le code HTML**.
- [ ] **Nouvelles du RSN** (0.17.0) : un billet par article de la catégorie « Actualités du RSN » paru dans les 30
      derniers jours, le plus récent en haut : image mise en avant à gauche (liée à l'article), étiquette jaune
      « NOUVELLE DU RSN », titre marine, texte (l'extrait rédigé dans l'article, sinon le premier paragraphe du
      texte, sans feuille de style ni titre répété ; 170 caractères au plus, « … » si coupé), bouton bleu « Lire l'article → »
      et « Publié le 10 novembre 2026 » en italique. Un article sans image mise en avant a un billet en texte
      seul. Aucun article de la catégorie 149 (programmes) ni article anglais dans ce bloc.
- [ ] **Articles publiés depuis le** : le champ montre la date appliquée (aujourd'hui moins 30 jours sans
      choix). Choisir une date plus ancienne puis **Appliquer** : l'adresse porte `&depuis=2026-08-01`, des
      billets plus anciens s'ajoutent et le compteur suit. Le nombre d'événements et la date « jusqu'au » déjà
      choisis sont conservés, et inversement (changer le nombre d'événements garde `depuis`). Une date future
      dans l'adresse (`&depuis=2099-01-01`) est ignorée. Avec une date d'aujourd'hui et aucun article du jour :
      « Aucun élément à inclure pour le moment. », boutons de copie grisés.
- [ ] **Agenda de l'écosystème** : une ligne par événement, séparées par un trait gris : pastille de date marine
      (jour en jaune, mois en capitales blanches), titre en gras marine, organisateur · lieu en bleu, pastilles
      de thème à droite (IA et Données en bleu, Réseautage et Formation en vert, Vieillissement en rouge,
      Financement en jaune, les autres en marine), résumé, bouton bleu arrondi « En savoir plus → » et le format
      en italique gris (« Présentiel – Gratuit », « · En anglais » pour un événement en anglais). Aucun événement
      du RSN n'y figure ; un événement d'un partenaire annoncé sur le site du RSN, si.
- [ ] **Événements du RSN** : un billet par événement (cadre gris arrondi) : image à gauche (200 px), étiquette
      jaune « ÉVÉNEMENT DU RSN », pastille de date, titre, organisateur · lieu, thèmes, résumé, bouton « En savoir
      plus → ». Réduire la fenêtre : l'image passe au-dessus du texte et le cadre d'aperçu reprend sa hauteur.
      Un événement du RSN sans image mise en avant a un billet sans image, le texte sur toute la largeur.
- [ ] **Programmes de financement du RSN** : un billet par programme ouvert : image mise en avant de l'article,
      étiquette « PROGRAMME DU RSN », titre court de l'encadré de l'article (sinon son titre), extrait, cadre
      clair « POUR QUI », « MONTANT », « DÉPÔT » (date limite en gras, « à 23 h 59 (heure de l'Est) »), bouton
      « Voir l'appel sur Fourwaves → » (lien de l'encadré, sinon premier lien Fourwaves du texte) et lien « Lire
      l'annonce ». Comparer avec la case du même programme sur la page Programmes du site : mêmes renseignements.
      Sans lien de dépôt : bouton « Consulter l'appel → » vers l'article, sans second lien. Sans la fonction
      `rsn_appel_etat` (extrait désactivé), aucun programme et aucune erreur.
- [ ] **Images en JPEG** (0.16.0) : dans le code copié d'un billet, l'adresse de l'image est
      `…/wp-content/uploads/rsn-veille-infolettre/<nom>-<empreinte>.jpg`, 400 px de large
      (`ls wp-content/uploads/rsn-veille-infolettre/`). Ouvrir le fichier : c'est bien un JPEG lisible, pas une
      image noire. Un PNG, ou un WebP à fond transparent (une affiche, un logo), est copié en `.png` et garde son
      fond. Recharger la page : aucun nouveau fichier. Remplacer l'image mise en avant d'un programme : une
      nouvelle copie apparaît, l'ancienne reste. Rendre le dossier `uploads` non inscriptible puis vider
      `rsn-veille-infolettre` : la page s'ouvre sans erreur, les billets gardent l'image d'origine (l'essai n'est
      refait qu'un jour plus tard : `wp transient delete --all` pour le refaire tout de suite). Les images de
      l'agenda de l'écosystème et des événements du RSN déjà passés ne sont pas copiées. Désinstaller
      l'extension ne supprime pas ce dossier.
- [ ] **Titre avec « % »** : un événement du RSN intitulé « Journée 100 % IA » s'affiche dans son billet, la page
      s'ouvre sans erreur.
- [ ] **Largeur du gabarit** : coller le bloc Programmes dans un bloc Code d'un gabarit Mailchimp dont la colonne
      fait moins de 600 px (marges de 18 px) : le texte reste à côté de l'image, rien ne déborde.
- [ ] **Copier (collage enrichi)** sur le bloc Agenda de l'écosystème, puis coller dans un éditeur enrichi
      (LibreOffice Writer, ou le corps d'un courriel) : la mise en forme est conservée (gras, couleurs, colonnes).
      Coller dans un éditeur de texte brut : la version texte (une information par ligne, « 12 NOV. » sur sa
      ligne, adresse après chaque lien, une ligne vide entre deux événements). Message « Bloc copié : collez-le
      dans Mailchimp. » annoncé sous le titre de la page. Même essai sur un billet : le texte donne « POUR QUI »,
      sa valeur, « MONTANT », sa valeur, « DÉPÔT », la date, sans adresse d'image.
- [ ] **Copier le code HTML** puis coller dans un bloc **Code** de Mailchimp (ou un fichier .html) : le code
      commence par le commentaire `<!-- Agenda de l'écosystème (généré par la veille de l'écosystème, … ) -->`,
      ne contient ni `<script>` ni `<style>`, et l'aperçu Mailchimp reproduit celui de la page. **Envoyer un
      essai de l'infolettre** à une boîte Outlook et à une boîte Gmail, et l'ouvrir sur un téléphone : les
      billets s'affichent avec leur image, l'image passe au-dessus du texte sur le téléphone.
- [ ] Même vérification pour les blocs **Appels de l'écosystème** (surtitre « DATE LIMITE » au-dessus de la
      pastille ; sans date limite, « PUBLIÉ LE » et une pastille en contour ; catégorie en pastille ; « Consulter
      l'appel → ») et **Nouvelles gardées** (0.16.2 : une seule carte au contour arrondi, étiquette « DANS L’ÉCOSYSTÈME »,
      titre « Nouvelles de nos partenaires », puis pour chaque nouvelle « Source · 20 oct. 2026 » en petit, le
      titre en gras qui est le lien, suivi d'une flèche bleue, et le résumé ; un trait fin entre deux nouvelles ;
      plus de « Lire la suite → »).
- [ ] Repli sans API moderne : dans la console, `delete window.ClipboardItem` puis **Copier** : la copie
      fonctionne encore (sélection du rendu de l'aperçu, `execCommand`).
- [ ] **Nombre d'événements** : choisir 6, puis 12, **Appliquer** : l'adresse porte `&nombre=6` et l'agenda de
      l'écosystème montre 6 événements au plus (le compteur suit) ; les événements du RSN ne sont pas comptés
      dans ce nombre. Une date dans « ou jusqu'au » remplace le nombre : tous les événements jusqu'à cette date
      (compris), et borne aussi le bloc Événements du RSN. Une valeur forgée dans l'adresse (`nombre=7`,
      `jusqu_au=pas-une-date`) est ignorée (8 événements, sans horizon).
- [ ] Un événement dont le titre contient `<b>gras</b> & <script>alert(1)</script>` (voir la préparation)
      s'affiche en texte dans l'aperçu, dans le code copié (`&lt;b&gt;…`) et dans le texte : aucune alerte.
- [ ] Dates : un événement de plusieurs jours du même mois montre « 1-3 » ; sur deux mois, « 28 NOV. » puis
      « → 2 DÉC. » en petit ; un événement ou une série déjà commencés, « JUSQU'AU » et le jour de fin. Un
      événement terminé hier n'apparaît pas ; un événement en cours, si.
- [ ] **Appels** : un appel dont la date limite est passée est absent ; l'ordre va de la date limite la plus
      proche à celle sans date limite. Un appel collecté qui pointe vers la page d'un programme du RSN n'est
      répété dans aucun bloc : le programme, en billet, le remplace. Tenir cette fiche hors de l'infolettre
      (File d'approbation › Publiées) retire aussi le billet du programme, avec la note « 1 appel publié est
      tenu hors de l'infolettre » sous le bloc Programmes de financement du RSN.
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

- [ ] **À regarder** (0.15.0) : proposer un événement par le formulaire de la page, puis **Envoyer un essai
      maintenant**. Le courriel commence par un encadré jaune « À regarder (n) » : « 1 proposition du public
      attend votre décision dans la file : « … » ». Avec une source en erreur au Journal, une ligne « 1 source
      n'a pas pu être lue à la dernière collecte : … ». Sans nouvelle fiche de la semaine mais avec une
      proposition en attente, le sujet est « Veille de l'écosystème : 1 point à regarder ».

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
      nouveautés cette semaine » (N = fiches publiées et fiches à approuver créées depuis 7 jours ; les fiches à approuver plus anciennes sont
      résumées par la ligne « K fiches attendent toujours dans la file. », hors du décompte),
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
- [ ] Sans nouveauté (passer toutes les fiches à `ecarte`, ou leur `cree_le` à une date ancienne ; une file non
      vide mais ancienne ne suffit pas à envoyer) : l'essai
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

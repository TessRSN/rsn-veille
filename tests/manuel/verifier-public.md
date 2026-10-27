# Vérification manuelle de la page publique (tâches 13 et 14)

À faire dans un WordPress réel (`npx @wordpress/env start`, extension active, idéalement avec The
Events Calendar, Elementor et LiteSpeed Cache), les tests PHPUnit n'utilisant qu'une imitation de
WordPress (`tests/bouchons-wordpress.php`). Les commandes `wp` se lancent par `npx wp-env run cli wp …`.

## Préparation

1. Désactiver puis réactiver l'extension (l'activation ajoute la règle `/veille.ics` et régénère les
   règles de réécriture) : `wp plugin deactivate rsn-veille && wp plugin activate rsn-veille`.
   `wp rewrite list --match=/veille.ics` affiche `^veille\.ics$ → index.php?rsn_veille_ics=1`.
2. Créer des fiches publiées et à approuver (voir `tests/manuel/verifier-admin.md`, Préparation 4),
   dont au moins un événement passé, un événement avec image, un appel avec échéance et un sans.
   Ajouter une fiche dont le titre contient `</script><script>alert(1)</script>` et un `&`.
3. Avec The Events Calendar : deux ou trois événements publiés, l'un dans la catégorie
   « Événements RSN », un dans « Événements partenaires », un passé (depuis septembre 2025), l'un avec
   une image mise en avant. Créer aussi une fiche collectée de même date et de même titre qu'un
   événement TEC, sans image : seule la version TEC doit apparaître ; avec image : seule la fiche.
4. Créer une page « Veille » contenant `[rsn_veille]` (bloc Code court, puis une seconde page avec le
   widget Code court d'Elementor) et la choisir dans Veille › Réglages (page de la veille).

## Page publique

- [ ] La page affiche l'intro, « S'abonner au calendrier » (lien vers `/veille.ics`) et « Proposer un
      événement », la bande « À la une du RSN », la bande « Dates limites », puis la colonne de filtres
      (recherche, **Afficher**, **Qui organise**, Mois avec « Plus tard » et « Événements passés », Format, Lieu,
      Thèmes repliés à six avec « Plus… », Organisateur, Réinitialiser), la liste unifiée et « Sources lues ».
      Plus d'onglets Agenda et Appels. (Le détail des bandes et des filtres : `PARCOURS.md`, étape 7.)
- [ ] Aucune trace du mode équipe : ni case « Mode équipe », ni file d'approbation, ni onglet
      Actualités, ni bouton « Copier pour l'infolettre », ni niveau A/B/C/M, ni nom de source
      technique, ni le mot « Prototype ». Les fiches à approuver n'apparaissent pas.
- [ ] Style conforme à la maquette v9 : titres de mois en DM Serif Display avec hexagone et trait
      #3D6A99 qui continue, frise à hexagones, fiches sans contour, image à gauche étirée à la hauteur
      du texte, bloc date serif, thèmes en petites capitales, « Plus d'information » souligné de jaune.
- [ ] Qui organise : RSN ne garde que la catégorie TEC de slug `rsn` (ou nommée « Événements RSN » /
      « RSN Events »), les fiches de la source du RSN et celles dont l'organisateur est « RSN » (appels
      compris) ; Partenaires la catégorie de slug `partenaires` (ou « Événements partenaires »), Écosystème
      le reste (dont le slug `ecosysteme`) ; un second clic retire le filtre. Renommer la catégorie dans TEC
      sans changer son slug ne change rien.
- [ ] « Événements passés » : lignes compactes sans image, du plus récent au plus ancien, compteur
      « N événements passés depuis {mois année} ».
- [ ] Calendrier : grille lundi → dimanche, flèches, Aujourd'hui, flèches du clavier sur la grille,
      glissement tactile (mobile), événements RSN en jaune, clic sur un jour → fiches dessous ; un appel
      apparaît le jour de sa date limite (« Date limite · … »).
- [ ] Appels (dans la liste unifiée, avec leur mois de date limite) : tuile jaune (date limite) ou grise
      (publication) ; un appel dont l'échéance est passée n'apparaît plus.
- [ ] « Proposer un événement » ouvre le formulaire (titre « Proposer un événement »), « Annuler » le ferme
      (envoi : voir la section Formulaire ci-dessous).
- [ ] Le titre piégé s'affiche en texte (aucune alerte) ; dans le code source, le JSON de
      `#rsn-veille-donnees` contient `</script>` et `&`.
- [ ] Console du navigateur : aucune erreur, sur la page et au fil des clics.
- [ ] Largeur mobile (375 px, outils de développement) : aucun défilement horizontal
      (`document.documentElement.scrollWidth === innerWidth`), image en bannière au-dessus du texte,
      colonne de filtres au-dessus de la liste, points de couleur dans le calendrier.
- [ ] Bureau : la colonne de filtres reste collante, sans barre de défilement (4 mois, Plus tard,
      thèmes repliés la gardent courte).
- [ ] `[rsn_veille section="agenda"]` : événements seuls (sans appels, bandes ni sources) ;
      `[rsn_veille section="appels"]` : dates limites puis appels seuls ; `[rsn_veille une="0"]` : sans la bande
      « À la une ». Les deux sur la même page : chacun fonctionne
      (nœuds `#rsn-veille-donnees` et `#rsn-veille-donnees-2`).
- [ ] Le bloc est un `<div class="rsn-veille" role="region">` : la page n'a qu'un seul `<main>` (celui du thème).

## Formulaire « Proposer un événement »

Hors connexion (fenêtre privée), sur la page Veille. Entre deux séries d'essais, vider le compteur de
débit : `wp transient list --search='rsn_veille_debit_*'` puis `wp transient delete <nom>` (ou
`wp transient delete --all`).

- [ ] Remplir titre, date de début, organisateur, format, lieu, lien, description, courriel et une
      image JPEG ou PNG de moins de 3 Mo, puis « Envoyer à l'équipe » : le bouton se grise (« Envoi… »)
      pendant l'envoi, puis le formulaire laisse place à « Merci ! Votre proposition est dans la file
      d'approbation de l'équipe du RSN. ». « Proposer un événement » rouvre un formulaire vide.
- [ ] Onglet Réseau : une requête POST `admin-ajax.php` (multipart) avec `action=rsn_veille_proposer`
      et `nonce`, réponse 200 `{"success":true,"data":{"ok":true,"message":…}}`, sans le courriel.
- [ ] `wp post list --post_type=rsn_veille --post_status=pending` : la fiche est là ; Veille › File
      d'approbation, onglet « À approuver » : la fiche (source `proposition`), avec le lien Modifier ;
      dans l'éditeur, l'image est l'image mise en avant ; Médiathèque : l'image y figure.
- [ ] `wp post meta list <id>` : `rsn_veille_courriel_proposant` porte le courriel,
      `rsn_veille_image_url` l'adresse de l'image, `rsn_veille_source` vaut `proposition`,
      `rsn_veille_niveau` `M`, `_thumbnail_id` la pièce jointe.
- [ ] La page publique, le JSON (`/wp-json/rsn/v1/veille`) et `/veille.ics` ne montrent pas la fiche
      tant qu'elle n'est pas publiée ; une fois publiée, elle apparaît avec son image, et le courriel
      n'apparaît nulle part (chercher l'adresse dans le code source et dans le JSON).
- [ ] Le lien proposé n'est jamais chargé par le serveur (journal d'accès du site visé, ou un lien vers
      un service de capture comme https://webhook.site : aucune requête reçue).
- [ ] Sixième envoi dans l'heure depuis la même adresse : refusé, message « Trop de propositions,
      réessayez plus tard. » en tête du formulaire (réponse 429), aucune fiche créée.
- [ ] Champ piège : dans les outils de développement, remplir `site_web` (invisible, hors écran) puis
      envoyer : le remerciement s'affiche, mais aucune fiche n'est créée. Le champ n'est atteint ni à la
      tabulation ni par un lecteur d'écran (VoiceOver).
- [ ] Erreurs : date de début d'il y a plus d'un an → message sous le champ et en tête (« Vérifiez les
      champs signalés : »), champ encadré de rouge ; un fichier `.svg`, `.gif` ou un PDF renommé
      `affiche.png` → « Image en JPEG, PNG ou WebP seulement. » ; une image de plus de 3 Mo → « Image
      trop lourde (3 Mo au plus). » ; aucune fiche créée.
- [ ] Lien `javascript:alert(1)` (en retirant `type="url"` dans les outils de développement) : la fiche
      est créée sans lien. Titre `<script>alert(1)</script>Colloque` : la fiche s'intitule « Colloque ».
- [ ] Doublon : proposer un événement de même date et de même titre qu'une fiche publiée → « Merci, cet
      événement est déjà dans la veille. », aucune nouvelle fiche, la fiche publiée inchangée (dates,
      lien, source).
- [ ] Jeton : modifier `rsnVeillePublic.nonce` dans la console puis envoyer → le JS demande un jeton
      neuf (`action=rsn_veille_jeton`) et l'envoi réussit. Avec LiteSpeed Cache (page en cache depuis
      plus de 24 h, ou ESI activé), l'envoi réussit aussi.
- [ ] Connecté comme administrateur : l'envoi fonctionne aussi (action `wp_ajax_rsn_veille_proposer`).
- [ ] Mobile (375 px) : champs sur une colonne, aucun défilement horizontal, messages lisibles.
- [ ] Image : le fichier de la médiathèque porte un nom aléatoire de 20 caractères (pas le nom
      d'origine) et est « attaché à » la fiche ; sa page de pièce jointe n'est pas visible hors connexion
      tant que la fiche est en attente. Une image de plus de 8 000 pixels de côté → « Image trop grande ».
- [ ] Écarter la proposition dans la file : l'image disparaît de la médiathèque et son adresse répond
      404 ; écarter une fiche collectée ne touche pas à son image.
- [ ] Collecte après une proposition de même date et de même titre (`wp eval-file
      tests/manuel/lancer-collecte.php`) : la fiche collectée est créée à part ; la proposition garde sa
      source, son lien et ses dates.
- [ ] Plafond du site : au-delà de 30 propositions valides dans l'heure (toutes adresses), refus 429.

## Ressources et cohabitation

- [ ] Sur la page Veille, `veille.css` et `veille.js` sont chargés avec `?ver=0.3.0` (onglet Réseau) ;
      sur une autre page du site (accueil, Nouvelles), ni l'un ni l'autre.
- [ ] Les polices Google ne sont pas chargées une seconde fois par l'extension (pas de feuille
      `rsn-veille-polices`) ; `add_filter('rsn_veille_charger_polices', '__return_true')` dans un
      mu-plugin les ajoute.
- [ ] Elementor : la page Elementor avec le widget Code court s'affiche comme la page simple ; les
      boutons, titres et liens de la veille ne prennent pas les styles du kit Elementor ni d'UiCore
      (majuscules, fonds, couleurs) ; l'éditeur Elementor s'ouvre sans erreur.
- [ ] Cache : publier une fiche dans la file d'approbation puis recharger la page Veille (hors
      connexion) → la fiche apparaît (transient `rsn_veille_json_fr` vidé, page LiteSpeed et cache
      Elementor purgés) ; `wp transient get rsn_veille_json_fr` est vide juste après.
- [ ] WPML : la page en anglais remplit `rsn_veille_json_en`, distinct de `rsn_veille_json_fr` ;
      une purge vide les deux.
- [ ] Modifier un événement TEC → la page Veille montre la modification au rechargement.

## REST et iCal

- [ ] `curl -i http://localhost:8888/wp-json/rsn/v1/veille` : 200, `Cache-Control: public, max-age=3600`,
      JSON `{genere_le, sources, items}` sans `niveau`, `source`, `cle_source`, `cles_source`,
      `courriel_proposant`, `absences`, `manuel` ; les fiches à approuver absentes.
- [ ] `curl -i http://localhost:8888/veille.ics` : 200 sans redirection vers `/veille.ics/`, `Content-Type: text/calendar; charset=utf-8`,
      `Content-Disposition: inline; filename="veille.ics"`, fins de ligne CRLF (`curl -s … | od -c | head`).
- [ ] Le fichier passe le validateur https://icalendar.org/validator.html.
- [ ] Abonnement dans Google Agenda, Apple Calendrier ou Outlook (URL publique, ou import du fichier) :
      événements d'une journée sur la bonne date (sans décalage d'un jour), événements à heure à la
      bonne heure de Montréal, deux heures, lieu, description et lien ; un second import ne crée pas
      de doublons (UID stables).
- [ ] Après désactivation de l'extension, `/veille.ics` ne répond plus en calendrier (règle retirée).

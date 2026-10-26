# Vérification manuelle de la page publique (tâche 13)

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

- [ ] La page affiche l'intro, « Proposer un événement », les onglets Agenda et Appels, la colonne de
      filtres (recherche, Mois avec « Plus tard » et « Événements passés », **Qui organise** au-dessus
      de Format, Lieu, Thèmes repliés à six avec « Plus… », Organisateur, Réinitialiser) et « Sources lues ».
- [ ] Aucune trace du mode équipe : ni case « Mode équipe », ni file d'approbation, ni onglet
      Actualités, ni bouton « Copier pour l'infolettre », ni niveau A/B/C/M, ni nom de source
      technique, ni le mot « Prototype ». Les fiches à approuver n'apparaissent pas.
- [ ] Style conforme à la maquette v6.1 : titres de mois en DM Serif Display avec hexagone et trait
      #3D6A99 qui continue, frise à hexagones, fiches sans contour, image à gauche étirée à la hauteur
      du texte, bloc date serif, thèmes en petites capitales, « Plus d'information » souligné de jaune.
- [ ] Qui organise : RSN ne garde que la catégorie TEC de slug `evenements-rsn` (ou nommée « Événements
      RSN »), Partenaires `evenements-partenaires`, Écosystème le reste ; un second clic retire le
      filtre. Renommer la catégorie dans TEC sans changer son slug ne change rien.
- [ ] « Événements passés » : lignes compactes sans image, du plus récent au plus ancien, compteur
      « N événements passés depuis {mois année} ».
- [ ] Calendrier : grille lundi → dimanche, flèches, Aujourd'hui, flèches du clavier sur la grille,
      glissement tactile (mobile), événements RSN en jaune, clic sur un jour → fiches dessous.
- [ ] Appels : triés par date limite, tuile jaune (date limite) ou grise (publication) ; un appel dont
      l'échéance est passée n'apparaît plus.
- [ ] « Proposer un événement » ouvre le formulaire (titre « Proposer un événement »), « Annuler » le ferme, « Envoyer » affiche
      « Envoi non encore branché. » sans créer de fiche (`wp post list --post_type=rsn_veille` inchangé).
- [ ] Le titre piégé s'affiche en texte (aucune alerte) ; dans le code source, le JSON de
      `#rsn-veille-donnees` contient `</script>` et `&`.
- [ ] Console du navigateur : aucune erreur, sur la page et au fil des clics.
- [ ] Largeur mobile (375 px, outils de développement) : aucun défilement horizontal
      (`document.documentElement.scrollWidth === innerWidth`), image en bannière au-dessus du texte,
      colonne de filtres au-dessus de la liste, points de couleur dans le calendrier.
- [ ] Bureau : la colonne de filtres reste collante, sans barre de défilement (4 mois, Plus tard,
      thèmes repliés la gardent courte).
- [ ] `[rsn_veille section="agenda"]` : agenda seul (sans onglets, appels ni sources) ;
      `[rsn_veille section="appels"]` : appels seuls. Les deux sur la même page : chacun fonctionne
      (nœuds `#rsn-veille-donnees` et `#rsn-veille-donnees-2`).
- [ ] Le bloc est un `<div class="rsn-veille" role="region">` : la page n'a qu'un seul `<main>` (celui du thème).

## Ressources et cohabitation

- [ ] Sur la page Veille, `veille.css` et `veille.js` sont chargés avec `?ver=0.1.0` (onglet Réseau) ;
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

# Journal des modifications

## 0.2.0 (2026-10-27)

La page publique devient la page « Événements et appels » (maquette `docs/maquette/gabarit-v8.html`) et six corrections d'exploitation, relevées à l'usage de la 0.1.0 sur rsn.quebec, sont faites. Les clés du JSON public `/wp-json/rsn/v1/veille` sont inchangées ; l'attribut `section`, le calendrier, le formulaire, l'iCal, les caches et WPML continuent de fonctionner comme en 0.1.0. Aucune migration : rien à refaire dans les réglages.

### Page publique
- Les onglets Agenda et Appels disparaissent : une seule liste, groupée par mois, mêle événements et appels (clé de tri : début d'un événement, date limite d'un appel). L'en-tête de mois indique « N événements · M appels ».
- Bande « À la une du RSN » : les trois prochains éléments organisés par le RSN, événements à venir ou en cours et appels en cours **avec une date limite**, triés par date. Étiquettes : « Événement du RSN », « Nouveau » (appel publié depuis 7 jours ou moins), « Ferme dans N jours » (21 jours ou moins avant la date limite, « Ferme demain », « Ferme aujourd'hui »), sinon « Date limite : … ». Le lien « Tout ce que le RSN organise et finance » applique le filtre Qui organise › RSN et mène à la liste. La bande déborde sur toute la largeur de la fenêtre (`margin-inline:calc(50% - 50vw)`) ; sans élément du RSN, elle ne s'affiche pas.
- Bande « Dates limites » : une puce par appel en cours qui a une date limite, triées par date, avec l'organisateur.
- Un appel **sans date limite** reste visible dans la liste seulement : classé dans le mois courant, jamais « passé », absent de « À la une » et de « Dates limites » (la règle des 90 jours de la 0.1.0 est inchangée).
- Un événement du RSN en cours (commencé, pas fini) reste à la une.
- Filtres : nouvelle rubrique **Afficher** (Tout, Événements, Appels) et rubrique **Qui organise** (Tous, RSN, Partenaires, Écosystème) au-dessus du Mois, avec compteurs ; la recherche porte aussi sur les thèmes.
- Fiches : pastilles (Événement ou Appel, organisateur, format, « En anglais », thèmes) à la place de la ligne organisateur et de la ligne de thèmes en capitales ; l'hexagone de la frise est doré pour un élément du RSN.
- Calendrier : un appel apparaît le jour de sa date limite (puce jaune « Date limite · … »), et le détail du jour liste les événements puis les appels ; le filtre Afficher s'applique aussi au calendrier.
- Boutons « S'abonner au calendrier » (lien vers `/veille.ics`) et « Proposer un événement ».
- Nouvel attribut `une="0"` du raccourci : retire la bande « À la une ». Les valeurs de `section` restent `tout`, `agenda` (filtre Afficher verrouillé sur Événements, sans bande des dates limites) et `appels` (bande des dates limites puis appels seuls, triés par date limite).
- Mobile et colonnes étroites : les cartes « À la une » s'empilent, la bande des dates limites passe son libellé au-dessus des puces.
- Les nouveaux textes passent tous par la traduction de WordPress (domaine `rsn-veille`).

### Corrections d'exploitation
1. **Qui organise des appels du RSN** : les fiches des sources `rsn:` et `rsn_appels:`, et toute fiche dont l'organisateur canonique est « RSN », sont classées RSN (les appels du RSN sortaient « Écosystème »).
2. **Catégories TEC par slug nu** : les catégories de The Events Calendar sont aussi reconnues par leurs slugs `rsn`, `partenaires` et `ecosysteme` (en plus de leurs noms français et anglais).
3. **Liste « Page Veille »** des réglages : elle propose aussi les pages privées (une page privée convient pour l'essai).
4. **Tri de la file** : « À approuver » et « À vérifier » sont triées par arrivée (jour `cree_le`, puis identifiant, du plus récent au plus ancien), avec un repère « Nouveau » pour une fiche arrivée aujourd'hui ou hier ; « Publiées » et « Écartées » restent triées par date d'événement.
5. **Image d'une fiche** : l'**image mise en avant** de WordPress prime sur l'image de la source (que la collecte réécrit chaque nuit) dans la page et le JSON public. Nouvelle propriété de fiche `image_manuelle` (lue, jamais écrite) et métabox « Image de la fiche » : elle montre l'image de la source et renvoie vers l'image mise en avant (elle n'a aucun champ).
6. **Pages IVADO de redirection** : quand la page d'un événement IVADO n'est qu'une redirection (titre ou `<h1>` « Redirect », `meta refresh`, ou affectation de `window.location` par une adresse), la fiche garde le titre et le résumé de la liste, et l'adresse cible (par exemple `event.fourwaves.com`) remplace l'adresse IVADO. La fiche déjà en base est corrigée à la collecte suivante.

### Livraison
- Numéro de version 0.2.0 (`rsn-veille.php`, `RSN_VEILLE_VERSION`, donc `?ver=0.2.0` sur `veille.css` et `veille.js`).
- Guide d'installation (section 4 : page privée, attribut `une`, bande pleine largeur ; catégories TEC par slug nu), `LISEZMOI.md` et parcours de vérification manuelle (`tests/manuel/PARCOURS.md`) mis à jour.
- 526 tests PHPUnit. Le parcours manuel de la nouvelle page n'a pas encore été déroulé dans un WordPress réel : la bande pleine largeur (barre de défilement verticale) et l'éditeur Elementor restent à vérifier sur la copie de préproduction.

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

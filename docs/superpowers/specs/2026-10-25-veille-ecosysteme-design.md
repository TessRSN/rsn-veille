# Veille de l'écosystème — devis de conception

Date : 2026-10-26 (révision 2 : application dans WordPress, sans Notion). Auteurs : Tess Berthier (RSN) avec Claude. Statut : brouillon soumis à Tess.
Emplacement final prévu : `docs/superpowers/specs/2026-10-25-veille-ecosysteme-design.md` dans le dépôt de l'extension (à créer, voir § 6).
Maquette de référence (prototype jetable, données réelles du 25 octobre 2026) : https://claude.ai/artifact/SfPBPD1L1XQ93g9bYBB2dB. Fichiers de travail (gabarit HTML, collecteur Python, inventaire des sources, événements saisis des infolettres) : dossier de notes partagées de la session, fichiers `veille-ecosysteme_*`.

## 1. Intention

Le Réseau santé numérique (RSN) veut une page « Veille de l'écosystème » qui rassemble, sans travail de saisie, les événements et les appels en santé numérique au Québec qui se passent ailleurs qu'au RSN : réseaux thématiques du FRQ, IVADO, Obvia, Mila, CRM, universités, Digital Health Canada, FRQ, organismes publics. Aujourd'hui, quelqu'un lit les infolettres d'IVADO, d'Obvia et des réseaux FRQ, repère ce qui touche la santé numérique et le recopie à la main dans l'infolettre du RSN et parfois dans le calendrier du site ; les co-directeur·rices relaient aussi ce qu'ils voient passer.

Décision de Tess (2026-10-26) : l'application vit **dans WordPress**, sur rsn.quebec, et cette page a vocation à devenir la nouvelle page Événements du site : les événements du RSN et ceux de l'écosystème au même endroit, avec un filtre pour ne voir que les siens ou tout voir.

Deux publics, une seule machine :

- **Les lecteurs** (chercheur·ses, étudiant·es, citoyen·nes partenaires) : voir ce qui se passe ce mois-ci et les prochains, filtrer, consulter le calendrier, proposer un événement.
- **L'équipe du RSN** (Charles-Édouard pour les communications, Tess, la coordination) : approuver ou écarter ce que la collecte remonte, trier un radar de nouvelles, et sortir en un clic les blocs Agenda, Appels et Nouvelles de l'infolettre.

## 2. Objectifs, non-objectifs, anti-objectifs

**Objectifs**
1. Collecte automatique chaque nuit, sans intervention, à partir des flux et pages inventoriés (§ 4).
2. Page publique dans le style du site rsn.quebec, avec agenda (liste et calendrier), appels, filtres, recherche, événements passés depuis octobre 2025, formulaire « Proposer un événement ».
3. File d'approbation pour l'équipe, dans l'administration WordPress, réservée aux comptes qui ont le rôle voulu, avec trois niveaux : publié d'office, à approuver, écarté.
4. Export pour l'infolettre : trois blocs HTML (Agenda, Appels, Nouvelles gardées) au format des sections correspondantes des infolettres du RSN (format en vigueur depuis mai 2026).
5. Une seule page sur rsn.quebec, en français puis en anglais, qui remplace à terme la page Événements actuelle (§ 7).

**Non-objectifs (v1)**
- Version anglaise de l'interface (le contenu garde sa langue d'origine, avec la mention « En anglais »).
- Comptes utilisateurs propres à l'application : on utilise les comptes et les rôles de WordPress.
- Envoi automatique d'infolettres ou de courriels.
- Lecture de LinkedIn, de Facebook, de pages protégées contre les robots (CRES/IR-CUSM, Polytechnique, McGill) : ces sources passent par le formulaire ou par la saisie de l'équipe. On ne contourne aucune protection.
- Commentaires, inscriptions aux événements, billetterie.

**Anti-objectifs** (ce qui ferait de l'application un échec même si elle fonctionne)
- Une page qui publie des faux positifs gênants (un atelier sans rapport avec la santé présenté comme de la santé numérique) sans que l'équipe puisse le retirer en un clic.
- Une page qui tombe en panne quand une source change de format : une source cassée doit être signalée et ignorée, jamais bloquer la collecte des autres.
- Un style « générique » qui jure avec le site (pastilles partout, bordures épaisses, cartes à contour) : la maquette v5.2 validée par Tess est la référence visuelle.
- Une charge de travail pour l'équipe supérieure à aujourd'hui.

## 3. Ce que l'application montre

### 3.1 Page publique (référence : maquette v5.2)
- Bandeau marine avec fil d'Ariane, titre « Veille de l'écosystème » en DM Serif Display, texte d'intro, bouton « Proposer un événement » (style du bouton « Répondre » de la page Appels à collaboration).
- Deux onglets publics : **Agenda** et **Appels**. Les nouvelles ne sont jamais publiées sur le site ; elles n'existent que dans le radar de l'équipe (§ 3.3).
- **Agenda** : colonne de filtres à gauche (recherche, mois à venir avec compteurs, « Événements passés », **qui organise : RSN / partenaires / écosystème**, format, lieu, thèmes, organisateur, réinitialiser) ; à droite, bascule Liste / Calendrier. La colonne est collante mais défile indépendamment quand elle est plus haute que la fenêtre.
  - Liste : sections par mois (titre serif + hexagone + trait bleu #3D6A99 qui continue + compteur), frise verticale à hexagones, fiches séparées par un trait fin : bloc date serif à gauche, image à gauche du texte (230 px, coins gauches arrondis, étirée à la hauteur du texte ; en fenêtre étroite, bannière de la largeur du texte au-dessus, coins du haut arrondis), organisateur, titre, lignes date et lieu avec icônes, thèmes en petites capitales, description sur deux lignes, lien « Plus d'information » souligné de jaune. Les événements passés s'affichent en lignes compactes sans image, du plus récent au plus ancien.
  - Calendrier : grille mensuelle (lundi à dimanche), flèches, bouton Aujourd'hui, glissement tactile et flèches du clavier pour changer de mois, événements du RSN en jaune et les autres en bleu, clic sur un jour qui affiche ses fiches dessous. Les séries de plus de sept jours n'apparaissent qu'à leur premier jour. Le calendrier remonte à octobre 2025.
- **Appels** : liste triée par date limite, tuile jaune avec la date limite (ou grise avec la date de publication si elle n'a pas été trouvée), organisateur, catégorie, description, lien « Consulter l'appel ».
- **Sources lues** en bas de page : nom, ce qu'on lit, nombre d'éléments, date de la dernière collecte. Le niveau de fiabilité (A, B, C, M) n'est pas montré au public.
- **Proposer un événement** : formulaire dans la page (titre, dates, organisateur, format, lieu, lien, description, image facultative, courriel). Envoi vers la file d'approbation. Confirmation dans la page.
- Aucune mention de la source technique (« flux de Digital Health Canada », « infolettre RSN ») dans les fiches publiques : seul l'organisateur réel apparaît.

### 3.2 Fiche normalisée (modèle de données)
Chaque élément collecté devient une fiche avec : type (événement, appel) ; titre ; date de début et de fin (dates d'événement, ou date de publication et date limite pour un appel) ; heure ; organisateur ; catégorie RSN le cas échéant (Événements RSN, Événements partenaires, Écosystème numérique) ; lieu, ville, région (Québec, En ligne, Hors Québec) ; format (Présentiel, Virtuel, Hybride) ; prix ; langue ; thèmes (liste) ; description courte (2 à 3 phrases) ; URL d'information ; URL d'image ; source technique et niveau de fiabilité ; statut (publié, à approuver, écarté) ; qui a tranché (automatique ou équipe) ; date de collecte ; clé de dédoublonnage.

### 3.3 Vue équipe (dans l'administration WordPress)
L'équipe travaille dans **wp-admin**, avec son compte WordPress habituel. L'extension ajoute un menu « Veille » visible seulement aux rôles autorisés (par défaut : Éditeur et Administrateur ; réglable) :
- **File d'approbation** : la liste des fiches « à approuver », avec pour chacune titre, dates, organisateur, source, extrait, et deux boutons, **Publier** et **Écarter**, qui agissent sans recharger la page. Les fiches publiées ont un bouton **Retirer**. Un filtre par type (événements, appels) et par source. Un onglet « À vérifier » liste les fiches signalées absentes de leur source (§ 4.3).
- **Radar de nouvelles** : les nouvelles des organismes repérées dans leurs flux (santé + numérique), lues à la volée depuis les flux et mises en cache une heure, rétention de 60 jours. Deux boutons par nouvelle : **Garder pour l'infolettre** et **Ignorer**. Les nouvelles gardées sont enregistrées (titre, date, source, extrait, lien), le reste n'est pas stocké.
- **Infolettre** : trois blocs au format HTML des sections de l'infolettre (à calquer sur le numéro de septembre 2026) : Agenda (prochains événements, horizon réglable, défaut huit), Appels en cours (programmes du RSN ouverts et appels externes publiés), Nouvelles gardées. Pour chaque bloc, deux boutons : « Copier » (collage enrichi pour l'éditeur visuel de Mailchimp) et « Copier le code HTML » (bloc Code). Une fois les nouvelles copiées, un bouton « Vider les nouvelles gardées ».
- **Réglages** : sources (activer, désactiver, niveau de confiance), mots-clés santé et numérique, thèmes, rôles autorisés, courriel hebdomadaire (case à cocher, destinataires, jour d'envoi), heure de la collecte.
- **Journal de collecte** : dernière exécution par source (date, nombre, erreur éventuelle).

Le **courriel hebdomadaire** (si la case est cochée) part par l'envoi de courriel de WordPress, déjà configuré sur le site : ce qui est entré dans la semaine (publié d'office, à approuver, nouvelles repérées), avec un lien vers la file d'approbation. Aucun service externe.

Les fiches sont modifiables comme n'importe quel contenu WordPress (titre, description, thèmes, dates, image) ; la collecte ne réécrit jamais ce que l'équipe a changé (§ 4.3).

### 3.4 Sorties pour d'autres usages
- Point d'accès REST `/wp-json/rsn/v1/veille` : toutes les fiches publiées (événements, appels), en JSON, pour d'autres usages (bottin, cartographie, applications sur Vercel).
- `/veille.ics` : les événements publiés (RSN et écosystème) au format iCal, pour s'abonner dans un calendrier ; les événements du RSN gardent aussi l'iCal de The Events Calendar.

## 4. Collecte

### 4.1 Sources et méthode
Les sources sont décrites dans un fichier de configuration du dépôt (une entrée par source : nom, type de lecteur, URL, niveau de confiance, politique de mots-clés, organisateur par défaut). Lecteurs à écrire, par ordre de valeur :

| Lecteur | Sources connues (inventaire du 25 octobre 2026) | Ce qu'il donne |
|---|---|---|
| The Events Calendar (REST `/wp-json/tribe/events/v1/events`, repli iCal `?ical=1`) | rsn.quebec, Digital Health Canada, RQRV | événements avec dates, lieu, image, catégories |
| RSS d'événements (pubDate = date de l'événement) | Réseau québécois de recherche sur le sommeil | événements |
| Pages HTML structurées (`<time datetime>`, `data-event-start`) | Obvia, Mila, FRQ (événements), IVADO (calendrier + résolution `/?p=ID`), CRCHUM, Conseil de l'innovation | événements, souvent avec image |
| RSS de nouvelles filtré par catégorie | FRQ (« Appel »), CRM (« Appel », « Concours ouvert »), RSN (« Appels de financement »), CARES (« Concours »), Prix Hippocrate (titre) | appels, date limite extraite du texte quand elle y figure |
| RSS de nouvelles | FRQ, IVADO, Mila, INSPQ, SSA Québec, Conseil de l'innovation, Génome Québec, RQRM, RQRD, CMDO, Acfas, fil de presse quebec.ca | nouvelles (radar équipe) |
| iCal (Luma, Google Agenda, tout `.ics`) | à activer quand une organisation en fournit un | événements |

L'inventaire complet (65 sites, adresses exactes, pièges rencontrés) est dans `veille-ecosysteme_inventaire-sources.md`. Les sites bloqués ne sont pas contournés.

### 4.2 Règles de confiance (validées par Tess)
- Source **santé numérique** (Digital Health Canada, RSN, FRQ pour les appels) : publié d'office.
- Source **santé** (RQRV, Sommeil, CARES, INSPQ…) : publié si le texte contient un mot-clé numérique, sinon à approuver.
- Source **numérique** (IVADO, Obvia, Mila, CRM) : publié si le texte contient un mot-clé santé, sinon à approuver.
- Source **généraliste** (FRQ événements, Acfas, quebec.ca) : publié si santé et numérique, à approuver si un seul, écarté sinon.
- Formulaire public : toujours à approuver.
- Les listes de mots-clés et les thèmes sont dans une page de réglages de l'administration WordPress, modifiables sans toucher au code.

### 4.3 Déroulement d'une collecte
1. La collecte tourne **dans WordPress**, par tâches planifiées (WP-Cron, déclenché par les visites, ou mieux par la tâche cron du serveur WHC qui appelle `wp-cron.php` toutes les cinq minutes : à régler chez WHC). Chaque nuit vers 3 h (heure de Montréal), une tâche met les sources en file ; une seconde tâche traite **une source à la fois**, toutes les deux minutes, avec un délai d'attente de 15 s par requête, pour rester sous les limites d'exécution PHP de l'hébergement mutualisé. Une source en erreur est consignée dans le journal et n'arrête pas les autres. Un bouton « Lancer la collecte maintenant » existe dans les réglages.
2. Lecture : flux RSS et Atom par l'analyseur intégré de WordPress (SimplePie, `fetch_feed`), iCal par un petit analyseur maison, JSON de The Events Calendar par `wp_remote_get`, pages HTML par `DOMDocument` avec un lecteur par site (Obvia, Mila, IVADO, FRQ, CRCHUM, Conseil de l'innovation). Chaque lecteur a un fichier d'exemple enregistré pour les tests.
3. Normaliser en fiches (§ 3.2), déduire format, région, thèmes, statut.
4. Dédoublonner, dans la collecte et contre la base : même date de début et mêmes 22 premiers caractères du titre normalisé, ou même URL, ou même clé stable (source + identifiant d'origine). Quand un élément correspond à une fiche déjà présente, on **fusionne** : la fiche reçoit la nouvelle clé de source, et si la nouvelle source est de niveau supérieur (A > C > M > B) elle fournit les dates, l'image et l'URL manquantes ou corrigées. On ne crée jamais une deuxième fiche. Les événements du RSN eux-mêmes (The Events Calendar du site) ne sont pas copiés en fiches : la page les lit directement et le dédoublonnage les prend en compte (le déjeuner Digital Health Canada/RSN n'apparaît qu'une fois).
5. Écrire : les fiches sont un type de contenu WordPress `rsn_veille` (statut WordPress `publish` = publié, `pending` = à approuver, `draft` = écarté) avec leurs champs en métadonnées. À la création, la collecte remplit tout. Aux collectes suivantes, elle ne touche que **dates, heure, URL d'information, URL d'image, prix, lieu** ; elle ne réécrit jamais titre, description, thèmes, organisateur ni statut. Approuver une fiche ne la fige donc pas : ses dates continuent de suivre la source.
6. Un élément publié qui n'est plus trouvé à sa source lors de deux collectes consécutives réussies reçoit un drapeau « Absent de la source depuis le … » (onglet « À vérifier »). Aucune dépublication automatique. Un titre qui contient « annulé », « reporté », « cancelled » ou « postponed » fait repasser la fiche « à approuver ».
7. Journal : par source, date, nombre d'éléments lus, créés, fusionnés, écartés, erreur éventuelle.
8. À la fin, purge du cache LiteSpeed de la page Veille (comme le fait l'extrait 45 pour la page Programmes) et du cache de rendu Elementor.

### 4.4 Événements du RSN et événements passés
- Les événements du RSN viennent du calendrier de rsn.quebec (The Events Calendar), passés compris, avec leur catégorie.
- Les 24 événements recopiés des infolettres de septembre 2025 à septembre 2026 (`veille-ecosysteme_evenements_infolettres.json`) sont importés une fois, avec le niveau M, par un bouton d'import dans les réglages (fichier JSON).
- Les fiches ne sont jamais supprimées : un événement passé reste consultable.

## 5. Formulaire « Proposer un événement »
- Champs : titre, date de début, date de fin, organisateur, format, lieu et ville, lien, description, image (fichier, facultatif), courriel du proposant.
- Protection : champ piège invisible, limite de débit par adresse IP, pas de CAPTCHA.
- Création d'une fiche `rsn_veille` au statut « à approuver », source « Proposition » ; l'image va dans la médiathèque. Le courriel du proposant est enregistré en métadonnée privée, jamais affiché.
- Accusé de réception dans la page ; pas de courriel automatique en v1.

## 6. Technique (choix de Claude, à confirmer)
- **Forme** : une **extension WordPress** `rsn-veille` (dossier de fichiers PHP, CSS et JS, sans dépendance externe), installée par Tess depuis wp-admin, développée dans un nouveau dépôt GitHub `TessRSN/rsn-veille` (tout en français : code, commits, documentation). Une extension plutôt qu'un extrait Code Snippets : trop gros pour un extrait, et il faut des tâches planifiées, un type de contenu, des pages d'administration et des tests. Les extraits existants (44, 45, 53, 61, 62) ne sont pas touchés tant que la page n'est pas basculée.
- **Compatibilité** : WordPress actuel du site, PHP de WHC (version à vérifier), Elementor, WPML, The Events Calendar, LiteSpeed Cache. Pièges connus du site à respecter : LiteSpeed met en cache les réponses REST (ajouter `?t=` et `litespeed_control_set_nocache` dans les routes maison), Elementor met en cache le rendu des widgets 24 h, une page reste en cache LiteSpeed jusqu'à minuit sans purge.
- **Données** : type de contenu `rsn_veille` et ses métadonnées ; taxonomie « Thème » ; options pour les réglages ; table de journal légère (option ou type de contenu). Pas de base externe.
- **Rendu public** : raccourci `[rsn_veille]` (options `section=agenda|appels|calendrier`) placé dans une page Elementor FR, et plus tard EN, dans le même esprit que `[rsn_evenements]` ; CSS et JS de la maquette v6 repris tels quels (polices et couleurs du site, thème clair, trait #3D6A99, hexagones). Le calendrier et les filtres fonctionnent côté navigateur à partir d'un JSON incorporé dans la page, comme la maquette.
- **Images** : URL d'origine stockée en métadonnée et chargée depuis le site source (pas de copie), image de remplacement neutre si le chargement échoue ; les images du formulaire vont dans la médiathèque.
- **Sécurité** : nonces et vérification des rôles sur toutes les actions d'administration ; limite de débit et champ piège sur le formulaire ; aucun secret dans le dépôt.
- **Qualité** : tests PHPUnit sur les lecteurs (fichiers d'exemple pour chaque source), les règles de confiance et le dédoublonnage ; un test manuel de bout en bout sur un site WordPress local (wp-env) avant toute installation sur rsn.quebec.
- **Volumes** : environ 200 fiches la première année ; une collecte complète de 30 sources prend une heure en tâches espacées de deux minutes, ce qui est sans importance la nuit.

## 7. Mise en place sur rsn.quebec (après accord explicite de Tess, étape par étape)
1. Développement et tests sur un WordPress local ; maquette de la page en Artifact pour validation.
2. Installation de l'extension sur rsn.quebec par Tess, collecte activée, page Veille créée en **brouillon** (non liée au menu) pour vérifier le rendu et l'administration pendant quelques jours.
3. Bascule : la page Veille devient la page Événements (adresse `/calendrier-evenements/` conservée, l'extrait 61 désactivé), avec le filtre « RSN seulement » pour retrouver l'usage actuel ; menus et liens des accueils vérifiés ; version anglaise de la page via WPML.
Rien n'est fait sur le site tant que Tess ne l'a pas demandé.

## 8. Ce qui est laissé au bâtisseur
- Découpage du code, bibliothèques de lecture RSS/iCal/HTML, gestion des fuseaux horaires (tout en heure de Montréal).
- Noms exacts des métadonnées, de la taxonomie et des options, tant que les champs du § 3.2 y sont.
- Format de la configuration des sources (fichier dans l'extension, modifiable dans les réglages).
- Mise en page détaillée du formulaire et des écrans d'administration.
- Stratégie de cache et de purge.

## 9. Décisions prises par Claude après la relecture du bâtisseur (à vérifier par Tess)
1. La collecte tourne dans WordPress, une source à la fois, par tâches planifiées (§ 4.3). Aucun jeton WordPress n'est stocké ailleurs.
2. Les nouvelles ne sont pas stockées, sauf celles que l'équipe garde pour l'infolettre (§ 3.3).
3. Les écrans d'équipe sont dans wp-admin et réservés aux rôles autorisés ; aucune page publique secrète (§ 3.3).
4. Après création, la collecte ne met à jour que les champs factuels (dates, lieu, URL, image, prix) et laisse à l'équipe titre, description, thèmes et statut (§ 4.3).
5. Un événement disparu de sa source est signalé, jamais dépublié automatiquement (§ 4.3).
6. Les doublons entre collectes sont fusionnés dans la fiche existante ; les événements du RSN ne sont pas copiés (§ 4.3).
7. L'export infolettre propose collage enrichi et code HTML pour trois blocs : Agenda, Appels, Nouvelles gardées (§ 3.3).
8. Forme : extension WordPress plutôt qu'extraits Code Snippets ou application Vercel (§ 6).

## 10. Réponses de Tess (2026-10-26) et questions restantes
- Où vit l'application : dans WordPress, la page devenant la nouvelle page Événements. Réglé.
- Courriel hebdomadaire : oui, en option à cocher. Réglé (envoi par WordPress).
- Notion : abandonné ; l'approbation se fait dans wp-admin avec les comptes WordPress. Réglé.
- Thèmes : liste actuelle conservée, modifiable dans les réglages. Réglé.
- Reste à vérifier avant de coder : la version de PHP chez WHC et si la tâche cron du serveur appelle déjà `wp-cron.php` (sinon à demander à WHC ou à régler dans cPanel).

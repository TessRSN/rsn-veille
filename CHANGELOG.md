# Journal des modifications

## 0.15.0 (2026-11-20)

Ménage de l'administration, d'après les remarques de l'équipe après une première vraie utilisation : dans la file, on ne distinguait pas un événement d'un appel, les niveaux n'étaient pas expliqués, les fiches dépassées restaient, et rien ne disait ce qui irait dans l'infolettre.

### File d'approbation (`Admin\PageFile`, `Fiche`)
- **Type bien visible** : « Événement » et « Appel » sont des pastilles de couleur avec une icône (calendrier bleu, porte-voix violet).
- **Niveaux expliqués** : sous le nom de la source, le niveau est écrit en clair (« niveau A : flux structuré », « niveau C : page web lue », « niveau M : saisie à la main », « niveau B : flux RSS ou infolettre »), et une légende repliée sous le tableau, « Que veulent dire les niveaux ? », dit ce que chacun vaut.
- **Rangement par la date qui compte** (`Fiche::date_utile`) : le début d'un événement, la **date limite** d'un appel, jamais sa date de publication. « À approuver » et « Publiées » vont de la plus proche à la plus lointaine ; « Archivées » et « Écartées », de la plus récente à la plus ancienne ; sans date, à la fin. « À approuver » n'est donc plus rangée par jour d'arrivée (le repère « Nouveau » reste).
- **Onglet « Archivées »** (`Fiche::est_passee`) : un événement fini, ou un appel dont la date limite est passée (sans date limite : publié depuis plus de 30 jours), quitte seul « À approuver » ou « Publiées ». Son statut ne change pas et rien n'est supprimé : une fiche publiée dépassée reste visible sur le calendrier public comme avant. Une fiche en attente déjà dépassée porte « Jamais publiée » et ne peut plus qu'être écartée.
- **Infolettre du RSN** : à l'approbation, **« Publier + infolettre »** (sur la page et dans l'infolettre) ou **« Publier seulement »**. Dans « Publiées », une colonne « Infolettre du RSN » dit pour chaque fiche « Dans l'infolettre » ou « Pas dans l'infolettre », avec un bouton pour changer d'avis. Les fiches déjà publiées, et celles que la collecte publie d'elle-même, sont « dans l'infolettre », comme avant. Le choix est gardé dans l'option `rsn_veille_hors_infolettre`.
- « Retirer » (dépublier) s'appelle maintenant **« Retirer de la page »**, pour ne pas le confondre avec « Retirer de l'infolettre ».

### Radar (`Admin\PageRadar`)
- Les nouvelles **« Santé + numérique »** ont une pastille verte et un bouton **« Garder pour l'infolettre » vert** : elles se repèrent dans le tableau.
- **Onglet « Archivées »** : une nouvelle gardée publiée depuis plus de 60 jours quitte « Gardées pour l'infolettre » et n'est plus dans l'infolettre. **« Remettre dans l'infolettre »** la garde malgré son âge ; « Retirer » l'enlève.

### Infolettre (`Admin\PageInfolettre`)
- Les blocs ne reprennent plus les fiches tenues hors de l'infolettre ni les nouvelles archivées ; une note sous chaque bloc dit combien, et où les retrouver. Les événements passés et les appels échus n'y étaient déjà pas.

### Courriel hebdomadaire (`Courriel`)
- Section **« À regarder »** en tête : propositions du public qui attendent une décision, sources dont la page a peut-être changé (gabarit), sources en erreur à la dernière collecte, flux du Radar en erreur, fiches publiées qui ne sont plus à leur source.
- Une proposition du public en attente ou un gabarit à vérifier fait partir le courriel même sans nouvelle fiche (sujet « n points à regarder »). Une source en panne seule ne le fait pas partir.

### Livraison
- Tests : 27 nouveaux (853 au total). Rendu de la file essayé sur les 93 fiches publiées du site au 20 novembre 2026 (49 d'actualité, 44 archivées) et du Radar sur les vrais flux ; boutons essayés sur ces rendus avec un serveur simulé.
- Relecture indépendante, corrigée avant la livraison : le choix « Pas dans l'infolettre » est appliqué avant le dédoublonnage (`Donnees::elements`), pour qu'il tienne quand un événement du RSN remplace la fiche et qu'un doublon voulu dans l'infolettre y garde l'événement ; une action de la file rend des variations des nombres d'onglets au lieu de relire la file ; le courriel ne compte plus comme « en attente » une fiche déjà dépassée ; une vieille nouvelle gardée depuis « Écartées » n'est pas archivée d'office ; « Garder publiée » ne fait plus disparaître la ligne dans « Archivées ».
- Limite connue : « Archivées » cherche les fiches dépassées parmi les 200 dernières fiches publiées ; au-delà, les plus anciennes ne sont plus listées (elles restent dans la base).
- Aucune fiche ni aucune option existante n'est modifiée par la mise à jour. Numéro de version 0.15.0.

## 0.14.0 (2026-11-20)

Radar : rangé comme la file d'approbation, à la demande de l'équipe. Les nouvelles étaient groupées par organisme ; ce qui venait de paraître n'était pas en haut.

### Un tableau, les plus récentes d'abord (`Admin\PageRadar`)
- L'onglet « À trier » est un **tableau**, comme celui de la file : une ligne par nouvelle, **de la plus récente à la plus ancienne, toutes sources mêlées** (à date égale, l'ordre de lecture ; sans date, à la fin). Les groupes par source et leurs titres disparaissent.
- Colonnes : **Publiée le**, **Nouvelle** (titre et extrait), **Source**, **Repères** (« Santé + numérique », « Santé seulement », « Numérique seulement », et « Déjà paru dans n autres numéros »), **Actions**. Le liseré orange des « Déjà paru » est gardé, à gauche de la ligne.
- « Gardées pour l'infolettre » et « Écartées » ont le même tableau ; la quatrième colonne y donne la date à laquelle la nouvelle a été gardée ou écartée (leur ordre ne change pas : la plus récemment gardée ou écartée d'abord).
- **Filtre par source** à côté du filtre par dates (« Toutes les sources », puis les sources de l'onglet en ordre alphabétique), pour retrouver d'un coup les nouvelles d'un organisme maintenant qu'elles ne sont plus groupées. Les onglets gardent le filtre ; leurs nombres restent des totaux. Avec un filtre : « 30 nouvelles de « Unité de soutien SSA Québec : infolettre » » et le lien « Tout afficher ».
- Quand la dernière ligne d'un onglet part, le tableau se cache et le message « Aucune nouvelle… » paraît (`assets/admin.js`).

### Livraison
- Tests : 4 nouveaux (826 au total) ; rendu essayé sur les vrais flux du 20 novembre 2026 (58 nouvelles à trier de 8 sources, bien mêlées par date) et actions essayées sur ce rendu avec un serveur simulé. Rien ne change dans les données : ni les options, ni la lecture des flux, ni l'infolettre. Numéro de version 0.14.0.

## 0.13.0 (2026-11-20)

File d'approbation : les mêmes repères que le Radar, à la demande de l'équipe. La file ne disait pas si une fiche à approuver était déjà dans l'agenda sous un autre titre.

### « Peut-être déjà dans l'agenda » (`Ressemblance`, `Admin\PageFile`)
- Dans l'onglet « À approuver », une fiche qui **ressemble à une fiche déjà publiée** porte l'étiquette orange du Radar, un liseré orange, et un lien vers la fiche publiée avec ses dates, pour comparer avant de publier ou d'écarter. Exemple réel : « Forum de l'industrie de la santé de Québec » (agenda de l'infolettre de l'Unité SSA) et « 20e Forum de l'industrie de la santé de Québec (FISQ) », déjà publié.
- Sinon, si elle ressemble à **une autre fiche en attente** (une proposition du public que la collecte a aussi trouvée) : « Ressemble à une autre fiche en attente », avec un lien vers sa ligne, dans les deux sens.
- Règle : même type ; pour deux événements, des dates qui se recoupent et un titre proche ou la même adresse ; pour deux appels sans dates limites contraires, un titre proche ou la même adresse. Titre proche : au moins 80 % des mots du titre le plus court sont dans l'autre (sans mots vides, numéros d'édition ni années) ; un titre de deux mots doit être le même de part et d'autre ; deux organisateurs nommés et sans rapport demandent quatre mots communs. L'adresse du numéro d'une infolettre, une page d'accueil ou une page portée par trois fiches ou plus ne prouvent rien.
- Comparaison avec les fiches publiées **encore d'actualité** seulement (événements à venir ou en cours, appels en cours) : le concours de l'an passé n'est pas « déjà dans l'agenda », et la file ne charge jamais toute la base.
- Ce n'est qu'un signalement : **rien n'est fusionné ni écarté** automatiquement. Le dédoublonnage de la collecte ne change pas.

### « Annoncé dans n numéros » (`Lecteurs\LecteurInfolettre`, `Collecte::mentions`)
- Un événement tiré de l'agenda d'une infolettre et annoncé dans plusieurs numéros le dit dans la colonne Source. Deux envois du même numéro comptent pour un. Le compte est refait à chaque collecte réussie (option `rsn_veille_mentions`) ; celui d'une source désactivée n'est plus montré.

### Livraison
- Tests : 16 nouveaux (822 au total) ; règle essayée sur les 93 fiches publiées du site (aucune fausse alerte ; elle retrouve la préannonce et le lancement du même programme du FRQ, publiés tous les deux) ; relecture indépendante (appels échus, titres courts, organisateurs différents, coût sur une grosse file : corrigés avant la livraison). Numéro de version 0.13.0.

## 0.12.0 (2026-11-20)

Infolettres Mailchimp : celle de l'Unité de soutien SSA Québec est lue et découpée, au lieu d'arriver en un seul bloc. Deux avertissements demandés par l'équipe : ce qui revient d'un numéro à l'autre, et une source dont le gabarit change.

### Découpage d'une infolettre (`Lecteurs\DecoupageMailchimp`)
- Le flux d'archive de Mailchimp donne les dix derniers envois avec leur HTML complet. Chaque numéro est découpé d'après le gabarit standard de Mailchimp : ses **encadrés avec image** (titre en gras, lien, texte) et les lignes de son bloc **« À l'agenda »** (« titre > 24 et 25 novembre à Québec »).
- Étude des 20 envois publics de l'Unité (février à novembre 2026) : les 16 numéros ordinaires ont le même gabarit (10 à 20 encadrés, un bloc d'agenda) ; 4 envois spéciaux en ont un autre.
- Liens nettoyés : enveloppe « Safe Links » d'Outlook dépliée, paramètres de suivi retirés (`utm_*`, `gclid`, `gad_*`, `fbclid`, identifiant d'abonné de Mailchimp, identifiant de partage de YouTube).
- Dates d'agenda : « 24 novembre », « 1er mai », « 24 nov. », « 24 et 25 novembre », « 24, 25 et 26 novembre », « 26 au 28 octobre », « 30 novembre au 2 décembre », « 30 décembre au 2 janvier 2027 », avec ou sans année (sans année : celle de l'envoi, ou la suivante si le jour est passé depuis plus de trois mois). Une ligne sans date lisible est laissée.

### Radar : chaque encadré est une nouvelle (`Nouvelles::lire_infolettre`, `Admin\PageRadar`)
- Nouvelle source « Unité de soutien SSA Québec : infolettre ». Chaque encadré devient une nouvelle, datée du dernier numéro où il paraît, avec son propre lien. Un envoi sans encadré (envoi spécial) reste une seule nouvelle. Le fil du site de l'Unité reste lu à part ; une adresse déjà vue n'est pas répétée.
- **« Déjà paru dans n autres numéros »** : étiquette orange et liseré sur un encadré qui revient d'un numéro à l'autre (« Boîte à outils SSA », « Formations gratuites »…). Deux envois du même numéro (mêmes encadrés, à une semaine d'écart) comptent pour un seul. Une nouvelle écartée ne revient pas aux numéros suivants.
- **« Gabarit à vérifier »** en tête du Radar si le dernier numéro ne donne plus d'encadré (ou moins du tiers de l'habitude) alors qu'il porte un titre comme les autres, ou si aucun numéro du flux n'en donne. Le numéro reste lisible en une seule nouvelle.

### Radar : libellés de pertinence
- À côté du titre, « Repérée » devient **« Santé + numérique »** et « À trier » devient **« Santé seulement »** ou **« Numérique seulement »** (le domaine de la source, ou celui que le texte touche pour une source généraliste). « À trier » ne désigne plus que l'onglet. Une lecture gardée en cache par la version précédente affiche « Un seul des deux » jusqu'au prochain rafraîchissement.

### Agenda de l'infolettre : événements à approuver (`Lecteurs\LecteurInfolettre`)
- Nouvelle source collectée la nuit, « Unité de soutien SSA Québec : agenda (infolettre) » : chaque ligne datée et à venir du bloc « À l'agenda » devient un événement **toujours « à approuver »** (jamais publié d'office). Le même événement annoncé dans plusieurs numéros ne donne qu'une fiche. S'il est déjà connu d'une autre source (même jour, même titre), la mention s'y rattache **sans rien réécrire** : la source est de niveau B, le moins fiable, et ne fait que combler un lien ou une image manquants. L'adresse ne sert jamais à repérer un doublon (`sans_doublon_par_url`), parce que plusieurs lignes peuvent partager celle du numéro ou d'une page générale.
- Format, ville, région et langue sont déduits de la mention (« en ligne », « à Québec et en ligne », « en anglais »). L'organisateur n'est rempli que si le lien mène chez l'éditeur de l'infolettre ; sinon il reste vide, à compléter dans « Détails de la fiche ». Le résumé dit d'où vient l'annonce (« Annoncé dans l'infolettre du 20 novembre 2026 (…) : à Québec. »).
- Un événement qui sort de l'agenda n'est pas signalé « absent de sa source » : une infolettre n'annonce un événement qu'un temps.
- Page publique : un événement sans organisateur n'affiche plus de séparateur orphelin ; la table des sources montre l'archive publique de l'infolettre.

### Gabarit d'une source à vérifier (`Journal::alerte_gabarit`, `Admin\PageJournal`, `Admin\PageFile`)
- Pour **toutes les sources collectées** : une lecture sans erreur qui ne rend plus rien, alors que la source donne d'habitude au moins trois éléments (ou moins du tiers pour une source d'au moins neuf), reçoit une alerte. L'habitude est la médiane des lectures saines des deux dernières semaines ; une source qui reste vide deux semaines est tenue pour vide et l'alerte s'éteint.
- Affichage : étiquette « Gabarit à vérifier » dans la ligne de la source et encadré en tête du **Journal** ; rappel au-dessus de la file **« À approuver »** (« n sources à vérifier », lien vers le journal).
- Pour l'agenda de l'infolettre, l'alerte porte sur le dernier numéro : il n'a plus de ligne d'agenda alors que les précédents en ont.

### Livraison
- Tests : 38 nouveaux (806 au total) ; découpage vérifié sur le vrai flux et les 20 envois publics ; relecture indépendante (fusions à tort, dates à cheval sur deux années, HTML hostile : corrigés avant la livraison). Textes anglais au `.po` (noms des deux sources, libellé de la table des sources). Numéro de version 0.12.0.

## 0.11.0 (2026-11-20)

File d'approbation : distinguer ce qu'une personne a proposé de ce que la collecte a trouvé, à la demande de l'équipe.

### File d'approbation (`Admin\PageFile`, `Fiche::origine`)
- **Origine de chaque fiche**, en pastille au début de la colonne Source : « Proposition du public » (formulaire de la page), « Collecte automatique » (lue à une source) ou « Saisie par l'équipe » (ajoutée à la main ou importée : niveau M). La ligne d'une proposition porte un trait jaune à gauche.
- **Proposition du public** : la source affiche « Formulaire de la page » au lieu de « proposition niveau M », et le courriel de la personne en lien `mailto:` pour lui répondre. Le courriel reste une méta privée, lue seulement pour cette page.
- **Filtre par origine** (« Toutes les origines », « Propositions du public », « Collecte automatique », « Saisie par l'équipe »), gardé d'un onglet à l'autre (`origine=` dans l'adresse).
- **Rappel** au-dessus de la file « À approuver » : « n propositions du public à traiter », avec un lien qui ne montre qu'elles.

### Livraison
- Tests : 5 nouveaux (768 au total). Numéro de version 0.11.0.

## 0.10.0 (2026-11-17)

Deux améliorations repérées au tour de santé du 10 novembre.

### Agenda : séries récurrentes regroupées (`assets/veille.js`)
- **Une série récurrente tient en une fiche** : trois événements ou plus de même titre et de même organisateur dans la liste (les « Office Hours Online » hebdomadaires de Digital Health Canada occupaient six fiches). La fiche est celle de la prochaine date ; une ligne dit « Chaque semaine, du … au … » (dates à sept jours d'intervalle) ou « Série de n dates », puis donne les autres dates en liens vers leur page (huit au plus, puis « et n autres »).
- La série est reconnue sur toute la liste, avant le filtre par mois : en ne regardant qu'un mois, elle reste regroupée même s'il n'en contient que deux dates. Les compteurs (mois, « Afficher », « Qui organise », total) comptent une série pour un. Les événements passés sont regroupés de la même façon.
- Le calendrier du mois, la bande de l'accueil et le fichier iCal ne changent pas : chaque date y reste.

### Résumés : formule des flux retirée (`Fiche::sans_formule_de_flux`, `LecteurRss`)
- WordPress termine les extraits de ses flux par « The post <titre> appeared first on <site>. » (« L'article … est apparu en premier sur … » en français) ; le flux du FRQ ne donne que cette formule. Elle est retirée à la lecture du flux, et le lecteur prend alors le texte suivant (`content:encoded`) comme résumé.
- Les fiches déjà enregistrées avec cette formule (entière, ou coupée par l'extrait) sont nettoyées à la lecture : leur résumé est vide au lieu d'afficher la formule. La collecte ne réécrit pas le résumé d'une fiche existante ; seuls les nouveaux appels reçoivent le vrai texte.

### Livraison
- Tests : 3 nouveaux (763 au total) ; rendu vérifié sur les données du site (faux DOM : liste complète, par mois, événements passés, anglais). Textes anglais au `.po`. Numéro de version 0.10.0.

## 0.9.0 (2026-11-10)

Radar en onglets et filtre par dates, à la demande de l'équipe après l'essai de la 0.8.0.

### Radar (`Admin\PageRadar`, `assets/admin.js`)
- **Trois onglets avec leur nombre**, comme la file d'approbation : « À trier » (les nouvelles du radar, groupées par source), « Gardées pour l'infolettre » et « Écartées » (les plus récemment écartées d'abord). L'onglet est dans l'adresse (`onglet=a_trier|gardees|ecartees`). La section repliée « Nouvelles ignorées » de la 0.8.0 devient l'onglet « Écartées ».
- **Vocabulaire** : le bouton « Ignorer » devient « Écarter », comme dans la file d'approbation ; « écartée le … ». Les noms internes (options, actions AJAX) ne changent pas.
- **Les nombres des onglets suivent les actions** sans recharger la page : chaque réponse rend les trois compteurs (`compteurs` ; « à trier » calculé depuis le cache du radar, jamais de réseau). « Remettre dans le radar » ne recharge plus la page.
- **Filtre par dates de publication** : « Publiées du … au … » (deux champs de date, une seule borne possible) filtre l'onglet en cours ; les onglets gardent les dates ; « Tout afficher » enlève le filtre ; une ligne dit combien de nouvelles sont montrées (« 3 nouvelles publiées du 10 au 31 octobre 2026 »). Bornes inversées remises dans l'ordre, date illisible ignorée. Les nombres des onglets restent des totaux.
- Le radar ne lit que les nouvelles des 60 derniers jours (8 par source) : le filtre porte sur ce que le radar contient, plus tout ce qui est gardé ou écarté.

### Livraison
- Tests : rendu réécrit pour les onglets et le filtre (760 tests) ; faux DOM des trois onglets (16 vérifications). Numéro de version 0.9.0.

## 0.8.0 (2026-11-10)

Radar : retrouver les nouvelles ignorées. Erreurs : dire quand un site refuse les lectures automatiques.

### Radar (`Admin\PageRadar`, `assets/admin.js`)
- **Section « Nouvelles ignorées »**, repliée sous le radar, avec son compteur : les nouvelles ignorées, les plus récemment ignorées d'abord, avec leur date, leur titre, leur source et le moment où elles ont été ignorées.
- **« Remettre dans le radar »** retire la nouvelle des ignorées ; si elle est encore dans les flux, la page se recharge et la nouvelle reparaît à sa place ; sinon le message prévient qu'elle ne reparaîtra pas. **« Garder pour l'infolettre »** la garde directement depuis les ignorées (même sortie des flux, grâce aux détails enregistrés).
- Les détails (titre, date, source, organisme, résumé, moment) sont enregistrés à l'instant où l'on ignore, dans l'option `rsn_veille_nouvelles_ignorees_details` (par adresse, bornée aux 500 ignorées) ; la liste des adresses (`rsn_veille_nouvelles_ignorees`) ne change pas. Une nouvelle ignorée avant la 0.8.0 n'a que son adresse, complétée par le cache si elle est encore dans les flux.
- Nouvelle action AJAX `rsn_veille_retablir_ignoree` (même jeton et même capacité que les autres). Garder une nouvelle la retire des ignorées.

### Erreurs de lecture (`Http::message_erreur`)
- Une réponse 403, 429 ou 503 qui est une page de vérification anti-robots (Cloudflare, « Just a moment… ») donne « Le site refuse les lectures automatiques (protection contre les robots, réponse HTTP 403). » au lieu de « Réponse HTTP 403 ». Cas de l'INSPQ depuis novembre 2026. L'extension ne contourne pas cette protection.

### Livraison
- Tests : 10 nouveaux (758 au total) ; faux DOM du Radar (remettre, garder depuis les ignorées, ignorer). Numéro de version 0.8.0.

## 0.7.0 (2026-11-02)

Dates de l'agenda, à la demande de l'équipe : les fiches qui s'étalent dans le temps n'occupent plus le haut du mois en cours.

### Agenda (`assets/veille.js`)
- **Série de plus d'une semaine** (fin à plus de sept jours du début) : classée à sa dernière date, dans le mois de sa fin seulement (elle ne compte plus dans les mois qu'elle traverse). La période complète reste affichée (« Du 4 septembre au 18 décembre 2026 ») ; la tuile montre la fin (« →18 déc. »), avec « en cours » une fois commencée. Un événement de deux ou trois jours reste classé à son début.
- **Appel sans date limite connue** : dans un dernier groupe, « Date limite à venir », après tous les mois (compté dans « Plus tard »), au lieu de la date du jour. Un appel avec date limite reste classé à sa date limite, comme avant.

### Collecte
- **Date limite lue sur la page de l'appel** : quand le flux d'un appel ne donne pas de date limite (le flux du FRQ ne contient que « The post … appeared first »), la collecte lit la page de l'appel et cherche la date limite dans sa zone principale (`<main>`, sinon `<article>`, sinon `<body>`, sans menus ni pied de page). Seulement pour les appels publiés il y a moins de 30 jours dont la fiche connue n'a pas déjà une date limite, au plus 8 pages par source et par nuit (`Collecte::PAGES_APPELS_PAR_SOURCE`). Une page illisible est retentée la nuit suivante, sans erreur au journal. Exemple : les Chaires de RISA du FRQ, date limite du 2 février 2027.
- Tests : 4 nouveaux (date trouvée puis page plus relue, page en erreur, plafond et appels anciens, date hors de la zone principale ignorée). Numéro de version 0.7.0.

## 0.6.2 (2026-10-29)

Correction de la version anglaise des événements IVADO. Sur la page anglaise de l'agenda, « IA en santé 2026 » gardait son titre français au lieu de « AI for Health 2026 ».

- **Cause** : le calendrier d'IVADO ne donne qu'un identifiant par événement ; la collecte en faisait l'adresse courte `https://ivado.ca/?p=id`, y compris pour l'identifiant de la traduction anglaise. Or IVADO renvoie cette adresse vers la page française (vérifié le 2026-10-29 sur cinq événements) : le titre et l'adresse français étaient enregistrés comme titre et adresse anglais, puis la page n'était plus relue.
- **Correction** : la passe anglaise utilise `https://ivado.ca/en/?p=id`, qui mène à la page anglaise.
- **Réparation des fiches existantes** : une fiche IVADO dont le titre anglais est identique au titre français voit sa page anglaise relue à la collecte suivante (dans le plafond de 25 pages et le budget de 20 secondes par nuit). Un événement dont le titre est vraiment le même dans les deux langues est relu chaque nuit, sans autre effet.
- Tests : la collecte de test sert maintenant l'adresse courte sans « /en/ » vers la page française, comme le vrai site. Numéro de version 0.6.2.

## 0.6.1 (2026-10-28)

Retouche du bandeau de l'Agenda de l'écosystème, à la demande de l'équipe après la mise en ligne de la 0.6.0 : le libellé « Proposer un événement ou un appel » allongeait le bouton jaune et faisait passer les deux boutons sous l'intro. Le bouton jaune dit maintenant **« Faire une proposition »** (« Make a suggestion » en anglais) et les boutons reviennent à droite de l'intro. Le lien du panneau « Comment ça marche » garde « Proposer un événement ou un appel ». Numéro de version 0.6.1.

## 0.6.0 (2026-10-28)

On peut choisir « événement » ou « appel » dans WordPress comme dans le formulaire public (conception : `docs/superpowers/specs/2026-10-28-fiche-type-0.6.0-design.md`, maquette : `docs/maquette/fiche-type-v1.html`). Aucune migration : rien à refaire dans les réglages, et les caches de la page Veille et de ses traductions sont vidés au premier chargement de wp-admin avec la nouvelle version. Les clés du JSON public `/wp-json/rsn/v1/veille` sont inchangées.

### Encadré « Détails de la fiche » (wp-admin)
- **Dans « Modifier la fiche de veille »**, sous le texte, un encadré (`Admin\MetaboxDetails`) : le type (Événement ou Appel), l'organisateur, la langue et le lien d'information pour les deux ; pour un événement, la date de début (obligatoire), la date de fin, l'heure, le format, le lieu et la ville ; pour un appel, la date limite, la catégorie (vide : « Appel ») et la date de publication. À droite du titre, l'origine en clair (« Proposée par le public », « Collectée : <source> », « Saisie à la main ») et l'état.
- **Champs du type choisi** : un petit script (`assets/admin-details.js`, sans dépendance) ne montre que les champs du type coché. Les champs masqués sont enregistrés tels quels : rien n'est effacé en changeant de type. De l'événement à l'appel, la date limite vide reprend la fin si elle diffère du début ; pour un appel, la fin vaut le début. Une date limite d'appel collecté n'est jamais perdue à l'enregistrement.
- **Enregistrement** : jeton propre à l'encadré, droit `edit_post`, ni pendant une sauvegarde automatique ni sur une révision ; valeurs nettoyées comme ailleurs (dates `Y-m-d`, heure `HH:MM`, lien http ou https, format et langue dans leur liste). Une fin antérieure au début devient le début ; un événement sans date de début valide garde son ancienne date et un avis le dit ; un lien refusé est signalé. Pour un appel, « Publié le » laissé vide garde l'ancienne date de publication (sinon le jour d'arrivée, sans la protéger) ; un appel ajouté à la main sans cette date est publié le jour même. Une fiche ne passe à « Événement » qu'avec une date de début valide. L'écriture passe par l'entrepôt : la clé de dédoublonnage, la date de la fiche et la région restent à jour, et les caches sont purgés, donc la page publique suit dès l'enregistrement.

### Corrections protégées de la collecte
- Une valeur changée dans l'encadré pour le **début, la fin, l'heure, la date limite, le lien, le lieu ou la ville** est notée dans la méta `champs_manuels` (`Fiche::$champs_manuels`). La collecte de nuit (`Depot::champs_factuels` et `Depot::champs_a_combler`) ne réécrit plus ces champs, ni la région qui en découle quand la ville ou le lieu sont protégés. Le type, l'organisateur, le format et la langue n'étaient déjà jamais changés par la collecte.
- Un champ protégé porte la pastille « corrigé à la main » dans l'encadré, avec une note qui explique la règle. **Aucun bouton ne lève la protection** en 0.6.0.

### Ajouter une fiche à la main
- Un bouton « Ajouter une fiche » en tête de la file d'approbation mène à un éditeur vide. La fiche créée reçoit la source `manuel`, le niveau M, la clé `manuel:<ID>`, la date du jour comme date d'arrivée et le type de l'encadré (événement par défaut). Son statut suit le bouton de WordPress (Publier ou Enregistrer le brouillon).

### Formulaire public : événement ou appel
- Le bouton jaune du bandeau et le lien du panneau « Comment ça marche » deviennent **« Proposer un événement ou un appel »** (« Suggest an event or a call » en anglais).
- En tête du formulaire, sous « Vous proposez », deux boutons à bascule (`aria-pressed`) : « Un événement » (par défaut) et « Un appel de financement ou de candidatures ». Le titre du formulaire suit (« Proposer un événement » ou « Proposer un appel »).
- **Un appel** masque les dates de l'événement, le format et le lieu, et demande une **date limite** (obligatoire, aide « Dernier jour pour déposer. ») ; « Organisateur » devient « Organisme » et « Lien d'information » devient « Lien de l'appel » (obligatoire). Titre, description, courriel et image ne changent pas.
- **Serveur** (`Formulaire::valider`) : le champ `type` est relu, toute autre valeur vaut `evenement` ; l'événement ne change pas. Un appel sans date limite valide, avec une date limite passée ou sans lien est refusé (« Date limite obligatoire », « Date limite invalide », « La date limite est passée », « Lien obligatoire »). Un appel accepté a pour début et fin le jour de l'envoi (sa date de publication), ni format, ni lieu, ni région, et arrive « à approuver ». Mêmes défenses qu'avant (jeton, débit, champ piège, image). Un appel déjà publié répond « Merci, cet appel est déjà dans la veille. » (« Thank you, this call is already listed. »).

### Page publique
- **Un appel n'a pas de format** : ni pastille « Présentiel », « Virtuel » ou « Hybride », ni place dans le filtre Format, même s'il garde un format d'avant son passage en appel (`assets/veille.js`). Les données ne changent pas.

### Livraison
- Numéro de version 0.6.0 (`rsn-veille.php`, `RSN_VEILLE_VERSION`) ; textes anglais du formulaire au `.po`, `.mo` recompilé.
- `LISEZMOI.md` et guide d'installation à jour (formulaire, encadré, section « Après la mise à jour de la 0.5.2 vers la 0.6.0 »), parcours de vérification manuelle (`tests/manuel/PARCOURS.md`, étape 34), `tests/manuel/verifier-public.md` et `verifier-admin.md` (`?ver=0.6.0`, contrôle de l'encadré et du bouton « Ajouter une fiche »).
- 742 tests PHPUnit. Le rendu réel de l'encadré dans l'éditeur de WordPress, la bascule du formulaire et la protection contre la collecte ne sont vérifiables que sur rsn.quebec : le parcours manuel n'a pas encore été déroulé.

## 0.5.2 (2026-10-28)

Retouche du bandeau de l'Agenda de l'écosystème, à la demande de l'équipe après la mise en ligne de la 0.5.1 : le bouton « Comment ça marche » perd son cadre arrondi et passe sur sa propre ligne, sous l'intro, aligné sur le début du paragraphe. Au survol et quand le panneau est ouvert, son texte est souligné. Rien d'autre ne change (comportement, textes, iCal). Numéro de version 0.5.2.

## 0.5.1 (2026-10-28)

L'Agenda de l'écosystème (`[rsn_veille]`, en français et en anglais) : le bouton « S'abonner au calendrier » s'abonne vraiment, et un point « Comment ça marche » explique d'où viennent les événements (conception : `docs/superpowers/specs/2026-10-28-abonnement-info-0.5.1-design.md`, maquette : `docs/maquette/agenda-info-v1.html`). Aucune migration : rien à refaire dans les réglages, et les caches sont vidés au premier chargement de wp-admin avec la nouvelle version. Le fichier iCal lui-même (contenu, rythme) ne change pas, sauf le nom du calendrier.

### Abonnement au calendrier
- **Lien `webcal://`** : le bouton pointait vers `https://…/veille.ics` ; un clic téléchargeait le fichier, importé une fois, et les nouveaux événements n'arrivaient jamais. Il pointe maintenant vers l'adresse du calendrier de la langue de la page (`/veille.ics`, `/en/veille.ics` avec WPML) en `webcal://` : Calendrier (Mac, iPhone) et Outlook ouvrent un abonnement qui se met à jour.
- **Google Agenda**, qui ne comprend pas `webcal://` : sous les boutons, « Google Agenda : ajouter » ouvre la page d'ajout de Google (nouvel onglet, adresse encodée), et « Copier l'adresse » copie l'adresse `https://…/veille.ics` de la langue (retour « Adresse copiée » 2,5 s ; si le presse-papiers est refusé, le retour affiche l'adresse elle-même).
- **Nom du calendrier** (`X-WR-CALNAME`) : « Agenda de l'écosystème RSN » (« RSN ecosystem calendar » en anglais), au lieu de « Veille de l'écosystème RSN ». Le libellé du conteneur (`aria-label`) devient « Agenda de l'écosystème ».
- La disposition du bandeau : les boutons restent sur une ligne, la ligne « Google Agenda » dessous ; alignés à droite, à gauche sous 820 px et dans un conteneur de 760 px.

### Point « Comment ça marche »
- Un bouton « Comment ça marche » (« How it works ») au bout de l'intro du bandeau déplie, dans le bandeau, un court texte : l'agenda est tenu par une veille semi-automatique qui lit chaque nuit les sources structurées, l'équipe vérifie et complète à la main, et un organisme qui n'y figure pas peut proposer son événement. Deux liens : « Voir les sources lues ↓ » (défilement jusqu'à la table, sans animation si le mouvement est réduit, titre surligné un instant ; la section reçoit `id="sources"`) et « Proposer un événement » (ferme le panneau et ouvre le formulaire, comme le bouton jaune).
- Clic pour ouvrir ou fermer (ça marche au téléphone et au clavier) ; la croix ou la touche Échap (quand le focus est dans le panneau ou sur son bouton, pas ailleurs : la recherche garde son Échap) ferment et rendent le focus au bouton ; à l'ouverture, le focus va au titre du panneau. `aria-expanded` et `aria-controls` sur le bouton.
- Seulement dans la section « tout » : le panneau mène à la table des sources, qui n'existe que là. Avec `section="agenda"`, le bandeau n'a que l'abonnement et « Proposer un événement ».

### Livraison
- Numéro de version 0.5.1 (`rsn-veille.php`, `RSN_VEILLE_VERSION`) ; `LISEZMOI.md` et guide d'installation à jour (bandeau, version, section « Après la mise à jour de la 0.5.0 vers la 0.5.1») ; textes anglais ajoutés au `.po`, `.mo` recompilé.
- Bouchon de test `esc_url` : second paramètre (liste de protocoles) pris en charge, comme dans WordPress ; sans lui, le bouchon n'accepte toujours que http, https et mailto.
- Parcours de vérification manuelle (`tests/manuel/PARCOURS.md`, étape 33) et `tests/manuel/verifier-public.md` mis à jour (`?ver=0.5.1`).
- 664 tests PHPUnit ; le script (ouverture, fermeture, Échap, focus, copie y compris refus du presse-papiers, lien vers les sources) a été essayé dans un faux DOM, hors du dépôt. Le rendu réel et l'abonnement dans Calendrier, Outlook et Google ne sont vérifiables que sur rsn.quebec : le parcours manuel n'a pas encore été déroulé.

## 0.5.0 (2026-10-28)

La bande « Prochains événements » de l'accueil de rsn.quebec, en français et en anglais, devient un code court de l'extension : `[rsn_veille_accueil]` (conception : `docs/superpowers/specs/2026-10-28-bande-accueil-0.5-design.md`, maquette : variante A de `docs/maquette/accueil-v1.html`). Elle remplace `[rsn_events_cards limit="3"]` du thème enfant, qui reste disponible. Aucune migration : rien à refaire dans les réglages, et les caches de la page Veille, de l'accueil et de leurs traductions sont vidés au premier chargement de wp-admin avec la nouvelle version. **Le remplacement du code court est à faire par l'équipe dans Elementor**, sur l'accueil français (page 152) et sur sa traduction anglaise (page 1147) ; le guide d'installation, section 6, dit comment. Les clés du JSON public `/wp-json/rsn/v1/veille` sont inchangées.

### Bande de l'accueil
- **Trois colonnes** : un calendrier du mois, « Au RSN » et « Dans l'écosystème ». Sous 980 px, le calendrier passe au-dessus et les deux autres colonnes restent côte à côte ; sous 640 px, une colonne.
- **Calendrier du mois** : mois courant au départ, deux flèches pour changer de mois (pas avant le mois courant, pas au-delà du dernier mois qui a un événement), jours passés grisés, aujourd'hui entouré, un point jaune les jours qui ont un événement du RSN, un point marine ceux qui ont un événement de l'écosystème. Une série de plus de sept jours ne pointe que son premier jour (même règle que l'agenda). Cliquer un jour pointé affiche dans la colonne de droite tous les événements de ce jour, ceux du RSN compris, avec la pastille « RSN » sur leur ligne (« Le 4 novembre », sans limite et sans la règle d'un événement par organisateur) ; recliquer le jour ou le bouton « Tous » revient à la liste. Quand la liste par défaut est vide, la colonne dit « Aucun événement de l'écosystème à venir. ». Changer de mois défait le jour choisi.
- **Au RSN**, selon le nombre d'événements du RSN à venir : aucun, une phrase (« Pas d'événement du RSN à venir pour le moment… ») ; un, une grande carte avec son image ; deux, deux cartes compactes ; trois ou plus, les deux prochains en cartes compactes puis le lien « + N autres événements du RSN → » vers l'agenda de la langue de la page. Sans image, la carte montre une zone marine avec l'hexagone jaune du RSN.
- **Dans l'écosystème** : les quatre prochains événements qui ne sont pas du RSN, **un seul par organisateur** (le plus proche), sans série de plus de sept jours déjà commencée. Chaque ligne montre une tuile de date (la date du jour pour un événement en cours), le titre, l'organisateur, le format et « En anglais » / « In French » quand la langue de la fiche diffère de celle de la page.
- **Deux langues** : français ou anglais selon la langue WPML de la page (`Donnees::langue_courante()`), textes, dates, formats, titres et liens comme sur l'agenda. Les textes viennent du fichier `languages/rsn-veille-en_US.po` (et du `.mo` recompilé).
- **Date du jour** : la sortie du code court peut être servie par un cache (Elementor, LiteSpeed) un jour plus tard. Le script prend donc comme « aujourd'hui » la plus tardive de la date du serveur et de la date de Montréal du navigateur, entoure ce jour dans le calendrier et recalcule les colonnes « Au RSN » et « Dans l'écosystème » à partir du calendrier, avec les règles de `Accueil::selection` (qui reste la référence testée).
- **Pastilles de catégorie** : celles de l'ancienne bande (qui menaient aux archives de The Events Calendar) disparaissent.
- **Données et caches** : les événements viennent de `Donnees` (le même cache JSON par langue que la page Veille, une heure au plus). Le serveur choisit les événements et les met en JSON dans la page ; `assets/accueil.js` dessine la bande. `Collecte::purger_caches()` vide aussi LiteSpeed et le cache Elementor de la page d'accueil (`page_on_front`) et de ses traductions WPML : la bande suit donc la collecte de nuit, toute modification de fiche et la mise à jour de l'extension. Sans JavaScript, un lien vers l'agenda remplace la bande.
- **Ressources** : `assets/accueil.css` et `assets/accueil.js`, distincts de `veille.css` et `veille.js`, versionnés par `RSN_VEILLE_VERSION` (`?ver=0.5.0`). Le CSS se charge dans l'en-tête et le script en pied de page, sur la page d'accueil et sur toute page dont le contenu contient le code court ; sinon, au rendu du code court. Un code court posé dans un widget Elementor d'une autre page que l'accueil n'a donc ses ressources qu'au rendu ; cette page n'est pas purgée par la collecte, la bande y suit les caches de LiteSpeed et d'Elementor. Le filtre `rsn_veille_charger_accueil` force ou empêche le chargement en tête.

### Livraison
- Numéro de version 0.5.0 (`rsn-veille.php`, `RSN_VEILLE_VERSION`).
- `LISEZMOI.md`, guide d'installation (section 6 « Bande de l'accueil » ; les sections suivantes sont décalées d'un numéro), parcours de vérification manuelle (`tests/manuel/PARCOURS.md`, étape 7 bis) et `tests/manuel/verifier-public.md` mis à jour.
- 660 tests PHPUnit. Le rendu réel de la bande n'est vérifiable que sur rsn.quebec, une fois le code court remplacé dans Elementor : le parcours manuel n'a pas encore été déroulé.

## 0.4.0 (2026-10-27)

La page « Événements et appels » existe en anglais (conception : `docs/superpowers/specs/2026-10-27-page-anglaise-0.4-design.md`). Aucune migration : rien à refaire dans les réglages, et les caches du JSON public et de l'iCal, ceux de LiteSpeed et d'Elementor pour la page Veille et ses traductions, sont vidés au premier chargement de wp-admin avec la nouvelle version. **La traduction anglaise de la page est à créer dans WPML par l'équipe** (même code court `[rsn_veille]`, titre « Events and calls ») ; le guide d'installation, Temps 3, dit comment. Les clés du JSON public `/wp-json/rsn/v1/veille` sont inchangées, une clé `langue` s'y ajoute.

### Page publique en anglais
- **Textes de l'interface** : un fichier de traduction livré avec l'extension (`languages/rsn-veille-en_US.po` et `.mo`, 194 chaînes, chargé sur `init` par `load_plugin_textdomain`) traduit le bandeau, les bandes « À la une » et « Dates limites », les filtres, les en-têtes de mois, les pastilles, les boutons, le calendrier, les compteurs (pluriel anglais : « 0 events »), la table des sources, le formulaire et ses messages, et les messages d'erreur du script. Les noms des sources sont traduits à l'appel. Un test PHPUnit compare le `.po` aux chaînes du côté public et le `.mo` au `.po`.
- **Dates à l'anglaise** : « October 1, 2026 », « October 4–18, 2026 », « Nov. 5, 2026 », heure « 8:00 ». Les mois courts anglais sont « Jan. », « Feb. », « Mar. », « Apr. », « May », « June », « July », « Aug. », « Sept. », « Oct. », « Nov. », « Dec. ». En français, la seule différence : « 08 h 00 » s'écrit « 8 h 00 ».
- **Pastille de langue** : « In French » sur la page anglaise pour une fiche en français, « En anglais » sur la page française pour une fiche en anglais ; rien quand la fiche est dans la langue de la page. Digital Health Canada, déjà en anglais, n'a pas de pastille sur la page anglaise.
- **Langue de la page** : `Donnees::langue_courante()` la lit de WPML (`fr` ou `en`, `fr` sans WPML). Le conteneur de la page porte `lang`, le JSON porte `langue`, l'API accepte `?lang=fr|en` (ignoré sans WPML), et le calendrier `/en/veille.ics` suit la langue du préfixe (`/veille.ics` reste en français).
- **Formulaire** : un champ caché `langue` ; une proposition envoyée depuis la page anglaise est enregistrée avec `langue = en`.
- **Événements du RSN** : un seul article par groupe de traduction WPML (`EvenementsRsn`), dans la langue de la page, sinon en français : titre, résumé, lieu, lien vers `/en/evenement/…` et image. Le dédoublonnage avec les fiches collectées du site se fait par groupe de traduction (`trid:` ou `id:`) ; la clé par début et titre reste celle du titre français. Quand la fiche collectée l'emporte (pour son image), elle prend le titre, le lien et la langue de la version anglaise, et son résumé, son lieu et sa ville s'ils ne sont pas vides.
- **Appels du RSN** : traduits par WPML (l'identifiant de l'article vient du guid `?p=N` de la clé `rsn_appels:`, sinon de `url_to_postid`, puis `wpml_object_id`) : titre, extrait saisi de l'article traduit (`post_excerpt`, nettoyé et raccourci ; le résumé français est gardé s'il est vide), lien et catégorie (le nom lu sur l'article traduit, par exemple « Funding calls »). L'échéance ne change pas. Un appel sans traduction reste en français, marqué « In French ».
- **Mila et IVADO** : titre, résumé et lien anglais sur la fiche (champs `titre_en`, `resume_en`, `url_en`, jamais écrasés par une valeur vide, ni affichés ni modifiables dans l'administration), remplis à la collecte en appariant la page française et la page anglaise par leurs dates (`Appariement`). En pratique, IVADO ne lit la page anglaise d'un événement que si son titre anglais manque (ou si son adresse finale diffère), dans le même plafond de requêtes. Le Journal indique « N appariées en anglais, M orphelines » pour ces deux sources (une orpheline est un événement de la page anglaise sans équivalent français : il n'est jamais enregistré). Une fiche française sans équivalent anglais reste en français, marquée.
- **Retouches de la relecture finale** : le format (Présentiel, Virtuel, Hybride) est traduit sur les pastilles, les cartes et la bande « À la une » ; le formulaire répond dans la langue envoyée (`switch_to_locale('en_US')` le temps de la requête) ; l'API `?lang=` bascule la locale avec la langue de WPML ; le nom du calendrier iCal est traduit (« RSN ecosystem monitoring ») ; le texte d'une fiche dans l'autre langue que la page porte `lang` ; les mois des tuiles sont échappés ; un événement Mila ou IVADO non traduit, listé par la page anglaise sous son adresse française (IVADO : ou son identifiant français), n'est plus apparié à sa propre fiche ; l'erreur du flux anglais d'IVADO porte le préfixe « Version anglaise » ; quelques libellés anglais retouchés (« Show less », « Sources monitored », « Calls for proposals… », « Invalid start date », « Deadline to be announced »).
- **Autres sources** (Obvia, FRQ, RQRV, Réseau sommeil, CRM, CARES, Prix Hippocrate, propositions du public) : une seule langue, celle de la fiche.
- **Infolettre** : toujours en français (`PageInfolettre` force `fr`).

### Correction
- **Doublons anglais sur la page française** : la page française montrait aussi la version anglaise des événements du RSN, par exemple « RSN Scientific Days » à côté de « Journées scientifiques RSN » (la version anglaise portait même la langue `fr`). Un seul article par groupe de traduction est maintenant gardé, dans la langue de la page.

### Livraison
- Numéro de version 0.4.0 (`rsn-veille.php`, `RSN_VEILLE_VERSION`, donc `?ver=0.4.0` sur `veille.css` et `veille.js`).
- `LISEZMOI.md`, guide d'installation (Temps 3 réécrit : créer la traduction, purger LiteSpeed, vérifier la page anglaise) et parcours de vérification manuelle (`tests/manuel/PARCOURS.md`, `verifier-public.md`, `verifier-admin.md`) mis à jour.
- 625 tests PHPUnit. Le rendu réel de la page anglaise n'est vérifiable que sur rsn.quebec, une fois la traduction de la page créée dans WPML : le parcours manuel n'a pas encore été déroulé.

### Limites connues
Voir « Limites connues de la version 0.4.0 » dans `LISEZMOI.md`. Principales : l'administration, les courriels, l'infolettre et le Journal restent en français ; les thèmes et les organisateurs ne sont pas traduits ; seuls Mila et IVADO sont appariés ; une collecte lancée depuis l'administration en locale anglaise stockerait des noms de source en anglais dans les fiches (la collecte de nuit et l'administration de rsn.quebec sont en français).

## 0.3.0 (2026-10-27)

Retouches demandées après le premier passage de la 0.2.0 sur la page de test de rsn.quebec (maquette `docs/maquette/gabarit-v9.html`). Le reste de la page ne change pas. Aucune migration : rien à refaire dans les réglages, et la purge automatique des caches se fait au premier chargement de wp-admin. La page WordPress est à renommer « Événements et appels » par l'équipe (le titre est celui de la page, l'extension n'y touche pas). Les clés du JSON public `/wp-json/rsn/v1/veille` sont inchangées.

### Page publique
- **Bandeau continu** : le bandeau bleu de l'extension (l'intro et les boutons « S'abonner au calendrier » et « Proposer un événement ») remplace la barre d'actions et se place en tête du raccourci, du même bleu `#003469` que l'en-tête du thème. Sur le thème Vault (UiCore) de rsn.quebec, l'en-tête, l'intro et « À la une » se lisent comme un seul bloc, sans écart blanc, un filet fin séparant l'intro de « À la une ». Quand « À la une » n'est pas affichée (`une="0"`, `section="agenda"`, aucun élément du RSN à venir), le bandeau finit le bleu tout seul. Le formulaire « Proposer un événement » passe après « À la une », pour ne pas couper le bleu quand il s'ouvre.
- **Classe `rsn-veille-page`** : l'extension ajoute cette classe au `<body>` quand la page contient `[rsn_veille]`, est la page choisie dans les réglages ou l'une de ses traductions WPML. Sur le thème Vault, elle sert à rendre l'en-tête de page moins haut (remplissage de 44 px en haut et de 0 en bas, 30 px en haut et 0 en bas sous 820 px ; l'intro du bandeau suit le titre à 12 px), à aligner le bloc sur le titre et à laisser le titre prendre toute la largeur (donc sur une ligne sur écran large une fois la page renommée). Sans la classe, ou avec un autre thème, ces règles ne s'appliquent à rien. Elle vaut pour une page ordinaire (bloc « Code court ») ; sur une page Elementor, les marges des sections empêchent le bandeau de toucher l'en-tête.
- **« À la une » en `#003469`** (au lieu de `#07285A`), du même bleu que le bandeau et l'en-tête du thème.
- **Dates limites : des flèches** à la place de la barre de défilement (une flèche à gauche, une à droite, désactivées quand il n'y a plus rien à faire défiler ou quand tout tient ; défilement doux, sans animation si le visiteur demande moins de mouvement). Sous 820 px, les flèches se rangent à droite du libellé et les puces passent en dessous.
- **Fiches du RSN** : le corps de la fiche (image et texte, sans le bloc de date) est sur un fond gris très pâle (`#F6F8FA`) aux coins arrondis, avec un trait jaune de 4 px à gauche. Les autres fiches gardent leur aspect. Sur mobile, l'image reste au-dessus du texte, dans le fond gris.
- **Pastille « Appel »** : contour bleu marine sur fond blanc, plus de fond jaune (le jaune reste celui du RSN et des dates limites).
- **Appel sans date limite** : il reste visible **30 jours** après sa publication (90 jours auparavant), puis disparaît. Tant qu'il est visible, il se place à la date du jour dans la liste (après les événements d'aujourd'hui, et non plus en tête du mois) et porte la mention « Date limite à venir ». Cette règle (`Fiche::JOURS_APPEL_SANS_LIMITE`) est unique : **le JSON public, le bloc Appels de l'infolettre et le courriel hebdomadaire suivent la même règle** (l'iCal ne contient que des événements : il n'est pas touché), donc un appel sans date limite publié il y a plus de 30 jours n'est plus proposé nulle part.
- **Séries en cours** : un événement commencé et pas fini (par exemple la série de webinaires France-Québec du 4 septembre au 18 décembre) se place à la date du jour, sa tuile montre sa date de fin précédée d'une flèche (« →18 déc. », avec la mention « en cours ») et une pastille « En cours » suit la pastille de type. « À la une », le calendrier et la règle des séries de plus de 7 jours ne changent pas.
- Nouveaux textes, tous traduisibles (domaine `rsn-veille`) : « En cours », « en cours », « Date limite à venir », « Dates limites précédentes », « Dates limites suivantes ».

### Livraison
- Numéro de version 0.3.0 (`rsn-veille.php`, `RSN_VEILLE_VERSION`, donc `?ver=0.3.0` sur `veille.css` et `veille.js`).
- `LISEZMOI.md`, guide d'installation (marche à suivre après la mise à jour, section 4 : classe `rsn-veille-page`, structure de référence) et parcours de vérification manuelle (`tests/manuel/PARCOURS.md`) mis à jour.
- 540 tests PHPUnit. Le rendu réel (bandeau continu, hauteur de l'en-tête, flèches, fiches du RSN) n'est vérifiable que sur rsn.quebec : le parcours manuel de la section 7 n'a pas encore été déroulé dans un WordPress réel.

### Limites connues
Voir « Limites connues de la version 0.3.0 » dans `LISEZMOI.md`.

## 0.2.0 (2026-10-27)

La page publique devient la page « Événements et appels » (maquette `docs/maquette/gabarit-v8.html`) et six corrections d'exploitation, relevées à l'usage de la 0.1.0 sur rsn.quebec, sont faites. Les clés du JSON public `/wp-json/rsn/v1/veille` sont inchangées ; l'attribut `section`, le calendrier, le formulaire, l'iCal, les caches et WPML continuent de fonctionner comme en 0.1.0. Aucune migration : rien à refaire dans les réglages.

### Page publique
- Les onglets Agenda et Appels disparaissent : une seule liste, groupée par mois, mêle événements et appels (clé de tri : début d'un événement, date limite d'un appel). L'en-tête de mois indique ce que le mois contient, sans les compteurs à zéro (« 2 événements », « 1 appel » ou « 2 événements · 1 appel »).
- Bande « À la une du RSN » : les trois prochains éléments organisés par le RSN, événements à venir ou en cours et appels en cours **avec une date limite**, triés par date. Étiquettes : « Événement RSN », « Nouveau » (appel publié depuis 7 jours ou moins), « Ferme dans N jours » (21 jours ou moins avant la date limite, « Ferme demain », « Ferme aujourd'hui »), sinon « Date limite : … ». Le lien « Tout ce que le RSN organise et finance » applique le filtre Qui organise › RSN et mène à la liste. Son fond marine est peint jusqu'aux bords de la fenêtre par une ombre étalée rognée verticalement (aucune marge négative : la page ne s'élargit pas et n'a aucun défilement horizontal) ; sans élément du RSN, elle ne s'affiche pas. Le résumé d'un appel y est coupé à la première vraie fin de phrase.
- Bande « Dates limites » : une puce par appel en cours qui a une date limite, triées par date, avec l'organisateur.
- Un appel **sans date limite** reste visible dans la liste seulement : classé dans le mois courant, jamais « passé », absent de « À la une » et de « Dates limites » (la règle des 90 jours de la 0.1.0 est inchangée).
- Un événement court du RSN en cours (commencé, pas fini) reste à la une ; une série de plus de 7 jours (même règle que le calendrier) en sort dès qu'elle a commencé. Le lien « Tout ce que le RSN organise et finance » repasse en vue Liste.
- Filtres : nouvelle rubrique **Afficher** (Tout, Événements, Appels) et rubrique **Qui organise** (Tous, RSN, Partenaires, Écosystème) au-dessus du Mois, avec compteurs ; la recherche porte aussi sur les thèmes.
- Fiches : pastilles (Événement ou Appel, organisateur, format, « En anglais », thèmes) à la place de la ligne organisateur et de la ligne de thèmes en capitales ; l'hexagone de la frise est doré pour un élément du RSN.
- Calendrier : le titre du mois compte les éléments affichés (événements et appels : « N éléments »). Un appel apparaît le jour de sa date limite (puce jaune « Date limite · … »), et le détail du jour liste les événements puis les appels ; le filtre Afficher s'applique aussi au calendrier.
- Boutons « S'abonner au calendrier » (lien vers `/veille.ics`) et « Proposer un événement ».
- Nouvel attribut `une="0"` du raccourci : retire la bande « À la une ». Les valeurs de `section` restent `tout`, `agenda` (filtre Afficher verrouillé sur Événements, sans bande des dates limites) et `appels` (bande des dates limites puis appels seuls, triés par date limite).
- Mobile et colonnes étroites : les cartes « À la une » s'empilent, la bande des dates limites passe son libellé au-dessus des puces.
- Les nouveaux textes passent tous par la traduction de WordPress (domaine `rsn-veille`).

### Corrections d'exploitation
1. **Qui organise** : la **catégorie du site** décide quand elle est connue (« Événements partenaires » ou « Écosystème numérique » ne sont plus classés RSN, y compris pour les événements collectés depuis rsn.quebec : la catégorie est lue pour la source d'identifiant `rsn`, et non plus pour une source nommée « RSN »). Sans catégorie, les fiches des sources `rsn:` et `rsn_appels:`, et toute fiche dont l'organisateur canonique est « RSN », sont classées RSN (les appels du RSN sortaient « Écosystème »). Au dédoublonnage d'une fiche collectée et d'un événement de The Events Calendar, ce dernier fait foi pour « Qui organise » et la catégorie, même quand la fiche collectée est gardée pour son image.
2. **Catégories TEC par slug nu** : les catégories de The Events Calendar sont aussi reconnues par leurs slugs `rsn`, `partenaires` et `ecosysteme` (en plus de leurs noms français et anglais).
3. **Liste « Page Veille »** des réglages : elle propose aussi les pages privées (une page privée convient pour l'essai).
4. **Tri de la file** : « À approuver » et « À vérifier » sont triées par arrivée (jour `cree_le`, puis identifiant, du plus récent au plus ancien), avec un repère « Nouveau » pour une fiche arrivée aujourd'hui ou hier ; « Publiées » et « Écartées » restent triées par date d'événement.
5. **Image d'une fiche** : l'**image mise en avant** de WordPress prime sur l'image de la source (que la collecte réécrit chaque nuit) dans la page et le JSON public. Nouvelle propriété de fiche `image_manuelle` (lue, jamais écrite) et métabox « Image de la fiche » : elle montre l'image de la source et renvoie vers l'image mise en avant (elle n'a aucun champ).
6. **Pages IVADO de redirection** : quand la page d'un événement IVADO n'est qu'une redirection (titre ou `<h1>` « Redirect », `meta refresh`, ou affectation de `window.location` par une adresse), la fiche prend le titre de la balise `<title>` (sans le suffixe « | IVADO », et jamais un titre contenant « redirect »), reste sans résumé, et l'adresse cible (par exemple `event.fourwaves.com`) remplace l'adresse IVADO. Seules les fiches créées à partir de cette version en profitent : les fiches déjà en base ne sont pas relues (la collecte reprend leur adresse enregistrée) et gardent l'adresse IVADO, qui redirige elle-même le visiteur (pour corriger l'une d'elles : `wp post meta update <ID> rsn_veille_url <cible>`).
7. **Purge des caches à la mise à jour** : au premier chargement de wp-admin avec une autre version (option `rsn_veille_version`), l'extension vide le cache du JSON public, LiteSpeed et Elementor pour la page Veille. Au dédoublonnage, une fiche collectée qui a une image mise en avant l'emporte aussi sur l'événement de The Events Calendar.

### Livraison
- Numéro de version 0.2.0 (`rsn-veille.php`, `RSN_VEILLE_VERSION`, donc `?ver=0.2.0` sur `veille.css` et `veille.js`).
- Guide d'installation (section 4 : page privée, attribut `une`, fond de la bande ; catégories TEC par slug nu ; marche à suivre après la mise à jour), `LISEZMOI.md` et parcours de vérification manuelle (`tests/manuel/PARCOURS.md`) mis à jour.
- 534 tests PHPUnit. Le parcours manuel de la nouvelle page n'a pas encore été déroulé dans un WordPress réel : le fond de la bande (barre de défilement verticale) et l'éditeur Elementor restent à vérifier sur la copie de préproduction.

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

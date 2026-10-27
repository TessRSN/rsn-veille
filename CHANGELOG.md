# Journal des modifications

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
- Page Infolettre : l'état des programmes du RSN est lu par l'identifiant de l'article, et une erreur de la fonction du site ne casse plus la page.

### Limites connues
Voir « Limites connues de la version 0.1.0 » dans `LISEZMOI.md`.

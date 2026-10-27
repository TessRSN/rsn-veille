# Journal des modifications

## 0.1.0 (2026-10-26)

Première version de l'extension.

### Collecte
- Type de contenu `rsn_veille` (fiches d'événements, d'appels et de nouvelles) et taxonomie « Thème ».
- Registre de 25 sources : 8 sources d'événements (The Events Calendar, RSS, pages HTML d'Obvia, Mila, FRQ et IVADO), 5 sources d'appels (flux RSS filtrés) et 12 flux de nouvelles réservés au Radar.
- Lecteurs TEC, RSS et Atom, iCal et HTML ; en-tête et début du corps vérifiés (une page HTML en code 200 n'est pas un flux), délai d'attente de 15 s, aucune protection anti-robot contournée.
- Règles de confiance par type de source, priorité des niveaux A, C, M, B, dédoublonnage et fusion, mise à jour des seuls champs factuels, drapeau « absent de la source » après deux collectes réussies, jamais de dépublication automatique.
- Collecte nocturne (3 h, heure de Montréal, modifiable) par tâches planifiées : une source à la fois, toutes les deux minutes, avec verrou et reprise ; journal par source.

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

### Limites connues
Voir « Limites connues de la version 0.1.0 » dans `LISEZMOI.md`.

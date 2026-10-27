# RSN : veille de l'écosystème

Extension WordPress du Réseau santé numérique (RSN), version 0.2.0. Elle collecte des sources externes (flux RSS, calendriers, pages publiques, The Events Calendar), en tire des fiches d'actualités, d'événements et d'appels, puis les présente sur une page publique « Événements et appels » (raccourci `[rsn_veille]`). L'équipe approuve les fiches dans wp-admin (menu **Veille**), repère des nouvelles dans le Radar, prépare les blocs de l'infolettre et, si elle le veut, reçoit un courriel hebdomadaire.

Les règles de confiance, la priorité des niveaux (A, C, M, B) et la conception complète se trouvent dans `docs/superpowers/specs/2026-10-25-veille-ecosysteme-design.md`. Pour installer l'extension sur un site, passer à la page rsn.quebec et dépanner, voir **`docs/installation-et-bascule.md`**. Le dossier `docs/` (ce guide compris) n'est pas dans le zip de l'extension : il se lit dans le dépôt GitHub, https://github.com/TessRSN/rsn-veille. L'historique des versions est dans `CHANGELOG.md`.

## La page publique

Le raccourci `[rsn_veille]` rend, dans l'ordre : une barre d'actions (« S'abonner au calendrier », lien vers `/veille.ics`, et « Proposer un événement »), la bande « À la une du RSN », la bande « Dates limites », puis deux colonnes (filtres à gauche, liste ou calendrier à droite) et « Sources lues ».

- **À la une du RSN** : les trois prochains éléments organisés par le RSN (événements à venir ou en cours, et appels en cours qui ont une date limite), triés par date, avec les étiquettes « Événement du RSN », « Nouveau » ou « Ferme dans N jours ». Fond marine sur toute la largeur de la fenêtre ; absente s'il n'y a rien à montrer.
- **Dates limites** : une puce par appel en cours qui a une date limite, triées par date.
- **Liste unifiée** : événements et appels mêlés, groupés par mois (début d'un événement, date limite d'un appel), avec des pastilles (type, organisateur, format, langue, thèmes). Un appel sans date limite est dans la liste seulement, classé dans le mois courant. « Événements passés » garde l'historique.
- **Filtres** : recherche (titre, organisateur, résumé, thèmes), **Afficher** (Tout, Événements, Appels), **Qui organise** (Tous, RSN, Partenaires, Écosystème), Mois, Format, Lieu, Thèmes, Organisateur. Le **calendrier** montre aussi les appels, à leur date limite.
- **Attributs** : `section="tout"` (par défaut), `section="agenda"` (événements seuls, filtre Afficher verrouillé, sans bande des dates limites) ou `section="appels"` (dates limites puis appels seuls) ; `une="0"` retire la bande « À la une » (page où le thème met déjà le RSN en avant). Pas de bande « À la une » hors de `section="tout"`.

## Prérequis

PHP 8.0 ou plus avec les extensions `mbstring`, `dom`, `libxml` et `json` ; WordPress 6.0 ou plus ; fuseau du site réglé sur Montréal (`America/Toronto`) : « aujourd'hui », l'heure de la collecte et celle du courriel suivent ce fuseau. Aucune bibliothèque externe à l'exécution.

## Structure des dossiers

```
rsn-veille.php            en-tête de l'extension, autochargeur, accrochage, activation et désactivation
uninstall.php             désinstallation (options, transients, tâches, capacité ; les fiches sont gardées)
includes/                 code PHP, espace de noms RSN\Veille (une classe par fichier)
  Fiche, Depot, Entrepot*   fiche normalisée, règles de confiance, fusion et mise à jour ; stockage (mémoire pour les tests, WordPress en production)
  Sources, Collecte         registre des sources, file de collecte, tâches planifiées, journal, verrou
  Http, Pertinence, Nouvelles, Courriel, TypeContenu, Journal, Verrou, ResolutionIvado
  Lecteurs/                 un lecteur par type de source (TEC, RSS, iCal, HTML Obvia, Mila, FRQ, IVADO)
  Admin/                    menu Veille, file, radar, infolettre, journal, réglages, import, métabox « Image de la fiche »
  Public/                   raccourci [rsn_veille], formulaire public, JSON, REST, iCal
assets/                   CSS et JS (public : veille.*, administration : admin.*)
tests/                    PHPUnit (*Test.php), bouchons de WordPress, fixtures/ (contenus réels), manuel/
docs/                     devis, plan, inventaire des sources, maquette, guide d'installation
```

## Ajouter une source

1. **Registre** : ajouter une entrée dans `Sources::par_defaut()` (`includes/Sources.php`) avec `entree(id, nom, url, type, niveau, type_source, organisateur, extras)`. `type` choisit le lecteur (`tec`, `rss`, `ical`, `html_obvia`, `html_mila`, `html_frq`, `html_ivado`) ; `niveau` est A, B, C ou M ; `type_source` (`sante_numerique`, `sante`, `numerique`, `generaliste`) décide de la publication d'office (devis § 4.2). Pour un flux de nouvelles du Radar seulement, utiliser `nouvelles(…)`.
2. **Lecteur** : si le format est déjà couvert (flux RSS, iCal, TEC), aucun lecteur à écrire, seulement des `extras` (`mode`, `categories`, `motif_titre`…). Sinon, créer `includes/Lecteurs/LecteurXxx.php` qui implémente `Lecteur::lire(string $contenu, array $source): Resultat`, et l'ajouter au `match` de `Collecte::lecteur_pour()`.
3. **Fixture** : enregistrer un contenu réel de la source dans `tests/fixtures/` (un fichier par source ou par mode).
4. **Test** : écrire un test qui lit la fixture avec le lecteur et vérifie les fiches (titre, dates, clé de source, niveau), et, si le type de source est nouveau, ajouter son cas à `CollecteTest` (`lecteur_pour`) et à `SourcesTest`. Règles du projet : une source qui renvoie du HTML en code 200 à la place d'un flux n'est pas un flux ; on ne contourne jamais une protection anti-robot.

## Tests

- `composer install` (installe PHPUnit, dépendance de développement seulement), puis **`composer test`**. Les classes pures de `includes/` sont testées sans WordPress grâce à `tests/bootstrap.php` et `tests/bouchons-wordpress.php`.
- Le bouchon de WordPress est **une imitation, pas la référence** : il peut différer du vrai WordPress (échappement, `wp_date`, requêtes de fiches, `kses`). Ce qui touche WordPress lui-même (menus, AJAX, cron, formulaire, médiathèque, mails) se vérifie dans un WordPress réel avec les listes de `tests/manuel/` : **`tests/manuel/PARCOURS.md`** les enchaîne dans l'ordre du parcours (`verifier-admin.md`, `verifier-public.md` et deux scripts `wp eval-file`).
- Les tests tournent avec la version de PHP de la machine (le verrou Composer vise PHP 8.0.30) ; il n'y a pas d'intégration continue en 0.2.0.

## Lancer wp-env

Pour essayer l'extension dans un WordPress local, il faut Docker et `@wordpress/env` : `npx @wordpress/env start` démarre un site (WordPress stable, PHP 8.2) avec l'extension active, par défaut sur http://localhost:8888 (administration : `/wp-admin`, utilisateur `admin`, mot de passe `password`, valeurs propres à l'environnement local). `npx @wordpress/env stop` l'arrête. Les commandes WP-CLI se lancent par `npx wp-env run cli wp …`.

## Livraison

Le zip de l'extension se fabrique à partir d'un commit, sans tests ni documents de conception :

```
git archive --format=zip --prefix=rsn-veille/ -o rsn-veille-0.2.0.zip HEAD -- . ':!tests' ':!docs' ':!.wp-env.json' ':!.superpowers' ':!composer.lock' ':!phpunit.xml'
```

(les fichiers `rsn-veille-*.zip` sont ignorés par Git). Le guide d'installation décrit aussi l'installation depuis GitHub (WP Pusher ou Git Updater).

## Limites connues de la version 0.2.0

- **File d'approbation** : pas de pagination ; les onglets « Publiées » et « Écartées » montrent les 200 fiches les plus récentes (les compteurs restent exacts), « À approuver » et « À vérifier » sont chargés en entier ; la liste des sources du filtre ne reprend que celles de l'onglet affiché ; un statut changé dans l'éditeur WordPress ne marque pas la fiche comme traitée à la main ; « À approuver » et « À vérifier » sont triées par le jour d'arrivée (`cree_le`) : une fiche sans jour d'arrivée prend le jour de son post (qui suit la date de l'événement) ; le repère « Nouveau » a la granularité du jour (arrivée aujourd'hui ou hier) ; un menu absent à la première requête après une mise à jour sans réactivation (recharger la page).
- **Courriel hebdomadaire** : la fenêtre de 7 jours peut se chevaucher d'un courriel à l'autre (une fiche peut figurer dans deux courriels) ; les fiches approuvées dans la semaine comptent comme « publiées automatiquement » ; les fiches créées avant le champ `cree_le` n'y figurent pas ; les destinataires sont visibles les uns des autres.
- **Radar** : le cache courant dure une heure même si une source est en erreur ; le verrou de lecture n'est pas atomique ; pas de rechargement automatique pendant « Rafraîchissement en cours ».
- **Collecte** : chaque source ne charge que ses propres fiches publiées ; le JSON public omet les événements finis depuis plus de 12 mois ; une fiche IVADO qui échoue de façon permanente est redemandée chaque nuit ; le gabarit HTML d'une source qui change peut donner « 0 fiche » sans erreur ; les fuseaux iCal inconnus sont lus comme Montréal ; la limite d'exécution PHP de WHC n'a pas été mesurée (une source par tâche, 120 s demandées : `max_execution_time` à vérifier).
- **Formulaire public** : le courriel du proposant est conservé sans durée limite (voir le guide, section Confidentialité) ; annuler un événement collecté ne se répercute pas sur une proposition publiée ; la limite de débit suppose que `REMOTE_ADDR` est l'adresse réelle du visiteur et ses compteurs ne sont pas atomiques.
- **Import** : l'import des événements des infolettres n'a ni plafond ni suspension des purges (une purge de cache par fiche écrite) ; un fichier de plus de 2 Mo est refusé.
- **Appels sans date limite** : un appel dont la source ne donne ni date limite (cherchée dans l'extrait, le contenu complet du flux et le titre) ni date de fin reste « en cours » 90 jours après sa publication à la source (sinon après son entrée dans la veille), puis disparaît de la page, du JSON, de l'iCal, de l'infolettre et du courriel, même s'il est encore ouvert. Tant qu'il est visible, il n'est que dans la liste : classé dans le mois courant, jamais « passé », absent des bandes « À la une » et « Dates limites » (elles ne montrent que des dates à venir).
- **Page publique** : les listes de sources des légendes sont écrites en dur ; deux raccourcis sur la même page dupliquent certains identifiants HTML ; la colonne collante des filtres peut demander un réglage de `top` sous l'en-tête du thème ; la version anglaise n'a **pas de fichier de traduction** : les textes de la page, bandes « À la une du RSN » et « Dates limites » comprises (qui sont écrites par le JavaScript), s'affichent en français sur la page anglaise tant que WPML String Translation (ou Loco Translate) ne les traduit pas ; la bande « À la une » déborde sur la largeur de la fenêtre par `margin-inline:calc(50% - 50vw)` : avec une barre de défilement verticale, selon le thème, un léger défilement horizontal est possible (alors `une="0"`, ou `overflow-x:clip` sur le conteneur du thème) ; un appel « Nouveau » porte cette étiquette même si sa date limite est proche ; le titre du mois du calendrier et le message d'un jour vide ne comptent que les événements, pas les appels ; l'image mise en avant d'une fiche remplace l'image de la source sur la page et dans le JSON (l'iCal n'a pas d'image), sans champ de saisie dans l'éditeur.
- **Collecte (IVADO)** : une page d'événement IVADO est reconnue comme une redirection d'après son titre, son `<h1>`, un `meta refresh` ou une affectation de `window.location` par une adresse (littérale ou variable définie par une adresse littérale) ; une redirection construite autrement, ou par `window.top.location`, n'est pas reconnue et la fiche garde l'adresse IVADO. Sur une page de redirection, la fiche prend le titre de la balise `<title>` et reste sans résumé ; les fiches déjà en base ne sont pas relues et gardent l'adresse IVADO.
- **Tests** : le bouchon de WordPress ne reproduit pas tout (voir plus haut) ; la vérification de bout en bout dans WordPress reste manuelle (`tests/manuel/PARCOURS.md`).

## Licence

© Réseau santé numérique, tous droits réservés.

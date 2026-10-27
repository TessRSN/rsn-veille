# RSN : veille de l'écosystème

Extension WordPress du Réseau santé numérique (RSN), version 0.1.0. Elle collecte des sources externes (flux RSS, calendriers, pages publiques, The Events Calendar), en tire des fiches d'actualités, d'événements et d'appels, puis les présente sur une page Veille (raccourci `[rsn_veille]`). L'équipe approuve les fiches dans wp-admin (menu **Veille**), repère des nouvelles dans le Radar, prépare les blocs de l'infolettre et, si elle le veut, reçoit un courriel hebdomadaire.

Les règles de confiance, la priorité des niveaux (A, C, M, B) et la conception complète se trouvent dans `docs/superpowers/specs/2026-10-25-veille-ecosysteme-design.md`. Pour installer l'extension sur un site, passer à la page rsn.quebec et dépanner, voir **`docs/installation-et-bascule.md`**. Le dossier `docs/` (ce guide compris) n'est pas dans le zip de l'extension : il se lit dans le dépôt GitHub, https://github.com/TessRSN/rsn-veille. L'historique des versions est dans `CHANGELOG.md`.

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
  Admin/                    menu Veille, file, radar, infolettre, journal, réglages, import
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
- Les tests tournent avec la version de PHP de la machine (le verrou Composer vise PHP 8.0.30) ; il n'y a pas d'intégration continue en 0.1.0.

## Lancer wp-env

Pour essayer l'extension dans un WordPress local, il faut Docker et `@wordpress/env` : `npx @wordpress/env start` démarre un site (WordPress stable, PHP 8.2) avec l'extension active, par défaut sur http://localhost:8888 (administration : `/wp-admin`, utilisateur `admin`, mot de passe `password`, valeurs propres à l'environnement local). `npx @wordpress/env stop` l'arrête. Les commandes WP-CLI se lancent par `npx wp-env run cli wp …`.

## Livraison

Le zip de l'extension se fabrique à partir d'un commit, sans tests ni documents de conception :

```
git archive --format=zip --prefix=rsn-veille/ -o rsn-veille-0.1.0.zip HEAD -- . ':!tests' ':!docs' ':!.wp-env.json' ':!.superpowers' ':!composer.lock' ':!phpunit.xml'
```

(les fichiers `rsn-veille-*.zip` sont ignorés par Git). Le guide d'installation décrit aussi l'installation depuis GitHub (WP Pusher ou Git Updater).

## Limites connues de la version 0.1.0

- **File d'approbation** : pas de pagination des onglets « Publiées » et « Écartées » ; un statut changé dans l'éditeur WordPress ne marque pas la fiche comme traitée à la main ; un menu absent à la première requête après une mise à jour sans réactivation (recharger la page).
- **Courriel hebdomadaire** : la fenêtre de 7 jours peut se chevaucher d'un courriel à l'autre (une fiche peut figurer dans deux courriels) ; les fiches approuvées dans la semaine comptent comme « publiées automatiquement » ; les fiches créées avant le champ `cree_le` n'y figurent pas ; les destinataires sont visibles les uns des autres.
- **Radar** : le cache courant dure une heure même si une source est en erreur ; le verrou de lecture n'est pas atomique ; pas de rechargement automatique pendant « Rafraîchissement en cours ».
- **Collecte** : `lister()` de l'entrepôt n'a pas de limite (acceptable pour environ 200 fiches par an) ; une fiche IVADO qui échoue de façon permanente est redemandée chaque nuit ; le gabarit HTML d'une source qui change peut donner « 0 fiche » sans erreur ; les fuseaux iCal inconnus sont lus comme Montréal ; la limite d'exécution PHP de WHC n'a pas été mesurée (une source par tâche, 120 s demandées : `max_execution_time` à vérifier).
- **Formulaire public** : le courriel du proposant est conservé sans durée limite (voir le guide, section Confidentialité) ; annuler un événement collecté ne se répercute pas sur une proposition publiée ; la limite de débit suppose que `REMOTE_ADDR` est l'adresse réelle du visiteur et ses compteurs ne sont pas atomiques.
- **Import** : l'import des événements des infolettres n'a ni plafond ni suspension des purges (une purge de cache par fiche écrite) ; un fichier de plus de 2 Mo est refusé.
- **Appels sans date limite** : un appel dont la source ne donne ni date limite (cherchée dans l'extrait, le contenu complet du flux et le titre) ni date de fin reste « en cours » 90 jours après sa publication à la source (sinon après son entrée dans la veille), puis disparaît de la page, du JSON, de l'iCal, de l'infolettre et du courriel, même s'il est encore ouvert.
- **Page publique** : les listes de sources des légendes sont écrites en dur ; deux raccourcis sur la même page dupliquent certains identifiants HTML ; la colonne collante des filtres peut demander un réglage de `top` sous l'en-tête du thème ; la version anglaise n'a pas de fichier de traduction (WPML String Translation ou Loco Translate).
- **Tests** : le bouchon de WordPress ne reproduit pas tout (voir plus haut) ; la vérification de bout en bout dans WordPress reste manuelle (`tests/manuel/PARCOURS.md`).

## Licence

© Réseau santé numérique, tous droits réservés.

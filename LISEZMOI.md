# RSN : veille de l'écosystème

Extension WordPress du Réseau santé numérique (RSN), version 0.5.2. Elle collecte des sources externes (flux RSS, calendriers, pages publiques, The Events Calendar), en tire des fiches d'actualités, d'événements et d'appels, puis les présente sur une page publique « Événements et appels » (raccourci `[rsn_veille]`), en français et en anglais, et la bande « Prochains événements » de l'accueil (code court `[rsn_veille_accueil]`). L'équipe approuve les fiches dans wp-admin (menu **Veille**), repère des nouvelles dans le Radar, prépare les blocs de l'infolettre et, si elle le veut, reçoit un courriel hebdomadaire.

Les règles de confiance, la priorité des niveaux (A, C, M, B) et la conception complète se trouvent dans `docs/superpowers/specs/2026-10-25-veille-ecosysteme-design.md`. Pour installer l'extension sur un site, passer à la page rsn.quebec et dépanner, voir **`docs/installation-et-bascule.md`**. Le dossier `docs/` (ce guide compris) n'est pas dans le zip de l'extension : il se lit dans le dépôt GitHub, https://github.com/TessRSN/rsn-veille. L'historique des versions est dans `CHANGELOG.md`.

## La page publique

Le raccourci `[rsn_veille]` rend, dans l'ordre : le bandeau (une courte intro et deux boutons, « S'abonner au calendrier », un lien `webcal://` vers `/veille.ics` (`/en/veille.ics` en anglais) qui ouvre un abonnement dans Calendrier ou Outlook, et « Proposer un événement » ; dessous, « Google Agenda : ajouter » et « Copier l'adresse » ; et, dans la section « tout » seulement, le point « Comment ça marche » qui déplie un court texte sur la veille semi-automatique, avec un lien vers « Sources lues »), la bande « À la une du RSN », le formulaire de proposition (fermé tant qu'on ne le demande pas), la bande « Dates limites », puis deux colonnes (filtres à gauche, liste ou calendrier à droite) et « Sources lues ». Sur le thème Vault (UiCore) de rsn.quebec, le bandeau prolonge l'en-tête de la page : même bleu `#003469`, sans écart blanc, comme « À la une » (voir `docs/installation-et-bascule.md`, section 4, pour la classe `rsn-veille-page` qui le permet).

- **À la une du RSN** : les trois prochains éléments organisés par le RSN (événements à venir ou en cours, et appels en cours qui ont une date limite), triés par date, avec les étiquettes « Événement RSN », « Nouveau » ou « Ferme dans N jours ». Même bleu que le bandeau, jusqu'aux bords de la fenêtre, sans élargir la page ; absente s'il n'y a rien à montrer.
- **Dates limites** : une puce par appel en cours qui a une date limite, triées par date ; deux flèches (gauche et droite) font défiler les puces.
- **Liste unifiée** : événements et appels mêlés, groupés par mois (début d'un événement, date limite d'un appel), avec des pastilles (type, organisateur, format, langue, thèmes). Les fiches du RSN ont leur corps sur un fond gris très pâle, avec un trait jaune à gauche. La pastille « Appel » est en contour bleu marine. Un appel sans date limite est dans la liste seulement, placé à la date du jour avec la mention « Date limite à venir », et disparaît 30 jours après sa publication. Un événement commencé et pas fini (une série) est placé à la date du jour, avec sa date de fin (« →18 déc. ») et la pastille « En cours ». « Événements passés » garde l'historique.
- **Filtres** : recherche (titre, organisateur, résumé, thèmes), **Afficher** (Tout, Événements, Appels), **Qui organise** (Tous, RSN, Partenaires, Écosystème), Mois, Format, Lieu, Thèmes, Organisateur. Le **calendrier** montre aussi les appels, à leur date limite.
- **Attributs** : `section="tout"` (par défaut), `section="agenda"` (événements seuls, filtre Afficher verrouillé, sans bande des dates limites) ou `section="appels"` (dates limites puis appels seuls) ; `une="0"` retire la bande « À la une » (page où le thème met déjà le RSN en avant). Pas de bande « À la une » hors de `section="tout"`.
- **Deux langues** (depuis la 0.4.0, avec WPML) : la même page existe en anglais sur `/en/` (traduction WPML de la page, même raccourci). Les textes de l'interface viennent du fichier `languages/rsn-veille-en_US.po` (et du `.mo` compilé, à recompiler avec `msgfmt` après une retouche du `.po`), les dates s'écrivent à l'anglaise (« October 1, 2026 », « Nov. 5, 2026 »). Les événements et les appels du RSN sont montrés dans leur version anglaise quand WPML la fournit, sinon en français avec la pastille « In French » ; Mila et IVADO ont titre, résumé et lien anglais quand la collecte a pu apparier leurs deux pages. Le reste des fiches garde sa langue. La page française ne montre qu'une version de chaque événement du RSN. Le JSON accepte `?lang=fr|en` ; `/en/veille.ics` est le calendrier en anglais. Une proposition envoyée depuis la page anglaise est enregistrée en anglais. Détail et limites : voir ci-dessous et le guide d'installation, Temps 3.

## La bande de l'accueil

Depuis la 0.5.0, le code court `[rsn_veille_accueil]` rend la bande « Prochains événements » de l'accueil, sous un en-tête (petite ligne, titre, intro, lien) que l'équipe garde dans Elementor. Il remplace `[rsn_events_cards limit="3"]` du thème enfant, qui reste disponible. Marche à suivre sur le site : `docs/installation-et-bascule.md`, section 6.

- **Trois colonnes** : le calendrier du mois, « Au RSN » et « Dans l'écosystème ». Sous 980 px, le calendrier passe au-dessus ; sous 640 px, une colonne.
- **Calendrier** : les flèches vont du mois courant au dernier mois qui a un événement ; un point jaune marque les jours qui ont un événement du RSN, un point marine ceux qui ont un événement de l'écosystème ; une série de plus de sept jours ne pointe que son premier jour. Cliquer un jour pointé montre dans la colonne de droite tous les événements de ce jour, ceux du RSN compris (sans limite) ; recliquer le jour ou « Tous » revient à la liste.
- **Au RSN** : aucun événement à venir, une phrase ; un, une grande carte ; deux, deux cartes compactes ; trois ou plus, les deux prochains en cartes compactes et le lien « + N autres événements du RSN → » vers l'agenda.
- **Dans l'écosystème** : les quatre prochains événements qui ne sont pas du RSN, un seul par organisateur (le plus proche), sans série de plus de sept jours déjà commencée.
- **Langue** : français ou anglais selon la langue WPML de la page (accueil français, page 152 ; traduction anglaise, page 1147), avec les textes de `languages/rsn-veille-en_US.po`.
- **Données et caches** : les événements viennent de `Donnees`, avec le cache JSON par langue de la page Veille (une heure au plus). La collecte de nuit, toute modification de fiche et la mise à jour de l'extension purgent aussi LiteSpeed et le cache Elementor de l'accueil et de ses traductions.
- **Ressources** : `assets/accueil.css` et `assets/accueil.js`, le CSS dans l'en-tête et le script en pied de page, sur l'accueil et les pages dont le contenu contient le code court, sinon au rendu (c'est le cas d'un code court posé dans un widget Elementor d'une autre page, qui n'est pas purgée par la collecte : la bande y suit les caches de LiteSpeed et d'Elementor). Le filtre `rsn_veille_charger_accueil` force ou empêche le chargement en tête.

## Prérequis

PHP 8.0 ou plus avec les extensions `mbstring`, `dom`, `libxml` et `json` ; WordPress 6.0 ou plus ; fuseau du site réglé sur Montréal (`America/Toronto`) : « aujourd'hui », l'heure de la collecte et celle du courriel suivent ce fuseau. Aucune bibliothèque externe à l'exécution.

## Structure des dossiers

```
rsn-veille.php            en-tête de l'extension, autochargeur, accrochage, activation et désactivation
uninstall.php             désinstallation (options, transients, tâches, capacité ; les fiches sont gardées)
includes/                 code PHP, espace de noms RSN\Veille (une classe par fichier)
  Fiche, Depot, Entrepot*   fiche normalisée, règles de confiance, fusion et mise à jour ; stockage (mémoire pour les tests, WordPress en production)
  Sources, Collecte         registre des sources, file de collecte, tâches planifiées, journal, verrou
  Http, Pertinence, Nouvelles, Courriel, TypeContenu, Journal, Verrou, ResolutionIvado, Appariement (Mila et IVADO, pages française et anglaise)
  Lecteurs/                 un lecteur par type de source (TEC, RSS, iCal, HTML Obvia, Mila, FRQ, IVADO)
  Admin/                    menu Veille, file, radar, infolettre, journal, réglages, import, métabox « Image de la fiche »
  Public/                   raccourcis [rsn_veille] et [rsn_veille_accueil] (Accueil), formulaire public, JSON, REST, iCal
assets/                   CSS et JS (public : veille.* et accueil.*, administration : admin.*)
languages/                traduction anglaise de la page publique (rsn-veille-en_US.po, source, et .mo compilé par msgfmt)
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
- Les tests tournent avec la version de PHP de la machine (le verrou Composer vise PHP 8.0.30) ; il n'y a pas d'intégration continue en 0.5.2.

## Lancer wp-env

Pour essayer l'extension dans un WordPress local, il faut Docker et `@wordpress/env` : `npx @wordpress/env start` démarre un site (WordPress stable, PHP 8.2) avec l'extension active, par défaut sur http://localhost:8888 (administration : `/wp-admin`, utilisateur `admin`, mot de passe `password`, valeurs propres à l'environnement local). `npx @wordpress/env stop` l'arrête. Les commandes WP-CLI se lancent par `npx wp-env run cli wp …`.

## Livraison

Le zip de l'extension se fabrique à partir d'un commit, sans tests ni documents de conception :

```
git archive --format=zip --prefix=rsn-veille/ -o rsn-veille-0.5.2.zip HEAD -- . ':!tests' ':!docs' ':!.wp-env.json' ':!.superpowers' ':!composer.lock' ':!phpunit.xml'
```

(les fichiers `rsn-veille-*.zip` sont ignorés par Git). Le guide d'installation décrit aussi l'installation depuis GitHub (WP Pusher ou Git Updater).

## Limites connues de la version 0.5.2

- **File d'approbation** : pas de pagination ; les onglets « Publiées » et « Écartées » montrent les 200 fiches les plus récentes (les compteurs restent exacts), « À approuver » et « À vérifier » sont chargés en entier ; la liste des sources du filtre ne reprend que celles de l'onglet affiché ; un statut changé dans l'éditeur WordPress ne marque pas la fiche comme traitée à la main ; « À approuver » et « À vérifier » sont triées par le jour d'arrivée (`cree_le`) : une fiche sans jour d'arrivée prend le jour de son post (qui suit la date de l'événement) ; le repère « Nouveau » a la granularité du jour (arrivée aujourd'hui ou hier) ; un menu absent à la première requête après une mise à jour sans réactivation (recharger la page).
- **Courriel hebdomadaire** : la fenêtre de 7 jours peut se chevaucher d'un courriel à l'autre (une fiche peut figurer dans deux courriels) ; les fiches approuvées dans la semaine comptent comme « publiées automatiquement » ; les fiches créées avant le champ `cree_le` n'y figurent pas ; les destinataires sont visibles les uns des autres.
- **Radar** : le cache courant dure une heure même si une source est en erreur ; le verrou de lecture n'est pas atomique ; pas de rechargement automatique pendant « Rafraîchissement en cours ».
- **Collecte** : chaque source ne charge que ses propres fiches publiées ; le JSON public omet les événements finis depuis plus de 12 mois ; une fiche IVADO qui échoue de façon permanente est redemandée chaque nuit ; le gabarit HTML d'une source qui change peut donner « 0 fiche » sans erreur ; les fuseaux iCal inconnus sont lus comme Montréal ; la limite d'exécution PHP de WHC n'a pas été mesurée (une source par tâche, 120 s demandées : `max_execution_time` à vérifier).
- **Formulaire public** : le courriel du proposant est conservé sans durée limite (voir le guide, section Confidentialité) ; annuler un événement collecté ne se répercute pas sur une proposition publiée ; la limite de débit suppose que `REMOTE_ADDR` est l'adresse réelle du visiteur et ses compteurs ne sont pas atomiques.
- **Import** : l'import des événements des infolettres n'a ni plafond ni suspension des purges (une purge de cache par fiche écrite) ; un fichier de plus de 2 Mo est refusé.
- **Appels sans date limite** : un appel dont la source ne donne ni date limite (cherchée dans l'extrait, le contenu complet du flux et le titre) ni date de fin reste « en cours » 30 jours après sa publication à la source (sinon après son entrée dans la veille), puis disparaît de la page, du JSON, de l'infolettre et du courriel (l'iCal ne contient pas d'appels), même s'il est encore ouvert. Tant qu'il est visible, il n'est que dans la liste : placé à la date du jour, jamais « passé », absent des bandes « À la une » et « Dates limites » (elles ne montrent que des dates à venir). Passer de 90 à 30 jours (0.3.0) vaut pour tous les canaux : un appel sans date limite publié il y a plus de 30 jours n'est plus dans le JSON, l'infolettre ni le courriel.
- **Page publique** : les listes de sources des légendes sont écrites en dur ; deux raccourcis sur la même page dupliquent certains identifiants HTML ; la colonne collante des filtres peut demander un réglage de `top` sous l'en-tête du thème ; **l'adaptation au thème Vault** (en-tête de page moins haut, bandeau qui le prolonge) est établie sur la page de test de rsn.quebec, une page ordinaire (bloc « Code court ») : elle n'agit que si la page porte la classe `rsn-veille-page` (raccourci dans le contenu de la page, page choisie dans les réglages ou sa traduction WPML ; pas de classe quand le raccourci est rendu ailleurs que dans le contenu de la page, ni quand le filtre `rsn_veille_charger_ressources` force le chargement), et sur une page Elementor les marges des sections laissent un écart entre l'en-tête et le bandeau ; avec un autre thème, le bandeau se place sous l'en-tête de ce thème, sans y toucher ; le titre de la page est celui de la page WordPress (à renommer « Événements et appels »), l'extension ne le change pas ; un appel « Nouveau » porte cette étiquette même si sa date limite est proche ; l'image mise en avant d'une fiche remplace l'image de la source sur la page et dans le JSON (l'iCal n'a pas d'image), sans champ de saisie dans l'éditeur.
- **Page en anglais** : les thèmes (`rsn_theme`) et les noms d'organisateurs restent en français ; l'administration, les courriels, l'infolettre et le Journal restent en français (l'infolettre force le français) ; pas de troisième langue. Les textes viennent du `.mo` livré : une retouche faite dans WPML String Translation prime sur lui. Obvia, le FRQ et les autres sources restent dans une seule langue ; **seuls Mila et IVADO sont appariés** (par leurs dates : deux événements le même jour avec une seule version anglaise donnent l'anglaise à la première, et pour IVADO l'erreur est définitive ; pour IVADO, un résumé anglais paru après coup n'est pas repris (Mila est réécrit à chaque passage)). Les champs anglais (`titre_en`, `resume_en`, `url_en`) ne sont ni affichés ni modifiables dans l'administration et ne sont jamais effacés par une valeur vide. `?lang=en` du JSON est sans effet sans WPML. Les noms des sources sont traduits à l'appel (`Sources`) : une collecte lancée depuis l'administration en locale anglaise enregistrerait des noms de source en anglais dans les fiches (la collecte de nuit et l'administration de rsn.quebec sont en français). Un appel du RSN sans traduction WPML reste en français ; si l'extrait de sa traduction est vide, il garde son résumé français sous le titre anglais.
- **Collecte (IVADO)** : une page d'événement IVADO est reconnue comme une redirection d'après son titre, son `<h1>`, un `meta refresh` ou une affectation de `window.location` par une adresse (littérale ou variable définie par une adresse littérale) ; une redirection construite autrement, ou par `window.top.location`, n'est pas reconnue et la fiche garde l'adresse IVADO. Sur une page de redirection, la fiche prend le titre de la balise `<title>` et reste sans résumé ; les fiches déjà en base ne sont pas relues et gardent l'adresse IVADO.
- **Bande de l'accueil** : le code court n'a pas de réglage ni d'attribut (quatre événements de l'écosystème, un par organisateur, deux cartes du RSN au plus) ; l'en-tête de la bande et ses marges restent dans Elementor ; un code court posé dans une autre page Elementor que l'accueil a ses ressources au rendu seulement (donc après le contenu, pas dans l'en-tête de la page) et cette page n'est pas purgée par la collecte (la bande y suit les caches de LiteSpeed et d'Elementor) ; la bande est dessinée par JavaScript, sans lui elle se réduit à un lien vers l'agenda ; elle suit le cache JSON d'une heure ; son rendu réel n'est établi que sur rsn.quebec, après le remplacement du code court dans Elementor.
- **Tests** : le bouchon de WordPress ne reproduit pas tout (voir plus haut) ; la vérification de bout en bout dans WordPress reste manuelle (`tests/manuel/PARCOURS.md`).

## Licence

© Réseau santé numérique, tous droits réservés.

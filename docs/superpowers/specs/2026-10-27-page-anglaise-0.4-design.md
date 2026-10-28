# Conception 0.4.0 — la page « Événements et appels » en anglais

Date : 2026-10-27 (soir). Succède à `2026-10-27-page-0.3-design.md` (0.3.0, fusionnée le même jour).

## 1. Intention

Tess veut que la page existe en anglais sur `rsn.quebec/en/` : l'interface en anglais, les événements et les appels
du RSN dans leur version anglaise quand elle existe, et tout le reste dans sa langue de publication (« je sais que
l'on n'a pas tout le contenu dans les deux langues »). Décisions prises avec elle le 27 octobre :

- une seule langue pour les sources externes, sauf **Mila et IVADO**, dont les pages anglaises listent exactement les
  mêmes événements que les pages françaises (vérifié le 27 octobre avec les lecteurs de l'extension : Mila 3/3, IVADO
  6/6) : ces deux-là sont appariés dans les deux langues ;
- Obvia (sa page anglaise ne liste que les événements tenus en anglais, 3 sur 10) et le FRQ (page anglaise vide)
  restent en français, comme toutes les autres sources (Digital Health Canada est déjà en anglais).

## 2. Faits sur le site et sur l'extension (relevés le 27 octobre)

- WPML Multilingual CMS 5.0.1 et WPML String Translation 5.0.0 actifs (TranslatePress inactif). Le site anglais est
  servi sous `/en/`, locale `en-US` (`<html lang="en-US">`) ; le français est `fr-FR`.
- **Événements du RSN** (The Events Calendar) : tous traduits. `/en/wp-json/tribe/events/v1/events` et
  `?lang=en` servent les versions anglaises (`/en/evenement/co-creation-digital-health-solutions-workshop-2026/`).
  Ce sont des articles `tribe_events` distincts, liés par WPML.
- **Appels du RSN** : les neuf articles de la catégorie 149 « Appels de financement » ont leur traduction dans la
  catégorie 154 (« Funding calls »), avec les mêmes champs `rsn_date_limite`, `rsn_date_ouverture`,
  `rsn_appel_titre`, `rsn_appel_details`, `rsn_appel_lien`, et un extrait traduit.
- **Page de test** 2845 : pas de traduction anglaise. Les pages anglaises existantes qui comptent : « Events »
  (`/en/events-calendar/`, 1561) et « Calls for collaboration » (2791).
- **Extension** : les textes publics passent déjà par `__()`, `esc_html__`, `esc_attr__` et `_x()` avec le domaine
  `rsn-veille` (gabarit : 62 chaînes ; `Raccourci::textes()` : 105 ; `Formulaire` : 15 ; `Donnees` : 4), mais
  l'extension n'appelle pas `load_plugin_textdomain` et n'a pas de dossier `languages/` : la page anglaise est
  aujourd'hui en français. Les textes lus par le script public sont rendus côté serveur (`textes()`), donc une
  traduction gettext suffit pour le script aussi.
- `Donnees::transient()` a déjà **un cache JSON et un cache iCal par langue** (`wpml_current_language`), vidés
  ensemble ; `Collecte::pages_veille()` et `Raccourci::classe_body()` reconnaissent déjà la traduction WPML de la
  page Veille. `/en/veille.ics` répond déjà en 200 (règle de réécriture sous le préfixe de langue).
- **Défaut existant** : `EvenementsRsn::lire()` (`tribe_get_events`) rend les articles des deux langues ; le JSON
  français contient aujourd'hui « Journées scientifiques RSN » **et** « RSN Scientific Days » (30 mars 2026), la
  version anglaise étiquetée `langue: fr`. Le dédoublonnage (`Fiche::cle_dedoublonnage` = début + 22 caractères du
  titre) ne peut pas les rapprocher. À corriger ici.
- **Mila** : fiches à partir de `https://mila.quebec/fr/evenements` (`LecteurMila`, titre, dates, URL, image sur
  la page de liste) ; la page `https://mila.quebec/en/events` a la même structure, titres traduits.
  **IVADO** : fiches à partir de `https://ivado.ca/evenements/` (`LecteurIvado` : dates et URL `?p=` sur la liste,
  titre et résumé complétés en lisant chaque page d'événement, `completer()`, résumés tirés du flux
  `https://ivado.ca/evenements/feed/`) ; `https://ivado.ca/en/events/` a la même structure, avec des identifiants
  `?p=` propres à chaque langue (204932 ↔ 204986…). Les dates (début, fin) coïncident une à une entre les deux
  langues chez les deux organismes.
- Outils sur le poste : `msgfmt` et `xgettext` (gettext Homebrew) ; pas de wp-cli.

## 3. Ce que la page anglaise montre

Même page, même gabarit, mêmes bandes, même liste, même calendrier, même formulaire, en anglais :

- **Les textes de l'interface** en anglais : bandeau (intro, « Subscribe to the calendar », « Suggest an event »),
  « Featured by the RSN », « Everything the RSN organizes and funds → », « Deadlines », les filtres (« Show », « Who
  organizes », « Month », « Format », « Location », « Themes », « Organizer », « Reset filters »), les en-têtes de
  mois et les mois courts, les pastilles (« Event », « Call », « Partner · … », « Ongoing », « Deadline to come »,
  « New », « Closes in N days »…), les boutons « More information » / « View the call », le calendrier (jours,
  légende, « Today »), les compteurs (« 12 events and 3 calls to come »), la table des sources (« Source », « What we
  read », « Items », « Collected on … »), le formulaire complet et ses messages, les messages d'erreur du script.
  Les dates s'écrivent à l'anglaise : « October 1, 2026 », « October 4–18, 2026 », « 8:00 ».
- **Les événements du RSN** dans leur version anglaise (titre, résumé, lieu, lien vers `/en/evenement/…`, image)
  quand elle existe ; sinon la version française, marquée « In French ».
- **Les appels du RSN** dans leur version anglaise (titre, extrait, lien vers `/en/…`, catégorie « Funding calls »,
  même date limite) quand elle existe ; sinon la version française, marquée « In French ».
- **Mila et IVADO** : titre, résumé et lien anglais quand l'appariement a réussi ; sinon le français, marqué.
- **Tout le reste** (Obvia, FRQ, RQRV, Réseau sommeil, CRM, CARES, Prix Hippocrate, propositions du public) tel
  quel, en français, avec la pastille « In French » ; Digital Health Canada, en anglais, sans pastille.
- Une **proposition** envoyée depuis le formulaire anglais est enregistrée avec `langue = en` (champ caché
  `langue`, valeur `fr`|`en` posée par le gabarit d'après la langue de la page, validée par `Formulaire`) : elle
  porte alors « En anglais » sur la page française et rien sur la page anglaise. Décision du concepteur.
- Sur la page **française**, rien ne change à l'œil, sauf la disparition des doublons anglais des événements du RSN.
  La pastille « En anglais » reste pour Digital Health Canada et les quelques fiches anglaises.
- Les **thèmes** (étiquettes `rsn_theme` : « Données », « Formation »…) restent en français sur la page anglaise
  (non-but, § 7). Les noms d'organisateurs aussi (ce sont des noms propres).
- Le **calendrier iCal** `/en/veille.ics` est en anglais (mêmes règles que la page) ; `/veille.ics` reste en
  français. Le JSON `/wp-json/rsn/v1/veille` accepte `?lang=en`.

## 4. Comment

### 4.1 Traduction des textes : un fichier gettext livré avec l'extension

- `rsn-veille.php` appelle `load_plugin_textdomain('rsn-veille', false, dirname(plugin_basename(__FILE__)) .
  '/languages')` sur `init` (avant que `Raccourci::textes()` ou le gabarit ne soient rendus ; les sources
  (`Sources::par_defaut`) ne sont lues qu'après `init`).
- Nouveau dossier `languages/` avec `rsn-veille-en_US.po` (source, commis) et `rsn-veille-en_US.mo` (compilé par
  `msgfmt`, commis aussi : WordPress lit le `.mo`). Le `.po` couvre **toutes les chaînes du côté public** : les
  fichiers d'`includes/Public/` (gabarit, `Raccourci`, `Formulaire`, `Donnees`, `Rest`, `Ical` s'il en a) et les
  noms et descriptions des sources (§ 4.6). Les contextes `_x()` sont respectés (`msgctxt`). Les chaînes de
  l'administration ne sont pas traduites (non-but).
- Un test PHPUnit lit le `.po` (analyse simple : `msgctxt`, `msgid`, `msgstr`, lignes repliées) et compare à
  l'ensemble des chaînes extraites par expression régulière de ces mêmes fichiers (`__(`, `esc_html__(`,
  `esc_attr__(`, `_x(`, `_n(` avec leurs deux formes) : aucune chaîne publique sans entrée, aucune entrée avec
  `msgstr` vide. Le même test vérifie que le `.mo` existe et contient autant d'entrées que le `.po` (en-tête du
  `.mo` : nombre de chaînes à l'octet 8), ce qui attrape un `.po` modifié sans recompilation.
- **Dates et heures en anglais.** Le script compose les dates lui-même ; il reçoit par `textes()` des gabarits
  traduisibles : `date_longue` = « %1$s %2$s %3$s » (jour, mois long, année ; en anglais « %2$s %1$s, %3$s »),
  `premier` = « 1er » (en anglais « 1 »), `heure` = « %1$s h %2$s » (en anglais « %1$s:%2$s »), `du_au_meme_mois` et
  `du_au` (déjà des clés) prennent la forme anglaise « %3$s %1$s–%2$s, %4$s » / « %1$s to %2$s ». Les tuiles de date
  (« 27 / OCT. ») et les cartes « À la une » lisent `mois` (déjà traduisible). Les mois courts anglais : « Jan. »,
  « Feb. », « Mar. », « Apr. », « May », « June », « July », « Aug. », « Sept. », « Oct. », « Nov. », « Dec. ».
- Le `lang` du conteneur : le gabarit pose `lang="<?php echo esc_attr(Donnees::langue_courante()); ?>"` sur
  `div.rsn-veille` (lecteurs d'écran, césure).

### 4.2 La langue de la page et des sorties

- `Donnees::langue_courante(): string` : `apply_filters('wpml_current_language', null)`, sinon `'fr'` ; seules
  `fr` et `en` sont connues (toute autre valeur vaut `fr` pour le contenu, les textes gettext suivant la locale de
  WordPress).
- `Donnees::construire()` et `Donnees::elements()` reçoivent la langue (paramètre `?string $langue = null`, défaut :
  la langue courante) ; le JSON porte `langue` au premier niveau (`{genere_le, langue, sources, items}`). Les
  transients par langue existent déjà.
- **REST** `GET /wp-json/rsn/v1/veille?lang=en` : `Rest::repondre` lit `lang` (`fr`|`en`, défaut : langue courante,
  donc `fr` hors préfixe), fait `do_action('wpml_switch_language', $lang)` avant `Donnees::en_cache()` et le
  remet après (`wpml_switch_language` avec la langue d'origine), pour que les filtres WPML (permaliens, requêtes)
  suivent. Sans WPML, `lang` ne change que les textes… il n'y a pas de textes dans le JSON : sans WPML, `lang=en`
  rend le même contenu que `fr` (documenté).
- **iCal** : `/en/veille.ics` est déjà servi dans le contexte anglais de WPML (transient `_en`) ; `Ical` prend
  les éléments de la langue courante, donc titres et liens anglais. Rien d'autre à faire que le vérifier au parcours.
- Le bouton « S'abonner au calendrier » garde `home_url('/veille.ics')`, que WPML réécrit en `/en/veille.ics` sur la
  page anglaise.

### 4.3 Événements du RSN : une seule version par événement, dans la langue de la page

`EvenementsRsn::lire(string $depuis, string $langue)` :

- Lit comme aujourd'hui tous les articles `tribe_events` à venir ou récents, **toutes langues** (c'est ce que
  `tribe_get_events` rend).
- Les regroupe par **groupe de traduction** WPML : `apply_filters('wpml_element_trid', null, $id,
  'post_tribe_events')` ; sans WPML (`null`), chaque article est son propre groupe.
- Dans chaque groupe, garde **un seul** article : celui dont la langue (`apply_filters('wpml_post_language_details',
  null, $id)['language_code']`) est `$langue` s'il existe, sinon celui en `fr`, sinon le premier. La fiche produite
  porte `langue` = la langue de l'article gardé (`fr` ou `en`), `url` = son permalien, `cle_source` =
  `rsn-tec:<id gardé>`, et une nouvelle clé `tec_trid` (entier ou null) pour le dédoublonnage (§ 4.4).
- Corrige du même coup le défaut existant : la page française ne montre plus les articles anglais.

### 4.4 Dédoublonnage des événements du RSN par identifiant, pas par titre

Les fiches collectées depuis le site (`cle_source` `rsn:<id>`, identifiant de l'article `tribe_events` français
rendu par la REST de TEC) et les événements de `EvenementsRsn` (`rsn-tec:<id gardé>`) se rapprochent aujourd'hui par
début + titre. En anglais, les titres diffèrent : il faut rapprocher par identifiant.

- `Donnees::elements()` : pour chaque fiche collectée dont une clé de source commence par `rsn:`, calcule le
  **groupe de traduction** de l'identifiant (`wpml_element_trid` ; sans WPML, l'identifiant lui-même) ; pour chaque
  événement de `EvenementsRsn`, son `tec_trid` (ou son identifiant). Deux éléments de même groupe sont un doublon,
  avec les mêmes règles de fusion qu'en 0.3.0 (la fiche collectée gagne si elle a une image, l'événement du RSN fait
  foi pour « Qui organise ») ; sinon le rapprochement par début + titre continue de s'appliquer (autres sources,
  propositions).
- **La clé par début + titre se calcule toujours sur le titre français**, même sur la page anglaise : `EvenementsRsn`
  rend aussi `titre_fr` (le titre de l'article français du groupe de traduction, sinon celui de l'article gardé) et
  `elements()` s'en sert pour la clé ; pour les fiches collectées, la clé se calcule avant d'appliquer `titre_en`
  (§ 4.6). Ainsi un événement partenaire inscrit au calendrier du RSN et collecté chez son organisateur reste
  dédoublonné en anglais comme en français. Décision du concepteur.
- Quand la fiche collectée gagne (image) sur la page anglaise, elle prend **le titre, le résumé, le lieu, l'URL et
  la langue** de l'événement anglais gardé par `EvenementsRsn` : la fiche collectée est française, l'événement
  anglais est la version à montrer. Seuls l'image (`image_url` / `image_manuelle`) et l'identifiant de la fiche
  restent ceux de la fiche collectée.

### 4.5 Appels du RSN : la traduction par WPML

Les fiches `rsn_appels:` viennent du flux RSS français ; leur `url` est le permalien français d'un article du site.

- `Donnees::elements()`, pour une fiche dont la clé de source commence par `rsn_appels:` et seulement si `$langue !==
  'fr'` : `url_to_postid($fiche->url)` ; si `> 0`, `apply_filters('wpml_object_id', $id, 'post', false, $langue)` ;
  si une traduction existe (entier différent de null), remplace `titre` par `get_the_title`, `resume` par l'extrait
  (`get_the_excerpt` de l'article traduit, nettoyé par `Fiche::nettoyer_texte`, vide si absent : on garde alors le
  résumé français), `url` par `get_permalink`, `categorie` par le nom de la première catégorie de l'article traduit
  (`Funding calls`), `langue` par `$langue`. L'échéance ne change pas (même `rsn_date_limite`).
- Coût : une requête par appel du RSN en cours (une dizaine), une fois par heure et par langue (transient). Pas de
  mise en cache à part.

### 4.6 Mila et IVADO : appariement à la collecte

- `Fiche` reçoit trois propriétés : `titre_en`, `resume_en`, `url_en` (chaînes, vides par défaut), persistées comme
  les autres (`TypeContenu::METAS`, `vers_tableau`, `depuis_tableau`), jamais exposées telles quelles dans le JSON
  public (`Donnees::CHAMPS_PUBLICS` ne les contient pas) : elles sont **appliquées** par `Donnees::elements()` quand
  `$langue === 'en'` et `titre_en !== ''` : `titre`, `resume`, `url` prennent la version anglaise, `langue` devient
  `en`. Sur la page française elles n'ont aucun effet.
- `Sources` : les entrées `mila` et `ivado` reçoivent `url_en` (`https://mila.quebec/en/events`,
  `https://ivado.ca/en/events/`) et, pour IVADO, `flux_evenements_url_en` (`https://ivado.ca/en/events/feed/`, à
  vérifier par le constructeur ; s'il n'existe pas, les résumés anglais d'IVADO restent vides et le résumé français
  est gardé).
- `Collecte::collecter_source()` : quand la source a `url_en`, après la lecture française (fiches, complétion IVADO
  comprise), lit la page anglaise avec **le même lecteur** et la même complétion (pour IVADO : lecture de chaque page
  d'événement anglaise pour le titre, résumés par le flux anglais), puis **apparie** : pour chaque fiche française,
  la fiche anglaise de mêmes `debut` et `fin` non encore appariée, dans l'ordre de la liste ; la fiche française
  appariée reçoit `titre_en`, `resume_en`, `url_en` ; les fiches anglaises non appariées sont ignorées et comptées
  dans le journal de la source (« N événements anglais sans équivalent français »). Les fiches anglaises ne sont
  jamais enregistrées comme fiches. Aucune nouvelle requête ne sort du budget HTTP existant de la source (le lecteur
  anglais ajoute ses propres requêtes, dans la même passe nocturne : une source par passe, comme aujourd'hui).
- Fiches déjà en base (les 3 de Mila et les 6 d'IVADO existent) : `titre_en`, `resume_en`, `url_en` sont des
  **champs factuels** (`Depot::CHAMPS_FACTUELS`, comme `url`) : la collecte les écrit à chaque passage où la lecture
  anglaise a donné une valeur, qu'une correction manuelle du français ait eu lieu ou non ; une valeur vide (lecture
  anglaise en échec, rien d'apparié) n'efface jamais une valeur existante. À la fusion d'un doublon d'une autre source
  (`trouver_doublon`), les trois champs passent à la fiche gardée si elle ne les a pas. Décision du concepteur.
- IVADO, requêtes : la page anglaise d'un événement n'est lue que pour une fiche appariée **sans `titre_en`** (ou
  dont `url_en` change), dans le même plafond `MAX`/`BUDGET` que les lectures françaises (les requêtes anglaises y
  comptent) ; une fiche déjà complète ne coûte rien les nuits suivantes. Mila ne lit que la page de liste.
- Administration : les trois champs ne sont ni édités ni affichés en 0.4.0 (non-but, documenté comme limite).

### 4.7 Pastille de langue

- `Raccourci::textes()` : nouvelle clé `en_francais` (« En français » ; en anglais « In French ») à côté de
  `en_anglais` (« En anglais » ; en anglais « In English »).
- Script : `D.langue` (clé du JSON) ; la pastille s'affiche quand `k.langue` vaut `fr` ou `en` **et** diffère de
  `D.langue` : `t(k.langue === 'en' ? 'en_anglais' : 'en_francais')`. Comportement inchangé sur la page française
  (seuls les éléments `en` ont une pastille).

### 4.8 Sources (noms et descriptions)

`Sources::par_defaut()` enveloppe `nom` et `flux` de chaque source dans `__(…, 'rsn-veille')` (chaînes littérales,
extractibles) ; l'identifiant `id` reste la clé technique. La table des sources de la page anglaise lit donc « RSN:
events », « Site events calendar »… Les pages d'administration restent françaises (locale du tableau de bord).

### 4.9 Côté site, à faire par l'équipe

1. Dans WPML, créer la traduction anglaise de la page Veille avec le même code court `[rsn_veille]`, titre « Events
   and calls » (hors menu tant que c'est l'essai). L'extension la reconnaît déjà : ressources en tête, classe body,
   purge des caches.
2. Après la mise à jour de l'extension : « LiteSpeed › Purger tout » (les caches JSON par langue sont purgés
   automatiquement au changement de version).
3. Rien à traduire dans WPML String Translation : les textes viennent du fichier de l'extension. Si l'équipe veut
   retoucher une traduction, elle peut le faire dans String Translation (qui prime sur le `.mo`).

### 4.10 Version et documents

- `0.4.0` ; `CHANGELOG.md` ; `LISEZMOI.md` (section « La page publique » : les deux langues ; limites : thèmes en
  français, admin en français, Mila/IVADO seuls appariés, Obvia/FRQ en français, `lang=en` sans WPML) ;
  `docs/installation-et-bascule.md` (Temps 3 réécrit : créer la traduction, vérifier `/en/veille.ics`, la
  pastille, les appels ; plus de « la version anglaise s'affiche en français ») ; `tests/manuel/PARCOURS.md`
  (étapes : page anglaise, textes, dates, événements et appels du RSN en anglais, fiche sans traduction marquée,
  Mila/IVADO appariés dans l'admin via le JSON `?lang=en`, iCal anglais, page française sans doublon anglais,
  JSON `lang`).

## 5. Vérification

- PHPUnit (bouchons) : `EvenementsRsn::lire` avec des bouchons `wpml_element_trid` / `wpml_post_language_details`
  (trois cas : traduction en langue de page, français seul, sans WPML) ; dédoublonnage par groupe de traduction
  (fiche `rsn:12` contre événement gardé 45 du même groupe, fusion image / qui organise, titre anglais appliqué) ;
  appels traduits (`url_to_postid` et `wpml_object_id` bouchonnés, extrait vide → résumé français gardé) ;
  `titre_en` appliqué en `en` et ignoré en `fr` ; appariement Mila/IVADO (dates identiques, doublon de date résolu
  par l'ordre, anglais orphelin ignoré et journalisé) ; REST `lang` (switch appelé et remis) ; pastille `en_francais`
  dans les textes ; test de couverture du `.po` (§ 4.1) ; `langue` du JSON.
- Le bouchon WordPress reçoit `url_to_postid`, `get_the_excerpt`, `load_plugin_textdomain`, `plugin_basename` s'ils
  manquent, et `tribe_get_events` s'il n'existe pas déjà.
- `msgfmt --check --statistics` propre sur le `.po` (parcours manuel, et à chaque recompilation).
- Rendu réel : seulement sur rsn.quebec après la mise à jour et la création de la page anglaise (parcours).

## 6. Faits à confirmer par le constructeur (mineurs)

- L'existence de `https://ivado.ca/en/events/feed/` et sa forme (même structure que le flux français).
- Le nom exact des catégories anglaises (« Funding calls ») n'a pas d'importance : il est lu sur l'article.
- `wpml_post_language_details` rend `['language_code' => 'en', …]` ou un `WP_Error` : traiter les deux.

## 7. Non-buts

- Pas de traduction de l'administration, des courriels, de l'infolettre, du journal.
- Pas de traduction des thèmes (`rsn_theme`) ni des noms d'organisateurs ; pas d'édition des champs anglais dans
  l'admin ; pas d'appariement pour Obvia, le FRQ ou les autres sources ; pas de lecture des flux anglais d'autres
  organismes.
- Pas de troisième langue : `fr` et `en` seulement.
- Pas de nouveau banc d'essai JavaScript.

## 8. Laissé au constructeur

- La forme exacte du test du `.po` et du lecteur minimal de `.mo`.
- La façon de transmettre la langue de la page au formulaire (champ caché proposé) et son test.
- L'organisation du code d'appariement (dans `Collecte` ou dans une petite classe `Appariement` à côté des
  lecteurs), pourvu qu'il soit testé seul.
- Le découpage des tâches ; l'ordre naturel : textes (4.1, 4.7, 4.8) · langue des sorties (4.2) · événements du RSN
  (4.3, 4.4) · appels du RSN (4.5) · Mila/IVADO (4.6) · documents et version (4.10).

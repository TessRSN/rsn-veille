# Vérification manuelle de l'administration (tâche 11)

À faire dans un WordPress réel (`npx @wordpress/env start`, extension active), les tests PHPUnit
n'utilisant qu'une imitation de WordPress (`tests/bouchons-wordpress.php`). Les commandes `wp` se
lancent par `npx wp-env run cli wp …`.

## Préparation

1. Désactiver puis réactiver l'extension (l'activation donne la capacité `rsn_veille_gerer`) :
   `wp plugin deactivate rsn-veille && wp plugin activate rsn-veille`.
2. Vérifier les rôles : `wp cap list editor | grep rsn_veille_gerer` et
   `wp cap list administrator | grep rsn_veille_gerer` affichent la capacité ;
   `wp cap list author | grep rsn_veille_gerer` et `wp cap list subscriber | grep rsn_veille_gerer` n'affichent rien.
3. Créer deux comptes de test (WP-CLI affiche le mot de passe généré ; il ne sert que dans wp-env) :
   `wp user create redaction redaction@exemple.test --role=editor` et
   `wp user create lecteur lecteur@exemple.test --role=subscriber`.
4. Créer des fiches : `wp eval-file wp-content/plugins/rsn-veille/tests/manuel/lancer-collecte.php`
   (vraie collecte) ou, plus court, trois fiches à la main :

   ```
   wp eval '$e = new RSN\Veille\EntrepotWordPress();
   foreach (["a_approuver", "a_approuver", "publie"] as $i => $s) {
       $f = new RSN\Veille\Fiche(); $f->type = "evenement"; $f->titre = "Fiche admin " . $i . " <script>alert(1)</script>";
       $f->debut = gmdate("Y-m-d", time() + ($i + 5) * DAY_IN_SECONDS); $f->source = "Vérification"; $f->niveau = "A";
       $f->organisateur = "RSN"; $f->resume = "Fiche de vérification de la file."; $f->url = "https://exemple.test/" . $i;
       $f->statut = $s; $f->cle_source = "verif-admin:" . $i; $f->cles_source = [$f->cle_source];
       echo $e->creer($f), PHP_EOL;
   }'
   ```

## Vérifications

- [ ] **Éditeur** (compte `redaction`) : le menu « Veille » (icône de flux) apparaît sous « Commentaires »,
      avec File d'approbation, Radar, Infolettre, Journal, Réglages. Radar, Infolettre et Réglages
      affichent « Cette page est à venir. ».
- [ ] **Abonné** (compte `lecteur`) : pas de menu « Veille » ; l'adresse
      `/wp-admin/admin.php?page=rsn-veille` répond « Désolé, vous n'avez pas l'autorisation… ».
- [ ] Le titre `<script>` s'affiche en texte (aucune alerte), avec le lien « Modifier » qui ouvre
      l'éditeur WordPress de la fiche et le lien « Page d'origine » dans un nouvel onglet.
- [ ] Onglet « À approuver » : **Publier** retire la ligne sans recharger, le compteur « À approuver »
      baisse de 1 et « Publiées » monte de 1 ; `wp post get <id> --field=post_status` donne `publish`
      et `wp post meta get <id> rsn_veille_manuel` donne `1`.
- [ ] **Écarter** retire la ligne ; statut `draft`.
- [ ] Onglet « Publiées » : **Retirer** retire la ligne ; statut `draft`.
- [ ] Onglet « Écartées » : **Publier** remet la fiche en `publish` ; les écartées sont triées de la
      plus récente à la plus ancienne.
- [ ] Filtres : « Appels » ne montre que les appels ; la liste des sources contient « Vérification » et
      la filtre ; les onglets gardent les filtres choisis.
- [ ] **À vérifier** : marquer une fiche publiée absente
      `wp eval '(new RSN\Veille\EntrepotWordPress())->marquer_absent(<id>, "2026-10-20");'`, puis
      recharger : l'onglet « À vérifier (1) » la liste avec « Absent de la source depuis le
      20 octobre 2026 ». **Garder publiée** la retire de l'onglet, `rsn_veille_absent_depuis` n'existe
      plus et `rsn_veille_absences` vaut 0. **Retirer** (sur une autre fiche marquée) la passe en `draft`.
- [ ] Jeton expiré : dans la console du navigateur, `rsnVeilleAdmin.nonce = 'x'`, puis un bouton :
      message « Jeton de sécurité expiré : rechargez la page. » dans la ligne, fiche inchangée.
- [ ] Réglage des rôles : `wp option patch insert rsn_veille_reglages roles '["author"]' --format=json`
      (ou `wp option update rsn_veille_reglages '{"roles":["author"]}' --format=json` si l'option n'existe
      pas), puis ouvrir une page d'administration : `wp cap list editor` n'a plus `rsn_veille_gerer`,
      `author` et `administrator` l'ont. Remettre ensuite `["administrator","editor"]`.
- [ ] **Journal** : après une collecte, « Dernière collecte terminée : … » en date lisible, une ligne
      par source (les sources jamais collectées : « jamais collectée »), erreurs repliées sous
      « N erreurs », et le déroulé de chaque source replié en bas de page.
- [ ] Les fichiers `admin.css` et `admin.js` ne sont chargés que sur les pages du menu Veille (onglet
      Réseau des outils du navigateur sur `/wp-admin/edit.php` : absents), avec `?ver=0.1.0`.
- [ ] Aucune erreur PHP dans `wp-content/debug.log` ni dans la console du navigateur.

## Nettoyage

`wp post delete $(wp post list --post_type=rsn_veille --meta_key=rsn_veille_source --meta_value=Vérification --format=ids --post_status=any) --force`
puis `wp user delete redaction lecteur --yes`.

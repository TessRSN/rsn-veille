# Conception 0.5.1 — abonnement au calendrier et point « Comment ça marche »

Date : 2026-10-28. Maquette validée par Tess : `docs/maquette/agenda-info-v1.html`
(https://claude.ai/artifact/X8QTm48RtRBevuad9cs3jr). Page concernée : l'Agenda de l'écosystème (`[rsn_veille]`,
`includes/Public/gabarit-veille.php`, `assets/veille.css`, `assets/veille.js`), en français et en anglais.

## 1. Pourquoi

- Le bouton « S'abonner au calendrier » pointe vers `https://…/veille.ics` : un clic télécharge le fichier, que le
  logiciel importe une fois. Les nouveaux événements n'arrivent jamais. Tess croyait s'abonner.
- Le calendrier s'appelle encore « Veille de l'écosystème RSN » dans les logiciels ; la page s'appelle maintenant
  « Agenda de l'écosystème ».
- Tess veut un point d'information discret qui explique que l'agenda est tenu par une veille semi-automatique, que seules
  les sources structurées sont lues, et qui mène à la table « Sources lues », pour que les gens qui ne voient pas leur
  organisme ne trouvent pas ça « bizarre ». Pas de texte permanent sur la page : un clic qui déplie.

## 2. Ce qui change

### 2.1 Abonnement
- Le bouton `a.btn.clair` « S'abonner au calendrier » pointe vers l'adresse du calendrier de la langue courante
  (`home_url('/veille.ics')`, que WPML met sous `/en/` en anglais) avec le schéma `webcal://` au lieu de `https://`
  ou `http://`. (`esc_url` doit accepter le protocole : passer `['http','https','webcal']` en liste de protocoles.)
- Sous les deux boutons, dans `.act`, une ligne `.autres` en petit texte : « Google Agenda : » puis un lien « ajouter »
  vers `https://calendar.google.com/calendar/render?cid=` + `rawurlencode(adresse webcal)` (nouvel onglet,
  `rel="noopener"`), puis un bouton « Copier l'adresse » qui copie l'adresse `https://…/veille.ics` de la langue
  courante (adresse dans `data-adresse`), avec un retour `role="status"` « Adresse copiée » pendant 2,5 s ; si le
  presse-papiers est refusé, le retour affiche l'adresse elle-même.
- `.act` passe en colonne alignée à droite (à gauche sous 820 px et dans le conteneur de 760 px), la ligne `.autres`
  sous les boutons. Valeurs de la maquette.
- `X-WR-CALNAME` du calendrier iCal : « Agenda de l'écosystème RSN » (anglais « RSN ecosystem calendar »).

### 2.2 Point « Comment ça marche »
- À la fin du paragraphe d'intro du bandeau, un bouton `button.info-btn#rsn-veille-info` (icône « i » entourée en SVG
  + « Comment ça marche »), `aria-expanded`, `aria-controls="rsn-veille-panneau"`.
- Juste après `.tete`, dans le `.wrap` du bandeau, un panneau `div.panneau#rsn-veille-panneau` `hidden` (pas de bulle
  flottante : `clip-path` du bandeau la couperait) : bouton de fermeture « × » (`aria-label` « Fermer »), titre
  `h2` « Un agenda tenu par une veille semi-automatique », un paragraphe, une liste de trois éléments, un paragraphe,
  deux liens : « Voir les sources lues ↓ » vers `#sources` (la `section.sources` reçoit `id="sources"`), et
  « Proposer un événement » qui ouvre le formulaire comme `#btn-proposer`. Textes exacts : ceux de la maquette (§ TX.fr
  et TX.en), traduits au `.po`.
- Comportement : clic sur le bouton = ouvrir ou fermer ; « × » ou Échap = fermer et rendre le focus au bouton ; à
  l'ouverture, le focus va au titre du panneau (`tabindex="-1"`) ; « Voir les sources lues » fait défiler (sans
  animation si mouvement réduit) et surligne brièvement le titre de la section. Le panneau n'existe que si la bande
  des sources existe (section `tout`) ; sinon le lien « Voir les sources lues » est absent.
- Styles de la maquette (`.info-btn`, `.panneau`, `.autres`, `.copie`), préfixés `.rsn-veille`, focus blanc sur le
  bleu (comme les boutons du bandeau).

### 2.3 Divers
- `aria-label` du conteneur : « Agenda de l'écosystème » (au lieu de « Veille de l'écosystème »).
- Version 0.5.1, CHANGELOG, PARCOURS (une étape : abonnement Apple/Outlook, Google, copie, point d'information,
  lien vers les sources, Échap, anglais), `verifier-public.md` (`?ver=0.5.1`).

## 3. Non-buts
Pas de changement du fichier iCal lui-même (contenu, rythme), de l'accueil, de l'administration.

## 4. Vérification
PHPUnit (gabarit : lien `webcal://`, lien Google encodé, bouton de copie et son `data-adresse`, bouton d'information et
panneau, `id="sources"`, absence du panneau en section `agenda`/`appels` si les sources n'y sont pas ; textes au
`.po` ; `X-WR-CALNAME`) ; `node --check` ; faux DOM pour l'ouverture, la fermeture, Échap, la copie ; rendu sur
rsn.quebec après la mise à jour.

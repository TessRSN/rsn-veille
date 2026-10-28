<?php
/**
 * Gabarit de la page publique ([rsn_veille]) d'après docs/maquette/gabarit-v9.html : bandeau (intro et
 * boutons ; le titre est fourni par le gabarit du site), « À la une », puis le formulaire, les dates
 * limites, l'agenda et les sources dans un même .wrap. Sans le mode équipe, la file d'approbation, l'onglet
 * Actualités ni la copie pour l'infolettre (tout cela vit dans wp-admin). Plus d'onglets : l'agenda et
 * les appels sont dans la même liste, filtrée par « Afficher ».
 *
 * Variables : $section (tout|agenda|appels), $une ('1'|'0' : bande « À la une »), $json_donnees et $json_textes (JSON déjà échappé pour
 * un <script>, voir Raccourci::json), $hex (hexagone SVG), $suffixe (« » au premier rendu de la page,
 * « -2 » au deuxième… : identifiants des nœuds de données uniques).
 *
 * Conteneur <div role="region"> et non <main> : le gabarit du site a déjà le sien.
 *
 * @var string $section
 * @var string $une
 * @var string $suffixe
 * @var string $langue Langue de la page : « fr » ou « en ».
 * @var string $json_donnees
 * @var string $json_textes
 * @var string $hex
 */

if (!defined('ABSPATH')) {
    exit;
}

$avec_agenda = $section !== 'appels';
$avec_appels = $section === 'appels';
$avec_limites = $section !== 'agenda';
$avec_une = $section === 'tout' && $une !== '0';
$horloge = '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><circle cx="8" cy="8" r="6"/><path d="M8 5v3l2 1.5"/></svg>';
$agenda_ics = '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="2" y="3" width="12" height="11" rx="1.5"/><path d="M2 7h12M6 1.5v3M10 1.5v3"/></svg>';
$info = '<svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.4"/><path d="M8 7.2v4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="8" cy="4.8" r=".95" fill="currentColor"/></svg>';
// Abonnement : l'adresse du calendrier de la langue courante (WPML la met sous /en/), en webcal:// pour Calendrier et
// Outlook, en lien d'ajout pour Google Agenda, et telle quelle pour le bouton « Copier l'adresse ».
$adresse_ics = home_url('/veille.ics');
$adresse_webcal = (string) preg_replace('#^https?://#i', 'webcal://', $adresse_ics);
$lien_google = 'https://calendar.google.com/calendar/render?cid=' . rawurlencode($adresse_webcal);
// Point « Comment ça marche » : seulement avec la table des sources (section « tout »), vers laquelle il mène.
$avec_info = $section === 'tout';
$fleche = '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12 12 4M6 4h6v6"/></svg>';
?>
<div class="rsn-veille" lang="<?php echo esc_attr($langue); ?>" role="region" aria-label="<?php echo esc_attr__("Agenda de l'écosystème", 'rsn-veille'); ?>" data-section="<?php echo esc_attr($section); ?>" data-une="<?php echo esc_attr($une); ?>">
<?php if ($avec_agenda) : ?>
<div class="bandeau">
  <div class="wrap">
    <div class="tete">
      <p><?php echo esc_html__("Les événements du RSN, ceux de nos partenaires et tout ce qui bouge en santé numérique au Québec : réseaux thématiques, IVADO, Obvia, Mila, FRQ, Digital Health Canada. Les appels de financement et de candidatures y figurent avec leur date limite.", 'rsn-veille'); ?><?php if ($avec_info) : ?><button type="button" class="info-btn" id="rsn-veille-info" aria-expanded="false" aria-controls="rsn-veille-panneau"><?php echo $info; // phpcs:ignore WordPress.Security.EscapeOutput -- SVG constant. ?><span><?php echo esc_html__('Comment ça marche', 'rsn-veille'); ?></span></button><?php endif; ?></p>
      <div class="act">
        <div class="boutons">
          <a class="btn clair" href="<?php echo esc_url($adresse_webcal, ['http', 'https', 'webcal']); ?>"><?php echo $agenda_ics; // phpcs:ignore WordPress.Security.EscapeOutput -- SVG constant. ?><?php echo esc_html__("S'abonner au calendrier", 'rsn-veille'); ?></a>
          <button class="btn jaune" id="btn-proposer" type="button"><?php echo esc_html__('Proposer un événement ou un appel', 'rsn-veille'); ?> <?php echo $fleche; // phpcs:ignore WordPress.Security.EscapeOutput -- SVG constant. ?></button>
        </div>
        <div class="autres"><span><?php echo esc_html__('Google Agenda :', 'rsn-veille'); ?></span> <a href="<?php echo esc_url($lien_google); ?>" target="_blank" rel="noopener"><?php echo esc_html(_x('ajouter', 'lien d\'ajout à Google Agenda', 'rsn-veille')); ?></a> <button type="button" class="copier" id="rsn-veille-copier" data-adresse="<?php echo esc_url($adresse_ics); ?>"><?php echo esc_html__('Copier l’adresse', 'rsn-veille'); ?></button> <span class="copie" id="rsn-veille-copie" role="status" hidden></span></div>
      </div>
    </div>
<?php if ($avec_info) : ?>
    <div class="panneau" id="rsn-veille-panneau" hidden>
      <button type="button" class="fermer" id="rsn-veille-fermer" aria-label="<?php echo esc_attr__('Fermer', 'rsn-veille'); ?>">×</button>
      <h2 tabindex="-1"><?php echo esc_html__('Un agenda tenu par une veille semi-automatique', 'rsn-veille'); ?></h2>
      <p><?php echo esc_html__('Chaque nuit, l’agenda lit les sites qui publient leurs événements de façon structurée :', 'rsn-veille'); ?></p>
      <ul><li><?php echo esc_html__('des calendriers en ligne et des flux RSS ;', 'rsn-veille'); ?></li><li><?php echo esc_html__('des pages d’événements bien organisées ;', 'rsn-veille'); ?></li><li><?php echo esc_html__('les événements et les appels du RSN.', 'rsn-veille'); ?></li></ul>
      <p><?php echo esc_html__('L’équipe du RSN vérifie ce qui est ambigu et ajoute à la main ce qui manque. Un organisme ne figure pas ici ? Son site ne publie souvent pas ses événements dans un format lisible automatiquement : proposez-nous l’événement.', 'rsn-veille'); ?></p>
      <div class="liens"><a href="#sources"><?php echo esc_html__('Voir les sources lues ↓', 'rsn-veille'); ?></a><button type="button" id="rsn-veille-panneau-proposer"><?php echo esc_html__('Proposer un événement ou un appel', 'rsn-veille'); ?></button></div>
    </div>
<?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($avec_une) : ?>
  <section class="alaune" hidden aria-label="<?php echo esc_attr__('À la une du RSN', 'rsn-veille'); ?>">
    <div class="wrap">
      <div class="tete"><span class="titre"><i></i><?php echo esc_html__('À la une du RSN', 'rsn-veille'); ?></span><a class="tout" href="#" id="lien-rsn"><?php echo esc_html__('Tout ce que le RSN organise et finance →', 'rsn-veille'); ?></a></div>
      <div class="unes" id="unes"></div>
    </div>
  </section>
<?php endif; ?>

<div class="wrap">
<?php if ($avec_agenda) : ?>
  <section class="encadre" id="dialogue" hidden>
    <h2><?php echo esc_html__('Proposer un événement', 'rsn-veille'); ?></h2>
    <p><?php echo esc_html__("Votre proposition entre dans la file d'approbation de l'équipe du RSN. Elle sera publiée ici et, si elle s'y prête, reprise dans l'infolettre.", 'rsn-veille'); ?></p>
    <form id="form-proposition" method="post" enctype="multipart/form-data">
      <div class="erreurs" id="p-erreurs" role="alert" tabindex="-1" hidden></div>
      <div class="choix">
        <span class="lab" id="lab-type"><?php echo esc_html__('Vous proposez', 'rsn-veille'); ?></span>
        <div class="seg" role="group" aria-labelledby="lab-type">
          <button type="button" id="p-type-evenement" data-type="evenement" aria-pressed="true"><?php echo esc_html__('Un événement', 'rsn-veille'); ?></button>
          <button type="button" id="p-type-appel" data-type="appel" aria-pressed="false"><?php echo esc_html__('Un appel de financement ou de candidatures', 'rsn-veille'); ?></button>
        </div>
        <input type="hidden" name="type" id="p-type" value="evenement">
      </div>
      <div class="champs">
        <label class="large"><?php echo esc_html__('Titre', 'rsn-veille'); ?> <input id="p-titre" name="titre" required maxlength="200" placeholder="<?php echo esc_attr__('Ex. : Colloque sur la souveraineté numérique en santé', 'rsn-veille'); ?>"></label>
        <label data-pour="evenement"><?php echo esc_html__('Date de début', 'rsn-veille'); ?> <input id="p-debut" name="debut" type="date" required data-requis-pour="evenement"></label>
        <label data-pour="evenement"><?php echo esc_html__('Date de fin (si plusieurs jours)', 'rsn-veille'); ?> <input id="p-fin" name="fin" type="date"></label>
        <label data-pour="appel" hidden><?php echo esc_html__('Date limite', 'rsn-veille'); ?> <input id="p-limite" name="echeance" type="date" data-requis-pour="appel" disabled><span class="aide"><?php echo esc_html__('Dernier jour pour déposer.', 'rsn-veille'); ?></span></label>
        <label><span id="lab-orga"><?php echo esc_html__('Organisateur', 'rsn-veille'); ?></span> <input id="p-orga" name="organisateur" required maxlength="120" placeholder="<?php echo esc_attr__('Ex. : Obvia', 'rsn-veille'); ?>"></label>
        <label data-pour="evenement"><?php echo esc_html__('Format', 'rsn-veille'); ?> <select id="p-format" name="format"><option value="Présentiel"><?php echo esc_html__('Présentiel', 'rsn-veille'); ?></option><option value="Virtuel"><?php echo esc_html__('Virtuel', 'rsn-veille'); ?></option><option value="Hybride"><?php echo esc_html__('Hybride', 'rsn-veille'); ?></option></select></label>
        <label data-pour="evenement"><?php echo esc_html__('Lieu et ville', 'rsn-veille'); ?> <input id="p-lieu" name="lieu" maxlength="160" placeholder="<?php echo esc_attr__('Ex. : HEC Montréal, Montréal', 'rsn-veille'); ?>"></label>
        <label><span id="lab-url"><?php echo esc_html__("Lien d'information", 'rsn-veille'); ?></span> <input id="p-url" name="url" type="url" placeholder="https://" data-requis-pour="appel"></label>
        <label class="large"><?php echo esc_html__('Courte description (2 à 3 phrases)', 'rsn-veille'); ?> <textarea id="p-resume" name="resume" rows="3" maxlength="1000"></textarea></label>
        <label><?php echo esc_html__('Votre courriel (pour vous répondre)', 'rsn-veille'); ?> <input id="p-courriel" name="courriel" type="email" autocomplete="email"></label>
        <label><?php echo esc_html__('Image (facultative : JPEG, PNG ou WebP, 3 Mo au plus)', 'rsn-veille'); ?> <input id="p-image" name="image" type="file" accept="image/jpeg,image/png,image/webp"></label>
      </div>
      <input type="hidden" name="langue" value="<?php echo esc_attr($langue); ?>">
      <div class="piege" aria-hidden="true"><label><?php echo esc_html__('Site web (laisser vide)', 'rsn-veille'); ?> <input name="site_web" type="text" tabindex="-1" autocomplete="off" aria-hidden="true"></label></div>
      <div class="boutons">
        <button class="btn" type="submit"><?php echo esc_html__("Envoyer à l'équipe", 'rsn-veille'); ?></button>
        <button class="btn sobre" type="button" id="btn-annuler"><?php echo esc_html__('Annuler', 'rsn-veille'); ?></button>
        <span class="note"><?php echo esc_html__("Votre courriel sert seulement à vous répondre ; il n'est jamais publié.", 'rsn-veille'); ?></span>
      </div>
    </form>
    <p class="merci" id="p-merci" role="status" tabindex="-1" hidden></p>
  </section>
<?php endif; ?>

<?php if ($avec_limites) : ?>
  <div class="limites" hidden>
    <span class="lab"><?php echo $horloge; // phpcs:ignore WordPress.Security.EscapeOutput -- SVG constant. ?><?php echo esc_html__('Dates limites', 'rsn-veille'); ?></span>
    <div class="defile" id="limites"></div>
    <div class="fleches">
      <button type="button" id="lim-prec" aria-label="<?php echo esc_attr__('Dates limites précédentes', 'rsn-veille'); ?>"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m10 3-5 5 5 5"/></svg></button>
      <button type="button" id="lim-suiv" aria-label="<?php echo esc_attr__('Dates limites suivantes', 'rsn-veille'); ?>"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 3 5 5-5 5"/></svg></button>
    </div>
  </div>
<?php endif; ?>

<?php if ($avec_agenda) : ?>
  <div class="deux" id="vue-agenda">
    <aside class="cote">
      <label class="recherche"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="9" cy="9" r="6"/><path d="m14 14 4 4"/></svg><input id="f-recherche" type="search" placeholder="<?php echo esc_attr__('Rechercher', 'rsn-veille'); ?>" aria-label="<?php echo esc_attr__('Rechercher un événement ou un appel', 'rsn-veille'); ?>"></label>
<?php if ($section === 'tout') : ?>
      <div><div class="fhead"><?php echo esc_html__('Afficher', 'rsn-veille'); ?></div><div class="rubs" id="f-type"></div></div>
<?php endif; ?>
      <div><div class="fhead"><?php echo esc_html__('Qui organise', 'rsn-veille'); ?></div><div class="rubs" id="f-qui"></div></div>
      <div><div class="fhead"><?php echo esc_html__('Mois', 'rsn-veille'); ?></div><div class="rubs" id="f-mois"></div></div>
      <div><div class="fhead"><?php echo esc_html__('Format', 'rsn-veille'); ?></div><div class="tags" id="f-format" data-cle="format"></div></div>
      <div><div class="fhead"><?php echo esc_html__('Lieu', 'rsn-veille'); ?></div><div class="tags" id="f-region" data-cle="region"></div></div>
      <div><div class="fhead"><?php echo esc_html__('Thèmes', 'rsn-veille'); ?></div><div class="tags" id="f-theme" data-cle="theme"></div></div>
      <div><div class="fhead"><?php echo esc_html__('Organisateur', 'rsn-veille'); ?></div><select id="f-orga" aria-label="<?php echo esc_attr__('Organisateur', 'rsn-veille'); ?>"><option value=""><?php echo esc_html__('Tous', 'rsn-veille'); ?></option></select></div>
      <button class="reinit" id="f-reinit" type="button"><?php echo esc_html__('Réinitialiser les filtres', 'rsn-veille'); ?></button>
    </aside>
    <div>
      <div class="barre">
        <div class="compte" id="compte-agenda" aria-live="polite"></div>
        <div class="vues" role="group" aria-label="<?php echo esc_attr__('Affichage', 'rsn-veille'); ?>">
          <button type="button" class="vue" data-vue="liste" aria-pressed="true"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M3 4h10M3 8h10M3 12h10"/></svg><?php echo esc_html__('Liste', 'rsn-veille'); ?></button>
          <button type="button" class="vue" data-vue="calendrier" aria-pressed="false"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="2" y="3" width="12" height="11" rx="1.5"/><path d="M2 7h12M6 1.5v3M10 1.5v3"/></svg><?php echo esc_html__('Calendrier', 'rsn-veille'); ?></button>
        </div>
      </div>
      <div id="liste-agenda"></div>
      <div id="calendrier" hidden>
        <div class="cal-tete">
          <button type="button" class="cal-nav" id="cal-prec" aria-label="<?php echo esc_attr__('Mois précédent', 'rsn-veille'); ?>"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m10 3-5 5 5 5"/></svg></button>
          <h2 id="cal-titre"></h2>
          <button type="button" class="cal-auj" id="cal-auj"><?php echo esc_html__("Aujourd'hui", 'rsn-veille'); ?></button>
          <button type="button" class="cal-nav" id="cal-suiv" aria-label="<?php echo esc_attr__('Mois suivant', 'rsn-veille'); ?>"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 3 5 5-5 5"/></svg></button>
        </div>
        <div class="cal" id="cal-grille" tabindex="0" aria-label="<?php echo esc_attr__('Calendrier du mois, flèches gauche et droite pour changer de mois', 'rsn-veille'); ?>"></div>
        <div class="cal-legende"><span><i class="rsn"></i><?php echo esc_html__('Événement du RSN', 'rsn-veille'); ?></span><span><i></i><?php echo esc_html__('Écosystème', 'rsn-veille'); ?></span><span><i class="appel"></i><?php echo esc_html__("Date limite d'un appel", 'rsn-veille'); ?></span><span><?php echo esc_html__('Glisser ou utiliser les flèches pour changer de mois', 'rsn-veille'); ?></span></div>
        <div class="cal-jour" id="cal-jour"></div>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if ($avec_appels) : ?>
  <div id="vue-appels">
    <div class="shead"><h2><?php echo $hex; // phpcs:ignore WordPress.Security.EscapeOutput -- SVG constant. ?><?php echo esc_html__('Appels en cours', 'rsn-veille'); ?></h2><span class="aside" id="aside-appels"></span></div>
    <p class="legende" style="margin:0 0 22px"><?php echo esc_html__('Appels à projets, concours et appels de candidatures repérés dans les flux du FRQ, du CRM, du RSN, de CARES et des Prix Hippocrate. La date limite est extraite du texte quand elle y figure.', 'rsn-veille'); ?></p>
    <div id="liste-appels"></div>
  </div>
<?php endif; ?>

<?php if ($section === 'tout') : ?>
  <section class="sources" id="sources">
    <div class="shead"><h2><svg class="hex" viewBox="0 0 24 26" aria-hidden="true" style="color:var(--navy)"><path d="M12 2 21.5 7.5v11L12 24 2.5 18.5v-11Z" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linejoin="round"/></svg><?php echo esc_html__('Sources lues', 'rsn-veille'); ?></h2><span class="aside" id="maj"></span></div>
    <div class="tableau"><table>
      <thead><tr><th><?php echo esc_html__('Source', 'rsn-veille'); ?></th><th><?php echo esc_html__("Ce qu'on lit", 'rsn-veille'); ?></th><th style="text-align:right"><?php echo esc_html__('Éléments', 'rsn-veille'); ?></th></tr></thead>
      <tbody id="tbl-sources"></tbody>
    </table></div>
    <p class="legende"><?php echo esc_html__("Les sources sans flux (CRES, Polytechnique, McGill, Le Point en santé, congrès PLS, LinkedIn) passent par le bouton Proposer ou par la saisie de l'équipe.", 'rsn-veille'); ?></p>
  </section>
<?php endif; ?>
</div>
<script type="application/json" id="rsn-veille-donnees<?php echo esc_attr($suffixe); ?>" data-rsn-veille-donnees><?php echo $json_donnees; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON échappé (JSON_HEX_TAG | JSON_HEX_AMP). ?></script>
<script type="application/json" id="rsn-veille-textes<?php echo esc_attr($suffixe); ?>" data-rsn-veille-textes><?php echo $json_textes; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON échappé (JSON_HEX_TAG | JSON_HEX_AMP). ?></script>
</div>

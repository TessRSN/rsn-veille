/*
 * RSN : bande « Prochains événements » de l'accueil ([rsn_veille_accueil]).
 * Repris du script de la variante A de docs/maquette/accueil-v1.html : calendrier du mois (flèches, points RSN et
 * écosystème, jour cliquable), colonne « Au RSN » (0, 1, 2 ou 3 et plus événements), colonne « Dans l'écosystème »
 * (liste par défaut ou jour choisi).
 * Données : <script type="application/json" data-rsn-accueil-donnees> = { rsn, eco, calendrier, agenda, langue } ;
 * textes : <script type="application/json" data-rsn-accueil-textes> (voir Accueil::textes()).
 * Chaque bande .rsn-veille-accueil est animée indépendamment des autres. Sans dépendance, sans police chargée.
 */
(function () {
  'use strict';
  document.querySelectorAll('.rsn-veille-accueil').forEach(racine => {
    try { animer(racine); } catch (e) { if (window.console && console.error) console.error(e); }
  });

function animer(racine) {
  const noeudDonnees = racine.querySelector('script[data-rsn-accueil-donnees]');
  const noeudTextes = racine.querySelector('script[data-rsn-accueil-textes]');
  const cal = racine.querySelector('.rsna-cal');
  const colRsn = racine.querySelector('[data-rsn]');
  const sous = racine.querySelector('[data-sous]');
  const bouton = racine.querySelector('[data-tous]');
  const liste = racine.querySelector('[data-liste]');
  if (!noeudDonnees || !cal || !colRsn || !sous || !bouton || !liste || racine.classList.contains('rsna-pret')) return;
  let D, T;
  try { D = JSON.parse(noeudDonnees.textContent); } catch (e) { return; }
  try { T = JSON.parse((noeudTextes || {}).textContent || '{}'); } catch (e) { T = {}; }
  if (!D || typeof D !== 'object') return;
  if (!T || typeof T !== 'object') T = {};

  const pad = n => String(n).padStart(2, '0');
  const ISO = /^\d{4}-\d{2}-\d{2}$/;
  // Aujourd'hui : celui du serveur (data-aujourdhui, fuseau de Montréal) ; sinon Montréal calculé ici.
  const AUJ = (() => {
    if (ISO.test(racine.dataset.aujourdhui || '')) return racine.dataset.aujourdhui;
    try {
      const s = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Toronto', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
      if (ISO.test(s)) return s;
    } catch (e) { /* repli ci-dessous */ }
    const x = new Date(); return `${x.getFullYear()}-${pad(x.getMonth() + 1)}-${pad(x.getDate())}`;
  })();
  const LANGUE = D.langue === 'en' || (D.langue !== 'fr' && racine.lang === 'en') ? 'en' : 'fr';
  const MOIS = Array.isArray(T.mois) ? T.mois : ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
  const MOIS_LONG = Array.isArray(T.mois_longs) ? T.mois_longs : ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
  const JOURS = Array.isArray(T.jours_courts) ? T.jours_courts : ['L', 'M', 'M', 'J', 'V', 'S', 'D'];

  // Textes : « %d » reçoit le nombre, « %1$s », « %2$s »… la valeur de ce rang (l'ordre peut changer à la
  // traduction), un « %s » simple la valeur suivante ; pluriel : 2 et plus en français, tout sauf 1 en anglais.
  const remplir = (modele, valeurs) => {
    let i = 0;
    return String(modele).replace(/%(\d+)\$s|%s/g, (m, rang) => { const v = rang ? valeurs[rang - 1] : valeurs[i++]; return v === undefined ? '' : String(v); });
  };
  const t = (cle, ...valeurs) => remplir(T[cle] !== undefined ? T[cle] : cle, valeurs);
  const n = (cle, nombre, ...valeurs) => { const f = Array.isArray(T[cle]) ? T[cle] : [cle, cle]; return remplir(String(f[(LANGUE === 'en' ? nombre !== 1 : nombre > 1) ? 1 : 0]).replace('%d', nombre), valeurs); };
  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const d = s => { const [a, m, j] = String(s || '').split('-').map(Number); return { a, m, j }; };
  const premier = j => j === 1 ? t('premier_du_mois') : j;
  const libelleFormat = f => (T.formats && T.formats[f]) || f;

  // Événements : début valide obligatoire, fin = le début si elle manque ou le précède (comme Accueil::fin).
  const valides = l => (Array.isArray(l) ? l : []).filter(k => k && typeof k === 'object' && typeof k.debut === 'string' && ISO.test(k.debut));
  const fin = k => typeof k.fin === 'string' && ISO.test(k.fin) && k.fin > k.debut ? k.fin : k.debut;
  // Nombre de jours calendaires de a à b (AAAA-MM-JJ), calculé en UTC : aucune heure locale en jeu.
  const jours = (a, b) => { const x = d(a), y = d(b); return Math.round((Date.UTC(y.a, y.m - 1, y.j) - Date.UTC(x.a, x.m - 1, x.j)) / 864e5); };
  // Une série de plus de sept jours (cours, webinaires) ne pointe que son premier jour, comme l'agenda.
  const longue = k => jours(k.debut, fin(k)) > 7;
  const dansJour = (k, jour) => k.debut === jour || (!longue(k) && k.debut <= jour && fin(k) >= jour);
  const estRSN = k => k.organise_par === 'rsn';
  const enCours = k => k.debut < AUJ && fin(k) >= AUJ;

  const rsn = valides(D.rsn);
  const eco = valides(D.eco);
  const calendrier = valides(D.calendrier);
  const parJourEco = jour => calendrier.filter(k => !estRSN(k) && dansJour(k, jour));

  // Dates : « 4 novembre 2026 » / « November 4, 2026 », périodes comme l'agenda (gabarits du .po).
  const dateLongue = s => { const x = d(s); return t('date_longue', premier(x.j), MOIS_LONG[x.m - 1], x.a); };
  // Date sans l'année : « date_longue » avec une année vide, puis on retire le séparateur resté au bout.
  const dateSansAnnee = s => { const x = d(s); return t('date_longue', premier(x.j), MOIS_LONG[x.m - 1], '').replace(/[,\s]+$/, ''); };
  function periode(k) {
    const a = d(k.debut), b = d(fin(k));
    if (fin(k) === k.debut) return dateLongue(k.debut);
    if (a.m === b.m && a.a === b.a) return t('du_au_meme_mois', premier(a.j), b.j, MOIS_LONG[a.m - 1], a.a);
    const debutCourt = t('date_longue', premier(a.j), MOIS_LONG[a.m - 1], a.a !== b.a ? a.a : '').replace(/[,\s]+$/, '');
    return t('du_au', debutCourt, dateLongue(fin(k)));
  }

  // Liens : http(s) seulement ; nouvel onglet seulement hors du site (l'hôte diffère de celui de la page).
  const lien = u => /^https?:\/\//i.test(String(u || '')) ? esc(u) : '#';
  const cible = u => {
    if (!/^https?:\/\//i.test(String(u || ''))) return '';
    try { return new URL(u, window.location.href).host !== window.location.host ? ' target="_blank" rel="noopener"' : ''; } catch (e) { return ''; }
  };
  const href = u => `href="${lien(u)}"${cible(u)}`;
  // Attribut `lang` d'une fiche dans l'autre langue que la page (lecteurs d'écran, césure, guillemets).
  const autreLangue = k => (k.langue === 'fr' || k.langue === 'en') && k.langue !== LANGUE;
  const attrLangue = k => autreLangue(k) ? ` lang="${esc(k.langue)}"` : '';
  const pastilleLangue = k => autreLangue(k) ? t(k.langue === 'en' ? 'en_anglais' : 'en_francais') : '';
  const meta = parties => parties.filter(Boolean).map(esc).join(' · ');

  // Colonne « Au RSN » : 0 (phrase), 1 (grande carte), 2 (deux cartes compactes), 3 et plus (deux cartes et « + N autres »).
  function carteRSN(k, compacte) {
    const image = /^https?:\/\//i.test(String(k.image_url || ''));
    const infos = meta([k.ville, k.format && libelleFormat(k.format), pastilleLangue(k)]);
    return `<a class="rsna-carte${compacte ? ' rsna-compacte' : ''}" ${href(k.url)}${attrLangue(k)}>`
      + `<div class="rsna-im${image ? '' : ' rsna-sans'}">${image ? `<img src="${lien(k.image_url)}" alt="" loading="lazy" decoding="async">` : ''}</div>`
      + `<div class="rsna-tx"><span class="rsna-pill">${esc(t('rsn'))}</span><span class="rsna-quand">${esc(periode(k))}</span>`
      + `<h3>${esc(k.titre)}</h3>${infos ? `<span class="rsna-meta">${infos}</span>` : ''}</div></a>`;
  }
  function htmlRSN() {
    if (!rsn.length) return `<div class="rsna-carte rsna-vide"><div class="rsna-tx"><span class="rsna-meta">${esc(t('aucun_rsn'))}</span></div></div>`;
    if (rsn.length === 1) return carteRSN(rsn[0], false);
    const reste = rsn.length - 2;
    const agenda = typeof D.agenda === 'string' ? D.agenda : '';
    return rsn.slice(0, 2).map(k => carteRSN(k, true)).join('')
      + (reste > 0 ? `<a class="rsna-plus" ${href(agenda)}>${esc(n('autres_rsn', reste))}</a>` : '');
  }

  // Colonne « Dans l'écosystème » : tuile de date (la date du jour pour un événement en cours, le jour choisi pour un
  // événement qui l'enjambe), titre, organisateur · format · langue.
  function ligne(k, jour) {
    const ref = jour || AUJ;
    const x = d(k.debut < ref ? ref : k.debut);
    const infos = meta([k.organisateur, k.format && libelleFormat(k.format), pastilleLangue(k)]);
    return `<a class="rsna-ligne${jour && dansJour(k, jour) ? ' rsna-sel' : ''}" ${href(k.url)}${attrLangue(k)}>`
      + `<div class="rsna-tuile"><b>${x.j}</b><span>${esc(MOIS[x.m - 1])}</span></div>`
      + `<div><h3>${esc(k.titre)}</h3>${infos ? `<span class="rsna-meta">${infos}</span>` : ''}</div></a>`;
  }

  // Calendrier : mois affiché (AAAA-MM) et jour choisi (AAAA-MM-JJ ou vide).
  const MOIS_COURANT = AUJ.slice(0, 7);
  // Dernier mois qui a un jour pointé : fin d'un événement, ou début d'une série longue.
  const DERNIER = calendrier.reduce((m, k) => { const p = (longue(k) ? k.debut : fin(k)).slice(0, 7); return p > m ? p : m; }, MOIS_COURANT);
  const etat = { mois: MOIS_COURANT, jour: '' };
  const decaler = (mois, delta) => { const x = d(mois + '-01'); const i = x.a * 12 + x.m - 1 + delta; return `${Math.floor(i / 12)}-${pad(i % 12 + 1)}`; };
  const FLECHE_PREC = '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m10 3-5 5 5 5"/></svg>';
  const FLECHE_SUIV = '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 3 5 5-5 5"/></svg>';

  function htmlCalendrier() {
    const { a, m } = d(etat.mois + '-01');
    const nbJours = new Date(Date.UTC(a, m, 0)).getUTCDate();
    const decalage = (new Date(Date.UTC(a, m - 1, 1)).getUTCDay() + 6) % 7; // lundi en premier
    // Pour chaque jour du mois : y a-t-il un événement du RSN, de l'écosystème ?
    const points = {};
    for (let j = 1; j <= nbJours; j++) {
      const iso = `${etat.mois}-${pad(j)}`;
      const K = calendrier.filter(k => dansJour(k, iso));
      if (K.length) points[iso] = { rsn: K.some(estRSN), eco: K.some(k => !estRSN(k)) };
    }
    let h = `<div class="rsna-cal-tete"><button type="button" data-nav="-1" aria-label="${esc(t('mois_precedent'))}"${etat.mois <= MOIS_COURANT ? ' disabled' : ''}>${FLECHE_PREC}</button>`
      + `<strong>${esc(MOIS_LONG[m - 1])} ${a}</strong>`
      + `<button type="button" data-nav="1" aria-label="${esc(t('mois_suivant'))}"${etat.mois >= DERNIER ? ' disabled' : ''}>${FLECHE_SUIV}</button></div><div class="rsna-grille">`;
    h += JOURS.map(x => `<span class="rsna-jn" aria-hidden="true">${esc(x)}</span>`).join('');
    for (let i = 0; i < decalage; i++) h += '<span class="rsna-jour rsna-hors" aria-hidden="true"></span>';
    for (let j = 1; j <= nbJours; j++) {
      const iso = `${etat.mois}-${pad(j)}`;
      const p = points[iso];
      const cls = ['rsna-jour', iso < AUJ ? 'rsna-passe' : '', iso === AUJ ? 'rsna-auj' : '', p ? 'rsna-a' : ''].filter(Boolean).join(' ');
      if (!p) { h += `<span class="${cls}">${j}</span>`; continue; }
      const etiquette = [dateSansAnnee(iso), p.rsn ? t('legende_rsn') : '', p.eco ? t('legende_ecosysteme') : ''].filter(Boolean).join(', ');
      h += `<button type="button" class="${cls}" data-jour="${iso}" aria-pressed="${etat.jour === iso}" aria-label="${esc(etiquette)}">${j}`
        + `<span class="rsna-pts">${p.rsn ? '<i class="rsna-pt-rsn"></i>' : ''}${p.eco ? '<i class="rsna-pt"></i>' : ''}</span></button>`;
    }
    return `${h}</div><div class="rsna-legende"><span><i class="rsna-pt-rsn"></i>${esc(t('legende_rsn'))}</span><span><i class="rsna-pt"></i>${esc(t('legende_ecosysteme'))}</span></div>`;
  }

  // Dessine ce qui dépend du mois et du jour choisi ; `focus` : sélecteurs des boutons à refocaliser, le premier qui
  // existe et n'est pas désactivé (le calendrier est redessiné, le focus clavier serait perdu).
  function rendre(focus) {
    cal.innerHTML = htmlCalendrier();
    if (etat.jour) {
      sous.textContent = t('le_jour', dateSansAnnee(etat.jour));
      bouton.textContent = t('tous');
      bouton.hidden = false;
    } else {
      sous.textContent = t('dans_ecosysteme');
      bouton.hidden = true;
    }
    const lignes = etat.jour ? parJourEco(etat.jour) : eco;
    liste.innerHTML = lignes.length ? lignes.map(k => ligne(k, etat.jour)).join('') : `<div class="rsna-vide-jour">${esc(t('aucun_jour'))}</div>`;
    for (const selecteur of focus || []) {
      const candidat = racine.querySelector(selecteur);
      if (candidat && !candidat.disabled && candidat.focus) { candidat.focus(); break; }
    }
  }

  racine.addEventListener('click', ev => {
    const el = ev.target && ev.target.closest ? ev.target : null;
    if (!el) return;
    const nav = el.closest('[data-nav]'), jour = el.closest('[data-jour]'), tous = el.closest('[data-tous]');
    if (nav && racine.contains(nav)) {
      const delta = Number(nav.dataset.nav) < 0 ? -1 : 1;
      const mois = decaler(etat.mois, delta);
      if (mois < MOIS_COURANT || mois > DERNIER) return;
      etat.mois = mois;
      etat.jour = '';
      rendre([`[data-nav="${delta}"]`, `[data-nav="${-delta}"]`]);
    } else if (jour && racine.contains(jour)) {
      etat.jour = etat.jour === jour.dataset.jour ? '' : jour.dataset.jour;
      rendre([`[data-jour="${jour.dataset.jour}"]`]);
    } else if (tous && racine.contains(tous)) {
      const avant = etat.jour;
      etat.jour = '';
      rendre(avant ? [`[data-jour="${avant}"]`] : []);
    }
  });

  colRsn.innerHTML = htmlRSN();
  liste.setAttribute('aria-live', 'polite');
  rendre([]);
  racine.classList.add('rsna-pret');
}
})();

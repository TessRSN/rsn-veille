/*
 * RSN : veille de l'écosystème, page publique ([rsn_veille]).
 * Repris du JS de docs/maquette/gabarit-v6.1.html, réduit aux parties publiques : filtres, mois (avec
 * « Plus tard » et « Événements passés »), thèmes repliés, liste et calendrier, glissement, formulaire de proposition.
 * Les actions de l'équipe (publier, écarter, infolettre) vivent dans wp-admin.
 * Données : <script type="application/json" id="rsn-veille-donnees" data-rsn-veille-donnees> (suffixé
 * « -2 », « -3 »… si le raccourci figure plusieurs fois) ; textes : [data-rsn-veille-textes] du même bloc.
 * Chaque bloc .rsn-veille est animé à partir de son nœud de données, indépendamment des autres.
 */
(function () {
  'use strict';
  document.querySelectorAll('script[data-rsn-veille-donnees]').forEach(noeud => {
    const racine = noeud.closest('.rsn-veille');
    if (racine) animer(racine, noeud);
  });

function animer(racine, noeudDonnees) {
  let D, T;
  try { D = JSON.parse(noeudDonnees.textContent); } catch (e) { return; }
  try { T = JSON.parse((racine.querySelector('script[data-rsn-veille-textes]') || {}).textContent || '{}'); } catch (e) { T = {}; }

  const pad = n => String(n).padStart(2, '0');
  const ISO = /^\d{4}-\d{2}-\d{2}$/;
  // Aujourd'hui à Montréal, quel que soit le fuseau du visiteur.
  const AUJ = (() => {
    try {
      const s = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Toronto', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
      if (ISO.test(s)) return s;
    } catch (e) { /* repli ci-dessous */ }
    const n = new Date(); return `${n.getFullYear()}-${pad(n.getMonth() + 1)}-${pad(n.getDate())}`;
  })();
  const items = (Array.isArray(D.items) ? D.items : [])
    .filter(k => k && (k.statut === 'publie' || !k.statut) && typeof k.debut === 'string' && ISO.test(k.debut))
    .map(k => Object.assign({}, k, { themes: Array.isArray(k.themes) ? k.themes : [], fin: ISO.test(k.fin || '') ? k.fin : null }));
  const MOIS = T.mois || ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
  const MOIS_LONG = T.mois_longs || ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
  const HEX = '<svg class="hex" viewBox="0 0 24 26" aria-hidden="true"><path d="M12 2 21.5 7.5v11L12 24 2.5 18.5v-11Z" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linejoin="round"/></svg>';
  const $ = s => racine.querySelector(s);
  const on = (s, type, rappel, options) => { const n = $(s); if (n) n.addEventListener(type, rappel, options); };
  let onglet = racine.dataset.section === 'appels' ? 'appels' : 'agenda';
  const filtres = { mois: '', qui: '', format: '', region: '', theme: '', orga: '', q: '' };

  // Textes : « %d » reçoit le nombre, « %1$s », « %2$s »… la valeur de ce rang (l'ordre peut changer à la
  // traduction), un « %s » simple la valeur suivante ; pluriel à la française (2 et plus).
  const remplir = (modele, valeurs) => {
    let i = 0;
    return String(modele).replace(/%(\d+)\$s|%s/g, (m, rang) => { const v = rang ? valeurs[rang - 1] : valeurs[i++]; return v === undefined ? '' : String(v); });
  };
  const t = (cle, ...valeurs) => remplir(T[cle] !== undefined ? T[cle] : cle, valeurs);
  const n = (cle, nombre, ...valeurs) => { const f = Array.isArray(T[cle]) ? T[cle] : [cle, cle]; return remplir(String(f[nombre > 1 ? 1 : 0]).replace('%d', nombre), valeurs); };
  const d = s => { const [a, m, j] = String(s || '').split('-').map(Number); return { a, m, j }; };
  const cap = s => s ? s[0].toUpperCase() + s.slice(1) : '';
  const premier = j => j === 1 ? t('premier_du_mois') : j;
  const dateLongue = s => { if (!s) return ''; const x = d(s); return `${premier(x.j)} ${MOIS_LONG[x.m - 1]} ${x.a}`; };
  const dateCourte = s => { if (!s) return ''; const x = d(s); return `${x.j} ${MOIS[x.m - 1]} ${x.a}`; };
  const esc = s => String(s || '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  // Liens et images : http(s) seulement.
  const lien = u => /^https?:\/\//i.test(String(u || '')) ? esc(u) : '#';
  const lieuTexte = k => [k.lieu, k.ville].filter(Boolean).filter((v, i, l) => l.indexOf(v) === i).join(' – ');
  // Qui organise : calculé côté serveur (Donnees, d'après la catégorie du site).
  const qui = k => ['rsn', 'partenaires', 'ecosysteme'].includes(k.organise_par) ? k.organise_par : 'ecosysteme';
  const estRSN = k => qui(k) === 'rsn';
  const libelle = (groupe, v) => (T[groupe] && T[groupe][v]) || v;

  function blocJour(k) {
    const a = d(k.debut), b = d(k.fin || k.debut);
    const meme = !k.fin || k.fin === k.debut;
    if (meme) return `<div class="jour">${a.j}<small>${MOIS[a.m - 1]}<b>${a.a}</b></small></div>`;
    if (a.m === b.m) return `<div class="jour">${a.j}–${b.j}<small>${MOIS[a.m - 1]}<b>${a.a}</b></small></div>`;
    return `<div class="jour">${a.j}<small>${MOIS[a.m - 1]}<b>${a.a}</b></small></div>`;
  }
  const ICO_DATE = '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="2" y="3" width="12" height="11" rx="1.5"/><path d="M2 7h12M6 1.5v3M10 1.5v3"/></svg>';
  const ICO_LIEU = '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M8 14.5s4.5-4.2 4.5-7.5a4.5 4.5 0 0 0-9 0c0 3.3 4.5 7.5 4.5 7.5Z"/><circle cx="8" cy="7" r="1.6"/></svg>';
  function periode(k) {
    const a = d(k.debut), b = d(k.fin || k.debut); const h = k.heure ? `, ${esc(k.heure).replace(':', ' h ')}` : '';
    if (!k.fin || k.fin === k.debut) return `${dateLongue(k.debut)}${h}`;
    if (a.m === b.m && a.a === b.a) return t('du_au_meme_mois', a.j, b.j, MOIS_LONG[a.m - 1], a.a);
    return t('du_au', `${premier(a.j)} ${MOIS_LONG[a.m - 1]}${a.a !== b.a ? ' ' + a.a : ''}`, dateLongue(k.fin));
  }
  // ligne compacte (événements passés, comme la section « Passés » de la page Événements)
  function ligneEv(k) {
    const etiq = k.themes.map(e => `<span>${esc(e)}</span>`).join('');
    const fmt = [k.format && `<b>${esc(k.format)}</b>`, k.langue === 'en' && esc(t('en_anglais'))].filter(Boolean).join(' · ');
    const cat = k.categorie_rsn ? `${esc(k.categorie_rsn)} · ` : '';
    return `<div class="ev">${HEX}${blocJour(k)}<div class="tx">
      <h3><a href="${lien(k.url)}" target="_blank" rel="noopener">${esc(k.titre)}</a></h3>
      <div class="meta">${cat}<b>${esc(k.organisateur)}</b>${lieuTexte(k) ? ' · ' + esc(lieuTexte(k)) : ''}</div>
      ${etiq ? `<div class="etiq">${etiq}</div>` : ''}
      <div class="pied"><a class="plus" href="${lien(k.url)}" target="_blank" rel="noopener">${esc(t('plus_info'))}</a><span class="fmt">${fmt}</span></div>
    </div></div>`;
  }
  // fiche à venir : même ligne que les passés, avec l'image à gauche du texte et les lignes date / lieu
  function carteEv(k, compact) {
    if (compact === true) return ligneEv(k);
    const etiq = k.themes.map(e => `<span>${esc(e)}</span>`).join('');
    const fmt = [k.prix && esc(k.prix), k.langue === 'en' && esc(t('en_anglais'))].filter(Boolean).join(' · ');
    const lieu = [lieuTexte(k), k.format].filter(Boolean).join(' · ');
    const image = /^https?:\/\//i.test(k.image_url || '');
    return `<div class="ev ${image ? 'avec-image' : ''}">${HEX}${blocJour(k)}
      ${image ? `<a class="im" href="${lien(k.url)}" target="_blank" rel="noopener" tabindex="-1" aria-hidden="true"><img src="${lien(k.image_url)}" alt="" loading="lazy" decoding="async"></a>` : ''}
      <div class="tx">
        <div class="meta"><b>${esc(k.organisateur)}</b>${k.categorie_rsn ? ' · ' + esc(k.categorie_rsn) : ''}</div>
        <h3><a href="${lien(k.url)}" target="_blank" rel="noopener">${esc(k.titre)}</a></h3>
        <div class="infos"><span>${ICO_DATE}${periode(k)}</span>${lieu ? `<span>${ICO_LIEU}${esc(lieu)}</span>` : ''}</div>
        ${etiq ? `<div class="etiq">${etiq}</div>` : ''}
        ${k.resume ? `<p>${esc(k.resume)}</p>` : ''}
        <div class="pied"><a class="plus" href="${lien(k.url)}" target="_blank" rel="noopener">${esc(t('plus_info'))}</a>${fmt ? `<span class="fmt">${fmt}</span>` : ''}</div>
      </div></div>`;
  }
  // Date limite d'un appel : l'échéance trouvée, sinon une fin distincte de la publication.
  const limite = k => k.echeance && ISO.test(k.echeance) ? k.echeance : (k.fin && k.fin !== k.debut ? k.fin : '');
  function carteAppel(k) {
    const lim = limite(k);
    const x = d(lim || k.debut);
    const tuile = lim ? `<div class="tuile"><b>${x.j}</b><span>${MOIS[x.m - 1]}</span><i>${esc(t('date_limite'))}</i></div>`
      : `<div class="tuile gris"><b>${x.j}</b><span>${MOIS[x.m - 1]}</span><i>${esc(t('publie'))}</i></div>`;
    return `<div class="appel">${tuile}<div class="tx">
      <h3><a href="${lien(k.url)}" target="_blank" rel="noopener">${esc(k.titre)}</a></h3>
      <div class="meta"><b>${esc(k.organisateur)}</b> · ${esc(k.categorie || t('appel'))}${lim ? ' · ' + esc(t('publie_le', dateCourte(k.debut))) : ''}</div>
      ${k.resume ? `<p>${esc(k.resume)}</p>` : ''}
      <div class="pied"><a class="plus" href="${lien(k.url)}" target="_blank" rel="noopener">${esc(t('consulter_appel'))}</a></div>
    </div></div>`;
  }
  const visibles = type => items.filter(k => k.type === type);
  const aVenir = () => visibles('evenement').filter(k => (k.fin || k.debut) >= AUJ);
  const passes = () => visibles('evenement').filter(k => (k.fin || k.debut) < AUJ);
  let vue = 'liste', calMois = AUJ.slice(0, 7), calJour = '';

  function filtrer(L) {
    const q = filtres.q.trim().toLowerCase();
    if (q) L = L.filter(k => (k.titre + ' ' + k.organisateur + ' ' + (k.resume || '')).toLowerCase().includes(q));
    if (filtres.qui) L = L.filter(k => qui(k) === filtres.qui);
    if (filtres.format) L = L.filter(k => k.format === filtres.format);
    if (filtres.region) L = L.filter(k => k.region === filtres.region);
    if (filtres.theme) L = L.filter(k => k.themes.includes(filtres.theme));
    if (filtres.orga) L = L.filter(k => k.organisateur === filtres.orga);
    return L;
  }
  function rendreAgenda() {
    if (!$('#liste-agenda')) return;
    $('#liste-agenda').hidden = vue !== 'liste'; $('#calendrier').hidden = vue !== 'calendrier';
    if (vue === 'calendrier') { rendreCalendrier(); rendreMois(); return; }
    const anciens = filtres.mois === 'passes';
    let L = filtrer(anciens ? passes() : aVenir());
    if (filtres.mois === 'plus-tard') { const apres = decalerMois(moisSuivants()[NB_MOIS - 1], 1); L = L.filter(k => k.debut.slice(0, 7) >= apres); }
    else if (filtres.mois && !anciens) L = L.filter(k => k.debut.slice(0, 7) <= filtres.mois && (k.fin || k.debut).slice(0, 7) >= filtres.mois);
    L.sort((a, b) => anciens ? b.debut.localeCompare(a.debut) : a.debut.localeCompare(b.debut));
    const groupes = new Map();
    for (const k of L) { const cle = (anciens || k.debut >= AUJ ? k.debut : AUJ).slice(0, 7); if (!groupes.has(cle)) groupes.set(cle, []); groupes.get(cle).push(k); }
    let html = '';
    for (const [cle, G] of groupes) {
      const x = d(cle + '-01');
      html += `<section class="bloc"><div class="shead"><h2>${HEX}${cap(MOIS_LONG[x.m - 1])} ${x.a}</h2><span class="aside">${esc(n('nb_evenements', G.length))}</span></div><div class="frise">${G.map(k => carteEv(k, anciens)).join('')}</div></section>`;
    }
    $('#liste-agenda').innerHTML = L.length ? html : `<div class="vide">${esc(t('aucun_evenement_filtres'))}</div>`;
    if (anciens) {
      const plusAncien = L.length ? d(L[L.length - 1].debut) : null;
      $('#compte-agenda').textContent = plusAncien ? n('nb_passes_depuis', L.length, `${MOIS_LONG[plusAncien.m - 1]} ${plusAncien.a}`) : n('nb_passes', L.length);
    } else {
      $('#compte-agenda').textContent = n('nb_a_venir', L.length);
    }
    rendreMois();
  }
  const NB_MOIS = 4;
  function moisSuivants() { const mois = []; const c = d(AUJ); for (let i = 0; i < NB_MOIS; i++) { mois.push(`${c.a}-${pad(c.m)}`); c.m++; if (c.m > 12) { c.m = 1; c.a++; } } return mois; }
  function rendreMois() {
    const E = filtrer(aVenir()); const mois = moisSuivants(); const apres = decalerMois(mois[mois.length - 1], 1);
    const nb = m => E.filter(k => k.debut.slice(0, 7) <= m && (k.fin || k.debut).slice(0, 7) >= m).length;
    const actif = vue === 'calendrier' ? calMois : filtres.mois;
    let html = `<button type="button" class="rub" data-mois="" aria-pressed="${actif === ''}">${HEX}<span class="t">${esc(t('tous_les_mois'))}</span><span class="n">${E.length}</span></button>`;
    let annee = d(AUJ).a;
    for (const m of mois) {
      const x = d(m + '-01');
      if (x.a !== annee) { annee = x.a; html += `<div class="annee">${x.a}</div>`; }
      html += `<button type="button" class="rub" data-mois="${m}" aria-pressed="${actif === m}">${HEX}<span class="t">${cap(MOIS_LONG[x.m - 1])}</span><span class="n">${nb(m)}</span></button>`;
    }
    const nTard = E.filter(k => k.debut.slice(0, 7) >= apres).length;
    html += `<button type="button" class="rub" data-mois="plus-tard" aria-pressed="${actif === 'plus-tard'}">${HEX}<span class="t">${esc(t('plus_tard'))}</span><span class="n">${nTard}</span></button>`;
    html += `<button type="button" class="rub" data-mois="passes" aria-pressed="${actif === 'passes'}">${HEX}<span class="t">${esc(t('evenements_passes'))}</span><span class="n">${filtrer(passes()).length}</span></button>`;
    $('#f-mois').innerHTML = html;
  }
  function decalerMois(m, nb) { const x = d(m + '-01'); x.m += nb; while (x.m > 12) { x.m -= 12; x.a++; } while (x.m < 1) { x.m += 12; x.a--; } return `${x.a}-${pad(x.m)}`; }
  function rendreCalendrier() {
    const E = filtrer(visibles('evenement'));
    const x = d(calMois + '-01');
    const nbJours = new Date(x.a, x.m, 0).getDate();
    const premierJour = (new Date(x.a, x.m - 1, 1).getDay() + 6) % 7; // lundi = 0
    const prec = d(decalerMois(calMois, -1) + '-01'); const nbPrec = new Date(prec.a, prec.m, 0).getDate();
    const nEv = E.filter(k => k.debut.slice(0, 7) <= calMois && (k.fin || k.debut).slice(0, 7) >= calMois).length;
    $('#cal-titre').innerHTML = `${cap(MOIS_LONG[x.m - 1])} ${x.a}<small>${esc(n('nb_ce_mois', nEv))}</small>`;
    let html = (T.jours_courts || ['L', 'M', 'M', 'J', 'V', 'S', 'D']).map(j => `<div class="jn">${esc(j)}</div>`).join('');
    // une série qui dure plus d'une semaine (webinaires, cours) n'apparaît qu'à son premier jour, pour ne pas remplir le mois
    const longue = k => (new Date(k.fin || k.debut) - new Date(k.debut)) / 864e5 > 7;
    const ceJour = (k, iso) => longue(k) ? k.debut === iso : (k.debut <= iso && (k.fin || k.debut) >= iso);
    const cellule = (iso, num, hors) => {
      const J = E.filter(k => ceJour(k, iso)).sort((a, b) => a.debut.localeCompare(b.debut));
      const puces = J.slice(0, 3).map(k => `<span class="puce ${estRSN(k) ? 'rsn' : ''}" title="${esc(k.titre)}">${esc(k.titre)}</span>`).join('') + (J.length > 3 ? `<span class="plus">+ ${J.length - 3}</span>` : '');
      const pts = `<span class="pts">${J.slice(0, 4).map(k => `<i class="pt ${estRSN(k) ? 'rsn' : ''}"></i>`).join('')}</span>`;
      return `<button type="button" class="c ${hors ? 'hors' : ''} ${iso === AUJ ? 'auj' : ''}" data-jour="${iso}" aria-pressed="${calJour === iso}" aria-label="${esc(dateLongue(iso) + ', ' + n('nb_evenements', J.length))}"><span class="num">${num}</span>${puces}${J.length ? pts : ''}</button>`;
    };
    for (let i = premierJour - 1; i >= 0; i--) { const j = nbPrec - i; html += cellule(`${prec.a}-${pad(prec.m)}-${pad(j)}`, j, true); }
    for (let j = 1; j <= nbJours; j++) html += cellule(`${calMois}-${pad(j)}`, j, false);
    const suiv = d(decalerMois(calMois, 1) + '-01'); const reste = (7 - (premierJour + nbJours) % 7) % 7;
    for (let j = 1; j <= reste; j++) html += cellule(`${suiv.a}-${pad(suiv.m)}-${pad(j)}`, j, true);
    $('#cal-grille').innerHTML = html;
    const J = calJour ? E.filter(k => ceJour(k, calJour)).sort((a, b) => a.debut.localeCompare(b.debut)) : [];
    $('#cal-jour').innerHTML = calJour ? `<div class="shead"><h2>${HEX}${cap(dateLongue(calJour))}</h2><span class="aside">${esc(n('nb_evenements', J.length))}</span></div>` + (J.length ? `<div class="frise">${J.map(k => carteEv(k)).join('')}</div>` : `<div class="vide">${esc(t('aucun_ce_jour'))}</div>`) : '';
    $('#compte-agenda').textContent = t('cliquer_jour');
  }
  let themesOuverts = false; const NB_THEMES = 6;
  function rendreTags() {
    if (!$('#f-theme')) return;
    const E = visibles('evenement');
    const mk = (id, vals, groupe) => { const el = $(id); el.innerHTML = vals.map(v => `<button type="button" class="tag" data-val="${esc(v)}" aria-pressed="${filtres[el.dataset.cle] === v}">${esc(groupe ? libelle(groupe, v) : v)}</button>`).join(''); };
    mk('#f-qui', ['rsn', 'partenaires', 'ecosysteme'], 'qui');
    mk('#f-format', ['Présentiel', 'Virtuel', 'Hybride'], 'formats');
    mk('#f-region', ['Québec', 'En ligne', 'Hors Québec'], 'regions');
    const compte = {}; E.forEach(k => k.themes.forEach(th => { compte[th] = (compte[th] || 0) + 1; }));
    let themes = Object.keys(compte).sort((a, b) => compte[b] - compte[a] || a.localeCompare(b, 'fr'));
    const tous = themes.length;
    if (!themesOuverts && tous > NB_THEMES) { themes = themes.slice(0, NB_THEMES); if (filtres.theme && !themes.includes(filtres.theme)) themes.push(filtres.theme); }
    mk('#f-theme', themes);
    if (tous > NB_THEMES) $('#f-theme').insertAdjacentHTML('beforeend', `<button type="button" class="plus-moins" data-plus-moins aria-expanded="${themesOuverts}">${esc(themesOuverts ? t('moins') : t('plus'))}</button>`);
    const orgas = [...new Set(E.map(k => k.organisateur).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'fr'));
    $('#f-orga').innerHTML = `<option value="">${esc(t('tous'))}</option>` + orgas.map(o => `<option value="${esc(o)}"${filtres.orga === o ? ' selected' : ''}>${esc(o)}</option>`).join('');
  }
  // Appels en cours : le serveur n'envoie que ceux-là (règle Fiche::appel_en_cours : date limite à venir, sinon
  // publié il y a moins de 90 jours). On ne retire ici que ceux dont la date limite est passée depuis la génération.
  const appelsEnCours = () => visibles('appel').filter(k => { const lim = limite(k); return !lim || lim >= AUJ; });
  function rendreAppels() {
    if (!$('#liste-appels')) return;
    const cle = k => limite(k) || '9' + k.debut;
    const L = appelsEnCours().sort((a, b) => cle(a).localeCompare(cle(b)));
    $('#liste-appels').innerHTML = L.length ? L.map(carteAppel).join('') : `<div class="vide">${esc(t('aucun_appel'))}</div>`;
    $('#aside-appels').textContent = n('nb_appels', L.length);
  }
  function rendreCompteurs() {
    if ($('#n-agenda')) $('#n-agenda').textContent = aVenir().length;
    if ($('#n-appels')) $('#n-appels').textContent = appelsEnCours().length;
  }
  function rendreSources() {
    if (!$('#tbl-sources')) return;
    const S = (Array.isArray(D.sources) ? D.sources : []).filter(s => s.nb > 0).sort((a, b) => b.nb - a.nb || String(a.nom).localeCompare(String(b.nom), 'fr'));
    $('#tbl-sources').innerHTML = S.map(s => `<tr><td><a href="${lien(s.url)}" target="_blank" rel="noopener">${esc(s.nom)}</a></td><td style="color:var(--body)">${esc(s.flux)}</td><td class="n">${Number(s.nb) || 0}</td></tr>`).join('');
    // genere_le : « 2026-10-26T03:05:00-04:00 » (heure de Montréal) ou seulement la date.
    const g = String(D.genere_le || '');
    if (!ISO.test(g.slice(0, 10))) return;
    const h = g.slice(11, 16);
    $('#maj').textContent = /^\d{2}:\d{2}$/.test(h) ? t('collecte_du_a', dateLongue(g.slice(0, 10)), `${Number(h.slice(0, 2))} h ${h.slice(3)}`) : t('collecte_du', dateLongue(g.slice(0, 10)));
  }
  function tout() { rendreCompteurs(); rendreTags(); rendreAgenda(); rendreAppels(); }

  // interactions
  racine.querySelectorAll('.onglet').forEach(b => b.addEventListener('click', () => {
    onglet = b.dataset.onglet;
    racine.querySelectorAll('.onglet').forEach(x => x.setAttribute('aria-selected', x === b));
    ['agenda', 'appels'].forEach(v => { const vueOnglet = $('#vue-' + v); if (vueOnglet) vueOnglet.hidden = v !== onglet; });
  }));
  on('#f-recherche', 'input', e => { filtres.q = e.target.value; rendreAgenda(); });
  on('#f-mois', 'click', e => {
    const b = e.target.closest('[data-mois]'); if (!b) return; const m = b.dataset.mois;
    if (vue === 'calendrier' && m && m !== 'passes' && m !== 'plus-tard') { calMois = m; calJour = ''; rendreAgenda(); return; }
    if (vue === 'calendrier') { vue = 'liste'; racine.querySelectorAll('.vue').forEach(x => x.setAttribute('aria-pressed', x.dataset.vue === vue)); }
    filtres.mois = m; rendreAgenda();
  });
  racine.querySelectorAll('.vue').forEach(b => b.addEventListener('click', () => {
    vue = b.dataset.vue;
    racine.querySelectorAll('.vue').forEach(x => x.setAttribute('aria-pressed', x === b));
    if (vue === 'calendrier' && filtres.mois && filtres.mois !== 'passes' && filtres.mois !== 'plus-tard') calMois = filtres.mois;
    rendreAgenda();
  }));
  on('#cal-prec', 'click', () => { calMois = decalerMois(calMois, -1); calJour = ''; rendreAgenda(); });
  on('#cal-suiv', 'click', () => { calMois = decalerMois(calMois, 1); calJour = ''; rendreAgenda(); });
  on('#cal-auj', 'click', () => { calMois = AUJ.slice(0, 7); calJour = AUJ; rendreAgenda(); });
  on('#cal-grille', 'click', e => {
    const c = e.target.closest('[data-jour]'); if (!c) return;
    calJour = calJour === c.dataset.jour ? '' : c.dataset.jour; if (calJour && calJour.slice(0, 7) !== calMois) calMois = calJour.slice(0, 7); rendreAgenda();
    if (calJour) $('#cal-jour').scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'nearest' });
  });
  on('#cal-grille', 'keydown', e => {
    if (e.key === 'ArrowLeft') { calMois = decalerMois(calMois, -1); calJour = ''; rendreAgenda(); }
    if (e.key === 'ArrowRight') { calMois = decalerMois(calMois, 1); calJour = ''; rendreAgenda(); }
  });
  let touche = null;
  on('#cal-grille', 'touchstart', e => { touche = { x: e.touches[0].clientX, y: e.touches[0].clientY }; }, { passive: true });
  on('#cal-grille', 'touchend', e => {
    if (!touche) return; const dx = e.changedTouches[0].clientX - touche.x, dy = e.changedTouches[0].clientY - touche.y; touche = null;
    if (Math.abs(dx) > 60 && Math.abs(dx) > Math.abs(dy) * 1.5) { calMois = decalerMois(calMois, dx < 0 ? 1 : -1); calJour = ''; rendreAgenda(); }
  }, { passive: true });
  on('#f-theme', 'click', e => { if (e.target.closest('[data-plus-moins]')) { themesOuverts = !themesOuverts; rendreTags(); } });
  ['#f-qui', '#f-format', '#f-region', '#f-theme'].forEach(id => on(id, 'click', e => {
    const b = e.target.closest('[data-val]'); if (!b) return;
    const cle = $(id).dataset.cle; filtres[cle] = filtres[cle] === b.dataset.val ? '' : b.dataset.val; rendreTags(); rendreAgenda();
  }));
  on('#f-orga', 'change', e => { filtres.orga = e.target.value; rendreAgenda(); });
  on('#f-reinit', 'click', () => { Object.keys(filtres).forEach(k => { filtres[k] = ''; }); $('#f-recherche').value = ''; rendreTags(); rendreAgenda(); });
  // Formulaire « Proposer un événement » : envoi en fetch vers admin-ajax.php (action rsn_veille_proposer,
  // jeton de window.rsnVeillePublic), erreurs sous les champs et en tête, remerciement à la place du formulaire.
  const CONFIG = window.rsnVeillePublic || {};
  let jeton = String(CONFIG.nonce || '');
  const form = $('#form-proposition');
  function effacerErreurs() {
    if (!form) return;
    form.querySelectorAll('.erreur-champ').forEach(el => el.remove());
    form.querySelectorAll('[aria-invalid]').forEach(el => { el.removeAttribute('aria-invalid'); el.removeAttribute('aria-describedby'); });
    const zone = $('#p-erreurs'); zone.hidden = true; zone.textContent = '';
  }
  // erreurs : { champ: message } (champ inconnu ou vide : message en tête seulement).
  function montrerErreurs(erreurs) {
    effacerErreurs();
    const messages = [];
    Object.keys(erreurs).forEach(cle => {
      const message = String(erreurs[cle] || ''); if (!message) return;
      messages.push(message);
      const champ = cle && form.elements.namedItem(cle);
      if (!champ || !champ.insertAdjacentElement) return;
      const note = document.createElement('span');
      note.className = 'erreur-champ'; note.id = 'p-erreur-' + cle; note.textContent = message;
      champ.insertAdjacentElement('afterend', note);
      champ.setAttribute('aria-invalid', 'true'); champ.setAttribute('aria-describedby', note.id);
    });
    const zone = $('#p-erreurs');
    const liste = document.createElement('ul');
    messages.forEach(m => { const li = document.createElement('li'); li.textContent = m; liste.appendChild(li); });
    // Une erreur générale seule : son message ; sinon « Vérifiez les champs signalés : » et la liste.
    const generale = messages.length === 1 && !Object.keys(erreurs).some(Boolean);
    const tete = document.createElement('p'); tete.textContent = generale ? messages[0] : t('corriger_champs');
    zone.appendChild(tete);
    if (!generale) zone.appendChild(liste);
    zone.hidden = false; zone.focus();
  }
  async function poster(donnees) {
    const reponse = await fetch(CONFIG.ajaxurl, { method: 'POST', body: donnees, credentials: 'same-origin' });
    let json = null; try { json = await reponse.json(); } catch (e) { /* réponse non JSON */ }
    return { statut: reponse.status, data: json && typeof json.data === 'object' && json.data ? json.data : null };
  }
  async function envoyer(dejaRenouvele) {
    const donnees = new FormData(form);
    donnees.set('action', CONFIG.action || 'rsn_veille_proposer'); donnees.set('nonce', jeton);
    const r = await poster(donnees);
    // Page servie depuis un cache plus vieux que son jeton : un jeton neuf, puis un seul nouvel essai.
    if (r.statut === 403 && !dejaRenouvele) {
      const demande = new FormData(); demande.set('action', CONFIG.action_jeton || 'rsn_veille_jeton');
      const j = await poster(demande);
      if (j.data && j.data.nonce) { jeton = String(j.data.nonce); return envoyer(true); }
    }
    return r;
  }
  function ouvrirFormulaire() {
    $('#dialogue').hidden = false; form.hidden = false; $('#p-merci').hidden = true; $('#p-titre').focus();
  }
  on('#btn-proposer', 'click', ouvrirFormulaire);
  on('#btn-annuler', 'click', () => { effacerErreurs(); $('#dialogue').hidden = true; });
  on('#form-proposition', 'submit', async e => {
    e.preventDefault();
    const bouton = form.querySelector('[type="submit"]');
    if (bouton.disabled) return;
    if (!CONFIG.ajaxurl) { montrerErreurs({ '': t('erreur_envoi') }); return; }
    // Image trop lourde : refusée avant l'envoi (au-delà de post_max_size, PHP viderait toute la requête).
    const image = form.elements.namedItem('image');
    if (image && image.files && image.files[0] && image.files[0].size > 3 * 1024 * 1024) { montrerErreurs({ image: t('image_trop_lourde') }); return; }
    const libelleBouton = bouton.textContent;
    bouton.disabled = true; bouton.setAttribute('aria-busy', 'true'); bouton.textContent = t('envoi_en_cours');
    effacerErreurs();
    try {
      const r = await envoyer(false);
      const data = r.data || {};
      if (data.ok) {
        form.reset(); form.hidden = true;
        const merci = $('#p-merci'); merci.textContent = data.message || t('merci_proposition'); merci.hidden = false; merci.focus();
      } else if (data.erreurs && typeof data.erreurs === 'object' && Object.keys(data.erreurs).length) {
        montrerErreurs(data.erreurs);
      } else {
        montrerErreurs({ '': data.message || (r.statut === 403 ? t('jeton_expire') : t('erreur_envoi')) });
      }
    } catch (err) {
      montrerErreurs({ '': t('erreur_envoi') });
    } finally {
      bouton.disabled = false; bouton.removeAttribute('aria-busy'); bouton.textContent = libelleBouton;
    }
  });

  rendreSources(); tout();
}
})();

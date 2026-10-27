/**
 * RSN Veille : boutons Publier, Écarter, Retirer et Garder de la file d'approbation.
 * Envoi en fetch vers admin-ajax.php ; au succès, la ligne quitte l'onglet et les compteurs suivent ;
 * en cas d'échec, un message s'affiche dans la ligne. Aucune dépendance.
 */
(function () {
	'use strict';

	var config = window.rsnVeilleAdmin;
	var tableau = document.querySelector('.rsn-veille-file');
	if (!config || !tableau) {
		return;
	}
	var onglet = tableau.getAttribute('data-onglet');

	function majCompteurs(compteurs) {
		Object.keys(compteurs || {}).forEach(function (cle) {
			var element = document.querySelector('[data-compteur="' + cle + '"]');
			if (element) {
				element.textContent = '(' + compteurs[cle] + ')';
			}
		});
	}

	function videSiNecessaire() {
		var corps = tableau.tBodies[0];
		if (corps.querySelector('tr[data-id]')) {
			return;
		}
		var ligne = corps.insertRow();
		ligne.className = 'rsn-veille-vide';
		var cellule = ligne.insertCell();
		cellule.colSpan = tableau.tHead.rows[0].cells.length;
		cellule.textContent = config.textes.vide;
	}

	function afficherErreur(ligne, message) {
		var cellule = ligne.querySelector('.rsn-veille-actions');
		var erreur = cellule.querySelector('.rsn-veille-erreur');
		if (!erreur) {
			erreur = document.createElement('p');
			erreur.className = 'rsn-veille-erreur';
			erreur.setAttribute('role', 'alert');
			cellule.appendChild(erreur);
		}
		erreur.textContent = message;
	}

	/** La fiche reste dans l'onglet courant seulement si son statut y correspond (une fiche gardée sort de « À vérifier »). */
	function appliquer(ligne, statut) {
		if (onglet === 'a_verifier' || statut !== onglet) {
			ligne.parentNode.removeChild(ligne);
			videSiNecessaire();
			return;
		}
		// Fiche gardée depuis l'onglet « Publiées » : drapeau, bouton « Garder » et ancien message retirés.
		ligne.querySelectorAll('.rsn-veille-absent, .rsn-veille-erreur, .rsn-veille-action[data-statut="publie"]').forEach(function (element) {
			element.parentNode.removeChild(element);
		});
		occuper(ligne, false);
	}

	function occuper(ligne, occupee) {
		ligne.setAttribute('aria-busy', occupee ? 'true' : 'false');
		ligne.querySelectorAll('.rsn-veille-action').forEach(function (bouton) {
			bouton.disabled = occupee;
		});
	}

	tableau.addEventListener('click', function (evenement) {
		var bouton = evenement.target.closest('.rsn-veille-action');
		if (!bouton) {
			return;
		}
		var ligne = bouton.closest('tr[data-id]');
		var statut = bouton.getAttribute('data-statut');
		var donnees = new FormData();
		donnees.append('action', config.action);
		donnees.append('nonce', config.nonce);
		donnees.append('id', ligne.getAttribute('data-id'));
		donnees.append('statut', statut);

		occuper(ligne, true);
		fetch(config.ajaxurl, { method: 'POST', credentials: 'same-origin', body: donnees })
			.then(function (reponse) {
				return reponse.json().catch(function () {
					return null;
				});
			})
			.then(function (json) {
				if (!json || !json.success || !json.data || !json.data.ok) {
					var refus = new Error(json && json.data && json.data.message ? json.data.message : config.textes.erreur);
					refus.serveur = true;
					throw refus;
				}
				majCompteurs(json.data.compteurs);
				appliquer(ligne, json.data.statut);
			})
			.catch(function (erreur) {
				occuper(ligne, false);
				// Message du serveur s'il y en a un ; sinon (réseau, réponse illisible) le message générique.
				afficherErreur(ligne, erreur && erreur.serveur ? erreur.message : config.textes.erreur);
			});
	});
})();

/**
 * RSN Veille : radar des nouvelles. Les boutons (`data-act` : garder, ignorer, retirer) envoient
 * l'adresse de la nouvelle en fetch vers admin-ajax.php ; le serveur reprend titre et résumé dans son
 * cache. Au succès, la ligne disparaît et le compteur suit ; en cas d'échec, le message s'affiche
 * dans la zone `role="status"`. Aucune dépendance.
 */
(function () {
	'use strict';

	var config = window.rsnVeilleAdmin && window.rsnVeilleAdmin.radar;
	var page = document.querySelector('.rsn-veille-radar');
	if (!config || !page) {
		return;
	}
	var message = page.querySelector('.rsn-veille-message');

	function annoncer(texte, erreur) {
		message.textContent = texte;
		message.classList.toggle('rsn-veille-message-erreur', !!erreur);
	}

	function occuper(ligne, occupee) {
		ligne.setAttribute('aria-busy', occupee ? 'true' : 'false');
		ligne.querySelectorAll('.rsn-veille-radar-action').forEach(function (bouton) {
			bouton.disabled = occupee;
		});
	}

	/** Le message « aucune nouvelle » d'une section réapparaît quand elle n'a plus de ligne. */
	function majVides() {
		page.querySelectorAll('[data-section]').forEach(function (section) {
			var vide = section.querySelector('.rsn-veille-vide');
			if (vide) {
				vide.hidden = !!section.querySelector('li[data-url]');
			}
		});
	}

	function retirerLigne(ligne) {
		var liste = ligne.parentNode;
		liste.removeChild(ligne);
		var groupe = liste.closest('.rsn-veille-groupe');
		if (groupe && !liste.querySelector('li[data-url]')) {
			groupe.parentNode.removeChild(groupe);
		}
		majVides();
	}

	page.addEventListener('click', function (evenement) {
		var bouton = evenement.target.closest('.rsn-veille-radar-action');
		if (!bouton) {
			return;
		}
		var acte = bouton.getAttribute('data-act');
		var ligne = bouton.closest('li[data-url]');
		if (!ligne || !config.actions[acte]) {
			return;
		}
		var donnees = new FormData();
		donnees.append('action', config.actions[acte]);
		donnees.append('nonce', config.nonce);
		donnees.append('url', ligne.getAttribute('data-url'));

		occuper(ligne, true);
		fetch(window.rsnVeilleAdmin.ajaxurl, { method: 'POST', credentials: 'same-origin', body: donnees })
			.then(function (reponse) {
				return reponse.json().catch(function () {
					return null;
				});
			})
			.then(function (json) {
				if (!json || !json.success || !json.data || !json.data.ok) {
					var refus = new Error(json && json.data && json.data.message ? json.data.message : config.textes.erreur);
					refus.serveur = true;
					throw refus;
				}
				var compteur = page.querySelector('[data-compteur-gardees]');
				if (compteur && json.data.libelle) {
					compteur.textContent = json.data.libelle;
				}
				retirerLigne(ligne);
				annoncer(acte === 'garder' ? config.textes.gardee : (acte === 'ignorer' ? config.textes.ignoree : config.textes.retiree), false);
			})
			.catch(function (erreur) {
				occuper(ligne, false);
				// Message du serveur s'il y en a un ; sinon (réseau, réponse illisible) le message générique.
				annoncer(erreur && erreur.serveur ? erreur.message : config.textes.erreur, true);
			});
	});
})();

/**
 * RSN Veille : page Infolettre. « Copier » met le bloc dans le presse-papiers en HTML et en texte
 * (`ClipboardItem`), pour un collage enrichi dans l'éditeur de Mailchimp ; repli : sélection du rendu
 * de l'aperçu et `execCommand('copy')`. « Copier le code HTML » copie le code source (`writeText`, repli
 * sur une zone de texte temporaire). Les codes viennent de zones de texte cachées, déjà échappées par le
 * serveur. Le cadre d'aperçu prend la hauteur de son contenu. Aucune dépendance.
 */
(function () {
	'use strict';

	var config = window.rsnVeilleAdmin && window.rsnVeilleAdmin.infolettre;
	var page = document.querySelector('.rsn-veille-infolettre');
	if (!config || !page) {
		return;
	}
	var message = page.querySelector('.rsn-veille-message');

	function annoncer(texte, erreur) {
		message.textContent = texte;
		message.classList.toggle('rsn-veille-message-erreur', !!erreur);
	}

	function lire(bloc, role) {
		var zone = bloc.querySelector('[data-role="' + role + '"]');
		return zone ? zone.value : '';
	}

	/** Copie un texte brut : API asynchrone, sinon zone de texte temporaire et execCommand. */
	function copierTexte(texte) {
		if (navigator.clipboard && navigator.clipboard.writeText) {
			return navigator.clipboard.writeText(texte).catch(function () {
				return repliTexte(texte);
			});
		}
		return repliTexte(texte);
	}

	function repliTexte(texte) {
		return new Promise(function (resolu, refuse) {
			var zone = document.createElement('textarea');
			zone.value = texte;
			zone.setAttribute('readonly', '');
			zone.style.position = 'fixed';
			zone.style.opacity = '0';
			document.body.appendChild(zone);
			zone.select();
			var ok = false;
			try {
				ok = document.execCommand('copy');
			} catch (e) {
				ok = false;
			}
			document.body.removeChild(zone);
			return ok ? resolu() : refuse(new Error('copie'));
		});
	}

	/** Copie enrichie : HTML et texte ensemble ; sinon sélection du rendu dans le cadre d'aperçu. */
	function copierRiche(bloc) {
		var html = lire(bloc, 'html');
		var texte = lire(bloc, 'texte');
		if (navigator.clipboard && navigator.clipboard.write && window.ClipboardItem) {
			var element = new window.ClipboardItem({
				'text/html': new Blob([html], { type: 'text/html' }),
				'text/plain': new Blob([texte], { type: 'text/plain' })
			});
			return navigator.clipboard.write([element]).catch(function () {
				return repliRiche(bloc);
			});
		}
		return repliRiche(bloc);
	}

	function repliRiche(bloc) {
		return new Promise(function (resolu, refuse) {
			var cadre = bloc.querySelector('iframe.rsn-veille-apercu');
			var doc = cadre && cadre.contentDocument;
			if (!doc || !doc.body || !cadre.contentWindow) {
				return refuse(new Error('apercu'));
			}
			var selection = cadre.contentWindow.getSelection();
			var plage = doc.createRange();
			plage.selectNodeContents(doc.body);
			selection.removeAllRanges();
			selection.addRange(plage);
			var ok = false;
			try {
				ok = doc.execCommand('copy');
			} catch (e) {
				ok = false;
			}
			selection.removeAllRanges();
			return ok ? resolu() : refuse(new Error('copie'));
		});
	}

	function ajusterCadre(cadre) {
		var doc = cadre.contentDocument;
		if (doc && doc.documentElement) {
			cadre.style.height = Math.max(120, doc.documentElement.scrollHeight + 4) + 'px';
		}
	}

	page.querySelectorAll('iframe.rsn-veille-apercu').forEach(function (cadre) {
		cadre.addEventListener('load', function () {
			ajusterCadre(cadre);
		});
		if (cadre.contentDocument && cadre.contentDocument.readyState === 'complete') {
			ajusterCadre(cadre);
		}
	});

	page.addEventListener('click', function (evenement) {
		var bouton = evenement.target.closest('.rsn-veille-copier');
		if (!bouton || bouton.disabled) {
			return;
		}
		var bloc = bouton.closest('[data-bloc]');
		var code = bouton.getAttribute('data-mode') === 'code';
		var copie = code ? copierTexte(lire(bloc, 'html')) : copierRiche(bloc);
		copie.then(function () {
			annoncer(code ? config.textes.code : config.textes.copie, false);
		}).catch(function () {
			annoncer(config.textes.erreur, true);
		});
	});

	// Vider les nouvelles gardées : confirmation avant l'envoi (le serveur vérifie jeton et droits).
	page.addEventListener('submit', function (evenement) {
		if (evenement.target.classList.contains('rsn-veille-vider') && !window.confirm(config.textes.confirmer_vider)) {
			evenement.preventDefault();
		}
	});
})();

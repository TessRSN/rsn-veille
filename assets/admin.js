/**
 * RSN Veille : boutons de la file d'approbation (Publier + infolettre, Publier seulement, Écarter, Retirer de la
 * page, Garder publiée) et choix de l'infolettre d'une fiche publiée (`rsn-veille-action-infolettre`).
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

	/** Applique aux nombres des onglets ce que l'action a changé (`variations` : -1 ou +1 par onglet). */
	function majCompteurs(variations) {
		Object.keys(variations || {}).forEach(function (cle) {
			var element = document.querySelector('[data-compteur="' + cle + '"]');
			var variation = parseInt(variations[cle], 10);
			if (!element || isNaN(variation)) {
				return;
			}
			var nombre = parseInt(element.textContent.replace(/[^0-9]/g, ''), 10);
			element.textContent = '(' + Math.max(0, (isNaN(nombre) ? 0 : nombre) + variation) + ')';
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

	/**
	 * La fiche reste dans l'onglet courant seulement si son statut y correspond (une fiche gardée sort de
	 * « À vérifier »). Dans « Archivées », une fiche écartée part ; une fiche publiée qu'on vient de garder
	 * (« Garder publiée ») y reste, puisqu'elle est toujours publiée et dépassée.
	 */
	function appliquer(ligne, statut) {
		var reste = onglet === 'archive' ? statut === 'publie' : statut === onglet;
		if (onglet === 'a_verifier' || !reste) {
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
		ligne.querySelectorAll('.rsn-veille-action, .rsn-veille-action-infolettre').forEach(function (bouton) {
			bouton.disabled = occupee;
		});
	}

	function envoyer(donnees) {
		return fetch(config.ajaxurl, { method: 'POST', credentials: 'same-origin', body: donnees })
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
				return json.data;
			});
	}

	/** Fiche publiée : la mettre dans l'infolettre du RSN ou l'en retirer. Le serveur rend la cellule à jour. */
	function reglerInfolettre(bouton) {
		var ligne = bouton.closest('tr[data-id]');
		var cellule = bouton.closest('.rsn-veille-infolettre-cellule');
		var donnees = new FormData();
		donnees.append('action', config.action_infolettre);
		donnees.append('nonce', config.nonce);
		donnees.append('id', ligne.getAttribute('data-id'));
		donnees.append('dans', bouton.getAttribute('data-dans'));
		occuper(ligne, true);
		envoyer(donnees)
			.then(function (data) {
				// HTML produit et échappé par le serveur (`PageFile::infolettre_html`).
				cellule.innerHTML = data.html;
				var erreur = ligne.querySelector('.rsn-veille-erreur');
				if (erreur) {
					erreur.parentNode.removeChild(erreur);
				}
				occuper(ligne, false);
				var suivant = cellule.querySelector('.rsn-veille-action-infolettre');
				if (suivant) {
					suivant.focus();
				}
			})
			.catch(function (erreur) {
				occuper(ligne, false);
				afficherErreur(ligne, erreur && erreur.serveur ? erreur.message : config.textes.erreur);
			});
	}

	tableau.addEventListener('click', function (evenement) {
		var choix = evenement.target.closest('.rsn-veille-action-infolettre');
		if (choix) {
			reglerInfolettre(choix);
			return;
		}
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
		// « Publier + infolettre » (1) ou « Publier seulement » (0) : le choix suit la publication.
		if (bouton.hasAttribute('data-infolettre')) {
			donnees.append('infolettre', bouton.getAttribute('data-infolettre'));
		}

		occuper(ligne, true);
		envoyer(donnees)
			.then(function (data) {
				majCompteurs(data.variations);
				appliquer(ligne, data.statut);
			})
			.catch(function (erreur) {
				occuper(ligne, false);
				// Message du serveur s'il y en a un ; sinon (réseau, réponse illisible) le message générique.
				afficherErreur(ligne, erreur && erreur.serveur ? erreur.message : config.textes.erreur);
			});
	});
})();

/**
 * RSN Veille : radar des nouvelles, en quatre onglets (À trier, Gardées pour l'infolettre, Archivées, Écartées).
 * Les boutons (`data-act` : garder, ignorer, retirer, retablir, conserver) envoient l'adresse de la nouvelle en fetch vers
 * admin-ajax.php ; le serveur reprend titre et résumé dans son cache. Au succès, la ligne quitte le tableau et
 * les nombres des onglets (`data-compteur`) suivent ; en cas d'échec, le message s'affiche dans la zone
 * `role="status"`. Aucune dépendance.
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

	/** Sans ligne, le tableau d'une section se cache et son message « aucune nouvelle » réapparaît. */
	function majVides() {
		page.querySelectorAll('[data-section]').forEach(function (section) {
			var reste = !!section.querySelector('tr[data-url]');
			var vide = section.querySelector('.rsn-veille-vide');
			var tableau = section.querySelector('.rsn-veille-radar-tableau');
			if (vide) {
				vide.hidden = reste;
			}
			if (tableau) {
				tableau.hidden = !reste;
			}
		});
	}

	function retirerLigne(ligne) {
		ligne.parentNode.removeChild(ligne);
		majVides();
	}

	page.addEventListener('click', function (evenement) {
		var bouton = evenement.target.closest('.rsn-veille-radar-action');
		if (!bouton) {
			return;
		}
		var acte = bouton.getAttribute('data-act');
		var ligne = bouton.closest('tr[data-url]');
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
				// Nombres des onglets : ceux du serveur (totaux, sans égard au filtre par dates).
				var compteurs = json.data.compteurs || {};
				Object.keys(compteurs).forEach(function (onglet) {
					var compteur = page.querySelector('[data-compteur="' + onglet + '"]');
					if (compteur) {
						compteur.textContent = '(' + compteurs[onglet] + ')';
					}
				});
				retirerLigne(ligne);
				// Le résumé du filtre par dates (« 3 nouvelles publiées du… ») ne vaut plus après une action.
				var periode = page.querySelector('[data-periode]');
				if (periode) {
					periode.hidden = true;
				}
				if (acte === 'retablir') {
					annoncer(json.data.message || config.textes.retablie, false);
					return;
				}
				if (acte === 'conserver') {
					annoncer(config.textes.conservee, false);
					return;
				}
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

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

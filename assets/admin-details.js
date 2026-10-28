/**
 * RSN Veille : bascule Événement / Appel de l'encadré « Détails de la fiche » (éditeur des fiches).
 * Montre les lignes du type coché (`data-pour`) et masque les autres ; les champs masqués restent envoyés.
 * Passage d'Événement à Appel : « Publié le » reprend la date de début et, si la date limite est vide et que
 * la fin diffère du début, la date limite reprend la fin. Passage inverse : la date de début reprend
 * « Publié le ». Aucune dépendance.
 */
(function () {
	'use strict';

	var boite = document.getElementById('rsn-veille-details');
	if (!boite) {
		return;
	}
	var radios = boite.querySelectorAll('input[name="rsn_veille_details[type]"]');
	var champ = function (nom) {
		return boite.querySelector('[name="rsn_veille_details[' + nom + ']"]');
	};
	var debut = champ('debut');
	var fin = champ('fin');
	var publie = champ('publie');
	var limite = champ('echeance');

	function typeCoche() {
		var coche = boite.querySelector('input[name="rsn_veille_details[type]"]:checked');
		return coche ? coche.value : 'evenement';
	}

	function montrer(type) {
		var lignes = boite.querySelectorAll('[data-pour]');
		for (var i = 0; i < lignes.length; i++) {
			lignes[i].hidden = lignes[i].getAttribute('data-pour') !== type;
		}
	}

	var precedent = typeCoche();

	function changer() {
		var type = typeCoche();
		if (precedent === 'evenement' && type === 'appel') {
			if (publie && debut && debut.value !== '') {
				publie.value = debut.value;
			}
			if (limite && fin && debut && limite.value === '' && fin.value !== '' && fin.value !== debut.value) {
				limite.value = fin.value;
			}
		} else if (precedent === 'appel' && type === 'evenement') {
			if (debut && publie && publie.value !== '') {
				debut.value = publie.value;
			}
		}
		precedent = type;
		montrer(type);
	}

	for (var i = 0; i < radios.length; i++) {
		radios[i].addEventListener('change', changer);
	}
	montrer(precedent);
})();

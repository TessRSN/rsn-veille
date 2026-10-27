# Maquettes

- `gabarit-v6.1.html` : maquette validée pour la version 0.1.0 (page Veille à onglets Agenda / Appels).
- `gabarit-v8.html` : maquette validée le 2026-10-27 pour la version 0.2.0 (page « Événements et appels » : bande « À la une du RSN », bande des dates limites, liste unifiée avec pastilles). Le bloc `__DATA__` attend un JSON `{aujourdhui: "AAAA-MM-JJ", genere_le, sources: [...], items: [...]}` où chaque `item` a la forme du JSON public `/wp-json/rsn/v1/veille` avec en plus `qui` (rsn | partenaires | ecosysteme), `etiquettes` (= `themes`) et `image` (= `image_url`, ou une URI `data:`). Le bandeau de titre y est reproduit pour le rendu : sur le site, il vient du gabarit du thème et le raccourci commence sous lui.
- `evenements-infolettres-2025-2026.json` : les 24 événements recopiés des infolettres, importables depuis Veille › Réglages.

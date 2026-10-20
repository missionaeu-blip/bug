# Modifications — carte de suivi client

La photo envoyée par le client est utilisée comme fond de carte **sans retouche** :
le fichier livré est une copie binaire identique de l'original. Aucune tuile OSM,
aucun cercle, trait de trajet ni repère de secours n'est dessiné dessus. Les seuls
éléments cartographiques superposés sont les marqueurs « Vous » et « Livreur ».

- La photo reste déplaçable et zoomable via Leaflet.
- Les positions GPS réelles sont converties en coordonnées pixels de la photo à
  partir des deux ancrages transmis : ouest `49.2903, -0.1381`, est
  `49.2905, -0.1299` (extrémités de la ligne bleue).
- Le point du livreur s'actualise avec le flux de suivi déjà présent dans le projet.
- La photo est locale, fonctionne hors connexion et n'expose pas la position client
  sur la carte publique.
- La carte d'administration explique désormais ce fonctionnement sans clé Google.

## Fichiers principaux modifiés

- `assets/map/plage-littoral-originale.jpg` — copie exacte de la photo envoyée.
- `assets/js/map.js` — projection GPS vers l'image et repères Leaflet.
- `assets/css/style.css` — proportions exactes de l'image et styles des repères.
- `assets/js/views/client.js`, `assets/js/views/admin.js` — suivi/légende et interface.
- `sw.js`, `assets/js/sw.js` — renouvellement du cache et mise en cache de la photo.
- `README.md`, `INSTALL-HOSTINGER.md`, `index.php`, `config.php` — documentation.

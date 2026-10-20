# PANNACOTTACLUB — version Hostinger (PHP + MySQL)

Site complet de livraison de glaces, boissons et encas **sur la plage de Cabourg**
(postes de secours 1 à 2), accessible par QR code.

- **Installation pas à pas : `INSTALL-HOSTINGER.md`** ← commencez par là.
- **Accueil** : présentation + **carte du livreur en direct** (sa position se déplace
  en temps réel pendant ses livraisons, arrondie à ~10 m, désactivable dans l'admin).
- **Carte client interactive** : la photo fournie pour la plage est affichée telle
  quelle, sans retouche ni fond de carte ajouté. Leaflet superpose uniquement les
  repères du client et du livreur ; le livreur se déplace en direct pendant la course.
- Parcours client : accueil → carte → panier → position GPS (automatique, affinée,
  jamais bloquante) → commande → suivi en direct.
- Espace livreur : commandes assignées, prise en charge, « À ENCAISSER », paiement reçu, livrée.
- Administration : catalogue (prix, descriptions, photos, disponibilité), commandes,
  livreurs, carte temps réel, réglages, panneaux QR à imprimer.
- **Aucun paiement en ligne, aucun compte client** : on règle au livreur.
- Charte bleu `#060CD4` et blanc, mobile d'abord, lisible en plein soleil.

Technique : HTML + CSS + JavaScript + PHP 8 + MySQL. Aucun Node.js.
Le temps réel se fait par interrogation courte de l'API (l'hébergement mutualisé
ne supporte pas les connexions permanentes), jamais de rechargement de page.

Vérifié de bout en bout dans un navigateur réel : parcours client complet, statuts
en direct, encaissement, tableau de bord, QR code, sessions persistantes.

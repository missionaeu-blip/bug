<?php
/* ==========================================================================
   PANNACOTTACLUB — configuration
   --------------------------------------------------------------------------
   ⚠️  LE SEUL FICHIER À MODIFIER POUR L'INSTALLATION.
       Remplissez les 4 valeurs de la base MySQL (hPanel Hostinger →
       Bases de données → MySQL, puis « Créer une base de données »).
   ========================================================================== */

return [

  // ------------------------------------------------------------------ base
  'db' => [
    'host'    => 'localhost',          // sur Hostinger : toujours localhost
    'port'    => 3306,
    'name'    => 'VOTRE_NOM_DE_BASE_MYSQL',   // ← nom de la base
    'user'    => 'VOTRE_UTILISATEUR_MYSQL',        // ← nom d'utilisateur
    'pass'    => 'VOTRE_MOT_DE_PASSE_MYSQL',        // ← mot de passe
    'charset' => 'utf8mb4',
  ],

  // ------------------------------------------------------- comptes de départ
  // Créés par install.php. CHANGEZ CES MOTS DE PASSE (Admin → Livreurs) après
  // la première connexion, ou modifiez-les ici avant l'installation.
  'seed' => [
    'admin'   => ['email' => 'admin@pannacottaclub.fr',  'password' => 'pannacotta', 'name' => 'Direction PannacottaClub'],
    'courier' => ['email' => 'livreur@pannacottaclub.fr', 'password' => 'cabourg',    'name' => 'Léo'],
  ],

  // ------------------------------------------------------------ identité
  'brand' => [
    'name'     => 'PANNACOTTACLUB',
    'tagline'  => 'Livré directement sur la plage.',
    'blue'     => '#060CD4',
    'city'     => 'Cabourg',
    'currency' => '€',
  ],

  // --------------------------------------------------- zone de livraison
  // V1 : plage de Cabourg, entre les postes de secours 1 et 2.
  // Modifiable ensuite depuis l'administration (onglet Réglages).
  'zone' => [
    'label'  => 'Plage de Cabourg — postes de secours 1 à 2',
    'lat'    => 49.29155,
    'lng'    => -0.12125,
    'radius' => 900,   // mètres
  ],

  // ------------------------------------------------------------ le plan
  // Carte dessinée par le site (aucun fond externe). Vos repères de vente :
  // postes de secours, points de rendez-vous, tente du club…
  // type : 'poste' (cabanon bleu) ou 'lieu' (simple point).
  'map' => [
    // Ancienne clé Google Maps, conservée pour compatibilité avec les
    // installations existantes. La carte actuelle utilise la photo locale fournie.
    'google_key' => '',
    'mer'       => 'nord',      // 'nord' : la mer en haut du plan ; 'sud' : en bas
    'largeur_m' => 1500,        // largeur couverte par le plan, en mètres
    'pois'      => [
      ['type' => 'poste', 'label' => 'Poste de secours 1', 'lat' => 49.29115, 'lng' => -0.12490],
      ['type' => 'poste', 'label' => 'Poste de secours 2', 'lat' => 49.29195, 'lng' => -0.11760],
      ['type' => 'lieu',  'label' => 'Promenade Marcel Proust', 'lat' => 49.29090, 'lng' => -0.12125],
    ],
  ],

  // ----------------------------------------------------------- exploitation
  // -------------------------------------------- carte du livreur en direct
  // true  = la page d'accueil montre le livreur sur la carte pendant ses livraisons
  // false = position privée (seuls le client qui attend et l'administration la voient)
  'live_map_public' => true,

  'defaults' => [
    'auto_assign'            => 1,   // 1 = la commande est confiée immédiatement au livreur en service
    'auto_assign_courier_id' => '',
    'require_in_zone'        => 1,   // 1 = refuse les commandes hors de la zone
    'accepting_orders'       => 1,   // 0 = service fermé (coupe-circuit)
    'avg_speed'              => 1.3, // m/s (vitesse moyenne du livreur à pied)
    'nearby_radius'          => 150, // m — « votre livreur approche »
    'arrived_radius'         => 45,  // m — « votre livreur est arrivé »
  ],

  // ------------------------------------------------------- sécurité / divers
  'session_days'  => 90,     // durée d'une session « rester connecté »
  'session_hours' => 12,     // durée si « rester connecté » est décoché
  'max_order_eur' => 300,    // panier maximum accepté
];

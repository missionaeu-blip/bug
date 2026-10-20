<?php
$titre = 'Sécurité';
$page  = 'securite';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/legal.php';
legal_document_debut($titre, 'Présentation générale des mesures et réflexes de sécurité de Missiona.', [
    'mesures' => 'Mesures',
    'incidents' => 'Incidents',
    'bonnes-pratiques' => 'Bonnes pratiques',
    'limites' => 'Limites et contact'
], 'securite');
// Contenu propre à cette page : il n’est pas mutualisé avec les autres documents.
legal_h2('mesures', '1. Mesures techniques et organisationnelles');
echo '<p>Missiona met en œuvre des mesures proportionnées à l’état du service, à la nature des données et aux risques identifiés. Elles comprennent notamment, selon les composants concernés : contrôle d’accès par rôle, mots de passe stockés sous forme de condensats, protection des formulaires contre les requêtes falsifiées, requêtes préparées, cloisonnement logique des accès, journalisation limitée, sauvegardes et hébergement configuré pour un accès HTTPS. Les paiements par carte sont traités par Stripe ; Missiona n’a pas vocation à stocker les numéros complets de carte.</p>';
legal_fin_section();
legal_h2('incidents', '2. Incidents et violations de données');
echo '<p>En cas de suspicion d’incident, Missiona analyse la situation, limite les accès ou traitements nécessaires, conserve les éléments utiles de manière proportionnée et documente les mesures prises. Lorsqu’une violation de données personnelles est susceptible d’engendrer un risque, l’éditeur apprécie ses obligations de notification à la CNIL et, lorsque requis, d’information des personnes concernées. Les engagements et délais contractuels particuliers doivent être formalisés séparément.</p>';
legal_fin_section();
legal_h2('bonnes-pratiques', '3. Bonnes pratiques utilisateurs');
echo '<ul><li>Utilisez un mot de passe long, unique et ne le partagez jamais.</li><li>Vérifiez les destinataires, liens et pièces jointes avant de transmettre un contenu.</li><li>Ne déposez que les informations nécessaires à une mission et évitez les données sensibles.</li><li>Déconnectez-vous des appareils partagés et prévenez Missiona sans délai en cas de compte compromis.</li></ul>';
legal_fin_section();
legal_h2('limites', '4. Limites, bêta et contact sécurité');
echo '<p>Aucune mesure ne permet de supprimer tout risque. Pendant la bêta, les mesures, fournisseurs et procédures peuvent évoluer. Pour signaler une vulnérabilité ou un incident présumé, utilisez le contact sécurité : ' . e(legal_placeholder()) . '. Ne transmettez pas de secret, d’exploit actif ou de données personnelles inutiles dans un premier message ; demandez un canal sécurisé si nécessaire.</p>';
legal_fin_section();
legal_document_fin();
require_once __DIR__ . '/includes/footer.php';

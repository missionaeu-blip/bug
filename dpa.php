<?php
$titre = 'Accord de sous-traitance (DPA)';
$page  = 'dpa';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/legal.php';
legal_document_debut($titre, 'Modèle B2B de traitement de données pour le compte d’un client, à compléter et à faire valider.', [
    'cadre' => 'Cadre',
    'annexe' => 'Traitement',
    'instructions' => 'Instructions et sécurité',
    'sous-traitants' => 'Sous-traitants',
    'assistance' => 'Assistance',
    'fin' => 'Fin du traitement'
], 'dpa');
// Contenu propre à cette page : il n’est pas mutualisé avec les autres documents.
legal_h2('cadre', '1. Objet et rôle des parties');
echo '<p>Le présent accord de sous-traitance (« DPA ») est un modèle à compléter pour les situations où Missiona traite des données personnelles pour le compte d’un client agissant comme responsable de traitement. Il a vocation à compléter le contrat principal conformément à l’article 28 du RGPD. Les parties, catégories de données, finalités, durée et instructions doivent être renseignées dans l’annexe ou le bon de commande applicable.</p>';
legal_fin_section();
legal_h2('annexe', '2. Description du traitement à compléter');
echo '<dl class="legal-definition"><dt>Responsable de traitement</dt><dd>' . e(legal_placeholder()) . '</dd><dt>Sous-traitant</dt><dd>Missiona — identité juridique : ' . e(legal_placeholder()) . '</dd><dt>Objet et durée</dt><dd>Fourniture de la plateforme et des prestations convenues : ' . e(legal_placeholder()) . '</dd><dt>Nature et finalité</dt><dd>Hébergement, accès, mise en relation, pilotage et support : ' . e(legal_placeholder()) . '</dd><dt>Personnes concernées</dt><dd>Utilisateurs, salariés ou contacts professionnels du client, experts et autres personnes listées : ' . e(legal_placeholder()) . '</dd><dt>Données concernées</dt><dd>Identité, coordonnées professionnelles, données de compte, projets, messages, fichiers et données techniques nécessaires : ' . e(legal_placeholder()) . '</dd></dl>';
legal_fin_section();
legal_h2('instructions', '3. Instructions, confidentialité et sécurité');
echo '<p>Missiona traite les données uniquement sur instruction documentée du responsable de traitement, sauf obligation légale contraire. Elle veille à ce que les personnes autorisées à traiter les données soient soumises à une obligation de confidentialité. Elle met en œuvre des mesures techniques et organisationnelles adaptées au risque, décrites de manière générale sur la page ' . legal_lien('/securite', 'Sécurité') . ', sans divulguer d’informations susceptibles de compromettre la sécurité.</p>';
legal_fin_section();
legal_h2('sous-traitants', '4. Sous-traitants ultérieurs et transferts');
echo '<p>Le responsable autorise, sous réserve de validation contractuelle, les sous-traitants listés et actualisés : Hostinger pour l’hébergement, Stripe pour les paiements lorsque applicable, ainsi que les prestataires techniques explicitement communiqués. Missiona informe le responsable de tout ajout ou remplacement matériel selon le mécanisme à compléter : ' . e(legal_placeholder()) . '. Tout transfert hors EEE est encadré par un mécanisme légal approprié.</p>';
legal_fin_section();
legal_h2('assistance', '5. Assistance, violations et droits des personnes');
echo '<p>Dans la limite des informations disponibles et de la nature du traitement, Missiona assiste le responsable pour répondre aux demandes de droits, réaliser une analyse d’impact, consulter une autorité de contrôle si nécessaire et gérer les violations de données. En cas de violation dont elle a connaissance et qui concerne les données traitées pour le compte du responsable, Missiona le notifie sans délai injustifié avec les informations raisonnablement disponibles.</p>';
legal_fin_section();
legal_h2('fin', '6. Sort des données, audit et contact');
echo '<p>À la fin de la prestation, Missiona supprime ou restitue les données selon l’instruction du responsable, sauf obligation légale de conservation ; les modalités et délais doivent être complétés : ' . e(legal_placeholder()) . '. Elle met à disposition les informations nécessaires pour démontrer le respect de ses obligations et coopère aux audits raisonnables, encadrés pour préserver la sécurité et la confidentialité. Contact : ' . legal_email_link() . '.</p>';
legal_fin_section();
legal_document_fin();
require_once __DIR__ . '/includes/footer.php';

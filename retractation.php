<?php
$titre = 'Rétractation et professionnels';
$page  = 'retractation';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/legal.php';
legal_document_debut($titre, 'Information sur le cadre B2B et les situations particulières de rétractation.', [
    'b2b' => 'Cadre B2B',
    'exceptions' => 'Cas particuliers',
    'formulaire' => 'Formulaire'
], 'retractation');
// Contenu propre à cette page : il n’est pas mutualisé avec les autres documents.
legal_h2('b2b', '1. Cadre B2B');
echo '<p>Missiona est conçu pour les professionnels. Lorsqu’un client agit pour les besoins de son activité professionnelle, le droit de rétractation prévu pour les consommateurs n’a en principe pas vocation à s’appliquer. Des règles particulières peuvent toutefois s’appliquer selon la qualité des parties, l’objet du contrat, le démarchage ou les dispositions impératives. Cette page est informative et doit être validée par un juriste avant ouverture commerciale.</p>';
legal_fin_section();
legal_h2('exceptions', '2. Cas particuliers et exécution anticipée');
echo '<p>Si une relation avec un consommateur devait exceptionnellement être envisagée, les règles de rétractation, les exceptions, les informations précontractuelles et le recueil d’une demande expresse d’exécution anticipée devraient être analysés avant toute commande. Aucune exception ne doit être invoquée sans validation juridique adaptée au service effectivement vendu.</p>';
legal_fin_section();
legal_h2('formulaire', '3. Formulaire type si applicable');
echo '<p>Si le droit de rétractation s’applique, le professionnel devra communiquer un formulaire conforme et des modalités de réception effectives. Modèle à compléter : « À l’attention de ' . e(legal_placeholder()) . ' — Je/notifie nous vous notifie/notifions ma/notre rétractation du contrat portant sur : ' . e(legal_placeholder()) . ' ; commandé le : ' . e(legal_placeholder()) . ' ; nom : ' . e(legal_placeholder()) . ' ; adresse : ' . e(legal_placeholder()) . ' ; date et signature si envoi papier. »</p>';
echo '<p>Pour les prestations B2B de Missiona, consultez les ' . legal_lien('/cgv', 'CGV') . ' et contactez ' . legal_email_link() . ' en cas de question sur votre situation.</p>';
legal_fin_section();
legal_document_fin();
require_once __DIR__ . '/includes/footer.php';

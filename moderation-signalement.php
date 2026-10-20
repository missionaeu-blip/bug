<?php
$titre = 'Modération et signalement';
$page  = 'moderation-signalement';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/legal.php';
legal_document_debut($titre, 'Règles de contenu, procédure de signalement et voies de recours.', [
    'regles' => 'Règles',
    'signaler' => 'Signaler',
    'traitement' => 'Traitement',
    'recours' => 'Recours'
], 'moderation-signalement');
// Contenu propre à cette page : il n’est pas mutualisé avec les autres documents.
legal_h2('regles', '1. Contenus et comportements interdits');
echo '<p>Il est interdit de publier ou transmettre tout contenu illicite, frauduleux, menaçant, haineux, discriminatoire, diffamatoire, trompeur, portant atteinte aux droits de propriété intellectuelle, aux données personnelles, à la confidentialité, à la sécurité ou au bon fonctionnement de Missiona. Sont également prohibés les faux profils, le spam, l’usurpation, le harcèlement, la diffusion de logiciels malveillants et tout contournement des mesures de sécurité.</p>';
legal_fin_section();
legal_h2('signaler', '2. Comment signaler');
echo '<p>Adressez un signalement à ' . e(legal_placeholder()) . ' ou au contact général ' . legal_email_link() . '. Indiquez, dans la mesure du possible : l’URL ou la référence de la mission, le compte ou contenu concerné, une description factuelle, le motif du signalement, les éléments justificatifs pertinents et un moyen de réponse. N’envoyez pas de données sensibles non nécessaires.</p>';
legal_fin_section();
legal_h2('traitement', '3. Traitement et mesures possibles');
echo '<p>Missiona accuse réception lorsque cela est possible et vise un premier examen indicatif sous ' . e(legal_placeholder()) . '. Les délais dépendent de la gravité, de la complexité et des éléments fournis. Après analyse contradictoire lorsque la situation le permet, Missiona peut ne prendre aucune mesure, demander une correction, limiter la visibilité, retirer un contenu, adresser un avertissement, suspendre temporairement un compte ou le résilier. Les mesures urgentes de sécurité peuvent être prises sans préavis.</p>';
legal_fin_section();
legal_h2('recours', '4. Recours et articulation avec les CGU');
echo '<p>La personne concernée par une mesure peut demander une révision motivée au contact modération : ' . e(legal_placeholder()) . '. Cette procédure ne limite pas les droits de saisir les autorités ou juridictions compétentes. Elle complète les ' . legal_lien('/cgu', 'CGU') . ' et ne constitue pas une obligation générale de surveillance des contenus.</p>';
legal_fin_section();
legal_document_fin();
require_once __DIR__ . '/includes/footer.php';

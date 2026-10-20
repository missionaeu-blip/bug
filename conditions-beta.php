<?php
$titre = 'Conditions de la bêta';
$page  = 'conditions-beta';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/legal.php';
legal_document_debut($titre, 'Règles spécifiques au pré-lancement de Missiona avant l’ouverture commerciale.', [
    'statut' => 'Statut',
    'limites' => 'Limites',
    'responsabilite' => 'Transition'
], 'conditions-beta');
// Contenu propre à cette page : il n’est pas mutualisé avec les autres documents.
legal_h2('statut', '1. Statut bêta');
echo '<p>Missiona est en mode bêta. Le marquage bêta visible sur le site signifie que la plateforme est en phase de pré-lancement : les fonctionnalités, interfaces, règles opérationnelles, prestataires et documents peuvent évoluer. L’inscription est disponible ; elle ne doit pas être présentée comme défaillante du seul fait du statut bêta.</p>';
legal_fin_section();
legal_h2('limites', '2. Disponibilité, données et retours');
echo '<p>La disponibilité, les performances et l’intégrité de chaque fonctionnalité ne sont pas garanties pendant la bêta. Les utilisateurs doivent éviter de déposer des données sensibles, des secrets d’affaires irremplaçables ou des documents dont ils ne disposent pas d’une copie. Les retours, erreurs et suggestions peuvent être utilisés par Missiona pour améliorer le service, sans obligation de mise en œuvre ni rémunération, sous réserve des droits de l’utilisateur sur ses contenus.</p>';
legal_fin_section();
legal_h2('responsabilite', '3. Responsabilité et transition');
echo '<p>Missiona met en œuvre des efforts raisonnables pour sécuriser et améliorer le service, mais peut interrompre, limiter ou réinitialiser une fonctionnalité de bêta si nécessaire. Dans les limites du droit applicable, sa responsabilité reste limitée aux dommages directs prouvés qui lui sont imputables. L’ouverture commerciale est annoncée au 11 novembre 2026, sous réserve des validations requises. À cette occasion, les ' . legal_lien('/cgu', 'CGU') . ', les ' . legal_lien('/cgv', 'CGV') . ' et les informations légales pourront être mises à jour ; les utilisateurs seront informés des changements substantiels par un moyen approprié.</p>';
legal_fin_section();
legal_document_fin();
require_once __DIR__ . '/includes/footer.php';

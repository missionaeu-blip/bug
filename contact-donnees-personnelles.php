<?php
$titre = 'Contacts légal et données personnelles';
$page  = 'contact-donnees-personnelles';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/legal.php';
legal_document_debut($titre, 'Canaux de contact pour le support, le juridique, les données, la sécurité et la modération.', [
    'contacts' => 'Contacts',
    'consignes' => 'Consignes'
], 'contact-donnees-personnelles');
// Contenu propre à cette page : il n’est pas mutualisé avec les autres documents.
legal_h2('contacts', '1. Contacts par sujet');
echo '<div class="table-wrap"><table class="table legal-table"><thead><tr><th>Sujet</th><th>Canal</th><th>Délai indicatif à compléter</th></tr></thead><tbody>';
echo '<tr><td>Support général et projets</td><td>' . legal_email_link() . '</td><td>' . e(legal_placeholder()) . '</td></tr>';
echo '<tr><td>Questions juridiques et contractuelles</td><td>' . e(legal_placeholder()) . '</td><td>' . e(legal_placeholder()) . '</td></tr>';
echo '<tr><td>Données personnelles et exercice des droits</td><td>' . e(legal_placeholder()) . '</td><td>Délai légal applicable, en principe un mois.</td></tr>';
echo '<tr><td>Sécurité et vulnérabilités</td><td>' . e(legal_placeholder()) . '</td><td>' . e(legal_placeholder()) . '</td></tr>';
echo '<tr><td>Modération et signalements</td><td>' . e(legal_placeholder()) . '</td><td>' . e(legal_placeholder()) . '</td></tr>';
echo '</tbody></table></div>';
legal_fin_section();
legal_h2('consignes', '2. Informations à transmettre');
echo '<p>Indiquez le sujet, la page ou mission concernée, un moyen de réponse et les éléments strictement nécessaires à l’analyse. Ne transmettez pas de mot de passe, numéro complet de carte, secret d’API, pièce d’identité ou donnée sensible sauf demande justifiée via un canal sécurisé. Les signalements de contenu suivent aussi la ' . legal_lien('/moderation-signalement', 'procédure de modération') . '.</p>';
legal_fin_section();
legal_document_fin();
require_once __DIR__ . '/includes/footer.php';

<?php
$titre = 'Accessibilité';
$page  = 'accessibilite';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/legal.php';
legal_document_debut($titre, 'Engagement de progression, limites connues et contact accessibilité.', [
    'engagement' => 'Engagement',
    'mesures' => 'Mesures',
    'limites' => 'Limitations',
    'contact' => 'Contact'
], 'accessibilite');
// Contenu propre à cette page : il n’est pas mutualisé avec les autres documents.
legal_h2('engagement', '1. Engagement d’accessibilité');
echo '<p>Missiona cherche à rendre ses services compréhensibles et utilisables par le plus grand nombre, y compris avec une navigation au clavier, une technologie d’assistance, un zoom, une taille de texte augmentée ou un appareil mobile. Cette page présente un engagement de progression ; elle ne revendique pas, à ce stade, de conformité complète à un niveau RGAA ou WCAG non vérifié.</p>';
legal_fin_section();
legal_h2('mesures', '2. Mesures déjà intégrées');
echo '<ul><li>Structure de titres et libellés explicites sur les pages publiques ;</li><li>liens et boutons identifiables, styles de focus visibles et navigation clavier sur les composants principaux ;</li><li>contrastes travaillés sur les textes et actions principales ;</li><li>mise en page responsive et champs de formulaire associés à leurs libellés lorsque le composant le permet.</li></ul>';
legal_fin_section();
legal_h2('limites', '3. Limitations connues et plan d’amélioration');
echo '<p>La bêta peut comporter des limitations, notamment sur des écrans anciens, tableaux complexes, documents déposés par les utilisateurs, contenus tiers, traductions automatiques et parcours de paiement Stripe. Le plan d’amélioration comprend un audit des parcours critiques, des tests clavier et lecteur d’écran, la revue des contrastes et la correction progressive des écarts identifiés. Calendrier prévisionnel : ' . e(legal_placeholder()) . '.</p>';
legal_fin_section();
legal_h2('contact', '4. Signaler une difficulté');
echo '<p>Pour signaler un problème d’accessibilité ou demander une alternative raisonnable, contactez ' . e(legal_placeholder()) . ' en précisant la page, l’action souhaitée, votre environnement et, si vous le souhaitez, une solution de contournement. Une réponse indicative est visée sous ' . e(legal_placeholder()) . '.</p>';
legal_fin_section();
legal_document_fin();
require_once __DIR__ . '/includes/footer.php';

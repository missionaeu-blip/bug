<?php
$titre = 'Mentions légales';
$page  = 'mentions-legales';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/legal.php';
legal_document_debut($titre, 'Identité, hébergement, statut bêta et informations de publication de Missiona.', [
    'editeur' => 'Éditeur',
    'hebergement' => 'Hébergement',
    'statut' => 'Statut bêta',
    'propriete' => 'Propriété intellectuelle',
    'responsabilite' => 'Responsabilité',
    'liens' => 'Documents associés'
], 'mentions-legales');
// Contenu propre à cette page : il n’est pas mutualisé avec les autres documents.
$email = legal_email_link();
legal_h2('editeur', '1. Éditeur et directeur de la publication');
echo '<dl class="legal-definition"><dt>Dénomination / nom de l’éditeur</dt><dd>' . e(legal_placeholder()) . '</dd><dt>Forme juridique</dt><dd>' . e(legal_placeholder()) . '</dd><dt>Siège ou adresse</dt><dd>' . e(legal_placeholder()) . '</dd><dt>SIRET / SIREN</dt><dd>' . e(legal_placeholder()) . '</dd><dt>RCS</dt><dd>' . e(legal_placeholder()) . '</dd><dt>TVA intracommunautaire</dt><dd>' . e(legal_placeholder()) . '</dd><dt>Capital social</dt><dd>' . e(legal_placeholder()) . '</dd><dt>Directeur ou directrice de la publication</dt><dd>' . e(legal_placeholder()) . '</dd><dt>Contact général</dt><dd>' . $email . '</dd><dt>Téléphone</dt><dd>' . e(legal_placeholder()) . '</dd></dl>';
echo '<p>Au jour de la présente version, la structure juridique de l’éditeur est en cours de constitution. Aucune donnée d’immatriculation ne doit être considérée comme renseignée avant sa complétion explicite par l’éditeur.</p>';
legal_fin_section();
legal_h2('hebergement', '2. Hébergement et prestataires critiques');
echo '<p><strong>Hébergement :</strong> Hostinger — raison sociale, adresse postale et coordonnées contractuelles : ' . e(legal_placeholder()) . '. Hostinger intervient pour l’hébergement technique selon les conditions applicables au contrat souscrit par l’éditeur.</p>';
echo '<p><strong>Paiements :</strong> Stripe peut intervenir pour les paiements par carte, les comptes de paiement connectés et les contrôles associés. Les numéros complets de cartes et coordonnées bancaires ne sont pas destinés à être stockés par Missiona.</p>';
legal_fin_section();
legal_h2('statut', '3. Statut du site et ouverture commerciale');
echo '<p>Missiona est une plateforme B2B de mise en relation et de pilotage de projets digitaux. Le site est présenté en <strong>mode bêta</strong> : des fonctionnalités, documents et parcours peuvent évoluer. L’ouverture commerciale est annoncée au <strong>11 novembre 2026</strong>, sous réserve de la finalisation des prérequis juridiques, organisationnels et techniques.</p>';
legal_fin_section();
legal_h2('propriete', '4. Propriété intellectuelle');
echo '<p>La structure du site, ses textes, éléments graphiques, marques, logos, logiciels et bases de données sont protégés par les droits applicables. Toute reproduction, extraction, adaptation ou exploitation non autorisée est interdite, sauf autorisation écrite préalable ou exception légale. Les contenus fournis par les utilisateurs restent régis par les droits de leurs titulaires et les ' . legal_lien('/cgu', 'CGU') . '.</p>';
legal_fin_section();
legal_h2('responsabilite', '5. Responsabilité et liens externes');
echo '<p>Les informations publiées sont fournies à titre informatif. Missiona peut modifier le site, le suspendre ou corriger une information, notamment pendant la bêta. Les liens vers des services tiers, dont Stripe, sont proposés pour la commodité de l’utilisateur ; leurs contenus et pratiques relèvent de leurs éditeurs respectifs.</p>';
legal_fin_section();
legal_h2('liens', '6. Documents associés');
echo '<p>Consultez également les ' . legal_lien('/cgu', 'Conditions générales d’utilisation') . ', les ' . legal_lien('/cgv', 'Conditions générales de vente') . ', la ' . legal_lien('/politique-confidentialite', 'Politique de confidentialité') . ' et la ' . legal_lien('/politique-cookies', 'Politique cookies') . '.</p>';
legal_fin_section();
legal_document_fin();
require_once __DIR__ . '/includes/footer.php';

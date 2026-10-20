<?php
$titre = 'Conditions générales de vente';
$page  = 'cgv';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/legal.php';
legal_document_debut($titre, 'Conditions applicables aux prestations B2B de Missiona et aux commissions éventuelles.', [
    'objet' => 'Objet',
    'compte' => 'Compte professionnel',
    'prestations' => 'Prestations',
    'prix' => 'Prix et facturation',
    'paiement' => 'Paiement Stripe',
    'obligations' => 'Obligations',
    'retractation' => 'Rétractation',
    'responsabilite' => 'Responsabilité',
    'final' => 'Dispositions finales'
], 'cgv');
// Contenu propre à cette page : il n’est pas mutualisé avec les autres documents.
legal_h2('objet', '1. Objet, clients concernés et bêta');
echo '<p>Les présentes conditions générales de vente (« CGV ») encadrent les prestations proposées par Missiona à des clients agissant à titre professionnel : publication et qualification de besoins, mise en relation, pilotage de projets, services associés et commissions éventuelles. Pendant la bêta, aucune prestation payante ne doit être considérée comme commandée sans confirmation expresse de Missiona et de la partie concernée.</p>';
legal_fin_section();
legal_h2('compte', '2. Compte professionnel et vérification');
echo '<p>Le client déclare agir pour les besoins de son activité professionnelle et fournit les informations nécessaires à son identification, à la facturation et, le cas échéant, aux vérifications raisonnables de conformité. Missiona peut refuser ou différer une activation lorsque les informations sont incomplètes, incohérentes ou exigées par la réglementation applicable.</p>';
legal_fin_section();
legal_h2('prestations', '3. Prestations, commande et exécution');
echo '<p>Les prestations peuvent inclure la publication d’une mission, sa qualification, la recherche ou présentation d’experts, le pilotage, la coordination et les parcours de paiement. Une commande ou un engagement devient effectif uniquement après validation des éléments essentiels : périmètre, prix, commission, taxes, parties concernées, échéances et modalités de paiement. Les livrables, délais et critères d’acceptation sont ceux convenus pour chaque projet.</p>';
legal_fin_section();
legal_h2('prix', '4. Prix, commission, taxes et facturation');
echo '<p>Les prix, commissions et taxes applicables sont indiqués avant tout engagement payant. Hors paiement carte, l’entreprise peut régler directement l’expert au montant convenu et la commission Missiona peut être facturée séparément à l’expert selon les conditions communiquées. Lorsqu’un paiement carte Stripe est proposé, le total affiché avant confirmation détaille la rémunération de l’expert et la commission Missiona. Les factures et justificatifs sont émis selon le rôle de chaque partie et les informations légales renseignées par l’éditeur.</p>';
legal_fin_section();
legal_h2('paiement', '5. Paiement via Stripe');
echo '<p>Les paiements par carte disponibles sur la plateforme sont traités par Stripe et, lorsque le flux le prévoit, via un compte Stripe connecté de l’expert. Missiona ne reçoit pas les numéros complets de carte. L’utilisateur doit respecter les conditions de Stripe et fournir des informations exactes. Un échec, une contestation, un remboursement ou une indisponibilité de Stripe peut entraîner la suspension ou l’adaptation du parcours de paiement concerné.</p>';
legal_fin_section();
legal_h2('obligations', '6. Obligations des parties');
echo '<p>Chaque partie coopère de bonne foi, fournit les informations et validations nécessaires, respecte les délais convenus et conserve les droits nécessaires sur les éléments transmis. Le client et l’expert restent responsables de leurs obligations fiscales, sociales, contractuelles et professionnelles respectives, sauf engagement écrit contraire de Missiona.</p>';
legal_fin_section();
legal_h2('retractation', '7. Rétractation, garanties et réclamations');
echo '<p>Les présentes CGV visent un cadre B2B. Le droit de rétractation des consommateurs n’a pas vocation à s’appliquer entre professionnels agissant pour les besoins de leur activité, sous réserve de règles impératives ou de situations particulières. La page ' . legal_lien('/retractation', 'Rétractation et professionnels') . ' explique ce cadre. Toute réclamation doit être adressée selon les coordonnées publiées sur la page ' . legal_lien('/contact-donnees-personnelles', 'Contacts légal et données') . '. Une médiation de la consommation n’est pas présentée comme applicable tant que le service reste strictement B2B ; ce point doit être validé par un juriste.</p>';
legal_fin_section();
legal_h2('responsabilite', '8. Responsabilité, suspension et force majeure');
echo '<p>Dans les limites du droit applicable, Missiona n’est responsable que des dommages directs prouvés résultant d’un manquement qui lui est imputable. Elle peut suspendre ou résilier un service en cas de non-paiement, fraude, risque de sécurité, contenu illicite ou violation grave des conditions, après information lorsque la situation le permet. Aucune partie ne répond d’un retard ou d’une inexécution résultant d’un cas de force majeure au sens du droit français.</p>';
legal_fin_section();
legal_h2('final', '9. Propriété intellectuelle, données et droit applicable');
echo '<p>Les droits sur les livrables et contenus sont définis par le contrat ou devis applicable ; à défaut, aucun transfert de droit ne se présume. Les données personnelles sont traitées selon la ' . legal_lien('/politique-confidentialite', 'Politique de confidentialité') . '. Sous réserve de dispositions impératives, les CGV sont soumises au droit français ; les litiges relèvent des juridictions compétentes selon les règles applicables.</p>';
legal_fin_section();
legal_document_fin();
require_once __DIR__ . '/includes/footer.php';

<?php
$titre = 'Politique de confidentialité';
$page  = 'politique-confidentialite';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/legal.php';
legal_document_debut($titre, 'Informations relatives aux traitements de données personnelles réalisés dans le cadre de Missiona.', [
    'responsable' => 'Responsable',
    'donnees' => 'Données et finalités',
    'destinataires' => 'Destinataires',
    'conservation' => 'Conservation',
    'droits' => 'Vos droits',
    'securite' => 'Sécurité et cookies'
], 'politique-confidentialite');
// Contenu propre à cette page : il n’est pas mutualisé avec les autres documents.
$email = legal_email_link();
legal_h2('responsable', '1. Responsable du traitement et contact');
echo '<p><strong>Responsable du traitement :</strong> ' . e(legal_placeholder()) . ', éditeur de Missiona. Les coordonnées d’identité, adresse et représentant légal doivent être complétées avant l’ouverture commerciale. <strong>Contact général :</strong> ' . $email . '. <strong>Contact données personnelles / DPO si désigné :</strong> ' . e(legal_placeholder()) . '.</p>';
legal_fin_section();
legal_h2('donnees', '2. Personnes, données, finalités et bases légales');
echo '<div class="table-wrap"><table class="table legal-table"><thead><tr><th>Personnes et données</th><th>Finalités</th><th>Base légale principale</th><th>Caractère</th></tr></thead><tbody>';
echo '<tr><td>Prospects et contacts : identité, coordonnées, demande, échanges.</td><td>Répondre, qualifier un besoin, préparer une relation B2B.</td><td>Mesures précontractuelles, intérêt légitime.</td><td>Nécessaire pour répondre à une demande ; sinon facultatif.</td></tr>';
echo '<tr><td>Utilisateurs, clients et experts : identité, coordonnées professionnelles, compte, authentification, rôle, profil, compétences, disponibilités.</td><td>Créer et sécuriser le compte, fournir le service, mise en relation et pilotage.</td><td>Exécution du contrat, intérêt légitime de sécurité.</td><td>Obligatoire pour les fonctions concernées.</td></tr>';
echo '<tr><td>Missions, messages, fichiers et évaluations : brief, livrables, échanges, historique de projet.</td><td>Exécuter, coordonner, justifier et sécuriser les projets.</td><td>Exécution du contrat, intérêt légitime de prévention des litiges.</td><td>Nécessaire au suivi d’une mission.</td></tr>';
echo '<tr><td>Paiement et facturation : montants, statuts, références, informations fiscales nécessaires.</td><td>Gérer les paiements, commissions, factures, comptabilité et obligations légales.</td><td>Contrat, obligation légale.</td><td>Obligatoire lorsqu’une prestation payante est concernée.</td></tr>';
echo '<tr><td>Données techniques : journaux de sécurité, navigateur, appareil, langue, données de session et statistiques internes agrégées.</td><td>Sécurité, prévention de fraude, diagnostic, audience interne exemptée lorsque ses conditions sont réunies.</td><td>Intérêt légitime, obligation de sécurité.</td><td>Nécessaire au fonctionnement ou facultatif selon le service.</td></tr>';
echo '</tbody></table></div>';
echo '<p>Missiona ne demande pas de données sensibles sauf nécessité légale clairement identifiée. Les utilisateurs ne doivent pas transmettre de données sensibles ou confidentielles non nécessaires dans les messages ou fichiers.</p>';
legal_fin_section();
legal_h2('destinataires', '3. Destinataires, sous-traitants et transferts');
echo '<p>Les destinataires internes sont les personnes autorisées de Missiona selon leur rôle. Les destinataires externes peuvent inclure les parties d’un projet dans la limite de leurs habilitations, Hostinger pour l’hébergement, Stripe pour les paiements et vérifications associées, ainsi que les prestataires e-mail ou techniques effectivement configurés par l’éditeur : ' . e(legal_placeholder()) . '.</p>';
echo '<p>Certains prestataires peuvent traiter des données hors de l’Espace économique européen. Avant tout transfert concerné, l’éditeur doit vérifier le mécanisme applicable (décision d’adéquation, clauses contractuelles types, mesures complémentaires ou autre garantie reconnue) et compléter cette politique. Les informations propres à Stripe sont également disponibles dans sa documentation et ses politiques applicables.</p>';
legal_fin_section();
legal_h2('conservation', '4. Durées de conservation');
echo '<div class="table-wrap"><table class="table legal-table"><thead><tr><th>Catégorie</th><th>Durée cible à confirmer</th></tr></thead><tbody>';
echo '<tr><td>Prospection et demandes sans relation contractuelle</td><td>3 ans à compter du dernier contact actif, sauf opposition ou obligation différente.</td></tr>';
echo '<tr><td>Compte et données de projet</td><td>Durée de la relation, puis archivage limité pendant la durée de prescription applicable.</td></tr>';
echo '<tr><td>Facturation, comptabilité et obligations fiscales</td><td>Durées légales applicables, à confirmer par le conseil comptable et juridique de l’éditeur.</td></tr>';
echo '<tr><td>Journaux de sécurité et accès</td><td>Durée proportionnée à la sécurité et aux investigations nécessaires : ' . e(legal_placeholder()) . '.</td></tr>';
echo '<tr><td>Audience interne agrégée</td><td>13 mois au maximum selon le paramétrage actuel, sous réserve du maintien des conditions d’exemption CNIL.</td></tr>';
echo '</tbody></table></div>';
legal_fin_section();
legal_h2('droits', '5. Vos droits et leur exercice');
echo '<p>Dans les conditions prévues par le RGPD, vous pouvez demander l’accès, la rectification, l’effacement, la limitation, l’opposition et la portabilité de vos données, retirer un consentement lorsqu’un traitement repose sur celui-ci, et définir des directives post-mortem selon le droit français. Écrivez à ' . $email . ' en précisant votre demande et, si nécessaire, les éléments permettant de vérifier votre identité. Missiona répond dans le délai légal applicable, en principe un mois, sous réserve des prolongations justifiées.</p>';
echo '<p>Vous pouvez introduire une réclamation auprès de la CNIL. La demande ne doit pas contenir de copie de pièce d’identité non sollicitée ; Missiona ne la demande qu’en cas de doute raisonnable sur l’identité du demandeur.</p>';
legal_fin_section();
legal_h2('securite', '6. Sécurité, cookies, mineurs et évolutions');
echo '<p>Les mesures de sécurité sont décrites de façon générale sur la page ' . legal_lien('/securite', 'Sécurité') . '. Les traceurs sont décrits sur la page ' . legal_lien('/politique-cookies', 'Politique cookies') . '. Le service est destiné aux professionnels et aux personnes majeures. La présente politique peut évoluer ; sa version et sa date de mise à jour sont publiées sur cette page.</p>';
legal_fin_section();
legal_document_fin();
require_once __DIR__ . '/includes/footer.php';

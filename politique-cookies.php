<?php
$titre = 'Politique cookies';
$page  = 'politique-cookies';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/legal.php';
legal_document_debut($titre, 'Informations sur les cookies et autres traceurs du site Missiona.', [
    'definition' => 'Définition',
    'traceurs' => 'Traceurs utilisés',
    'choix' => 'Vos choix',
    'navigateur' => 'Navigateur'
], 'politique-cookies');
// Contenu propre à cette page : il n’est pas mutualisé avec les autres documents.
legal_h2('definition', '1. Définition et responsable');
echo '<p>Un cookie est un petit fichier enregistré ou lu par un terminal lors de la consultation d’un service. D’autres technologies de stockage ou d’accès au terminal peuvent avoir un effet comparable. Le responsable est l’éditeur de Missiona : ' . e(legal_placeholder()) . '. La présente politique complète la ' . legal_lien('/politique-confidentialite', 'Politique de confidentialité') . '.</p>';
legal_fin_section();
legal_h2('traceurs', '2. Traceurs actuellement utilisés');
echo '<div class="table-wrap"><table class="table legal-table"><thead><tr><th>Catégorie</th><th>Nom / éditeur</th><th>Finalité</th><th>Durée et base</th></tr></thead><tbody>';
echo '<tr><td>Strictement nécessaire</td><td><code>sid</code> — Missiona / PHP</td><td>Maintien de session, sécurité, formulaires et authentification.</td><td>Session du navigateur. Exempté de consentement car nécessaire au service demandé.</td></tr>';
echo '<tr><td>Préférences de consentement</td><td>Stockage local <code>missiona_cookie_consent</code> — Missiona</td><td>Mémoriser le choix et la version de la politique ; aucune publicité ni profilage.</td><td>6 mois maximum, puis nouvelle demande. Nécessaire à la preuve et au respect du choix.</td></tr>';
echo '<tr><td>Mesure d’audience interne</td><td>Aucun cookie tiers ni publicité. Mesure côté serveur utilisant un identifiant quotidien pseudonymisé lorsque disponible, avec repli de session nécessaire.</td><td>Comprendre l’usage du site, diagnostiquer les parcours et améliorer le service sans profilage publicitaire.</td><td>Pas de stockage optionnel sur le terminal ; le maintien d’une éventuelle exemption de consentement au regard des critères CNIL doit être vérifié avant ouverture.</td></tr>';
echo '<tr><td>Fonctionnels optionnels / marketing</td><td>Aucun à la date de mise à jour.</td><td>Aucun cookie non essentiel ne doit être déposé avant un consentement valable.</td><td>Non applicable tant qu’aucun outil n’est activé.</td></tr>';
echo '</tbody></table></div>';
legal_fin_section();
legal_h2('choix', '3. Consentement, refus et personnalisation');
echo '<p>Le bandeau de consentement permet d’accepter, de refuser ou de personnaliser les catégories optionnelles avec un niveau de simplicité équivalent. Le refus n’empêche pas l’accès aux fonctions essentielles. À ce jour, Missiona n’active pas de cookie non essentiel avant consentement. Si un outil optionnel est ajouté, il devra être documenté ici et bloqué tant que le choix correspondant n’a pas été exprimé.</p>';
echo '<p>Vous pouvez modifier votre choix à tout moment avec le lien « Gérer les cookies » présent dans le pied de page. La preuve du choix est conservée sous une forme limitée, sans enregistrer de contenu de navigation.</p>';
legal_fin_section();
legal_h2('navigateur', '4. Gestion depuis le navigateur');
echo '<p>Vous pouvez aussi supprimer ou bloquer les cookies depuis les réglages de votre navigateur. Le blocage des cookies nécessaires peut empêcher la connexion, la sécurité des formulaires ou certaines fonctions du service. Les modalités varient selon le navigateur utilisé.</p>';
legal_fin_section();
legal_document_fin();
require_once __DIR__ . '/includes/footer.php';

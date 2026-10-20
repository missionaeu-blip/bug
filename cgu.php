<?php
$titre = 'Conditions générales d’utilisation';
$page  = 'cgu';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/legal.php';
legal_document_debut($titre, 'Règles d’accès et d’utilisation de la plateforme Missiona en mode bêta.', [
    'objet' => 'Objet',
    'beta' => 'Bêta',
    'compte' => 'Compte',
    'service' => 'Service',
    'obligations' => 'Règles',
    'contenus' => 'Contenus',
    'moderation' => 'Modération',
    'disponibilite' => 'Disponibilité',
    'responsabilite' => 'Responsabilité',
    'donnees' => 'Données et droit applicable'
], 'cgu');
// Contenu propre à cette page : il n’est pas mutualisé avec les autres documents.
legal_h2('objet', '1. Objet et champ d’application');
echo '<p>Les présentes conditions générales d’utilisation (« CGU ») encadrent l’accès au site Missiona et aux fonctionnalités proposées aux professionnels : création de compte, publication et qualification de missions, mise en relation, pilotage, messagerie, partage de documents, suivi de projet et, lorsque disponible, parcours de paiement. Elles s’appliquent en complément des ' . legal_lien('/cgv', 'CGV') . ' pour les prestations payantes.</p>';
legal_fin_section();
legal_h2('beta', '2. Accès au service en mode bêta');
echo '<p>Le service est en bêta jusqu’à l’ouverture commerciale annoncée le 11 novembre 2026. Certaines fonctionnalités peuvent être limitées, indisponibles, modifiées ou retirées. L’inscription via le site est accessible ; elle ne vaut pas promesse de disponibilité d’une prestation, d’un expert, d’un financement ou d’un résultat.</p>';
legal_fin_section();
legal_h2('compte', '3. Compte, informations et identifiants');
echo '<p>L’utilisateur fournit des informations exactes, à jour et professionnelles. Il protège ses identifiants, ne les partage pas et informe sans délai Missiona de tout accès non autorisé présumé. Toute action réalisée depuis un compte est réputée effectuée par son titulaire, sous réserve d’une fraude démontrée ou d’une défaillance imputable à Missiona.</p>';
legal_fin_section();
legal_h2('service', '4. Description du service');
echo '<p>Missiona peut proposer la publication de besoins, l’analyse d’un brief, la présentation d’experts, la coordination et le pilotage de projets, des échanges et documents liés aux missions, ainsi que des mécanismes de paiement et de commission. Le périmètre exact, les responsabilités de chaque partie, le prix et les échéances sont précisés avant l’exécution d’une mission lorsque cela est applicable.</p>';
legal_fin_section();
legal_h2('obligations', '5. Règles d’utilisation et comportements interdits');
echo '<p>L’utilisateur s’engage à utiliser le service de manière loyale, à respecter les lois applicables, les droits de tiers et la confidentialité des informations reçues. Sont notamment interdits : l’usurpation d’identité, le contournement des mesures de sécurité, l’envoi de contenus illicites, discriminatoires, menaçants ou diffamatoires, l’aspiration automatisée non autorisée, l’introduction de code malveillant, le détournement d’un contact ou d’un paiement en violation d’un engagement contractuel, et toute atteinte au bon fonctionnement du service.</p>';
legal_fin_section();
legal_h2('contenus', '6. Contenus publiés par les utilisateurs');
echo '<p>Chaque utilisateur demeure responsable des contenus, briefs, fichiers, messages, marques, données et informations qu’il transmet. Il garantit disposer des droits et autorisations nécessaires. Il concède à Missiona une licence non exclusive, limitée à la durée nécessaire, pour héberger, reproduire techniquement, afficher aux personnes autorisées et traiter ces contenus afin de fournir, sécuriser et administrer le service.</p>';
legal_fin_section();
legal_h2('moderation', '7. Signalement, modération, suspension et résiliation');
echo '<p>Les signalements sont traités conformément à la ' . legal_lien('/moderation-signalement', 'procédure de modération et de signalement') . '. En cas de risque, de violation des CGU, d’obligation légale ou de protection du service, Missiona peut demander une précision, restreindre un contenu, suspendre temporairement un compte ou y mettre fin, dans la mesure du possible après information de l’utilisateur. L’utilisateur peut demander des explications et exercer un recours selon la procédure publiée.</p>';
legal_fin_section();
legal_h2('disponibilite', '8. Disponibilité, maintenance et évolution');
echo '<p>Missiona met en œuvre des efforts raisonnables pour maintenir le service, sans garantir une disponibilité continue, particulièrement pendant la bêta. Des opérations de maintenance, de sécurité ou d’évolution peuvent entraîner une interruption temporaire. Les sauvegardes, délais de rétablissement et niveaux de service éventuellement contractualisés doivent faire l’objet d’un écrit spécifique.</p>';
legal_fin_section();
legal_h2('responsabilite', '9. Responsabilité');
echo '<p>Dans les limites autorisées par le droit applicable, Missiona répond des dommages directs prouvés résultant d’un manquement qui lui est imputable. Elle ne garantit ni l’obtention d’une mission, ni la qualité intrinsèque d’un livrable fourni par un tiers, ni la solvabilité d’un utilisateur, ni l’absence totale d’interruption. Aucune clause ne limite les droits auxquels il ne peut être légalement renoncé.</p>';
legal_fin_section();
legal_h2('donnees', '10. Données personnelles, modification et droit applicable');
echo '<p>Les traitements de données sont décrits dans la ' . legal_lien('/politique-confidentialite', 'Politique de confidentialité') . '. Missiona peut modifier les CGU pour tenir compte d’une évolution du service, de la loi ou de la sécurité ; une version à jour est publiée et, lorsqu’un changement est substantiel, une information appropriée est fournie. Sous réserve des règles impératives, le droit français est applicable et les juridictions compétentes sont celles déterminées par les règles de procédure applicables.</p>';
legal_fin_section();
legal_document_fin();
require_once __DIR__ . '/includes/footer.php';

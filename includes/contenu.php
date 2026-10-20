<?php
/**
 * Blocs de contenu réutilisables : expertises et FAQ.
 * Centralisés ici pour rester cohérents entre les pages et le JSON-LD
 * (une FAQPage ne doit jamais déclarer une question absente de la page).
 */

/** Domaines réellement couverts par la plateforme. */
function wc_expertises() {
    return [
        [
            'slug'  => 'developpement-web',
            'titre' => 'Développement web',
            'desc'  => "Sites vitrines, plateformes métier, e-commerce, refontes et reprises de code existant. Intégration aux outils déjà en place (CRM, ERP, facturation).",
            'items' => ['Site vitrine et institutionnel', 'Application web métier', 'Boutique en ligne', 'Refonte et migration', 'Reprise de code non documenté'],
        ],
        [
            'slug'  => 'developpement-mobile',
            'titre' => 'Développement mobile',
            'desc'  => "Applications iOS et Android, natives ou multiplateformes, de la maquette à la publication sur les stores.",
            'items' => ['Application iOS et Android', 'Version mobile d\'un service existant', 'Publication et suivi des stores'],
        ],
        [
            'slug'  => 'design-ui-ux',
            'titre' => 'Design UI / UX',
            'desc'  => "Maquettes, design system, identité visuelle et parcours utilisateur. Le design est livré prêt à intégrer, pas seulement joli sur une présentation.",
            'items' => ['Maquettes et prototypes', 'Design system réutilisable', 'Identité visuelle', 'Audit d\'ergonomie'],
        ],
        [
            'slug'  => 'data-automatisation',
            'titre' => 'Data et automatisation',
            'desc'  => "Tableaux de bord, nettoyage et consolidation de données, automatisation des tâches répétitives entre vos outils.",
            'items' => ['Tableau de bord décisionnel', 'Consolidation de données', 'Automatisation de processus', 'Scripts d\'import et d\'export'],
        ],
        [
            'slug'  => 'marketing-digital',
            'titre' => 'Marketing digital',
            'desc'  => "Référencement naturel, contenu, campagnes d'acquisition et suivi de performance, avec des indicateurs définis avant le démarrage.",
            'items' => ['Référencement naturel', 'Rédaction et contenu', 'Campagnes d\'acquisition', 'Mesure et reporting'],
        ],
        [
            'slug'  => 'redaction-contenu',
            'titre' => 'Rédaction & contenu',
            'desc'  => "Contenus éditoriaux et documentation technique, construits pour être utiles à vos clients comme à vos équipes.",
            'items' => ['Contenu éditorial', 'Documentation technique', 'Rédaction web et SEO'],
        ],
        [
            'slug'  => 'traduction-langues',
            'titre' => 'Traduction & langues',
            'desc'  => "Traduction, localisation, relecture et adaptation de contenus par des spécialistes du domaine concerné.",
            'items' => ['Traduction générale et spécialisée', 'Localisation de sites et logiciels', 'Relecture et correction', 'Sous-titrage et transcription'],
        ],
        [
            'slug'  => 'video-son-photo',
            'titre' => 'Vidéo, son et photographie',
            'desc'  => "Films de présentation, motion design, voix off et photographies produit. Livrés aux formats attendus par chaque canal de diffusion.",
            'items' => ['Montage et motion design', 'Voix off et habillage sonore', 'Photographie produit', 'Formats courts pour les réseaux'],
        ],
        [
            'slug'  => 'finance-pilotage',
            'titre' => 'Finance et pilotage',
            'desc'  => "Prévisionnels, business plans, modélisation financière et préparation de levée de fonds, pour éclairer vos décisions et convaincre vos financeurs.",
            'items' => ['Prévisionnel et business plan', 'Modélisation financière', 'Analyse financière et contrôle de gestion', 'Préparation de levée de fonds'],
            // Entité HTML volontaire afin que ce rappel réglementaire s'affiche correctement.
            'note'  => 'Missiona ne réalise aucun travail réservé aux experts-compt&#97;bles : tenue de comptes, bilan, déclarations fiscales et paie relèvent d’un professionnel inscrit à l’Ordre.',
        ],
        [
            'slug'  => 'assistance-administration',
            'titre' => 'Assistance et relation client',
            'desc'  => "Assistanat à distance, traitement administratif et support à vos clients, en français comme en anglais.",
            'items' => ['Assistanat virtuel', 'Saisie et traitement de données', 'Support client et après-vente', 'Prise de rendez-vous'],
        ],
        [
            'slug'  => 'ingenierie-architecture',
            'titre' => 'Ingénierie et architecture',
            'desc'  => "Plans, modélisation et études techniques : bâtiment, mécanique, électronique et design industriel.",
            'items' => ['Plans et modélisation BIM', 'Conception mécanique et CAO', 'Design industriel et produit', 'Architecture d\'intérieur'],
        ],
        [
            'slug'  => 'conseil-formation',
            'titre' => 'Conseil, RH et formation',
            'desc'  => "Organisation, recrutement, montée en compétences des équipes et accompagnement des dirigeants.",
            'items' => ['Gestion de projet et organisation', 'Recrutement et sourcing', 'Formation et e-learning', 'Coaching professionnel'],
        ],
    ];
}

/**
 * FAQ du site. Sert à la fois à l'affichage et au JSON-LD FAQPage.
 * Réponses volontairement directes : la première phrase répond à la question,
 * ce qui facilite la reprise en extrait par les moteurs et les IA.
 */
function wc_faq() {
    // Valeurs réellement appliquées : la FAQ ne doit pas annoncer une
    // commission que la plateforme ne prélève plus.
    $com   = (int) round(taux_frais());
    $frais = frais_dossier();

    return [
        [
            'q' => "Comment fonctionne Missiona ?",
            'r' => "Vous décrivez votre projet en ligne, notre équipe l'analyse et revient vers vous sous 48 heures avec un expert sélectionné et une estimation de budget. Vous validez, nous pilotons la réalisation jusqu'à la livraison. Vous n'avez ni recherche ni gestion de prestataire à assurer.",
        ],
        [
            'q' => "Dois-je chercher moi-même un freelance ?",
            'r' => "Non. C'est la différence principale avec une place de marché classique : vous ne consultez aucun catalogue et ne triez aucun profil. Notre équipe sélectionne l'expert selon les compétences requises, sa disponibilité et son historique de missions sur la plateforme.",
        ],
        [
            'q' => "Combien coûte un projet ?",
            'r' => "Le prix dépend du périmètre et vous est communiqué avant tout engagement. Le devis de l'expert est le montant que vous payez : notre rémunération y est déjà incluse, et aucun supplément ne vous est facturé.",
        ],
        [
            'q' => "En combien de temps ai-je une réponse ?",
            'r' => "Sous 48 heures ouvrées après le dépôt du projet. Cette première réponse comprend une estimation de budget et de délai ainsi que le profil de l'expert pressenti. Le déposer ne vous engage à rien.",
        ],
        [
            'q' => "Qui est mon interlocuteur pendant le projet ?",
            'r' => "Un gestionnaire de projet unique vous est attribué dès votre inscription. Il centralise les échanges, suit l'avancement et vous alerte en cas de dérive. Vous ne gérez jamais plusieurs prestataires en parallèle.",
        ],
        [
            'q' => "À qui appartient le code ou les livrables produits ?",
            'r' => "Les livrables vous appartiennent intégralement une fois le projet réglé. La cession des droits est prévue au contrat : sources, fichiers de design et documentation vous sont transmis sans restriction d'usage.",
        ],
        [
            'q' => "Que se passe-t-il si le résultat ne convient pas ?",
            'r' => "Le projet est découpé en étapes validées au fur et à mesure, ce qui évite de découvrir un écart à la fin. En cas de désaccord persistant, vous pouvez demander l'annulation depuis votre espace : notre équipe traite la demande et procède au remboursement des sommes versées lorsqu'elle est acceptée.",
        ],
        [
            'q' => "Comment est calculé le score de matching ?",
            'r' => "Il repose sur des critères mesurables, non sur une appréciation subjective : correspondance des compétences demandées, adéquation entre le budget et le tarif de l'expert, disponibilité déclarée, années d'expérience et qualité des missions déjà réalisées. Les coefficients de pondération sont réglés par notre équipe et ajustés selon les retours terrain.",
        ],
        [
            'q' => "Puis-je contacter directement le freelance ?",
            'r' => "Par défaut, les échanges passent par votre gestionnaire de projet. Ce choix assure la traçabilité des décisions, évite les consignes contradictoires et protège les deux parties. Un contact direct reste possible ponctuellement, sur demande, quand le projet le justifie.",
        ],
        [
            'q' => "Puis-je choisir moi-même le freelance ?",
            'r' => "Nous vous présentons une recommandation argumentée plutôt qu'une liste à trier : c'est précisément la charge que nous vous évitons. Vous conservez un droit de regard et pouvez demander une autre proposition si le profil ne vous convient pas.",
        ],
        [
            'q' => "Mes informations sont-elles confidentielles ?",
            'r' => "Oui. Les entreprises et les freelances n'ont pas d'accès direct l'un à l'autre par défaut : les échanges passent par votre gestionnaire. Les données de votre projet ne sont visibles que par vous, l'expert affecté et l'équipe Missiona.",
        ],
        [
            'q' => "Comment les freelances sont-ils sélectionnés ?",
            'r' => "Chaque candidature est examinée par notre équipe sur des critères mesurables : compétences déclarées et vérifiées, expérience, tarifs et historique des missions réalisées sur la plateforme. Les experts qui ne tiennent pas leurs engagements ne se voient plus attribuer de mission.",
        ],
        [
            'q' => "Quand dois-je payer ?",
            'r' => "L'étude de votre besoin est gratuite. Vous réglez directement l'intervenant qui réalise la prestation, selon les modalités indiquées dans sa proposition. Missiona ne perçoit aucun paiement à votre place et n'émet aucune facture au nom des intervenants.",
        ],
    ];
}

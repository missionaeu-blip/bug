-- Missiona — audit non destructif des compétences retirées
-- Schéma actuel : la liste est stockée dans users.competences (CSV), pas dans
-- une table user_skills. Cette requête ne modifie aucune donnée.

WITH RECURSIVE
competences_retires(skill_name) AS (
    VALUES
        ('Comptabilité'),
        ('Tenue de livres'),
        ('Bilan'),
        ('Déclarations fiscales'),
        ('Paie'),
        ('Direction financière externalisée'),
        ('Rédaction de contrats'),
        ('Conditions générales'),
        ('Droit des sociétés'),
        ('Propriété intellectuelle'),
        ('Marques et brevets'),
        ('Conformité'),
        ('Protection des données'),
        ('Droit du travail'),
        ('Recherche juridique')
),
competences_utilisateurs(user_id, email, firstname, lastname, skill_name, reste) AS (
    SELECT id, email, prenom, nom, '', COALESCE(competences, '') || ','
      FROM users
    UNION ALL
    SELECT user_id,
           email,
           firstname,
           lastname,
           trim(substr(reste, 1, instr(reste, ',') - 1)),
           substr(reste, instr(reste, ',') + 1)
      FROM competences_utilisateurs
     WHERE reste <> ''
)
SELECT DISTINCT
       u.user_id,
       u.email,
       u.firstname,
       u.lastname,
       u.skill_name
  FROM competences_utilisateurs u
  JOIN competences_retires r ON r.skill_name = u.skill_name
 ORDER BY u.user_id ASC, u.skill_name ASC;

-- Migration de groupe : non applicable dans la version actuelle.
-- Missiona ne possède ni table user_skills ni colonne group_name ; les groupes
-- ne sont pas dénormalisés en base. Les profils existants restent intacts.

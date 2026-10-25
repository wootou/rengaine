-- Suppression de compte par son titulaire (CPT-09, DELETE /me) : tout ce qui appartient au compte
-- part avec lui (clés étrangères en cascade, déjà en place). Seules les corrections de la
-- bibliothèque et les lots proposés le retenaient (RESTRICT) : ils servent à tous et restent,
-- leur auteur devient NULL (« Compte supprimé » dans l'API). Relançable sans effet.

ALTER TABLE corrections DROP FOREIGN KEY IF EXISTS fk_corrections_creator;
ALTER TABLE corrections MODIFY created_by BIGINT UNSIGNED NULL COMMENT 'NULL = compte supprimé';
ALTER TABLE corrections ADD CONSTRAINT fk_corrections_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL;

ALTER TABLE change_sets DROP FOREIGN KEY IF EXISTS fk_change_sets_creator;
ALTER TABLE change_sets MODIFY created_by BIGINT UNSIGNED NULL COMMENT 'NULL = compte supprimé';
ALTER TABLE change_sets ADD CONSTRAINT fk_change_sets_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL;

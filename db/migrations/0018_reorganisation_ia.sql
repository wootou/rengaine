-- Réorganisation lancée depuis l'admin (IA-04) : nouvelle tâche `organize`, et compte rendu de la
-- tâche (texte du modèle, lots proposés). Relançable sans effet.

ALTER TABLE jobs MODIFY type ENUM('index','analyze','inbox','thumbnails','import','mail','dedup','purge','organize') NOT NULL;
ALTER TABLE jobs ADD COLUMN IF NOT EXISTS summary TEXT NULL COMMENT 'Compte rendu (IA-04)' AFTER stats;

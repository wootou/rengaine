-- MP3 en double (LEC-05) : nouvelle tâche `dedup`, qui déplace dans la corbeille du stockage les MP3
-- d'un album dont le fichier sans perte est là. Seule la liste des types de tâches change.
-- Relançable sans effet.

ALTER TABLE jobs MODIFY type ENUM('index','analyze','inbox','thumbnails','import','mail','dedup') NOT NULL;

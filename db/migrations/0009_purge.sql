-- Conservation des journaux (JRN-05) : nouvelle tâche `purge`, une fois par jour (IP au-delà de
-- conservation_ip_jours, vieux journaux au-delà de conservation_jours). Seule la liste des types change.
-- Relançable sans effet.

ALTER TABLE jobs MODIFY type ENUM('index','analyze','inbox','thumbnails','import','mail','dedup','purge') NOT NULL;

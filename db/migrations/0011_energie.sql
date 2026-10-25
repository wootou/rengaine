-- Énergie et dansabilité (AUD-06) : mesurées par Essentia avec le BPM et la tonalité (étape « rhythm »
-- de l'analyseur). Les pistes présentes repassent par cette étape ; rien d'autre ne change.
-- Relançable sans effet (une fois l'étape faite, la piste n'est plus en file pour elle).

INSERT INTO analysis_queue (track_id, steps)
    SELECT t.id, 'energy' FROM tracks t
     WHERE t.missing_since IS NULL
       AND NOT EXISTS (SELECT 1 FROM track_analysis ta WHERE ta.track_id = t.id AND ta.energy IS NOT NULL)
    ON DUPLICATE KEY UPDATE steps = steps | 16;

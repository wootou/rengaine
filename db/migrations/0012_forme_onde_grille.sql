-- Forme d'onde (AUD-07) et grilles des temps (LEC-06).
--   * track_analysis.waveform : un niveau RMS par tranche, un octet chacun (0 = -63,75 dBFS ou moins,
--     255 = 0 dBFS, pas de 0,25 dB), relevé par la mesure ffmpeg (étape loudness) ; waveform_step_ms :
--     durée d'une tranche.
--   * track_analysis.beat_grid : grilles des temps des 30 premières et des 30 dernières secondes,
--     {"intro": {"bpm", "offsetMs"} | null, "outro": …}, relevées par Essentia (étape bpm).
--   * Les pistes présentes repassent par ces deux étapes (mesure et Essentia).
-- Relançable sans effet (une fois les étapes faites, la piste n'est plus en file pour elles).

ALTER TABLE track_analysis
    ADD COLUMN IF NOT EXISTS waveform         VARBINARY(2000) NULL COMMENT 'AUD-07 : niveau RMS par tranche, un octet' AFTER danceability,
    ADD COLUMN IF NOT EXISTS waveform_step_ms DECIMAL(9,3)    NULL COMMENT 'Durée d’une tranche' AFTER waveform,
    ADD COLUMN IF NOT EXISTS beat_grid        JSON            NULL COMMENT 'LEC-06 : grilles des temps (début, fin)' AFTER waveform_step_ms;

INSERT INTO analysis_queue (track_id, steps)
    SELECT t.id, 'loudness,bpm' FROM tracks t
     WHERE t.missing_since IS NULL
       AND NOT EXISTS (SELECT 1 FROM track_analysis ta WHERE ta.track_id = t.id AND ta.waveform IS NOT NULL)
    ON DUPLICATE KEY UPDATE steps = steps | 5;

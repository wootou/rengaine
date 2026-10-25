-- Fichiers abîmés : trames que le décodeur n'a pas pu lire, relevées par la mesure ffmpeg (étape
-- loudness) sur le fichier mesuré (le meilleur de la piste, celui qui est servi). ffmpeg les saute à
-- la lecture, mais on entend un accroc ; à savoir avant la migration (choix de la copie à garder).
--   * decode_errors : nombre de trames illisibles (0 = sain ; NULL = pas encore vérifié) ;
--   * decode_detail : la première raison donnée par le décodeur (« flac : invalid subframe padding »).
-- Les pistes déjà mesurées repassent par la mesure. Relançable sans effet.

ALTER TABLE track_analysis
    ADD COLUMN IF NOT EXISTS decode_errors SMALLINT UNSIGNED NULL COMMENT 'Trames illisibles du fichier mesuré ; NULL = pas vérifié' AFTER audio_end_ms,
    ADD COLUMN IF NOT EXISTS decode_detail VARCHAR(255)      NULL COMMENT 'Première raison donnée par le décodeur' AFTER decode_errors,
    ADD KEY IF NOT EXISTS ix_track_analysis_decode_errors (decode_errors);

INSERT INTO analysis_queue (track_id, steps)
    SELECT ta.track_id, 'loudness' FROM track_analysis ta JOIN tracks t ON t.id = ta.track_id
     WHERE t.missing_since IS NULL AND ta.decode_errors IS NULL AND ta.loudness_lufs IS NOT NULL
    ON DUPLICATE KEY UPDATE steps = steps | 1;

-- Empreintes acoustiques (BAS-03) : le même enregistrement dans plusieurs fichiers (autre source,
-- autre extraction), quels que soient les tags.
--   * track_analysis.chromaprint : l'empreinte de fpcalc (120 premières secondes), 4 octets par
--     valeur en base64 (colonne prévue dès le schéma initial).
--   * track_fingerprint_keys : une valeur sur 8 de chaque empreinte (choisie par hachage), pour
--     repérer les pistes candidates sans tout comparer.
--   * same_recordings : paires de pistes reconnues comme le même enregistrement, avec la
--     ressemblance (0 à 1) et le décalage du début de b par rapport à a.
-- L'étape « fingerprint » est déjà dans la file des pistes (schéma initial). Relançable sans effet.

CREATE TABLE IF NOT EXISTS track_fingerprint_keys (
    fp_key    INT UNSIGNED    NOT NULL,
    track_id  BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (fp_key, track_id),
    KEY ix_fingerprint_keys_track (track_id),
    CONSTRAINT fk_fingerprint_keys_track FOREIGN KEY (track_id) REFERENCES tracks (id) ON DELETE CASCADE
) ENGINE=InnoDB COMMENT='BAS-03 : valeurs d’empreinte indexées pour le repérage';

CREATE TABLE IF NOT EXISTS same_recordings (
    track_a    BIGINT UNSIGNED NOT NULL COMMENT 'La plus petite des deux',
    track_b    BIGINT UNSIGNED NOT NULL,
    score      DECIMAL(4,3)    NOT NULL COMMENT 'Proportion de bits identiques au meilleur décalage',
    offset_ms  INT             NOT NULL COMMENT 'Début de b par rapport à a',
    found_at   DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (track_a, track_b),
    KEY ix_same_recordings_b (track_b),
    CONSTRAINT fk_same_recordings_a FOREIGN KEY (track_a) REFERENCES tracks (id) ON DELETE CASCADE,
    CONSTRAINT fk_same_recordings_b FOREIGN KEY (track_b) REFERENCES tracks (id) ON DELETE CASCADE,
    CONSTRAINT ck_same_recordings_order CHECK (track_a < track_b)
) ENGINE=InnoDB COMMENT='BAS-03 : même enregistrement dans deux pistes';

INSERT INTO analysis_queue (track_id, steps)
    SELECT t.id, 'fingerprint' FROM tracks t
     WHERE t.missing_since IS NULL
       AND NOT EXISTS (SELECT 1 FROM track_analysis ta WHERE ta.track_id = t.id AND ta.chromaprint IS NOT NULL)
    ON DUPLICATE KEY UPDATE steps = steps | 32;

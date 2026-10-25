-- Images par piste et par artiste (CAT-04).
--   * images.source « upload » : image envoyée depuis l'admin (photo d'artiste), gardée en base
--     dans image_blobs (sauvegardée avec la base, contrairement au cache).
--   * track_files.picture_sha256 : empreinte de la pochette intégrée au fichier (32 zéros = illisible).
--     Une piste n'a sa propre image que si sa pochette diffère de celle de la majorité de l'album.
--   * Les pistes déjà analysées dont un fichier a une pochette repassent par l'analyse (étape hash).
-- Relançable sans effet.

ALTER TABLE images MODIFY source ENUM('file','embedded','external','upload') NOT NULL;

CREATE TABLE IF NOT EXISTS image_blobs (
    image_id  BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    data      MEDIUMBLOB      NOT NULL,
    CONSTRAINT fk_image_blobs_image FOREIGN KEY (image_id) REFERENCES images (id) ON DELETE CASCADE
) ENGINE=InnoDB COMMENT='Octets des images envoyées (source upload)';

ALTER TABLE track_files ADD COLUMN IF NOT EXISTS picture_sha256 BINARY(32) NULL
    COMMENT 'Empreinte de la pochette intégrée (32 zéros : illisible) ; NULL = pas encore lue' AFTER has_picture;

INSERT INTO analysis_queue (track_id, steps)
    SELECT DISTINCT tf.track_id, 'hash' FROM track_files tf JOIN tracks t ON t.id = tf.track_id
     WHERE tf.has_picture AND tf.picture_sha256 IS NULL AND t.missing_since IS NULL
    ON DUPLICATE KEY UPDATE steps = steps | 64;

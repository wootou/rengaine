-- Indexeur (CAT-02) : besoins apparus en l'écrivant.
--   1. Un fichier audio lu par ffprobe est enregistré dans track_files avant d'être rattaché à une
--      piste (le regroupement en albums se fait ensuite, dossier par dossier).
--   2. Chaque objet du stockage retient le dossier de son album (sous-dossiers CD1/, flac/… rattachés
--      au parent), avec un index : reconstruire un album ne parcourt pas toute la table.
--   3. On note si le fichier audio contient une pochette, pour ne l'extraire que si besoin.

ALTER TABLE track_files
    MODIFY track_id BIGINT UNSIGNED NULL COMMENT 'NULL = fichier lu, pas encore rattaché à une piste',
    ADD COLUMN IF NOT EXISTS has_picture BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'Pochette intégrée' AFTER channels;

ALTER TABLE storage_objects
    ADD COLUMN IF NOT EXISTS album_dir VARCHAR(1024) NULL COLLATE utf8mb4_bin COMMENT 'Dossier de l’album (sans CD1/, flac/…)' AFTER kind,
    ADD COLUMN IF NOT EXISTS album_dir_hash BINARY(32) AS (UNHEX(SHA2(album_dir, 256))) STORED AFTER album_dir,
    ADD INDEX IF NOT EXISTS ix_storage_objects_album_dir (album_dir_hash);

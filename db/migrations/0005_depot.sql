-- Rangement du dépôt (CAT-05) : les albums déposés dans `inbox/` sont rangés sous un nom lisible
-- (`Artiste/Album (année)/01 - Titre.flac`) puis indexés. Cette table garde l'état de chaque dossier
-- déposé, pour l'admin (en attente, incomplet, bloqué et pourquoi, rangé) et pour ne signaler un
-- problème qu'une fois (pas un mail toutes les heures pour le même dossier).
-- Relançable sans effet.

CREATE TABLE IF NOT EXISTS inbox_items (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    source_dir     VARCHAR(1024)   NOT NULL COMMENT 'Dossier déposé (clé du stockage, sous inbox/)',
    source_hash    BINARY(32)      NOT NULL COMMENT 'SHA-256 de source_dir',
    status         ENUM('waiting','incomplete','blocked','filed') NOT NULL,
    file_count     INT UNSIGNED    NOT NULL DEFAULT 0,
    size_bytes     BIGINT UNSIGNED NOT NULL DEFAULT 0,
    signature      BINARY(32)          NULL COMMENT 'Empreinte de la liste des fichiers (clé, taille) : un changement relance l’examen',
    message        VARCHAR(1000)       NULL COMMENT 'Raison d’un blocage, avertissement d’un rangement',
    album_title    VARCHAR(255)        NULL,
    album_artist   VARCHAR(255)        NULL,
    target_dir     VARCHAR(1024)       NULL COMMENT 'Dossier de rangement (prévu ou fait)',
    first_seen_at  DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at     DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    filed_at       DATETIME(3)         NULL,
    UNIQUE KEY uq_inbox_items_source (source_hash),
    KEY ix_inbox_items_status (status, updated_at)
) ENGINE=InnoDB;

-- Images de fond du site, choisies par l'admin (Admin > Fonds). Le lecteur web en tire une au hasard
-- parmi les actives ; chaque compte peut les masquer (réglage interface.background).
-- Les images sont gardées en base : peu nombreuses (30 au plus), réduites par le navigateur avant
-- l'envoi (quelques centaines de Ko), et sauvegardées avec la base, contrairement à la musique.
-- Relançable sans effet.

CREATE TABLE IF NOT EXISTS backgrounds (
    id           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(100)     NOT NULL,
    mime_type    VARCHAR(20)      NOT NULL COMMENT 'image/jpeg, image/png ou image/webp',
    width        SMALLINT UNSIGNED NOT NULL,
    height       SMALLINT UNSIGNED NOT NULL,
    size_bytes   INT UNSIGNED     NOT NULL,
    sha256       BINARY(32)       NOT NULL,
    data         MEDIUMBLOB       NOT NULL,
    active       TINYINT(1)       NOT NULL DEFAULT 1,
    created_by   BIGINT UNSIGNED      NULL,
    created_at   DATETIME(3)      NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_backgrounds_sha (sha256),
    KEY ix_backgrounds_active (active),
    CONSTRAINT fk_backgrounds_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

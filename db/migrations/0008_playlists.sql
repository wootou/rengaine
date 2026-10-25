-- Playlists (FUT-01) : créées par chaque compte, privées par défaut ; « partagée » = visible et
-- écoutable par tous les comptes du serveur, mais seul son créateur la modifie (les autres la copient).
-- Une piste peut y figurer plusieurs fois : chaque entrée a son identifiant et sa position.
-- Relançable sans effet.

CREATE TABLE IF NOT EXISTS playlists (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id      BIGINT UNSIGNED NOT NULL COMMENT 'Créateur, seul à pouvoir la modifier',
    name         VARCHAR(200)    NOT NULL,
    description  VARCHAR(1000)       NULL,
    shared       BOOLEAN         NOT NULL DEFAULT FALSE COMMENT 'Visible par tous les comptes',
    created_at   DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at   DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    KEY ix_playlists_user (user_id, updated_at),
    KEY ix_playlists_shared (shared, updated_at),
    CONSTRAINT fk_playlists_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS playlist_items (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    playlist_id  BIGINT UNSIGNED NOT NULL,
    track_id     BIGINT UNSIGNED NOT NULL,
    position     INT UNSIGNED    NOT NULL COMMENT '0, 1, 2… sans trou ; renuméroté à chaque changement',
    added_at     DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    KEY ix_playlist_items_order (playlist_id, position),
    KEY ix_playlist_items_track (track_id),
    CONSTRAINT fk_playlist_items_playlist FOREIGN KEY (playlist_id) REFERENCES playlists (id) ON DELETE CASCADE,
    CONSTRAINT fk_playlist_items_track    FOREIGN KEY (track_id)    REFERENCES tracks (id)    ON DELETE CASCADE
) ENGINE=InnoDB;

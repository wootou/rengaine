-- Télécommande partagée (FUT-02) : un lien donné à un invité (sans compte) pour piloter la file
-- d'un compte, pendant une durée choisie, avec des droits choisis.
--   * queue_shares : les liens. Seule l'empreinte SHA-256 du secret est gardée, comme pour les
--     jetons d'appareil. Un lien expiré ou révoqué reste en base (journal), sans effet.
--   * player_states : dernier état connu du lecteur de chaque compte (piste, lecture ou pause,
--     appareil qui joue), envoyé par le lecteur tant qu'un lien est valable. Sert à l'invité et
--     à savoir à quel appareil relayer les commandes.
-- Nouvelles tables seulement : rien de l'existant ne change. Relançable sans effet.

CREATE TABLE IF NOT EXISTS queue_shares (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id       BIGINT UNSIGNED NOT NULL COMMENT 'Compte dont la file est pilotée',
    token_hash    BINARY(32)      NOT NULL COMMENT 'SHA-256 du secret du lien',
    token_prefix  CHAR(8)         NOT NULL,
    label         VARCHAR(100)    NOT NULL,
    can_add       TINYINT(1)      NOT NULL DEFAULT 1,
    can_edit      TINYINT(1)      NOT NULL DEFAULT 1,
    can_control   TINYINT(1)      NOT NULL DEFAULT 1,
    created_at    DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    created_by    BIGINT UNSIGNED     NULL COMMENT 'Jeton (appareil) qui a créé le lien',
    expires_at    DATETIME(3)     NOT NULL,
    revoked_at    DATETIME(3)         NULL,
    last_used_at  DATETIME(3)         NULL,
    UNIQUE KEY ux_queue_shares_token (token_hash),
    KEY ix_queue_shares_user (user_id, expires_at),
    CONSTRAINT fk_queue_shares_user  FOREIGN KEY (user_id)    REFERENCES users (id)         ON DELETE CASCADE,
    CONSTRAINT fk_queue_shares_token FOREIGN KEY (created_by) REFERENCES access_tokens (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS player_states (
    user_id        BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    device_key     VARCHAR(64)     NOT NULL COMMENT 'Lecteur (onglet) qui reçoit les commandes',
    track_id       BIGINT UNSIGNED     NULL,
    playing        TINYINT(1)      NOT NULL DEFAULT 0,
    position_ms    INT UNSIGNED    NOT NULL DEFAULT 0,
    queue_version  INT UNSIGNED    NOT NULL DEFAULT 0,
    reported_at    DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    CONSTRAINT fk_player_states_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

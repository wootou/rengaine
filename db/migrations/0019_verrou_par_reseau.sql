-- Verrouillage après des échecs de connexion (revue sécurité du 2026-10-24) : par couple (compte, réseau)
-- au lieu du compte entier, pour qu'un inconnu ne puisse pas bloquer le titulaire depuis ailleurs.
-- Réseau = IPv4, ou /64 en IPv6 (Format::network). Les colonnes users.failed_logins et
-- users.locked_until ne servent plus (gardées, sans effet). Relançable sans effet.

CREATE TABLE IF NOT EXISTS login_failures (
    user_id      BIGINT UNSIGNED NOT NULL,
    network      VARCHAR(45)     NOT NULL COMMENT 'IPv4, ou préfixe /64 en IPv6',
    failures     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME(3)         NULL,
    updated_at   DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (user_id, network),
    KEY ix_login_failures_updated (updated_at),
    CONSTRAINT fk_login_failures_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

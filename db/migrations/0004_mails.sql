-- Mails (CPT-06) : file d'envoi. Une requête web ne parle jamais au serveur SMTP pendant qu'elle
-- répond (une demande de compte ou « mot de passe oublié » doit répondre en un temps constant) :
-- le mail est mis en file, envoyé juste après la réponse, et repris chaque minute par
-- musique-jobs.timer s'il a échoué. Le texte est effacé une fois le mail parti : il peut contenir
-- un lien de réinitialisation du mot de passe. Relançable sans effet.

CREATE TABLE IF NOT EXISTS mail_queue (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    kind             VARCHAR(40)     NOT NULL COMMENT 'Ex. register_admin, account_approved, password_reset, job_errors, test',
    to_address       VARCHAR(254)    NOT NULL,
    subject          VARCHAR(255)    NOT NULL,
    body             MEDIUMTEXT          NULL COMMENT 'Texte du mail ; effacé une fois envoyé ou abandonné',
    user_id          BIGINT UNSIGNED     NULL COMMENT 'Compte concerné',
    status           ENUM('queued','sending','sent','failed') NOT NULL DEFAULT 'queued',
    attempts         TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_error       VARCHAR(500)        NULL,
    next_attempt_at  DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    claimed_at       DATETIME(3)         NULL COMMENT 'Pris par un envoi en cours ; repris s’il reste bloqué',
    created_at       DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    sent_at          DATETIME(3)         NULL,
    KEY ix_mail_queue_todo (status, next_attempt_at),
    KEY ix_mail_queue_kind (kind, created_at),
    CONSTRAINT fk_mail_queue_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

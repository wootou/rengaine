-- Lots de changements de l'agent, fusion d'artistes (API-09, IA-02) :
--   1. Une proposition peut rattacher une valeur de tag à un genre (genre_alias) : elle n'a pas
--      d'identifiant numérique, la valeur de tag est dans entity_key.
--   2. L'agent explique chaque changement proposé (reason), l'admin le lit avant d'appliquer.
--   3. Un artiste renommé ou fusionné est retrouvé par son ancien nom : index sur les corrections
--      d'artistes actives, lues à chaque piste par l'indexeur.
-- Rien n'est supprimé ; relançable sans effet.

ALTER TABLE corrections
    MODIFY entity_type ENUM('album','track','artist','genre_alias') NOT NULL,
    MODIFY entity_id BIGINT UNSIGNED NULL COMMENT 'NULL pour genre_alias (voir entity_key)',
    ADD COLUMN IF NOT EXISTS entity_key VARCHAR(100) NULL COMMENT 'Valeur de tag, pour genre_alias' AFTER entity_id,
    ADD COLUMN IF NOT EXISTS reason TEXT NULL COMMENT 'Explication de l’auteur (proposition de l’agent)' AFTER change_set_id,
    ADD COLUMN IF NOT EXISTS lookup_name VARCHAR(255) NULL COMMENT 'Ancien nom d’un artiste renommé ou fusionné (recherche par l’indexeur)' AFTER reason,
    ADD INDEX IF NOT EXISTS ix_corrections_lookup (entity_type, status, lookup_name);

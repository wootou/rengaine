-- Droits par fonction des jetons d'agent (IA-07) et comptes agent sans e-mail.
--   - access_tokens.capabilities : liste JSON des capacités accordées (voir server/src/Auth/Capabilities.php),
--     seulement pour les jetons d'agent ; relue à chaque requête.
--   - Les jetons d'agent déjà créés gardent ce qu'ils pouvaient faire en 1.0.3 : tout, sauf les pochettes en
--     ligne (nouvelles, désactivées jusqu'à ce que l'admin les coche).
--   - users.email devient facultatif : un compte agent n'a pas besoin d'adresse (la clé unique admet
--     plusieurs NULL). Les autres comptes en ont toujours une (vérifié par l'API).
-- Relançable sans effet.

ALTER TABLE access_tokens ADD COLUMN IF NOT EXISTS capabilities JSON NULL
    COMMENT 'Jeton d''agent : capacités accordées (IA-07) ; NULL pour les autres jetons'
    CHECK (capabilities IS NULL OR JSON_VALID(capabilities));

UPDATE access_tokens
   SET capabilities = '["library.read","library.analysis","admin.read","propose.tags","propose.structure","propose.cover.library"]'
 WHERE kind = 'agent' AND capabilities IS NULL;

ALTER TABLE users MODIFY email VARCHAR(254) NULL COMMENT 'NULL seulement pour un compte agent';

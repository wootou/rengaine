-- Volume du lecteur, donné avec son état (FUT-02) : la télécommande l'affiche et peut le régler.
--   * volume : pourcentage 0 à 100 (0 si le son est coupé) ; NULL = lecteur qui ne le donne pas.
-- Une colonne en plus seulement. Relançable sans effet.

ALTER TABLE player_states
    ADD COLUMN IF NOT EXISTS volume TINYINT UNSIGNED NULL COMMENT 'Volume en %, NULL = inconnu' AFTER position_ms;

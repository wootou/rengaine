-- =============================================================================
-- Rengaine — schéma MariaDB (CAD-01)
-- Cible : MariaDB 11.8, InnoDB, utf8mb4 / utf8mb4_uca1400_ai_ci.
--
-- Conventions (détail dans docs/CONCEPTION.md) :
--   - noms de tables et de colonnes en anglais, au pluriel pour les tables ;
--   - clés primaires `id` BIGINT UNSIGNED AUTO_INCREMENT ;
--   - toutes les dates en UTC, type DATETIME(3) : la connexion pose toujours
--     time_zone = '+00:00', donc CURRENT_TIMESTAMP(3) donne l'heure UTC ;
--   - textes affichés : collation par défaut, insensible à la casse et aux accents
--     (« beyonce » trouve « Beyoncé ») ; clés de stockage et empreintes : binaires ;
--   - durées en millisecondes, volumes en LUFS / dB ;
--   - jamais de secret en clair : jetons et codes sont stockés sous forme d'empreinte SHA-256.
--
-- Ce fichier crée la version 1 du schéma. Les évolutions suivantes passeront par
-- des migrations numérotées (db/migrations/NNNN_description.sql).
-- =============================================================================

SET NAMES utf8mb4 COLLATE utf8mb4_uca1400_ai_ci;
SET time_zone = '+00:00';

-- -----------------------------------------------------------------------------
-- Version du schéma
-- -----------------------------------------------------------------------------
CREATE TABLE schema_migrations (
    version     INT UNSIGNED NOT NULL PRIMARY KEY,
    applied_at  DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    description VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

-- =============================================================================
-- 1. COMPTES ET ACCÈS
-- =============================================================================

CREATE TABLE users (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email            VARCHAR(254)    NOT NULL,
    display_name     VARCHAR(100)    NOT NULL,
    password_hash    VARCHAR(255)        NULL COMMENT 'argon2id ; NULL pour un compte agent (jeton seulement)',
    role             ENUM('admin','user','agent') NOT NULL DEFAULT 'user',
    status           ENUM('pending','active','suspended','refused') NOT NULL DEFAULT 'pending',
    request_message  VARCHAR(1000)       NULL COMMENT 'Mot laissé lors de la demande de compte',
    request_ip       INET6               NULL COMMENT 'IP publique de la demande ; effacée après la décision',
    request_network  INET6               NULL COMMENT 'IPv4 : l’adresse ; IPv6 : le préfixe /64. Sert à limiter les demandes en attente par réseau',
    created_at       DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    decided_at       DATETIME(3)         NULL COMMENT 'Approbation ou refus',
    decided_by       BIGINT UNSIGNED     NULL,
    last_login_at    DATETIME(3)         NULL,
    failed_logins    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until     DATETIME(3)         NULL COMMENT 'Verrouillage temporaire après des échecs répétés',
    UNIQUE KEY uq_users_email (email),
    KEY ix_users_status (status),
    KEY ix_users_pending_network (request_network, status),
    CONSTRAINT fk_users_decided_by FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB COMMENT='Comptes. Une demande de compte est un utilisateur en statut pending.';

-- Sessions du site, jetons de l'appli et jetons d'agent : une seule table, un seul code de
-- vérification. Le secret n'est jamais stocké, seulement son empreinte SHA-256.
CREATE TABLE access_tokens (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id       BIGINT UNSIGNED NOT NULL,
    kind          ENUM('web','app','agent') NOT NULL COMMENT 'web = cookie de session ; app et agent = en-tête Bearer',
    token_hash    BINARY(32)      NOT NULL COMMENT 'SHA-256 du jeton',
    token_prefix  CHAR(8)         NOT NULL COMMENT 'Début du jeton, affiché pour le reconnaître',
    csrf_hash     BINARY(32)          NULL COMMENT 'Sessions web : empreinte du jeton anti-CSRF',
    name          VARCHAR(100)    NOT NULL COMMENT 'Ex. « Firefox sur PC », « Pixel 8 »',
    created_at    DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    last_used_at  DATETIME(3)         NULL,
    last_ip       INET6               NULL,
    user_agent    VARCHAR(255)        NULL,
    expires_at    DATETIME(3)         NULL COMMENT 'NULL = sans expiration (appli) ; les sessions web expirent',
    revoked_at    DATETIME(3)         NULL,
    revoked_by    BIGINT UNSIGNED     NULL,
    UNIQUE KEY uq_access_tokens_hash (token_hash),
    KEY ix_access_tokens_user (user_id, revoked_at),
    CONSTRAINT fk_access_tokens_user    FOREIGN KEY (user_id)    REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_access_tokens_revoker FOREIGN KEY (revoked_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Appairage de l'appli par QR (CPT-05). Le QR contient un code à usage unique valable quelques
-- minutes, jamais un jeton : l'appli l'échange contre son propre jeton.
CREATE TABLE pairing_codes (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id     BIGINT UNSIGNED NOT NULL,
    code_hash   BINARY(32)      NOT NULL,
    created_at  DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    expires_at  DATETIME(3)     NOT NULL,
    used_at     DATETIME(3)         NULL,
    token_id    BIGINT UNSIGNED     NULL COMMENT 'Jeton créé par l’échange',
    UNIQUE KEY uq_pairing_codes_hash (code_hash),
    CONSTRAINT fk_pairing_codes_user  FOREIGN KEY (user_id)  REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_pairing_codes_token FOREIGN KEY (token_id) REFERENCES access_tokens (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE password_resets (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id     BIGINT UNSIGNED NOT NULL,
    token_hash  BINARY(32)      NOT NULL,
    created_at  DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    expires_at  DATETIME(3)     NOT NULL,
    used_at     DATETIME(3)         NULL,
    UNIQUE KEY uq_password_resets_hash (token_hash),
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Réglages de l'utilisateur, partagés entre le site et l'appli (CPT-04). Le contenu suit le
-- schéma `UserSettings` de l'OpenAPI ; l'API le valide. `version` évite qu'un appareil écrase
-- en silence ce qu'un autre vient d'enregistrer (If-Match).
CREATE TABLE user_settings (
    user_id     BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    settings    JSON            NOT NULL CHECK (JSON_VALID(settings)),
    version     INT UNSIGNED    NOT NULL DEFAULT 1,
    updated_at  DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    CONSTRAINT fk_user_settings_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Réglages globaux du serveur, modifiables depuis l'admin (ex. inscriptions ouvertes).
-- Les secrets restent dans /etc/rengaine/rengaine.ini, jamais ici.
CREATE TABLE server_settings (
    name        VARCHAR(64)     NOT NULL PRIMARY KEY,
    value       JSON            NOT NULL CHECK (JSON_VALID(value)),
    updated_at  DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_by  BIGINT UNSIGNED     NULL,
    CONSTRAINT fk_server_settings_user FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =============================================================================
-- 2. STOCKAGE
-- Un objet = un fichier présent (ou disparu) dans le stockage, S3 ou disque.
-- =============================================================================

CREATE TABLE storage_objects (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    storage_key     VARCHAR(1024)   NOT NULL COLLATE utf8mb4_bin COMMENT 'Chemin relatif à la racine du stockage',
    key_hash        BINARY(32) AS (UNHEX(SHA2(storage_key, 256))) STORED COMMENT 'Index unique sur une clé trop longue pour un index direct',
    kind            ENUM('audio','image','other') NOT NULL,
    mime_type       VARCHAR(100)        NULL,
    size_bytes      BIGINT UNSIGNED NOT NULL,
    modified_at     DATETIME(3)         NULL COMMENT 'mtime (disque) ou Last-Modified (S3)',
    etag            VARCHAR(100)        NULL COMMENT 'ETag S3 ; NULL en mode disque',
    content_sha256  BINARY(32)          NULL COMMENT 'Calculé lors de l’analyse (lecture complète du fichier)',
    first_seen_at   DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    last_seen_at    DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT 'Dernier passage de l’indexeur qui l’a vu',
    missing_since   DATETIME(3)         NULL COMMENT 'Disparu du stockage ; la ligne est gardée pour les références',
    UNIQUE KEY uq_storage_objects_key (key_hash),
    KEY ix_storage_objects_sha (content_sha256),
    KEY ix_storage_objects_missing (missing_since)
) ENGINE=InnoDB;

-- =============================================================================
-- 3. CATALOGUE
-- Les colonnes des tables du catalogue contiennent les valeurs **effectives** : ce que
-- l'indexeur a lu, recouvert par les corrections actives (table `corrections`). L'indexeur
-- réapplique les corrections à chaque passage ; un scan n'écrase donc jamais une correction.
-- =============================================================================

CREATE TABLE images (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sha256        BINARY(32)      NOT NULL COMMENT 'Une seule ligne par image identique',
    source        ENUM('file','embedded','external') NOT NULL,
    object_id     BIGINT UNSIGNED     NULL COMMENT 'Fichier image dans le stockage, ou fichier audio qui la contient',
    mime_type     VARCHAR(50)     NOT NULL,
    width         SMALLINT UNSIGNED   NULL,
    height        SMALLINT UNSIGNED   NULL,
    size_bytes    INT UNSIGNED    NOT NULL,
    created_at    DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_images_sha (sha256),
    CONSTRAINT fk_images_object FOREIGN KEY (object_id) REFERENCES storage_objects (id) ON DELETE SET NULL
) ENGINE=InnoDB COMMENT='Les vignettes sont des fichiers de cache, nommés par empreinte et taille, pas des lignes.';

CREATE TABLE artists (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name             VARCHAR(255)    NOT NULL,
    sort_name        VARCHAR(255)        NULL COMMENT 'Ex. « Beatles, The » ; NULL = name',
    sort_key         VARCHAR(320)    NOT NULL DEFAULT '' COMMENT 'NATURAL_SORT_KEY(COALESCE(sort_name, name)), rempli par déclencheur',
    image_id         BIGINT UNSIGNED     NULL,
    musicbrainz_id   UUID                NULL,
    created_at       DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at       DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_artists_name (name) COMMENT 'Collation insensible : « Beyonce » et « Beyoncé » sont le même artiste',
    KEY ix_artists_sort (sort_key),
    CONSTRAINT fk_artists_image FOREIGN KEY (image_id) REFERENCES images (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE albums (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    title            VARCHAR(255)    NOT NULL,
    sort_key         VARCHAR(320)    NOT NULL DEFAULT '' COMMENT 'NATURAL_SORT_KEY(title), rempli par déclencheur',
    album_artist_id  BIGINT UNSIGNED     NULL COMMENT 'NULL = artistes divers (compilation)',
    year             SMALLINT UNSIGNED   NULL,
    is_compilation   BOOLEAN         NOT NULL DEFAULT FALSE,
    kind             ENUM('album','loose') NOT NULL DEFAULT 'album' COMMENT 'loose = dossier de pistes en vrac, sans album commun',
    source_dir       VARCHAR(1024)       NULL COLLATE utf8mb4_bin COMMENT 'Dossier de l’album dans le stockage (sans sous-dossiers CD1/, flac/) ; NULL = album créé dans l’admin (séparation)',
    dir_hash         BINARY(32) AS (UNHEX(SHA2(source_dir, 256))) STORED,
    cover_image_id   BIGINT UNSIGNED     NULL,
    -- Valeurs calculées par l'indexeur à partir des pistes, pour ne pas les recalculer à chaque page
    track_count      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    disc_count       TINYINT UNSIGNED  NOT NULL DEFAULT 1,
    duration_ms      BIGINT UNSIGNED   NOT NULL DEFAULT 0,
    has_lossless     BOOLEAN           NOT NULL DEFAULT FALSE,
    -- Volume de l'album (EBU R128), pour la normalisation en mode album
    loudness_lufs    DECIMAL(5,2)        NULL,
    true_peak_dbtp   DECIMAL(5,2)        NULL,
    musicbrainz_id   UUID                NULL,
    added_at         DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT 'Première indexation : sert aux « ajouts récents »',
    updated_at       DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    missing_since    DATETIME(3)         NULL COMMENT 'Tous les fichiers ont disparu ; masqué, mais favoris et historique gardés',
    UNIQUE KEY uq_albums_dir (dir_hash),
    KEY ix_albums_sort (sort_key),
    KEY ix_albums_added (added_at),
    KEY ix_albums_artist (album_artist_id),
    KEY ix_albums_year (year),
    FULLTEXT KEY ft_albums_title (title),
    CONSTRAINT fk_albums_artist FOREIGN KEY (album_artist_id) REFERENCES artists (id) ON DELETE SET NULL,
    CONSTRAINT fk_albums_cover  FOREIGN KEY (cover_image_id)  REFERENCES images (id)  ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE tracks (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    album_id        BIGINT UNSIGNED NOT NULL,
    disc_number     TINYINT UNSIGNED  NOT NULL DEFAULT 1,
    track_number    SMALLINT UNSIGNED     NULL,
    title           VARCHAR(255)    NOT NULL,
    artist_credit   VARCHAR(500)        NULL COMMENT 'Texte affiché, ex. « Gorillaz feat. Kali Uchis » ; NULL = artiste de l’album',
    duration_ms     INT UNSIGNED    NOT NULL DEFAULT 0,
    image_id        BIGINT UNSIGNED     NULL COMMENT 'Seulement si la piste a sa propre image, différente de l’album',
    added_at        DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at      DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    missing_since   DATETIME(3)         NULL,
    KEY ix_tracks_album (album_id, disc_number, track_number),
    KEY ix_tracks_added (added_at),
    FULLTEXT KEY ft_tracks_title (title, artist_credit),
    CONSTRAINT fk_tracks_album FOREIGN KEY (album_id) REFERENCES albums (id) ON DELETE CASCADE,
    CONSTRAINT fk_tracks_image FOREIGN KEY (image_id) REFERENCES images (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Artistes d'une piste (principal, invité « feat. », remixeur) : une piste en featuring
-- apparaît aussi sur la page de l'artiste invité.
CREATE TABLE track_artists (
    track_id    BIGINT UNSIGNED NOT NULL,
    artist_id   BIGINT UNSIGNED NOT NULL,
    role        ENUM('main','featured','remixer') NOT NULL DEFAULT 'main',
    position    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (track_id, artist_id, role),
    KEY ix_track_artists_artist (artist_id),
    CONSTRAINT fk_track_artists_track  FOREIGN KEY (track_id)  REFERENCES tracks (id)  ON DELETE CASCADE,
    CONSTRAINT fk_track_artists_artist FOREIGN KEY (artist_id) REFERENCES artists (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Une piste peut exister en plusieurs fichiers (MP3 et FLAC du même morceau). Le flux
-- « original » sert le meilleur : sans perte d'abord, puis le plus haut débit.
CREATE TABLE track_files (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    track_id        BIGINT UNSIGNED NOT NULL,
    object_id       BIGINT UNSIGNED NOT NULL,
    format          ENUM('mp3','flac','opus','vorbis','aac','alac','wav','other') NOT NULL,
    lossless        BOOLEAN         NOT NULL,
    bitrate_kbps    SMALLINT UNSIGNED   NULL,
    sample_rate_hz  MEDIUMINT UNSIGNED  NULL,
    bit_depth       TINYINT UNSIGNED    NULL,
    channels        TINYINT UNSIGNED    NULL,
    duration_ms     INT UNSIGNED    NOT NULL DEFAULT 0,
    tags_raw        JSON                NULL COMMENT 'Tags lus par ffprobe, tels quels' CHECK (tags_raw IS NULL OR JSON_VALID(tags_raw)),
    probed_at       DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_track_files_object (object_id),
    KEY ix_track_files_track (track_id),
    CONSTRAINT fk_track_files_track  FOREIGN KEY (track_id)  REFERENCES tracks (id)          ON DELETE CASCADE,
    CONSTRAINT fk_track_files_object FOREIGN KEY (object_id) REFERENCES storage_objects (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Analyse audio d'une piste (lot AUD), faite sur son meilleur fichier.
CREATE TABLE track_analysis (
    track_id          BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    file_id           BIGINT UNSIGNED     NULL COMMENT 'Fichier analysé',
    -- AUD-01 : volume perçu
    loudness_lufs     DECIMAL(5,2)        NULL COMMENT 'Volume intégré EBU R128',
    true_peak_dbtp    DECIMAL(5,2)        NULL,
    loudness_range_lu DECIMAL(5,2)        NULL,
    -- AUD-02 : silences (points de fondu)
    audio_start_ms    INT UNSIGNED        NULL COMMENT 'Fin du silence de début',
    audio_end_ms      INT UNSIGNED        NULL COMMENT 'Début du silence de fin',
    -- AUD-03 / AUD-04 : infos « DJ »
    bpm               DECIMAL(6,2)        NULL,
    bpm_source        ENUM('tag','analysis','manual') NULL,
    musical_key       VARCHAR(4)          NULL COMMENT 'Notation anglaise : C, Am, F#m…',
    camelot           VARCHAR(3)          NULL COMMENT 'Ex. 8A',
    key_source        ENUM('tag','analysis','manual') NULL,
    -- AUD-06 (P3)
    energy            DECIMAL(4,3)        NULL COMMENT '0 à 1',
    danceability      DECIMAL(4,3)        NULL COMMENT '0 à 1',
    -- BAS-03 : dédoublonnage
    chromaprint       MEDIUMTEXT          NULL COMMENT 'Empreinte acoustique (fpcalc)',
    analyzer_version  VARCHAR(50)         NULL COMMENT 'Pour relancer l’analyse si l’outil change',
    analyzed_at       DATETIME(3)         NULL,
    KEY ix_track_analysis_bpm (bpm),
    KEY ix_track_analysis_camelot (camelot),
    CONSTRAINT fk_track_analysis_track FOREIGN KEY (track_id) REFERENCES tracks (id)      ON DELETE CASCADE,
    CONSTRAINT fk_track_analysis_file  FOREIGN KEY (file_id)  REFERENCES track_files (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Genres : portés par l'album, comme aujourd'hui avec meta.json (CAT-07).
CREATE TABLE genres (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100)    NOT NULL,
    parent_id   BIGINT UNSIGNED     NULL COMMENT 'Hiérarchie facultative (Rock > Rock alternatif)',
    created_at  DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_genres_name (name),
    CONSTRAINT fk_genres_parent FOREIGN KEY (parent_id) REFERENCES genres (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Valeurs de genre lues dans les tags, et le genre auquel chacune correspond. Un tag ne crée
-- jamais de genre tout seul : une valeur égale (sans casse ni accents) au nom d'un genre est
-- rattachée d'office ; les autres restent sans genre (genre_id NULL) et apparaissent dans l'admin,
-- où l'admin ou l'agent les rattache (« Hip-Hop/Rap » → « Rap »).
CREATE TABLE genre_aliases (
    alias          VARCHAR(100)    NOT NULL PRIMARY KEY,
    genre_id       BIGINT UNSIGNED     NULL,
    track_count    INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'Pistes qui portent cette valeur, tenu par l’indexeur',
    first_seen_at  DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    KEY ix_genre_aliases_genre (genre_id),
    CONSTRAINT fk_genre_aliases_genre FOREIGN KEY (genre_id) REFERENCES genres (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Genres de l'album. Les genres choisis (import, manual, agent) priment ; à défaut, l'indexeur
-- prend les genres majoritaires des tags des pistes (source = tag).
CREATE TABLE album_genres (
    album_id    BIGINT UNSIGNED NOT NULL,
    genre_id    BIGINT UNSIGNED NOT NULL,
    source      ENUM('tag','import','manual','agent') NOT NULL COMMENT 'import = repris de meta.json / genres_manual.json',
    PRIMARY KEY (album_id, genre_id),
    KEY ix_album_genres_genre (genre_id),
    CONSTRAINT fk_album_genres_album FOREIGN KEY (album_id) REFERENCES albums (id) ON DELETE CASCADE,
    CONSTRAINT fk_album_genres_genre FOREIGN KEY (genre_id) REFERENCES genres (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Genres **effectifs** de chaque piste, tenus à jour pour que les filtres et l'aléatoire par genre
-- restent une simple jointure indexée :
--   - genres propres à la piste (manual, agent, ou tag pour une compilation) s'il y en a ;
--   - sinon, ceux de l'album, recopiés avec source = album.
-- Un seul code les recalcule (à chaque changement des genres de l'album ou de la piste).
CREATE TABLE track_genres (
    track_id    BIGINT UNSIGNED NOT NULL,
    genre_id    BIGINT UNSIGNED NOT NULL,
    source      ENUM('album','tag','manual','agent') NOT NULL,
    PRIMARY KEY (track_id, genre_id),
    KEY ix_track_genres_genre (genre_id, track_id),
    CONSTRAINT fk_track_genres_track FOREIGN KEY (track_id) REFERENCES tracks (id) ON DELETE CASCADE,
    CONSTRAINT fk_track_genres_genre FOREIGN KEY (genre_id) REFERENCES genres (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Organisation au-delà du genre (CAT-08, à définir avec Rémy) : catégories, ambiances,
-- collections. Structure générique, provisoire.
CREATE TABLE collections (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    kind        ENUM('category','mood','collection') NOT NULL,
    name        VARCHAR(100)    NOT NULL,
    description VARCHAR(1000)       NULL,
    parent_id   BIGINT UNSIGNED     NULL,
    created_at  DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_collections_name (kind, name),
    CONSTRAINT fk_collections_parent FOREIGN KEY (parent_id) REFERENCES collections (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE collection_albums (
    collection_id BIGINT UNSIGNED NOT NULL,
    album_id      BIGINT UNSIGNED NOT NULL,
    position      INT UNSIGNED        NULL,
    PRIMARY KEY (collection_id, album_id),
    KEY ix_collection_albums_album (album_id),
    CONSTRAINT fk_collection_albums_collection FOREIGN KEY (collection_id) REFERENCES collections (id) ON DELETE CASCADE,
    CONSTRAINT fk_collection_albums_album      FOREIGN KEY (album_id)      REFERENCES albums (id)      ON DELETE CASCADE
) ENGINE=InnoDB;

-- =============================================================================
-- 4. CORRECTIONS ET PROPOSITIONS (CAT-06, IA-02)
-- =============================================================================

-- Lot de changements proposé par l'agent (ou préparé par l'admin), validé avant application.
CREATE TABLE change_sets (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    title        VARCHAR(255)    NOT NULL,
    description  TEXT                NULL,
    status       ENUM('draft','pending','applied','rejected','reverted') NOT NULL DEFAULT 'pending',
    created_by   BIGINT UNSIGNED NOT NULL,
    created_at   DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    decided_by   BIGINT UNSIGNED     NULL,
    decided_at   DATETIME(3)         NULL,
    KEY ix_change_sets_status (status),
    CONSTRAINT fk_change_sets_creator FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT fk_change_sets_decider FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Une correction remplace la valeur lue par l'indexeur pour un champ d'un élément.
-- Active = appliquée et non annulée. On ne supprime jamais une correction : l'annuler
-- remplit reverted_at, ce qui garde l'historique.
CREATE TABLE corrections (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    entity_type     ENUM('album','track','artist') NOT NULL,
    entity_id       BIGINT UNSIGNED NOT NULL,
    field           VARCHAR(64)     NOT NULL COMMENT 'Ex. title, year, genre_ids, album_artist, artist_credit, cover, album_id (piste déplacée)',
    value           JSON            NOT NULL CHECK (JSON_VALID(value)),
    previous_value  JSON                NULL CHECK (previous_value IS NULL OR JSON_VALID(previous_value)),
    change_set_id   BIGINT UNSIGNED     NULL,
    status          ENUM('proposed','active','rejected','reverted') NOT NULL DEFAULT 'active',
    created_by      BIGINT UNSIGNED NOT NULL,
    created_at      DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    reverted_by     BIGINT UNSIGNED     NULL,
    reverted_at     DATETIME(3)         NULL,
    KEY ix_corrections_entity (entity_type, entity_id, field, status),
    KEY ix_corrections_change_set (change_set_id),
    CONSTRAINT fk_corrections_change_set FOREIGN KEY (change_set_id) REFERENCES change_sets (id) ON DELETE SET NULL,
    CONSTRAINT fk_corrections_creator    FOREIGN KEY (created_by)    REFERENCES users (id),
    CONSTRAINT fk_corrections_reverter   FOREIGN KEY (reverted_by)   REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =============================================================================
-- 5. UTILISATION : favoris, file d'attente, historique
-- =============================================================================

CREATE TABLE favorites (
    user_id     BIGINT UNSIGNED NOT NULL,
    item_type   ENUM('track','album','artist') NOT NULL,
    item_id     BIGINT UNSIGNED NOT NULL COMMENT 'Pas de clé étrangère (polymorphe) : pistes et albums ne sont jamais supprimés, seulement marqués disparus',
    created_at  DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (user_id, item_type, item_id),
    KEY ix_favorites_item (item_type, item_id),
    CONSTRAINT fk_favorites_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- File d'attente enregistrée sur le serveur (WEB-04) : on la retrouve sur un autre appareil.
-- Une file par utilisateur ; la liste des pistes est un tableau JSON d'identifiants.
CREATE TABLE play_queues (
    user_id        BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    track_ids      JSON            NOT NULL CHECK (JSON_VALID(track_ids)),
    current_index  INT UNSIGNED        NULL,
    position_ms    INT UNSIGNED    NOT NULL DEFAULT 0,
    mode           ENUM('normal','repeat_all','repeat_one','continuous') NOT NULL DEFAULT 'normal',
    version        INT UNSIGNED    NOT NULL DEFAULT 1,
    updated_at     DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_by     BIGINT UNSIGNED     NULL COMMENT 'Jeton (appareil) qui a écrit en dernier',
    CONSTRAINT fk_play_queues_user  FOREIGN KEY (user_id)    REFERENCES users (id)         ON DELETE CASCADE,
    CONSTRAINT fk_play_queues_token FOREIGN KEY (updated_by) REFERENCES access_tokens (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Historique d'écoute (JRN-06) : évite les répétitions en lecture continue, sert aux statistiques.
CREATE TABLE play_history (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id      BIGINT UNSIGNED NOT NULL,
    track_id     BIGINT UNSIGNED NOT NULL,
    token_id     BIGINT UNSIGNED     NULL COMMENT 'Appareil',
    started_at   DATETIME(3)     NOT NULL,
    played_ms    INT UNSIGNED    NOT NULL DEFAULT 0,
    completed    BOOLEAN         NOT NULL DEFAULT FALSE,
    skipped      BOOLEAN         NOT NULL DEFAULT FALSE,
    context      ENUM('album','artist','search','random','continuous','queue','other') NOT NULL DEFAULT 'other',
    KEY ix_play_history_user (user_id, started_at),
    KEY ix_play_history_track (track_id),
    CONSTRAINT fk_play_history_user  FOREIGN KEY (user_id)  REFERENCES users (id)         ON DELETE CASCADE,
    CONSTRAINT fk_play_history_track FOREIGN KEY (track_id) REFERENCES tracks (id)        ON DELETE CASCADE,
    CONSTRAINT fk_play_history_token FOREIGN KEY (token_id) REFERENCES access_tokens (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =============================================================================
-- 6. TÂCHES DE FOND ET JOURNAUX
-- =============================================================================

-- Chaque passage de l'indexeur, de l'analyseur… (JRN-03).
CREATE TABLE jobs (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    type          ENUM('index','analyze','inbox','thumbnails','import','mail') NOT NULL,
    status        ENUM('queued','running','done','failed','cancelled') NOT NULL DEFAULT 'queued',
    params        JSON                NULL COMMENT 'Ex. {"prefix": "Artiste/"} pour un scan partiel' CHECK (params IS NULL OR JSON_VALID(params)),
    stats         JSON                NULL COMMENT 'Ex. {"seen": 1200, "added": 12, "changed": 3, "missing": 1, "errors": 2}' CHECK (stats IS NULL OR JSON_VALID(stats)),
    requested_by  BIGINT UNSIGNED     NULL COMMENT 'NULL = minuterie systemd',
    created_at    DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    started_at    DATETIME(3)         NULL,
    finished_at   DATETIME(3)         NULL,
    worker        VARCHAR(100)        NULL COMMENT 'Hôte et PID',
    KEY ix_jobs_type_status (type, status, created_at),
    CONSTRAINT fk_jobs_user FOREIGN KEY (requested_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Erreurs par fichier (CAT-09) : affichées dans l'admin, résumées par mail.
CREATE TABLE job_errors (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    job_id       BIGINT UNSIGNED NOT NULL,
    object_id    BIGINT UNSIGNED     NULL,
    storage_key  VARCHAR(1024)       NULL COLLATE utf8mb4_bin,
    code         VARCHAR(64)     NOT NULL COMMENT 'Ex. probe_failed, invalid_utf8, unreadable',
    message      VARCHAR(2000)   NOT NULL,
    created_at   DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    resolved_at  DATETIME(3)         NULL,
    KEY ix_job_errors_job (job_id),
    KEY ix_job_errors_open (resolved_at, created_at),
    CONSTRAINT fk_job_errors_job    FOREIGN KEY (job_id)    REFERENCES jobs (id)            ON DELETE CASCADE,
    CONSTRAINT fk_job_errors_object FOREIGN KEY (object_id) REFERENCES storage_objects (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- File d'analyse audio (AUD-05). Les analyseurs prennent une ligne avec
-- SELECT … FOR UPDATE SKIP LOCKED : plusieurs processus en parallèle, sans doublon,
-- et la file reprend d'elle-même après un arrêt.
CREATE TABLE analysis_queue (
    track_id      BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    priority      TINYINT UNSIGNED NOT NULL DEFAULT 100 COMMENT 'Plus petit = plus urgent (favoris : 10)',
    steps         SET('loudness','silence','bpm','key','energy','fingerprint','hash') NOT NULL,
    enqueued_at   DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_error    VARCHAR(2000)       NULL,
    locked_by     VARCHAR(100)        NULL,
    locked_until  DATETIME(3)         NULL,
    KEY ix_analysis_queue_next (priority, enqueued_at),
    CONSTRAINT fk_analysis_queue_track FOREIGN KEY (track_id) REFERENCES tracks (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Journal d'audit (JRN-02) : qui a fait quoi, quand, avant et après.
-- Jamais de secret dans before_value / after_value.
CREATE TABLE audit_log (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    created_at     DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    actor_user_id  BIGINT UNSIGNED     NULL COMMENT 'NULL = système ou visiteur (demande de compte)',
    actor_token_id BIGINT UNSIGNED     NULL,
    action         VARCHAR(64)     NOT NULL COMMENT 'Ex. auth.login, user.approve, token.revoke, album.update',
    target_type    VARCHAR(32)         NULL,
    target_id      BIGINT UNSIGNED     NULL,
    before_value   JSON                NULL CHECK (before_value IS NULL OR JSON_VALID(before_value)),
    after_value    JSON                NULL CHECK (after_value  IS NULL OR JSON_VALID(after_value)),
    ip             INET6               NULL,
    request_id     CHAR(26)            NULL COMMENT 'Même identifiant que le journal technique (en-tête X-Request-Id)',
    KEY ix_audit_log_created (created_at),
    KEY ix_audit_log_actor (actor_user_id, created_at),
    KEY ix_audit_log_target (target_type, target_id),
    CONSTRAINT fk_audit_log_user  FOREIGN KEY (actor_user_id)  REFERENCES users (id)         ON DELETE SET NULL,
    CONSTRAINT fk_audit_log_token FOREIGN KEY (actor_token_id) REFERENCES access_tokens (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- -----------------------------------------------------------------------------
-- Tri naturel (« Album 21 » avant « Album 100 »). MariaDB n'accepte pas NATURAL_SORT_KEY
-- dans une colonne calculée indexable : des déclencheurs tiennent la colonne à jour, quelle
-- que soit la requête qui modifie le titre (indexeur, correction, admin).
-- -----------------------------------------------------------------------------
DELIMITER //
CREATE TRIGGER trg_artists_sort_ins BEFORE INSERT ON artists FOR EACH ROW
    SET NEW.sort_key = NATURAL_SORT_KEY(COALESCE(NEW.sort_name, NEW.name))//
CREATE TRIGGER trg_artists_sort_upd BEFORE UPDATE ON artists FOR EACH ROW
    SET NEW.sort_key = NATURAL_SORT_KEY(COALESCE(NEW.sort_name, NEW.name))//
CREATE TRIGGER trg_albums_sort_ins BEFORE INSERT ON albums FOR EACH ROW
    SET NEW.sort_key = NATURAL_SORT_KEY(NEW.title)//
CREATE TRIGGER trg_albums_sort_upd BEFORE UPDATE ON albums FOR EACH ROW
    SET NEW.sort_key = NATURAL_SORT_KEY(NEW.title)//
DELIMITER ;

-- =============================================================================
-- Valeurs initiales
-- =============================================================================

INSERT INTO server_settings (name, value) VALUES
    ('registration_open', 'true'),
    ('registration_pending_per_network', '2'),
    ('server_name',       '"Rengaine"');

INSERT INTO schema_migrations (version, description) VALUES (1, 'Schéma initial (CAD-01)');

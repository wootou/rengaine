<?php
// Reconstruit un album à partir des fichiers de son dossier (docs/CONCEPTION.md § 5).
//   - Titre, artiste, année : vote majoritaire sur les tags ; à défaut, nom du dossier.
//   - Dossier sans album commun : « en vrac » (kind = loose).
//   - MP3 et FLAC d'un même morceau : une seule piste.
//   - Les identifiants existants sont conservés ; les corrections actives recouvrent les tags.

declare(strict_types=1);

namespace Musique\Library;

use Musique\Db\Database;

final class AlbumBuilder
{
    public const VARIOUS = '/^(various( artists)?|va|v\.a\.|artistes? divers|multi-?artistes?|compilation|divers)$/i';
    private const FEAT = '/\s+(?:feat\.?|ft\.?|featuring)\s+/i';

    public function __construct(
        private readonly Database $db,
        private readonly Genres $genres,
        private readonly Images $images,
    ) {
    }

    /**
     * @param array<int, true> $freshObjects objets lus par ffprobe pendant ce passage (analyse à refaire)
     * @return array{album: ?int, created: bool, tracksNew: int, missing: bool}
     */
    public function rebuild(string $dir, array $freshObjects = []): array
    {
        $rows = $this->db->all(
            'SELECT so.id AS object_id, so.storage_key, so.kind, tf.id AS file_id, tf.track_id, tf.format, tf.lossless,
                    tf.bitrate_kbps, tf.duration_ms, tf.has_picture, tf.tags_raw
               FROM storage_objects so LEFT JOIN track_files tf ON tf.object_id = so.id
              WHERE so.album_dir_hash = UNHEX(SHA2(?, 256)) AND so.album_dir = ? AND so.missing_since IS NULL
              ORDER BY so.storage_key',
            [$dir, $dir]
        );
        $files = array_values(array_filter($rows, static fn ($r) => $r['kind'] === 'audio' && $r['file_id'] !== null));
        $imageRows = array_values(array_filter($rows, static fn ($r) => $r['kind'] === 'image'));
        $album = $this->db->one('SELECT * FROM albums WHERE dir_hash = UNHEX(SHA2(?, 256))', [$dir]);

        // Plus aucun fichier audio lisible : l'album est masqué, ses favoris et son historique restent.
        if ($files === []) {
            if ($album !== null) {
                $this->db->run('UPDATE albums SET missing_since = COALESCE(missing_since, CURRENT_TIMESTAMP(3)) WHERE id = ?', [$album['id']]);
                $this->db->run('UPDATE tracks SET missing_since = COALESCE(missing_since, CURRENT_TIMESTAMP(3)) WHERE album_id = ?', [$album['id']]);
            }
            return ['album' => $album === null ? null : (int) $album['id'], 'created' => false, 'tracksNew' => 0, 'missing' => $album !== null];
        }

        foreach ($files as &$f) {
            $f['tags'] = json_decode((string) $f['tags_raw'], true) ?: [];
        }
        unset($f);

        // ------------------------------------------------------------------ Album
        [$dirArtist, $dirTitle] = Layout::fromDirName($dir);
        $albumTags = array_map(static fn ($f) => $f['tags']['album'] ?? null, $files);
        [$tagTitle, $titleShare] = Text::majority($albumTags);
        $hasAlbumTags = count(array_filter($albumTags, static fn ($v) => $v !== null)) > 0;
        $loose = $hasAlbumTags && $titleShare < 0.5;
        $title = match (true) {
            $loose => $dir === '' ? 'Racine' : (Text::clean(basename($dir)) ?? basename($dir)),
            $tagTitle !== null => $tagTitle,
            default => $dirTitle,
        };

        [$aa, $aaShare] = Text::majority(array_map(static fn ($f) => $f['tags']['album_artist'] ?? null, $files));
        [$mainArtist, $artistShare] = Text::majority(array_map(static fn ($f) => self::splitArtist($f['tags']['artist'] ?? null)[0], $files));
        $isVarious = $aa !== null && preg_match(self::VARIOUS, $aa) === 1;
        $albumArtist = match (true) {
            $isVarious => null,
            $aa !== null && $aaShare >= 0.5 => $aa,
            $mainArtist !== null && $artistShare >= 0.5 => $mainArtist,
            !$loose && !$hasAlbumTags => $dirArtist,
            default => null,
        };
        [$compTag] = Text::majority(array_map(static fn ($f) => $f['tags']['compilation'] ?? null, $files));
        $isCompilation = $isVarious || $compTag === '1' || (!$loose && $albumArtist === null && $mainArtist !== null);

        [$yearStr] = Text::majority(array_map(static fn ($f) => ($y = Text::year($f['tags']['date'] ?? null)) === null ? null : (string) $y, $files));
        $year = $yearStr === null ? null : (int) $yearStr;
        [$mbid] = Text::majority(array_map(static fn ($f) => $f['tags']['musicbrainz_album_id'] ?? null, $files));
        $mbid = $mbid !== null && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $mbid) ? strtolower($mbid) : null;

        // Corrections actives de l'album
        $albumCorr = $album === null ? [] : $this->corrections('album', [(int) $album['id']])[(int) $album['id']] ?? [];
        if (array_key_exists('title', $albumCorr)) {
            $title = (string) $albumCorr['title'];
        }
        if (array_key_exists('year', $albumCorr)) {
            $year = $albumCorr['year'] === null ? null : (int) $albumCorr['year'];
        }
        if (array_key_exists('album_artist', $albumCorr)) {
            $albumArtist = $albumCorr['album_artist'] === null ? null : (string) $albumCorr['album_artist'];
        }
        if (array_key_exists('is_compilation', $albumCorr)) {
            $isCompilation = (bool) $albumCorr['is_compilation'];
        }

        $albumArtistId = $albumArtist === null ? null : $this->artistId($albumArtist);
        $created = false;
        if ($album === null) {
            $this->db->run(
                'INSERT INTO albums (title, album_artist_id, year, is_compilation, kind, source_dir, musicbrainz_id) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [mb_substr($title, 0, 255), $albumArtistId, $year, (int) $isCompilation, $loose ? 'loose' : 'album', $dir, $mbid]
            );
            $albumId = (int) $this->db->pdo()->lastInsertId();
            $created = true;
        } else {
            $albumId = (int) $album['id'];
            $this->db->run(
                'UPDATE albums SET title = ?, album_artist_id = ?, year = ?, is_compilation = ?, kind = ?, musicbrainz_id = ?, missing_since = NULL WHERE id = ?',
                [mb_substr($title, 0, 255), $albumArtistId, $year, (int) $isCompilation, $loose ? 'loose' : 'album', $mbid, $albumId]
            );
        }

        // ------------------------------------------------------------------ Pistes
        $groups = [];
        foreach ($files as $f) {
            $t = $f['tags'];
            $artistTag = $t['artist'] ?? null;
            [$fileNum, $fileTitle] = Layout::fromFilename($f['storage_key'], $artistTag ?? $albumArtist ?? $dirArtist);
            $disc = Text::firstInt($t['disc'] ?? null) ?? Layout::discFromPath($f['storage_key']) ?? 1;
            $num = Text::firstInt($t['track'] ?? null) ?? $fileNum;
            $trackTitle = $t['title'] ?? $fileTitle;
            $key = $disc . '|' . ($num ?? '') . '|' . Text::key($trackTitle);
            $groups[$key]['files'][] = $f;
            $groups[$key] += ['disc' => min(255, $disc), 'num' => $num === null ? null : min(65535, $num), 'title' => $trackTitle, 'artist' => $artistTag];
        }

        // Pistes déjà connues : par les fichiers d'abord, sinon par disque + numéro + titre
        $existing = $this->db->all('SELECT id, disc_number, track_number, title FROM tracks WHERE album_id = ?', [$albumId]);
        $byKey = [];
        foreach ($existing as $e) {
            $byKey[$e['disc_number'] . '|' . ($e['track_number'] ?? '') . '|' . Text::key($e['title'])] = (int) $e['id'];
        }
        $used = [];
        $tracksNew = 0;
        $liveTrackIds = [];
        // Pistes liées à ce dossier : celles de l'album, et celles qu'une correction a déplacées
        // ailleurs (leurs fichiers sont toujours ici, disparus compris)
        $withFilesHere = array_map('intval', array_column($this->db->all(
            'SELECT DISTINCT tf.track_id FROM track_files tf JOIN storage_objects so ON so.id = tf.object_id
              WHERE so.album_dir_hash = UNHEX(SHA2(?, 256)) AND so.album_dir = ? AND tf.track_id IS NOT NULL',
            [$dir, $dir]
        ), 'track_id'));
        $linked = array_values(array_unique([...array_map(static fn ($e) => (int) $e['id'], $existing), ...$withFilesHere]));
        $trackCorr = $this->corrections('track', $linked);
        /** @var array<int, true> $otherAlbums albums qui reçoivent une piste déplacée */
        $otherAlbums = [];

        foreach ($groups as $key => $g) {
            usort($g['files'], static fn ($a, $b) => [(int) $b['lossless'], (int) $b['bitrate_kbps']] <=> [(int) $a['lossless'], (int) $a['bitrate_kbps']]);
            $trackId = null;
            foreach ($g['files'] as $f) {
                if ($f['track_id'] !== null && !isset($used[(int) $f['track_id']])) {
                    $trackId = (int) $f['track_id'];
                    break;
                }
            }
            if ($trackId === null && isset($byKey[$key]) && !isset($used[$byKey[$key]])) {
                $trackId = $byKey[$key];
            }

            [$main, $featured] = self::splitArtist($g['artist'] ?? null);
            if (preg_match('/[(\[](?:feat\.?|ft\.?|featuring)\s+([^)\]]+)[)\]]/i', $g['title'], $m)) {
                $featured = array_merge($featured, self::splitNames($m[1]));
            }
            $credit = ($g['artist'] !== null && Text::key($g['artist']) !== Text::key($albumArtist)) ? preg_replace('/\s*;\s*/u', ', ', $g['artist']) : null;

            $corr = $trackId === null ? [] : ($trackCorr[$trackId] ?? []);
            $values = [
                'title' => mb_substr((string) ($corr['title'] ?? $g['title']), 0, 255),
                'artist_credit' => array_key_exists('artist_credit', $corr) ? $corr['artist_credit'] : ($credit === null ? null : mb_substr($credit, 0, 500)),
                'disc_number' => (int) ($corr['disc_number'] ?? $g['disc']),
                'track_number' => array_key_exists('track_number', $corr) ? $corr['track_number'] : $g['num'],
                // Une piste déplacée par une correction reste dans l'album choisi par l'admin
                'album_id' => isset($corr['album_id']) ? (int) $corr['album_id'] : $albumId,
            ];
            $duration = (int) $g['files'][0]['duration_ms'];

            if ($trackId === null) {
                $this->db->run(
                    'INSERT INTO tracks (album_id, disc_number, track_number, title, artist_credit, duration_ms) VALUES (?, ?, ?, ?, ?, ?)',
                    [$values['album_id'], $values['disc_number'], $values['track_number'], $values['title'], $values['artist_credit'], $duration]
                );
                $trackId = (int) $this->db->pdo()->lastInsertId();
                $tracksNew++;
                $this->enqueueAnalysis($trackId);
            } else {
                $this->db->run(
                    'UPDATE tracks SET album_id = ?, disc_number = ?, track_number = ?, title = ?, artist_credit = ?, duration_ms = ?, missing_since = NULL WHERE id = ?',
                    [$values['album_id'], $values['disc_number'], $values['track_number'], $values['title'], $values['artist_credit'], $duration, $trackId]
                );
                foreach ($g['files'] as $f) {
                    if (isset($freshObjects[(int) $f['object_id']])) {
                        $this->enqueueAnalysis($trackId);
                        break;
                    }
                }
            }
            $used[$trackId] = true;
            $liveTrackIds[] = $trackId;
            if ($values['album_id'] !== $albumId) {
                $otherAlbums[$values['album_id']] = true;
            }

            $fileIds = array_map(static fn ($f) => (int) $f['file_id'], $g['files']);
            $this->db->run('UPDATE track_files SET track_id = ? WHERE id IN (' . implode(',', array_fill(0, count($fileIds), '?')) . ')', [$trackId, ...$fileIds]);

            $this->db->run('DELETE FROM track_artists WHERE track_id = ?', [$trackId]);
            $pos = 0;
            if ($main !== null) {
                foreach (preg_split('/\s*;\s*/u', $main) ?: [] as $one) {
                    if (($one = Text::clean($one)) !== null) {
                        $this->db->run("INSERT IGNORE INTO track_artists (track_id, artist_id, role, position) VALUES (?, ?, 'main', ?)", [$trackId, $this->artistId($one), min(255, $pos++)]);
                    }
                }
            } elseif ($albumArtistId !== null) {
                $this->db->run("INSERT IGNORE INTO track_artists (track_id, artist_id, role, position) VALUES (?, ?, 'main', ?)", [$trackId, $albumArtistId, $pos++]);
            }
            foreach (array_unique($featured) as $name) {
                $this->db->run("INSERT IGNORE INTO track_artists (track_id, artist_id, role, position) VALUES (?, ?, 'featured', ?)", [$trackId, $this->artistId($name), min(255, $pos++)]);
            }
        }

        // Pistes liées au dossier sans fichier vivant (fichier supprimé, déplacé…) : masquées, jamais
        // supprimées. Une piste venue d'un autre dossier par une correction n'a pas de fichier ici :
        // c'est le scan de son propre dossier qui en décide.
        $gone = array_values(array_diff($withFilesHere, $liveTrackIds));
        if ($gone !== []) {
            foreach ($this->db->all('SELECT DISTINCT album_id FROM tracks WHERE id IN (' . implode(',', array_fill(0, count($gone), '?')) . ') AND album_id <> ?', [...$gone, $albumId]) as $r) {
                $otherAlbums[(int) $r['album_id']] = true;
            }
            $this->db->run(
                'UPDATE tracks SET missing_since = COALESCE(missing_since, CURRENT_TIMESTAMP(3)) WHERE id IN (' . implode(',', array_fill(0, count($gone), '?')) . ')',
                $gone
            );
        }

        // ------------------------------------------------------------------ Jaquette
        if (array_key_exists('cover', $albumCorr)) {
            // Jaquette choisie par l'admin (empreinte d'une image connue ; null = aucune)
            $coverId = $albumCorr['cover'] === null ? null
                : $this->db->value('SELECT id FROM images WHERE sha256 = UNHEX(?)', [(string) $albumCorr['cover']]);
            $this->db->run('UPDATE albums SET cover_image_id = ? WHERE id = ?', [$coverId, $albumId]);
        } else {
            $coverId = null;
            $pick = Images::pickCoverFile(array_map(static fn ($r) => ['id' => (int) $r['object_id'], 'storage_key' => (string) $r['storage_key']], $imageRows));
            if ($pick !== null) {
                $coverId = $this->images->fromStorageObject($pick['id'], $pick['storage_key']);
            }
            if ($coverId === null) {
                $withPicture = array_values(array_filter($files, static fn ($f) => (bool) $f['has_picture']));
                usort($withPicture, static fn ($a, $b) => strcmp($a['storage_key'], $b['storage_key']));
                foreach (array_slice($withPicture, 0, 3) as $f) {
                    $coverId = $this->images->fromEmbedded((int) $f['object_id'], (string) $f['storage_key']);
                    if ($coverId !== null) {
                        break;
                    }
                }
            }
            $this->db->run('UPDATE albums SET cover_image_id = ? WHERE id = ?', [$coverId, $albumId]);
        }

        $this->updateCounts($albumId);
        $this->genres->refreshAlbum($albumId);
        foreach (array_keys($otherAlbums) as $other) {
            $this->updateCounts((int) $other);
            $this->genres->refreshAlbum((int) $other);
        }
        return ['album' => $albumId, 'created' => $created, 'tracksNew' => $tracksNew, 'missing' => false];
    }

    /** Compteurs de l'album (nombre de pistes, durée…), pour ne pas les recalculer à chaque page. */
    public function updateCounts(int $albumId): void
    {
        $this->db->run(
            'UPDATE albums a
               LEFT JOIN (
                    SELECT t.album_id, COUNT(*) AS n, MAX(t.disc_number) AS discs, SUM(t.duration_ms) AS dur,
                           MAX(EXISTS(SELECT 1 FROM track_files tf WHERE tf.track_id = t.id AND tf.lossless)) AS lossless
                      FROM tracks t WHERE t.album_id = ? AND t.missing_since IS NULL GROUP BY t.album_id
               ) s ON s.album_id = a.id
                SET a.track_count = COALESCE(s.n, 0), a.disc_count = COALESCE(s.discs, 1),
                    a.duration_ms = COALESCE(s.dur, 0), a.has_lossless = COALESCE(s.lossless, 0),
                    -- Plus aucune piste (toutes déplacées ou disparues) : masqué ; de nouveau visible sinon
                    a.missing_since = IF(s.n IS NULL, COALESCE(a.missing_since, CURRENT_TIMESTAMP(3)), NULL)
              WHERE a.id = ?',
            [$albumId, $albumId]
        );
    }

    /**
     * Artiste par son nom (créé s'il n'existe pas ; « Beyonce » = « Beyoncé »). Les corrections
     * de l'admin sont suivies : un artiste renommé est retrouvé par son ancien nom, un artiste
     * fusionné mène à celui qui l'a absorbé.
     */
    public function artistId(string $name): int
    {
        $name = mb_substr($name, 0, 255);
        $id = $this->db->value('SELECT id FROM artists WHERE name = ?', [$name])
            ?? $this->db->value(
                "SELECT entity_id FROM corrections WHERE entity_type = 'artist' AND status = 'active' AND lookup_name = ? AND field = 'name' ORDER BY id DESC LIMIT 1",
                [$name]
            );
        if ($id === null) {
            // Pas de « ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id) » : quand la ligne existe,
            // MariaDB peut renvoyer 0 comme identifiant. On relit donc par le nom.
            $this->db->run('INSERT IGNORE INTO artists (name) VALUES (?)', [$name]);
            $id = $this->db->value('SELECT id FROM artists WHERE name = ?', [$name]);
            if ($id === null) {
                throw new \RuntimeException("Artiste introuvable après création : $name");
            }
        }
        return $this->mergedTarget((int) $id);
    }

    /** Artiste qui a absorbé celui-ci (fusions suivies en chaîne), ou lui-même. */
    public function mergedTarget(int $id): int
    {
        for ($i = 0; $i < 10; $i++) {
            $into = $this->db->value(
                "SELECT value FROM corrections WHERE entity_type = 'artist' AND entity_id = ? AND field = 'merged_into' AND status = 'active' ORDER BY id DESC LIMIT 1",
                [$id]
            );
            if ($into === null) {
                break;
            }
            $id = (int) json_decode((string) $into, true);
        }
        return $id;
    }

    private function enqueueAnalysis(int $trackId): void
    {
        $this->db->run(
            "INSERT INTO analysis_queue (track_id, steps) VALUES (?, 'loudness,silence,bpm,key,energy,hash,fingerprint')
             ON DUPLICATE KEY UPDATE steps = VALUES(steps), attempts = 0, last_error = NULL,
               enqueued_at = CURRENT_TIMESTAMP(3)", // un analyseur en cours sur l'ancien fichier verra la remise en file
            [$trackId]
        );
    }

    /**
     * Corrections actives, la plus récente l'emportant : [id => [champ => valeur]].
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    public function corrections(string $type, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->all(
            "SELECT entity_id, field, value FROM corrections
              WHERE entity_type = ? AND status = 'active' AND entity_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')
              ORDER BY id',
            [$type, ...$ids]
        ) as $c) {
            $out[(int) $c['entity_id']][$c['field']] = json_decode((string) $c['value'], true);
        }
        return $out;
    }

    /**
     * « Gorillaz feat. Kali Uchis, Bootie Brown » → [« Gorillaz », [« Kali Uchis », « Bootie Brown »]].
     * L'artiste principal n'est pas coupé sur « & » (« Simon & Garfunkel »).
     * @return array{0: ?string, 1: list<string>}
     */
    public static function splitArtist(?string $artist): array
    {
        if ($artist === null) {
            return [null, []];
        }
        $parts = preg_split(self::FEAT, $artist, 2) ?: [$artist];
        $main = Text::clean($parts[0]);
        return [$main, isset($parts[1]) ? self::splitNames($parts[1]) : []];
    }

    /** @return list<string> */
    private static function splitNames(string $names): array
    {
        $out = [];
        foreach (preg_split('/\s*(?:,|&|\s+and\s+|\s+et\s+)\s*/i', $names) ?: [] as $n) {
            $n = Text::clean(trim($n, " ()[]"));
            if ($n !== null) {
                $out[] = $n;
            }
        }
        return $out;
    }
}

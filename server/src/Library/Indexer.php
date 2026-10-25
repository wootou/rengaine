<?php
// Indexeur incrémental (CAT-02). Toujours en tâche de fond, jamais dans une requête web.
//   1. Liste le stockage et compare chaque fichier à storage_objects (taille, date, ETag).
//   2. Fait lire par ffprobe les fichiers audio nouveaux ou modifiés.
//   3. Reconstruit les albums dont un fichier a changé.
//   4. Marque comme disparus les fichiers non vus (passage complet du préfixe seulement).
// Le dépôt (`inbox/`) n'est pas indexé : ses albums le sont une fois rangés (Inbox).
// La corbeille (`corbeille/`, MP3 en double retirés) non plus, ni `_tests/` : les tests du stockage y
// écrivent un instant dans le bucket de dev, un passage de l'indexeur au même moment les ramassait.

declare(strict_types=1);

namespace Musique\Library;

use Musique\Db\Database;
use Musique\Jobs\JobLog;
use Musique\Log\Logger;
use Musique\Storage\Storage;

final class Indexer
{
    /** Préfixe des tests du stockage (StorageContract) : jamais indexé. */
    public const TEST_PREFIX = '_tests/';
    private const BATCH = 500;

    public function __construct(
        private readonly Database $db,
        private readonly Storage $storage,
        private readonly Logger $logger,
        private readonly Probe $probe,
        private readonly AlbumBuilder $builder,
        private readonly Genres $genres,
    ) {
    }

    /** @return array<string, int> compteurs du passage */
    public function run(string $prefix = '', bool $force = false, ?int $requestedBy = null, ?callable $progress = null, ?int $queuedJobId = null): array
    {
        $progress ??= static function (string $msg): void {};
        if ((int) $this->db->value("SELECT GET_LOCK('musique:index', 0)") !== 1) {
            throw new \RuntimeException('Une indexation est déjà en cours.');
        }
        $job = new JobLog($this->db, $this->logger, 'index', array_filter(['prefix' => $prefix, 'force' => $force ?: null]), $requestedBy, $queuedJobId);
        $t0 = microtime(true);
        try {
            $start = (string) $this->db->value('SELECT CURRENT_TIMESTAMP(3)');
            /** @var array<string, true> $dirty dossiers d'albums à reconstruire */
            $dirty = [];

            // 1. Parcours du stockage
            $batch = [];
            foreach ($this->storage->list($prefix) as $obj) {
                if (Inbox::contains($obj->key) || Duplicates::inTrash($obj->key) || str_starts_with($obj->key, self::TEST_PREFIX)) {
                    continue;
                }
                $batch[] = $obj;
                if (count($batch) >= self::BATCH) {
                    $this->syncBatch($batch, $start, $dirty, $job);
                    $batch = [];
                    $progress(sprintf('%d fichiers parcourus', $job->stats()['seen'] ?? 0));
                }
            }
            $this->syncBatch($batch, $start, $dirty, $job);
            $progress(sprintf('%d fichiers parcourus', $job->stats()['seen'] ?? 0));

            // 2. Fichiers disparus (le parcours du préfixe est complet : on peut conclure)
            $like = $this->likePrefix($prefix);
            foreach ($this->db->all(
                'SELECT id, album_dir FROM storage_objects
                  WHERE missing_since IS NULL AND last_seen_at < ?' . ($like === null ? '' : ' AND storage_key LIKE ?'),
                $like === null ? [$start] : [$start, $like]
            ) as $gone) {
                $this->db->run('UPDATE storage_objects SET missing_since = CURRENT_TIMESTAMP(3) WHERE id = ?', [$gone['id']]);
                $this->db->run('UPDATE images SET object_id = NULL WHERE object_id = ?', [$gone['id']]);
                $dirty[(string) $gone['album_dir']] = true;
                $job->count('missing');
            }

            // 3. Lecture par ffprobe
            $fresh = $this->probeAll($prefix, $force, $dirty, $job, $progress);

            if ($force) {
                foreach ($this->db->all(
                    'SELECT DISTINCT album_dir FROM storage_objects WHERE album_dir IS NOT NULL'
                    . ($like === null ? '' : ' AND storage_key LIKE ?'),
                    $like === null ? [] : [$like]
                ) as $r) {
                    $dirty[(string) $r['album_dir']] = true;
                }
            }

            // 4. Reconstruction des albums concernés, un par un (une erreur n'arrête pas le passage)
            $dirs = array_keys($dirty);
            sort($dirs, SORT_STRING);
            foreach ($dirs as $i => $dir) {
                $dir = (string) $dir;
                try {
                    $res = $this->db->transaction(fn () => $this->builder->rebuild($dir, $fresh));
                    $job->count('albums_rebuilt');
                    $res['created'] && $job->count('albums_new');
                    $res['missing'] && $job->count('albums_missing');
                    $job->count('tracks_new', $res['tracksNew']);
                } catch (\Throwable $e) {
                    $job->error('album_failed', $e->getMessage(), $dir === '' ? '(racine)' : $dir);
                }
                if (($i + 1) % 50 === 0) {
                    $progress(sprintf('%d / %d albums reconstruits', $i + 1, count($dirs)));
                }
            }
            $this->genres->recountAliases();
            $job->count('duration_s', (int) round(microtime(true) - $t0));
            $job->finish();
            return $job->stats();
        } catch (\Throwable $e) {
            $job->fail($e);
            throw $e;
        } finally {
            $this->db->value("SELECT RELEASE_LOCK('musique:index')");
        }
    }

    /**
     * @param list<\Musique\Storage\StorageObject> $objects
     * @param array<string, true> $dirty
     */
    private function syncBatch(array $objects, string $start, array &$dirty, JobLog $job): void
    {
        if ($objects === []) {
            return;
        }
        $hashes = array_map(static fn ($o) => hash('sha256', $o->key, true), $objects);
        $known = [];
        foreach ($this->db->all(
            'SELECT id, storage_key, size_bytes, modified_at, etag, missing_since FROM storage_objects WHERE key_hash IN ('
            . implode(',', array_fill(0, count($hashes), '?')) . ')',
            $hashes
        ) as $row) {
            $known[$row['storage_key']] = $row;
        }

        $seenIds = [];
        foreach ($objects as $obj) {
            $job->count('seen');
            $kind = Layout::kind($obj->key);
            $dir = Layout::albumDir($obj->key);
            $modified = $obj->modifiedAt?->format('Y-m-d H:i:s.v');
            $row = $known[$obj->key] ?? null;

            if ($row === null) {
                $this->db->run(
                    'INSERT INTO storage_objects (storage_key, kind, album_dir, size_bytes, modified_at, etag, first_seen_at, last_seen_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$obj->key, $kind, $dir, $obj->size, $modified, $obj->etag, $start, $start]
                );
                $job->count('new');
                $dirty[$dir] = true;
                continue;
            }

            $changed = (int) $row['size_bytes'] !== $obj->size
                || ($obj->etag !== null && $row['etag'] !== null ? $obj->etag !== $row['etag'] : self::sameTime($row['modified_at'], $modified) === false);
            if ($changed) {
                $this->db->run(
                    'UPDATE storage_objects SET kind = ?, album_dir = ?, size_bytes = ?, modified_at = ?, etag = ?, content_sha256 = NULL,
                            last_seen_at = ?, missing_since = NULL WHERE id = ?',
                    [$kind, $dir, $obj->size, $modified, $obj->etag, $start, $row['id']]
                );
                // Le fichier sera relu par ffprobe ; sa piste est retrouvée ensuite (même identifiant)
                $this->db->run('DELETE FROM track_files WHERE object_id = ?', [$row['id']]);
                $this->db->run('UPDATE images SET object_id = NULL WHERE object_id = ?', [$row['id']]);
                $job->count('changed');
                $dirty[$dir] = true;
                continue;
            }

            if ($row['missing_since'] !== null) {
                $this->db->run('UPDATE storage_objects SET missing_since = NULL, album_dir = ?, last_seen_at = ? WHERE id = ?', [$dir, $start, $row['id']]);
                $job->count('reappeared');
                $dirty[$dir] = true;
                continue;
            }
            $seenIds[] = (int) $row['id'];
            $job->count('unchanged');
        }
        if ($seenIds !== []) {
            $this->db->run(
                'UPDATE storage_objects SET last_seen_at = ? WHERE id IN (' . implode(',', array_fill(0, count($seenIds), '?')) . ')',
                [$start, ...$seenIds]
            );
        }
    }

    /**
     * Lit par ffprobe les fichiers audio pas encore lus (ou tous avec --force).
     * @param array<string, true> $dirty
     * @return array<int, true> objets lus pendant ce passage
     */
    private function probeAll(string $prefix, bool $force, array &$dirty, JobLog $job, callable $progress): array
    {
        $like = $this->likePrefix($prefix);
        $sql = "SELECT so.id, so.storage_key, so.album_dir FROM storage_objects so
                  LEFT JOIN track_files tf ON tf.object_id = so.id
                 WHERE so.kind = 'audio' AND so.missing_since IS NULL"
            . ($force ? '' : ' AND tf.id IS NULL')
            . ($like === null ? '' : ' AND so.storage_key LIKE ?');
        $todo = $this->db->all($sql, $like === null ? [] : [$like]);
        $fresh = [];
        foreach (array_chunk($todo, 100) as $chunk) {
            $sources = [];
            $byId = [];
            foreach ($chunk as $row) {
                try {
                    $sources[(int) $row['id']] = $this->storage->mediaSource((string) $row['storage_key'], 600);
                    $byId[(int) $row['id']] = $row;
                } catch (\Throwable $e) {
                    $job->error('unreadable', $e->getMessage(), (string) $row['storage_key'], (int) $row['id']);
                }
            }
            foreach ($this->probe->probeMany($sources) as $id => $res) {
                $row = $byId[$id];
                $dirty[(string) $row['album_dir']] = true;
                if (!$res['ok']) {
                    $job->error('probe_failed', $res['error'] ?? 'erreur inconnue', (string) $row['storage_key'], $id);
                    continue;
                }
                $i = $res['info'];
                $this->db->run(
                    'INSERT INTO track_files (object_id, format, lossless, bitrate_kbps, sample_rate_hz, bit_depth, channels, has_picture, duration_ms, tags_raw, probed_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP(3))
                     ON DUPLICATE KEY UPDATE format = VALUES(format), lossless = VALUES(lossless), bitrate_kbps = VALUES(bitrate_kbps),
                        sample_rate_hz = VALUES(sample_rate_hz), bit_depth = VALUES(bit_depth), channels = VALUES(channels),
                        has_picture = VALUES(has_picture), picture_sha256 = NULL, duration_ms = VALUES(duration_ms), tags_raw = VALUES(tags_raw), probed_at = VALUES(probed_at)',
                    [$id, $i['format'], (int) $i['lossless'], $i['bitrate_kbps'], $i['sample_rate_hz'], $i['bit_depth'], $i['channels'],
                        (int) $i['has_picture'], $i['duration_ms'], json_encode($i['tags'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)]
                );
                $fresh[$id] = true;
                $job->count('probed');
            }
            $progress(sprintf('%d / %d fichiers audio lus', count($fresh), count($todo)));
        }
        return $fresh;
    }

    private function likePrefix(string $prefix): ?string
    {
        return $prefix === '' ? null : addcslashes($prefix, '\\%_') . '%';
    }

    private static function sameTime(?string $a, ?string $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }
        return abs(strtotime($a . ' UTC') - strtotime($b . ' UTC')) < 1;
    }
}

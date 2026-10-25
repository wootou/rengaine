<?php
// Analyse audio en tâche de fond (AUD-01, AUD-02, étape « hash » ; file AUD-05).
//
// Prend les pistes de analysis_queue avec SELECT … FOR UPDATE SKIP LOCKED : plusieurs analyseurs
// peuvent tourner ensemble sans doublon, et une piste prise par un analyseur arrêté redevient
// disponible quand son verrou expire. Chaque piste est mesurée sur son meilleur fichier (sans perte
// d'abord, puis le plus haut débit). En mode S3, le fichier est téléchargé une seule fois dans le
// cache, mesuré, haché, puis effacé.
// BPM et tonalité (AUD-03, AUD-04) : tags d'abord, puis Essentia s'il est installé (voir Rhythm).
// Forme d'onde (AUD-07) : relevée par la mesure ffmpeg ; grilles des temps (LEC-06) : par Essentia.
// Pochette intégrée (CAT-04) : son empreinte est relevée avec celle du fichier ; quand tout l'album est
// relevé, une piste dont la pochette diffère de la majorité reçoit sa propre image (Images::trackImages).
//
// Ordre de la file (AUD-05) : recalculé à chaque passage, du plus urgent au moins urgent :
//   5   dans la file de lecture d'un compte (modifiée depuis 7 jours) : elle va être jouée ;
//   10  favori (la piste, son album ou un de ses artistes) ou dans une playlist ;
//   20  écoutée depuis 90 jours ;
//   100 le reste. Une autre valeur posée à la main n'est pas touchée.

declare(strict_types=1);

namespace Musique\Audio;

use Musique\Db\Database;
use Musique\Jobs\JobLog;
use Musique\Library\Images;
use Musique\Library\SameRecordings;
use Musique\Log\Logger;
use Musique\Storage\Storage;

final class Analyzer
{
    /** Étapes faites ici (bits du SET analysis_queue.steps) : loudness=1, silence=2, hash=64. */
    public const STEPS_DONE = 1 | 2 | 64;
    /** bpm=4, key=8, energy=16 : faites ensemble par Essentia, seulement s'il est installé. */
    public const STEPS_RHYTHM = 4 | 8 | 16;
    /** fingerprint=32 : empreinte acoustique (BAS-03), seulement si fpcalc est installé. */
    public const STEP_FINGERPRINT = 32;
    /** Au-delà, une piste reste dans la file en échec (voir `rengaine analyze --retry`). */
    public const MAX_ATTEMPTS = 3;
    private const LOCK_MINUTES = 30;
    public const PRIORITY_QUEUED = 5;
    public const PRIORITY_FAVORITE = 10;
    public const PRIORITY_PLAYED = 20;
    public const PRIORITY_DEFAULT = 100;

    private string $worker;
    private ?string $version = null;
    /** Étapes que cet analyseur sait faire. */
    private int $mask;

    public function __construct(
        private readonly Database $db,
        private readonly Storage $storage,
        private readonly Logger $logger,
        private readonly string $tmpDir,
        private readonly int $parallel = 2,
        private readonly int $timeoutSeconds = 900,
        private readonly ?Rhythm $rhythm = null,
        private readonly ?Fingerprint $fingerprint = null,
    ) {
        $this->worker = gethostname() . ':' . getmypid();
        $this->mask = self::STEPS_DONE | ($rhythm !== null ? self::STEPS_RHYTHM : 0) | ($fingerprint !== null ? self::STEP_FINGERPRINT : 0);
    }

    /** Remet en file les pistes abandonnées après trop d'échecs. */
    public function retryFailed(): int
    {
        return $this->db->run('UPDATE analysis_queue SET attempts = 0, last_error = NULL WHERE attempts >= ?', [self::MAX_ATTEMPTS])->rowCount();
    }

    /** Recalcule l'ordre des pistes qui restent à analyser ici (voir en tête). Renvoie le nombre de pistes déplacées. */
    public function prioritize(): int
    {
        $managed = implode(', ', [self::PRIORITY_QUEUED, self::PRIORITY_FAVORITE, self::PRIORITY_PLAYED, self::PRIORITY_DEFAULT]);
        return $this->db->run(
            // Les files de lecture sont lues une seule fois (jointure), pas pour chaque piste.
            "UPDATE analysis_queue q
               LEFT JOIN (SELECT DISTINCT jt.id FROM play_queues pq,
                              JSON_TABLE(pq.track_ids, '$[*]' COLUMNS (id BIGINT UNSIGNED PATH '$')) jt
                           WHERE pq.updated_at > CURRENT_TIMESTAMP(3) - INTERVAL 7 DAY) lq ON lq.id = q.track_id
             SET q.priority = CASE
                WHEN lq.id IS NOT NULL THEN " . self::PRIORITY_QUEUED . "
                WHEN EXISTS (SELECT 1 FROM favorites f WHERE f.item_type = 'track' AND f.item_id = q.track_id)
                  OR EXISTS (SELECT 1 FROM tracks t JOIN favorites f ON f.item_type = 'album' AND f.item_id = t.album_id WHERE t.id = q.track_id)
                  OR EXISTS (SELECT 1 FROM track_artists ta JOIN favorites f ON f.item_type = 'artist' AND f.item_id = ta.artist_id WHERE ta.track_id = q.track_id)
                  OR EXISTS (SELECT 1 FROM tracks t JOIN albums a ON a.id = t.album_id
                               JOIN favorites f ON f.item_type = 'artist' AND f.item_id = a.album_artist_id WHERE t.id = q.track_id)
                  OR EXISTS (SELECT 1 FROM playlist_items pi WHERE pi.track_id = q.track_id)
                    THEN " . self::PRIORITY_FAVORITE . "
                WHEN EXISTS (SELECT 1 FROM play_history h WHERE h.track_id = q.track_id AND h.started_at > CURRENT_TIMESTAMP(3) - INTERVAL 90 DAY)
                    THEN " . self::PRIORITY_PLAYED . "
                ELSE " . self::PRIORITY_DEFAULT . "
             END
             WHERE q.priority IN ($managed) AND (q.steps & ?) <> 0",
            [$this->mask]
        )->rowCount();
    }

    /**
     * Traite la file jusqu'à ce qu'elle soit vide (ou $limit pistes).
     * @param ?callable(string): void $progress
     * @return array<string, int>
     */
    public function run(?int $limit = null, ?callable $progress = null, ?int $queuedJobId = null): array
    {
        $progress ??= static function (string $msg): void {};
        if (!is_dir($this->tmpDir) && !@mkdir($this->tmpDir, 0750, true) && !is_dir($this->tmpDir)) {
            throw new \RuntimeException("Dossier temporaire impossible à créer : {$this->tmpDir}");
        }
        $this->cleanTmp();
        $this->prioritize();
        // File vide : pas de ligne dans le journal des tâches (le minuteur passe toutes les 10 min)
        if ($this->db->value(
            'SELECT 1 FROM analysis_queue WHERE (steps & ?) <> 0 AND attempts < ?
               AND (locked_until IS NULL OR locked_until < CURRENT_TIMESTAMP(3)) LIMIT 1',
            [$this->mask, self::MAX_ATTEMPTS]
        ) === null) {
            if ($queuedJobId !== null) {
                // Demandée depuis l'admin : on clôt la tâche, même s'il n'y avait rien à faire
                (new JobLog($this->db, $this->logger, 'analyze', [], null, $queuedJobId))->finish();
            }
            return [];
        }
        $job = new JobLog($this->db, $this->logger, 'analyze', array_filter(['limit' => $limit]), null, $queuedJobId);
        $t0 = microtime(true);
        $claimed = 0;
        /** @var array<int, array<string, mixed>> $running piste → tâche */
        $running = [];
        try {
            $empty = false;
            while (true) {
                while (!$empty && count($running) < $this->parallel && ($limit === null || $claimed < $limit)) {
                    $task = $this->claim($job);
                    if ($task === null) {
                        $empty = true;
                        break;
                    }
                    $claimed++;
                    if ($task !== false) {
                        $running[$task['track_id']] = $task;
                        $this->next($running[$task['track_id']]);
                    }
                }
                if ($running === []) {
                    break;
                }
                $this->wait($running);
                foreach ($running as $tid => &$task) {
                    if (!$this->poll($task)) {
                        continue;
                    }
                    if ($task['error'] === null && $task['steps'] !== []) {
                        $this->next($task);
                        if ($task['error'] === null) {
                            continue;
                        }
                    }
                    $this->finish($task, $job);
                    unset($running[$tid]);
                    $done = ($job->stats()['analyzed'] ?? 0) + ($job->stats()['errors'] ?? 0);
                    if ($done % 25 === 0) {
                        $progress(sprintf('%d pistes traitées', $done));
                    }
                }
                unset($task);
            }
            $job->count('duration_s', (int) round(microtime(true) - $t0));
            $job->finish();
            return $job->stats();
        } catch (\Throwable $e) {
            foreach ($running as $task) {
                $this->kill($task);
                $this->release((int) $task['track_id'], 'interrompu : ' . $e->getMessage());
            }
            $job->fail($e);
            throw $e;
        }
    }

    // ------------------------------------------------------------------ file

    /**
     * Prend la prochaine piste de la file. null = file vide ; false = piste écartée (rien à mesurer).
     * @return array<string, mixed>|null|false
     */
    private function claim(JobLog $job): array|null|false
    {
        $row = $this->db->transaction(function (): ?array {
            $row = $this->db->one(
                'SELECT track_id, enqueued_at, steps + 0 AS bits FROM analysis_queue
                  WHERE (steps & ?) <> 0 AND attempts < ?
                    AND (locked_until IS NULL OR locked_until < CURRENT_TIMESTAMP(3))
                  ORDER BY priority, enqueued_at LIMIT 1 FOR UPDATE SKIP LOCKED',
                [$this->mask, self::MAX_ATTEMPTS]
            );
            if ($row !== null) {
                $this->db->run(
                    'UPDATE analysis_queue SET locked_by = ?, locked_until = CURRENT_TIMESTAMP(3) + INTERVAL ' . self::LOCK_MINUTES . ' MINUTE,
                            attempts = attempts + 1 WHERE track_id = ?',
                    [$this->worker, $row['track_id']]
                );
            }
            return $row;
        });
        if ($row === null) {
            return null;
        }
        $trackId = (int) $row['track_id'];
        $files = $this->db->all(
            'SELECT tf.id AS file_id, tf.object_id, tf.duration_ms, tf.sample_rate_hz, tf.tags_raw, so.storage_key, so.size_bytes, so.content_sha256 IS NULL AS need_hash,
                    tf.has_picture AND tf.picture_sha256 IS NULL AS need_picture
               FROM track_files tf JOIN storage_objects so ON so.id = tf.object_id
               JOIN tracks t ON t.id = tf.track_id
              WHERE tf.track_id = ? AND so.missing_since IS NULL AND t.missing_since IS NULL
              ORDER BY tf.lossless DESC, tf.bitrate_kbps DESC, tf.id',
            [$trackId]
        );
        if ($files === []) {
            // Piste disparue : elle sera remise en file si son fichier revient
            $this->db->run('DELETE FROM analysis_queue WHERE track_id = ?', [$trackId]);
            $job->count('skipped');
            return false;
        }

        $s3 = $this->storage->mode() === 's3';
        $best = $files[0];
        $bits = (int) $row['bits'];
        $needMeasure = ($bits & (1 | 2)) !== 0 || $this->db->value('SELECT 1 FROM track_analysis WHERE track_id = ?', [$trackId]) === null;
        $needRhythm = $this->rhythm !== null && ($bits & self::STEPS_RHYTHM) !== 0;
        $needFingerprint = $this->fingerprint !== null && ($bits & self::STEP_FINGERPRINT) !== 0;
        $task = [
            'track_id' => $trackId, 'enqueued_at' => $row['enqueued_at'], 'best' => $best,
            'steps' => [], 'proc' => null, 'error' => null, 'result' => null, 'rhythm' => null, 'fingerprint' => null, 'hashes' => [], 'pictures' => [], 'tmp' => [],
            'measure' => $needMeasure, 'tags' => self::tagValues($files),
        ];
        // 1. Fichier mesuré : téléchargé (S3) puis lu par ffmpeg, et haché au passage
        $source = $this->storage->mediaSource((string) $best['storage_key']);
        if ($s3) {
            $tmp = $this->tmpName();
            $task['steps'][] = ['download', $source, $tmp, $best];
            $source = $tmp;
        }
        if ($needMeasure) {
            $task['steps'][] = ['measure', $source, $best];
        }
        if ($needRhythm) {
            $task['steps'][] = ['rhythm', $source, $best];
        }
        if ($needFingerprint) {
            $task['steps'][] = ['fingerprint', $source, $best];
        }
        if ((bool) $best['need_picture']) {
            $task['steps'][] = ['picture', $source, $best];
        }
        $task['steps'][] = ['hash', $source, $best];
        // 2. Autres fichiers de la piste (le MP3 à côté du FLAC) : seulement l'empreinte
        foreach (array_slice($files, 1) as $f) {
            if (!(bool) $f['need_hash'] && !(bool) $f['need_picture']) {
                continue;
            }
            $src = $this->storage->mediaSource((string) $f['storage_key']);
            if ($s3) {
                $tmp = $this->tmpName();
                $task['steps'][] = ['download', $src, $tmp, $f];
                $src = $tmp;
            }
            if ((bool) $f['need_picture']) {
                $task['steps'][] = ['picture', $src, $f];
            }
            $task['steps'][] = ['hash', $src, $f];
        }
        return $task;
    }

    /** Enregistre le résultat, ou l'erreur. */
    private function finish(array $task, JobLog $job): void
    {
        $this->cleanup($task);
        $trackId = (int) $task['track_id'];
        if ($task['error'] !== null) {
            $msg = self::scrub((string) $task['error']);
            $this->release($trackId, $msg);
            $job->error('analysis_failed', $msg, (string) $task['best']['storage_key'], (int) $task['best']['object_id']);
            return;
        }
        $r = $task['result'];
        $saved = $this->db->transaction(function () use ($task, $trackId, $r): bool {
            $q = $this->db->one('SELECT enqueued_at FROM analysis_queue WHERE track_id = ? FOR UPDATE', [$trackId]);
            if ($q === null || $q['enqueued_at'] !== $task['enqueued_at']) {
                // Fichier modifié pendant la mesure : l'indexeur a remis la piste en file, on recommencera
                $this->db->run('UPDATE analysis_queue SET locked_by = NULL, locked_until = NULL, attempts = 0 WHERE track_id = ?', [$trackId]);
                return false;
            }
            foreach ($task['pictures'] as $fileId => $pic) {
                $this->db->run('UPDATE track_files SET picture_sha256 = ? WHERE id = ?', [$pic, $fileId]);
            }
            foreach ($task['hashes'] as $objectId => [$sha, $size]) {
                $this->db->run('UPDATE storage_objects SET content_sha256 = UNHEX(?) WHERE id = ? AND size_bytes = ?', [$sha, $objectId, $size]);
            }
            if ($task['measure']) {
                $this->db->run(
                    'INSERT INTO track_analysis (track_id, file_id, loudness_lufs, true_peak_dbtp, loudness_range_lu, audio_start_ms, audio_end_ms,
                        waveform, waveform_step_ms, decode_errors, decode_detail, analyzer_version, analyzed_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP(3))
                     ON DUPLICATE KEY UPDATE file_id = VALUES(file_id), loudness_lufs = VALUES(loudness_lufs), true_peak_dbtp = VALUES(true_peak_dbtp),
                       loudness_range_lu = VALUES(loudness_range_lu), audio_start_ms = VALUES(audio_start_ms), audio_end_ms = VALUES(audio_end_ms),
                       waveform = VALUES(waveform), waveform_step_ms = VALUES(waveform_step_ms),
                       decode_errors = VALUES(decode_errors), decode_detail = VALUES(decode_detail),
                       analyzer_version = VALUES(analyzer_version), analyzed_at = VALUES(analyzed_at)',
                    [$trackId, $task['best']['file_id'], $r['loudness_lufs'], $r['true_peak_dbtp'], $r['loudness_range_lu'],
                        $r['audio_start_ms'], $r['audio_end_ms'], $r['waveform'] ?? null,
                        ($r['waveform'] ?? null) !== null ? Measure::waveStepMs((int) $task['best']['duration_ms'], isset($task['best']['sample_rate_hz']) ? (int) $task['best']['sample_rate_hz'] : null) : null,
                        $r['decode_errors'] ?? 0, $r['decode_detail'] ?? null, $this->version()]
                );
            }
            $this->saveRhythm($trackId, $task['tags'], $task['rhythm']);
            if ($task['fingerprint'] !== null) {
                (new SameRecordings($this->db))->save($trackId, $task['fingerprint']);
            }
            $this->db->run(
                'UPDATE analysis_queue SET steps = steps & ~?, attempts = 0, last_error = NULL, locked_by = NULL, locked_until = NULL WHERE track_id = ?',
                [$this->mask, $trackId]
            );
            $this->db->run('DELETE FROM analysis_queue WHERE track_id = ? AND steps + 0 = 0', [$trackId]);
            $albumId = $this->db->value('SELECT album_id FROM tracks WHERE id = ?', [$trackId]);
            if ($albumId !== null) {
                $this->refreshAlbum((int) $albumId);
            }
            return true;
        });
        $job->count($saved ? 'analyzed' : 'requeued');
        if ($saved && $task['pictures'] !== []) {
            // Hors transaction : peut relire une pochette dans le stockage
            $albumId = $this->db->value('SELECT album_id FROM tracks WHERE id = ?', [$trackId]);
            try {
                if ($albumId !== null && ($n = (new Images($this->db, $this->storage, dirname($this->tmpDir)))->trackImages((int) $albumId)) > 0) {
                    $job->count('track_images', $n);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Images des pistes', ['album' => $albumId, 'exception' => $e]);
            }
        }
        if ($saved && $task['rhythm'] !== null) {
            $job->count('bpm_key');
        }
        if ($saved && $task['fingerprint'] !== null) {
            $job->count('fingerprints');
        }
        if ($saved && ($r['silent'] ?? false)) {
            $job->count('silent');
        }
        if ($saved && ($r['decode_errors'] ?? 0) > 0) {
            $job->count('damaged');
            $this->logger->warning('Fichier abîmé', ['key' => (string) $task['best']['storage_key'], 'errors' => $r['decode_errors'], 'detail' => $r['decode_detail']]);
        }
        $job->count('hashed', count($task['hashes']));
    }

    /**
     * BPM et tonalité des tags des fichiers de la piste (le meilleur fichier d'abord).
     * @param list<array<string, mixed>> $files
     * @return array{bpm: ?float, key: ?string}
     */
    private static function tagValues(array $files): array
    {
        $out = ['bpm' => null, 'key' => null];
        foreach ($files as $f) {
            $tags = json_decode((string) ($f['tags_raw'] ?? ''), true);
            if (!is_array($tags)) {
                continue;
            }
            $out['bpm'] ??= Rhythm::tagBpm($tags['bpm'] ?? null);
            $out['key'] ??= Rhythm::normalizeKey(is_string($tags['key'] ?? null) ? $tags['key'] : null);
        }
        return $out;
    }

    /**
     * Enregistre BPM et tonalité : tag, sinon analyse. Une correction à la main n'est jamais remplacée.
     * @param array{bpm: ?float, key: ?string} $tags
     * @param ?array{bpm: ?float, confidence: float, key: ?string, strength: float, energy: ?float, danceability: ?float, grid: ?array<string, mixed>} $analysis
     */
    private function saveRhythm(int $trackId, array $tags, ?array $analysis): void
    {
        if ($analysis !== null) {
            $this->db->run('INSERT IGNORE INTO track_analysis (track_id) VALUES (?)', [$trackId]);
            $this->db->run('UPDATE track_analysis SET energy = ?, danceability = ?, beat_grid = ? WHERE track_id = ?',
                [$analysis['energy'], $analysis['danceability'], $analysis['grid'] !== null ? json_encode($analysis['grid']) : null, $trackId]);
        }
        [$bpm, $bpmSource] = $tags['bpm'] !== null ? [$tags['bpm'], 'tag'] : [$analysis['bpm'] ?? null, ($analysis['bpm'] ?? null) !== null ? 'analysis' : null];
        [$key, $keySource] = $tags['key'] !== null ? [$tags['key'], 'tag'] : [$analysis['key'] ?? null, ($analysis['key'] ?? null) !== null ? 'analysis' : null];
        if ($bpmSource === null && $keySource === null) {
            return;
        }
        $this->db->run('INSERT IGNORE INTO track_analysis (track_id) VALUES (?)', [$trackId]);
        if ($bpmSource !== null) {
            $this->db->run("UPDATE track_analysis SET bpm = ?, bpm_source = ? WHERE track_id = ? AND (bpm_source IS NULL OR bpm_source <> 'manual')",
                [$bpm, $bpmSource, $trackId]);
        }
        if ($keySource !== null) {
            $this->db->run("UPDATE track_analysis SET musical_key = ?, camelot = ?, key_source = ? WHERE track_id = ? AND (key_source IS NULL OR key_source <> 'manual')",
                [$key, Rhythm::camelot($key), $keySource, $trackId]);
        }
    }

    /** Volume et crête de l'album, quand toutes ses pistes sont mesurées. */
    public function refreshAlbum(int $albumId): void
    {
        $pending = (int) $this->db->value(
            'SELECT COUNT(*) FROM analysis_queue q JOIN tracks t ON t.id = q.track_id
              WHERE t.album_id = ? AND t.missing_since IS NULL AND (q.steps & 1) <> 0 AND q.attempts < ?',
            [$albumId, self::MAX_ATTEMPTS]
        );
        if ($pending > 0) {
            return;
        }
        $rows = $this->db->all(
            'SELECT ta.loudness_lufs, ta.true_peak_dbtp, t.duration_ms FROM tracks t JOIN track_analysis ta ON ta.track_id = t.id
              WHERE t.album_id = ? AND t.missing_since IS NULL',
            [$albumId]
        );
        $tracks = [];
        $peak = null;
        foreach ($rows as $row) {
            if ($row['loudness_lufs'] !== null) {
                $tracks[] = ['lufs' => (float) $row['loudness_lufs'], 'ms' => (int) $row['duration_ms']];
            }
            if ($row['true_peak_dbtp'] !== null) {
                $peak = max($peak ?? -INF, (float) $row['true_peak_dbtp']);
            }
        }
        $this->db->run('UPDATE albums SET loudness_lufs = ?, true_peak_dbtp = ? WHERE id = ?',
            [Measure::albumLoudness($tracks), $peak, $albumId]);
    }

    private function release(int $trackId, string $error): void
    {
        $this->db->run('UPDATE analysis_queue SET last_error = ?, locked_by = NULL, locked_until = NULL WHERE track_id = ?',
            [mb_substr($error, 0, 2000), $trackId]);
    }

    // ------------------------------------------------------------------ étapes

    /** Lance l'étape suivante de la tâche (les étapes PHP s'enchaînent tout de suite). */
    private function next(array &$task): void
    {
        while ($task['steps'] !== [] && $task['proc'] === null && $task['error'] === null) {
            $step = array_shift($task['steps']);
            try {
                switch ($step[0]) {
                    case 'download':
                        $task['tmp'][] = $step[2];
                        $this->start($task, ['curl', '-sS', '-f', '--retry', '2', '--max-time', (string) $this->timeoutSeconds, '-o', $step[2], $step[1]], $step);
                        break;
                    case 'measure':
                        $this->start($task, Measure::command($step[1], (int) $step[2]['duration_ms'], isset($step[2]['sample_rate_hz']) ? (int) $step[2]['sample_rate_hz'] : null), $step);
                        break;
                    case 'rhythm':
                        $this->start($task, $this->rhythm->command($step[1]), $step);
                        break;
                    case 'fingerprint':
                        $this->start($task, $this->fingerprint->command($step[1]), $step);
                        break;
                    case 'picture':
                        // Rapide (quelques Ko au début du fichier) : fait sur place, sans processus d'attente
                        $bytes = Images::extractEmbedded($step[1]);
                        $task['pictures'][(int) $step[2]['file_id']] = $bytes === null ? str_repeat("\0", 32) : hash('sha256', $bytes, true);
                        break;
                    case 'hash':
                        $f = $step[2];
                        $size = @filesize($step[1]);
                        if ($size !== (int) $f['size_bytes']) {
                            throw new \RuntimeException('taille différente de celle de l’index : fichier modifié depuis le dernier passage');
                        }
                        $task['hashes'][(int) $f['object_id']] = [hash_file('sha256', $step[1]), $size];
                        if (in_array($step[1], $task['tmp'], true)) {
                            @unlink($step[1]); // fichier secondaire : plus utile
                        }
                        break;
                }
            } catch (\Throwable $e) {
                $task['error'] = $e->getMessage();
            }
        }
    }

    private function start(array &$task, array $cmd, array $step): void
    {
        // Les étapes « rhythm » et « fingerprint » répondent sur la sortie standard (JSON)
        $out = in_array($step[0], ['rhythm', 'fingerprint'], true) ? ['pipe', 'w'] : ['file', '/dev/null', 'w'];
        $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => $out, 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            throw new \RuntimeException($cmd[0] . ' ne démarre pas');
        }
        stream_set_blocking($pipes[2], false);
        if (isset($pipes[1])) {
            stream_set_blocking($pipes[1], false);
        }
        $task['proc'] = ['res' => $proc, 'err' => $pipes[2], 'out' => $pipes[1] ?? null, 'stdout' => '', 'stderr' => '', 'start' => microtime(true), 'step' => $step];
    }

    /** Attend qu'un processus écrive ou se termine (au plus 0,2 s). */
    private function wait(array $running): void
    {
        $read = [];
        foreach ($running as $t) {
            if ($t['proc'] !== null) {
                $read[] = $t['proc']['err'];
            }
        }
        $w = $e = null;
        if ($read !== []) {
            @stream_select($read, $w, $e, 0, 200000);
        }
    }

    /** true quand l'étape en cours est finie (ou qu'il n'y en a pas). */
    private function poll(array &$task): bool
    {
        $p = &$task['proc'];
        if ($p === null) {
            return true;
        }
        $p['stderr'] .= (string) stream_get_contents($p['err']);
        if ($p['out'] !== null) {
            $p['stdout'] .= (string) stream_get_contents($p['out']);
        }
        if (strlen($p['stderr']) > 1_000_000) {
            // Le résumé est à la fin ; les trames illisibles de la partie jetée sont comptées avant
            $dropped = Measure::decodeErrors(substr($p['stderr'], 0, -200_000), (int) ($p['step'][2]['duration_ms'] ?? 0));
            $p['dropped'] = ['decode_errors' => ($p['dropped']['decode_errors'] ?? 0) + $dropped['decode_errors'],
                'decode_detail' => $p['dropped']['decode_detail'] ?? $dropped['decode_detail']];
            $p['stderr'] = substr($p['stderr'], -200_000);
        }
        $status = proc_get_status($p['res']);
        $timedOut = microtime(true) - $p['start'] > $this->timeoutSeconds;
        if ($status['running'] && !$timedOut) {
            return false;
        }
        if ($status['running']) {
            proc_terminate($p['res'], 9);
        }
        $p['stderr'] .= (string) stream_get_contents($p['err']);
        fclose($p['err']);
        if ($p['out'] !== null) {
            $p['stdout'] .= (string) stream_get_contents($p['out']);
            fclose($p['out']);
        }
        $code = proc_close($p['res']);
        $code = $status['running'] ? -1 : ($status['exitcode'] >= 0 ? $status['exitcode'] : $code);
        $step = $p['step'];
        $stderr = $p['stderr'];
        $dropped = $p['dropped'] ?? null;
        $stdout = $p['stdout'];
        $task['proc'] = null;

        if ($timedOut) {
            $task['error'] = "$step[0] : délai dépassé ({$this->timeoutSeconds} s)";
        } elseif ($code !== 0) {
            $task['error'] = "$step[0] : code $code — " . trim(mb_substr(trim($stderr), -500));
        } elseif ($step[0] === 'measure') {
            try {
                $task['result'] = Measure::parse($stderr, (int) $step[2]['duration_ms']);
                if ($dropped !== null) {
                    $task['result']['decode_errors'] += $dropped['decode_errors'];
                    $task['result']['decode_detail'] = $dropped['decode_detail'] ?? $task['result']['decode_detail'];
                }
            } catch (\Throwable $e) {
                $task['error'] = 'measure : ' . $e->getMessage();
            }
        } elseif ($step[0] === 'rhythm') {
            try {
                $task['rhythm'] = Rhythm::parse($stdout);
            } catch (\Throwable $e) {
                $task['error'] = 'rhythm : ' . $e->getMessage();
            }
        } elseif ($step[0] === 'fingerprint') {
            try {
                $task['fingerprint'] = Fingerprint::parse($stdout);
            } catch (\Throwable $e) {
                $task['error'] = 'fingerprint : ' . $e->getMessage();
            }
        }
        return true;
    }

    private function kill(array $task): void
    {
        if ($task['proc'] !== null) {
            proc_terminate($task['proc']['res'], 9);
            @fclose($task['proc']['err']);
            if ($task['proc']['out'] !== null) {
                @fclose($task['proc']['out']);
            }
            @proc_close($task['proc']['res']);
        }
        $this->cleanup($task);
    }

    private function cleanup(array $task): void
    {
        foreach ($task['tmp'] as $f) {
            @unlink($f);
        }
    }

    // ------------------------------------------------------------------ divers

    private function tmpName(): string
    {
        return $this->tmpDir . '/analyse-' . getmypid() . '-' . bin2hex(random_bytes(6));
    }

    /** Fichiers laissés par un analyseur arrêté brutalement. */
    private function cleanTmp(): void
    {
        foreach (glob($this->tmpDir . '/analyse-*') ?: [] as $f) {
            if (@filemtime($f) < time() - 6 * 3600) {
                @unlink($f);
            }
        }
    }

    /** Version de l'outil + méthode : permet de remesurer quand l'un des deux change. */
    private function version(): string
    {
        if ($this->version === null) {
            $out = (string) @shell_exec('ffmpeg -hide_banner -version 2>/dev/null');
            $v = preg_match('/ffmpeg version (\S+)/', $out, $m) ? $m[1] : '?';
            $this->version = mb_substr("ffmpeg $v;" . Measure::METHOD, 0, 50);
        }
        return $this->version;
    }

    /** Les adresses signées ne sortent jamais dans les messages d'erreur. */
    private static function scrub(string $message): string
    {
        return (string) preg_replace('#https?://\S+#', '<adresse>', $message);
    }
}

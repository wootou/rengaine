<?php
// MP3 en double (LEC-05) : quand une piste existe aussi sans perte (FLAC, ALAC, WAV), c'est ce
// fichier qui est servi, et converti à la volée au besoin (LEC-04). Le MP3 ne fait plus que
// prendre de la place.
//   - Un MP3 n'est retirable que si son fichier sans perte est présent et de même durée (à 2 s
//     près) : un écart signale souvent une autre version (live, édition radio).
//   - On retire album par album, sur décision de l'admin (tâche `dedup`).
//   - Retirer = déplacer dans la corbeille du stockage (`corbeille/AAAA-MM-JJ/<clé d'origine>`),
//     jamais supprimer : `rengaine corbeille:restaurer` les remet en place. La corbeille n'est
//     jamais indexée et ne se vide qu'à la main (`rengaine corbeille:vider`).

declare(strict_types=1);

namespace Musique\Library;

use Musique\Db\Database;
use Musique\Jobs\JobLog;
use Musique\Log\Logger;
use Musique\Storage\Storage;
use Musique\Storage\StorageException;

final class Duplicates
{
    public const TRASH = 'corbeille/';
    public const MAX_GAP_MS = 2000;

    public function __construct(
        private readonly Database $db,
        private readonly Storage $storage,
        private readonly Logger $logger,
    ) {
    }

    public static function inTrash(string $key): bool
    {
        return str_starts_with($key, self::TRASH);
    }

    /**
     * MP3 qui ont un double sans perte, avec le verdict (retirable ou gardé, et pourquoi).
     * @return list<array<string, mixed>>
     */
    public function files(?int $albumId = null): array
    {
        $rows = $this->db->all(
            "SELECT t.id AS track_id, t.album_id, t.disc_number, t.track_number, t.title,
                    m.id AS mp3_file_id, m.object_id AS mp3_object_id, mo.storage_key AS mp3_key, mo.size_bytes AS mp3_size,
                    m.duration_ms AS mp3_duration, m.bitrate_kbps AS mp3_bitrate,
                    l.format AS ll_format, lo.storage_key AS ll_key, l.duration_ms AS ll_duration, lo.missing_since AS ll_missing
               FROM track_files m
               JOIN storage_objects mo ON mo.id = m.object_id AND mo.missing_since IS NULL
               JOIN tracks t ON t.id = m.track_id
               JOIN track_files l ON l.id = (
                    SELECT l2.id FROM track_files l2 JOIN storage_objects o2 ON o2.id = l2.object_id
                     WHERE l2.track_id = t.id AND l2.lossless = 1
                     ORDER BY o2.missing_since IS NOT NULL, l2.id LIMIT 1)
               JOIN storage_objects lo ON lo.id = l.object_id
              WHERE m.format = 'mp3'" . ($albumId === null ? '' : ' AND t.album_id = ?') . '
              ORDER BY t.album_id, t.disc_number, t.track_number, t.id, m.id',
            $albumId === null ? [] : [$albumId]
        );
        $out = [];
        foreach ($rows as $r) {
            $reason = null;
            $gap = abs((int) $r['mp3_duration'] - (int) $r['ll_duration']);
            if ($r['ll_missing'] !== null) {
                $reason = 'fichier sans perte absent du stockage';
            } elseif ((int) $r['ll_duration'] <= 0) {
                $reason = 'fichier sans perte illisible (durée inconnue)';
            } elseif ($gap > self::MAX_GAP_MS) {
                $reason = sprintf('durées différentes (%s s) : peut-être une autre version', number_format($gap / 1000, $gap < 10000 ? 1 : 0, ',', ''));
            }
            $r['removable'] = $reason === null;
            $r['reason'] = $reason;
            $out[] = $r;
        }
        return $out;
    }

    /**
     * Résumé par album, ceux qui libèrent le plus de place d'abord.
     * @return list<array{albumId: int, title: string, artist: ?string, removable: int, kept: int, removableBytes: int}>
     */
    public function albums(): array
    {
        $by = [];
        foreach ($this->files() as $f) {
            $id = (int) $f['album_id'];
            $by[$id] ??= ['albumId' => $id, 'title' => '', 'artist' => null, 'removable' => 0, 'kept' => 0, 'removableBytes' => 0];
            if ($f['removable']) {
                $by[$id]['removable']++;
                $by[$id]['removableBytes'] += (int) $f['mp3_size'];
            } else {
                $by[$id]['kept']++;
            }
        }
        if ($by !== []) {
            $names = $this->db->all(
                'SELECT a.id, a.title, ar.name AS artist FROM albums a LEFT JOIN artists ar ON ar.id = a.album_artist_id
                  WHERE a.id IN (' . implode(',', array_keys($by)) . ')'
            );
            foreach ($names as $n) {
                $by[(int) $n['id']]['title'] = (string) $n['title'];
                $by[(int) $n['id']]['artist'] = $n['artist'];
            }
        }
        $list = array_values($by);
        usort($list, static fn(array $a, array $b): int => [$b['removableBytes'], $a['title']] <=> [$a['removableBytes'], $b['title']]);
        return $list;
    }

    /**
     * Retire les MP3 retirables d'un album (verdict recalculé au moment de la tâche).
     * @param ?callable(string): void $progress
     * @return array{moved: int, bytes: int, kept: int, failed: int, trash: string}
     */
    public function remove(int $albumId, ?int $requestedBy = null, ?callable $progress = null, ?int $queuedJobId = null): array
    {
        $job = new JobLog($this->db, $this->logger, 'dedup', ['albumId' => $albumId], $requestedBy, $queuedJobId);
        $trash = self::TRASH . gmdate('Y-m-d') . '/';
        $res = ['moved' => 0, 'bytes' => 0, 'kept' => 0, 'failed' => 0, 'trash' => $trash];
        $moved = [];
        try {
            foreach ($this->files($albumId) as $f) {
                if (!$f['removable']) {
                    $res['kept']++;
                    continue;
                }
                $from = (string) $f['mp3_key'];
                $to = $trash . $from;
                try {
                    if ($this->storage->stat($to) !== null) {
                        throw new StorageException(StorageException::FAILED, "Déjà dans la corbeille : $to");
                    }
                    $this->storage->move($from, $to);
                } catch (StorageException $e) {
                    $res['failed']++;
                    $job->error('dedup_failed', $e->getMessage(), $from, (int) $f['mp3_object_id']);
                    continue;
                }
                // L'objet n'existe plus à cette clé : sa ligne part, et le fichier de la piste avec (cascade)
                $this->db->run('DELETE FROM storage_objects WHERE id = ?', [(int) $f['mp3_object_id']]);
                $res['moved']++;
                $res['bytes'] += (int) $f['mp3_size'];
                $moved[] = $from;
                $job->count('mp3_moved');
                $progress && $progress("  → corbeille : $from");
            }
            $job->count('mp3_kept', $res['kept']);
            $job->count('bytes_freed', $res['bytes']);
            if ($moved !== []) {
                $this->db->run(
                    "INSERT INTO audit_log (actor_user_id, action, target_type, target_id, after_value) VALUES (?, 'library.dedup', 'album', ?, ?)",
                    [$requestedBy, $albumId, json_encode(['trash' => $trash, 'keys' => $moved, 'bytes' => $res['bytes']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
                );
            }
            $job->finish();
        } catch (\Throwable $e) {
            $job->fail($e);
            throw $e;
        }
        $this->logger->info('MP3 en double retirés', ['album' => $albumId] + $res);
        return $res;
    }

    /**
     * Contenu de la corbeille, par jour.
     * @return array<string, array{files: int, bytes: int}>
     */
    public function trash(): array
    {
        $days = [];
        foreach ($this->storage->list(self::TRASH) as $obj) {
            $day = explode('/', substr($obj->key, strlen(self::TRASH)), 2)[0];
            $days[$day] ??= ['files' => 0, 'bytes' => 0];
            $days[$day]['files']++;
            $days[$day]['bytes'] += $obj->size;
        }
        ksort($days);
        return $days;
    }

    /**
     * Remet en place les fichiers d'un jour de corbeille (tous, ou ceux sous un dossier d'origine).
     * Un fichier dont la place est reprise reste dans la corbeille. Lancer ensuite l'indexation.
     * @return array{restored: int, skipped: list<string>}
     */
    public function restore(string $day, string $prefix = ''): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            throw new \InvalidArgumentException('Jour attendu au format AAAA-MM-JJ');
        }
        $base = self::TRASH . $day . '/';
        $out = ['restored' => 0, 'skipped' => []];
        foreach ($this->storage->list($base . ltrim($prefix, '/')) as $obj) {
            $original = substr($obj->key, strlen($base));
            if ($this->storage->stat($original) !== null) {
                $out['skipped'][] = $original;
                continue;
            }
            $this->storage->move($obj->key, $original);
            $out['restored']++;
        }
        $this->logger->info('Corbeille restaurée', ['day' => $day, 'prefix' => $prefix, 'restored' => $out['restored'], 'skipped' => count($out['skipped'])]);
        return $out;
    }

    /**
     * Supprime pour de bon les jours de corbeille plus vieux que $days jours. Sans $confirm, ne fait
     * que compter.
     * @return array{files: int, bytes: int, days: list<string>}
     */
    public function emptyTrash(int $days, bool $confirm): array
    {
        $limit = gmdate('Y-m-d', time() - $days * 86400);
        $out = ['files' => 0, 'bytes' => 0, 'days' => []];
        foreach ($this->trash() as $day => $st) {
            if ($day >= $limit) {
                continue;
            }
            $out['days'][] = $day;
            $out['files'] += $st['files'];
            $out['bytes'] += $st['bytes'];
            if ($confirm) {
                foreach ($this->storage->list(self::TRASH . $day . '/') as $obj) {
                    $this->storage->delete($obj->key);
                }
            }
        }
        if ($confirm && $out['days'] !== []) {
            $this->logger->info('Corbeille vidée', $out);
        }
        return $out;
    }
}

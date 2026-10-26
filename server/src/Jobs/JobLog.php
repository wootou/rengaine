<?php
// Journal des tâches de fond (JRN-03) : une ligne par passage dans `jobs`, une ligne par fichier en
// erreur dans `job_errors` (affichées dans l'admin, CAT-09).

declare(strict_types=1);

namespace Musique\Jobs;

use Musique\Db\Database;
use Musique\Log\Logger;

final class JobLog
{
    public readonly int $id;
    /** @var array<string, int> */
    private array $stats = [];

    /** @param array<string, mixed> $params */
    /** @param ?int $queuedId tâche mise en file par l'admin (POST /admin/jobs) : on la reprend au lieu d'en créer une */
    public function __construct(private readonly Database $db, private readonly Logger $logger, string $type, array $params = [], ?int $requestedBy = null, ?int $queuedId = null)
    {
        self::reapOrphans($db, $logger);
        if ($queuedId !== null) {
            $taken = $db->run(
                "UPDATE jobs SET status = 'running', started_at = CURRENT_TIMESTAMP(3), worker = ? WHERE id = ? AND status = 'queued'",
                [Worker::id(), $queuedId]
            )->rowCount();
            if ($taken !== 1) {
                throw new \RuntimeException("Tâche $queuedId déjà prise ou introuvable");
            }
            $this->id = $queuedId;
        } else {
            $db->run(
                "INSERT INTO jobs (type, status, params, requested_by, started_at, worker)
                 VALUES (?, 'running', ?, ?, CURRENT_TIMESTAMP(3), ?)",
                [$type, $params === [] ? null : json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $requestedBy, Worker::id()]
            );
            $this->id = (int) $db->pdo()->lastInsertId();
        }
        $logger->info('Tâche démarrée', ['job' => $this->id, 'type' => $type, 'params' => $params]);
    }

    public function count(string $name, int $by = 1): void
    {
        $this->stats[$name] = ($this->stats[$name] ?? 0) + $by;
    }

    /** @return array<string, int> */
    public function stats(): array
    {
        return $this->stats;
    }

    /**
     * Une erreur déjà ouverte pour le même fichier et le même code n'est pas réenregistrée : sans cela, un
     * fichier illisible ajoutait une ligne (et une ligne au résumé envoyé aux admins) à chaque passage.
     */
    public function error(string $code, string $message, ?string $storageKey = null, ?int $objectId = null): void
    {
        $this->count('errors');
        if ($storageKey !== null && $this->db->value(
            'SELECT 1 FROM job_errors WHERE storage_key = ? AND code = ? AND resolved_at IS NULL LIMIT 1',
            [$storageKey, $code]
        ) !== null) {
            $this->logger->info('Erreur de tâche déjà signalée', ['job' => $this->id, 'code' => $code, 'key' => $storageKey]);
            return;
        }
        $this->db->run(
            'INSERT INTO job_errors (job_id, object_id, storage_key, code, message) VALUES (?, ?, ?, ?, ?)',
            [$this->id, $objectId, $storageKey, $code, mb_substr($message, 0, 2000)]
        );
        $this->logger->warning('Erreur de tâche', ['job' => $this->id, 'code' => $code, 'key' => $storageKey, 'message' => $message]);
    }

    public function finish(): void
    {
        $this->close('done');
        $this->logger->info('Tâche terminée', ['job' => $this->id, 'stats' => $this->stats]);
    }

    public function fail(\Throwable $e): void
    {
        $this->stats['failed'] = 1;
        $this->close('failed');
        $this->logger->error('Tâche en échec', ['job' => $this->id, 'exception' => $e, 'stats' => $this->stats]);
    }

    /**
     * Clôt les erreurs ouvertes d'un fichier, quand il redevient lisible ou disparaît.
     * @param list<string> $codes vide = tous les codes
     */
    public static function resolve(Database $db, ?int $objectId, ?string $storageKey = null, array $codes = []): int
    {
        $where = [];
        $args = [];
        if ($objectId !== null) {
            $where[] = 'object_id = ?';
            $args[] = $objectId;
        }
        if ($storageKey !== null) {
            $where[] = 'storage_key = ?';
            $args[] = $storageKey;
        }
        if ($where === []) {
            return 0;
        }
        $sql = 'UPDATE job_errors SET resolved_at = CURRENT_TIMESTAMP(3) WHERE resolved_at IS NULL AND (' . implode(' OR ', $where) . ')';
        if ($codes !== []) {
            $sql .= ' AND code IN (' . implode(',', array_fill(0, count($codes), '?')) . ')';
            array_push($args, ...$codes);
        }
        return $db->run($sql, $args)->rowCount();
    }

    /**
     * Tâches « en cours » dont le processus a disparu (tué faute de mémoire, SIGKILL) : passées en échec,
     * sinon elles le restent pour toujours. Seules celles de cette machine sont vérifiables.
     */
    public static function reapOrphans(Database $db, Logger $logger): int
    {
        $n = 0;
        foreach ($db->all("SELECT id, type, worker FROM jobs WHERE status = 'running' AND worker LIKE ?", [addcslashes((string) gethostname(), '\\%_') . ':%']) as $j) {
            if (!Worker::isGone($j['worker'])) {
                continue;
            }
            $n += $db->run(
                "UPDATE jobs SET status = 'failed', finished_at = CURRENT_TIMESTAMP(3),
                        stats = JSON_SET(COALESCE(stats, '{}'), '$.failed', 1, '$.interrupted', 1)
                  WHERE id = ? AND status = 'running'",
                [$j['id']]
            )->rowCount();
            $logger->warning('Tâche interrompue (processus disparu) : passée en échec', ['job' => (int) $j['id'], 'type' => $j['type'], 'worker' => $j['worker']]);
        }
        return $n;
    }

    private function close(string $status): void
    {
        $this->db->run(
            'UPDATE jobs SET status = ?, stats = ?, finished_at = CURRENT_TIMESTAMP(3) WHERE id = ?',
            [$status, json_encode($this->stats ?: new \stdClass()), $this->id]
        );
    }
}

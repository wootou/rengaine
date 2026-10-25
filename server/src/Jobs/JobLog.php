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
        if ($queuedId !== null) {
            $taken = $db->run(
                "UPDATE jobs SET status = 'running', started_at = CURRENT_TIMESTAMP(3), worker = ? WHERE id = ? AND status = 'queued'",
                [gethostname() . ':' . getmypid(), $queuedId]
            )->rowCount();
            if ($taken !== 1) {
                throw new \RuntimeException("Tâche $queuedId déjà prise ou introuvable");
            }
            $this->id = $queuedId;
        } else {
            $db->run(
                "INSERT INTO jobs (type, status, params, requested_by, started_at, worker)
                 VALUES (?, 'running', ?, ?, CURRENT_TIMESTAMP(3), ?)",
                [$type, $params === [] ? null : json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $requestedBy, gethostname() . ':' . getmypid()]
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

    public function error(string $code, string $message, ?string $storageKey = null, ?int $objectId = null): void
    {
        $this->count('errors');
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

    private function close(string $status): void
    {
        $this->db->run(
            'UPDATE jobs SET status = ?, stats = ?, finished_at = CURRENT_TIMESTAMP(3) WHERE id = ?',
            [$status, json_encode($this->stats ?: new \stdClass()), $this->id]
        );
    }
}

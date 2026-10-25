<?php
// Exécute les tâches demandées depuis l'admin (POST /admin/jobs, statut « queued »).
// Lancé chaque minute par rengaine-jobs.timer : aucune requête web ne parcourt le stockage.

declare(strict_types=1);

namespace Musique\Jobs;

use Musique\Audio\Analyzer;
use Musique\Config;
use Musique\Db\Database;
use Musique\Library\AlbumBuilder;
use Musique\Library\Duplicates;
use Musique\Library\Genres;
use Musique\Library\Images;
use Musique\Library\Inbox;
use Musique\Library\Indexer;
use Musique\Library\Probe;
use Musique\Log\Logger;
use Musique\Storage\StorageFactory;

final class Runner
{
    public function __construct(private readonly Config $config, private readonly Database $db, private readonly Logger $logger)
    {
    }

    /**
     * Traite les tâches en file, la plus ancienne d'abord.
     * @param ?callable(string): void $progress
     * @return int nombre de tâches traitées
     */
    public function runQueued(?callable $progress = null): int
    {
        $progress ??= static function (string $m): void {};
        $done = 0;
        $skipped = [];
        while (true) {
            $job = $this->db->one(
                "SELECT id, type, params, requested_by FROM jobs WHERE status = 'queued'"
                . ($skipped === [] ? '' : ' AND id NOT IN (' . implode(',', array_map('intval', $skipped)) . ')')
                . ' ORDER BY created_at, id LIMIT 1'
            );
            if ($job === null) {
                return $done;
            }
            $id = (int) $job['id'];
            $params = json_decode((string) ($job['params'] ?? 'null'), true) ?: [];
            $progress("Tâche n° $id : {$job['type']}");
            try {
                $this->run($id, (string) $job['type'], $params, $job['requested_by'] === null ? null : (int) $job['requested_by'], $progress);
                $done++;
            } catch (\Throwable $e) {
                // Ex. une indexation du minuteur est en cours : la tâche reste en file, reprise à la minute suivante
                $this->logger->warning('Tâche en file pas lancée', ['job' => $id, 'message' => $e->getMessage()]);
                $progress("  pas lancée : {$e->getMessage()}");
                $skipped[] = $id;
            }
        }
    }

    /** @param array<string, mixed> $params */
    private function run(int $id, string $type, array $params, ?int $requestedBy, callable $progress): void
    {
        $storage = StorageFactory::fromConfig($this->config);
        $cache = rtrim($this->config->string('cache', 'dossier', '/var/cache/rengaine'), '/');
        $genres = new Genres($this->db);
        switch ($type) {
            case 'index':
                $indexer = new Indexer($this->db, $storage, $this->logger, new Probe($this->config->int('indexeur', 'processus', 8)),
                    new AlbumBuilder($this->db, $genres, new Images($this->db, $storage, $cache)), $genres);
                $indexer->run((string) ($params['prefix'] ?? ''), (bool) ($params['force'] ?? false), $requestedBy, $progress, $id);
                break;
            case 'inbox':
                // Rangement demandé depuis l'admin, puis indexation des albums rangés
                $res = Inbox::fromConfig($this->config, $this->db, $storage, $this->logger)->run($requestedBy, $progress, $id);
                if ($res['filed'] !== []) {
                    (new Indexer($this->db, $storage, $this->logger, new Probe($this->config->int('indexeur', 'processus', 8)),
                        new AlbumBuilder($this->db, $genres, new Images($this->db, $storage, $cache)), $genres))->run('', false, $requestedBy, $progress);
                }
                break;
            case 'dedup':
                (new Duplicates($this->db, $storage, $this->logger))->remove((int) ($params['albumId'] ?? 0), $requestedBy, $progress, $id);
                break;
            case 'analyze':
                $cores = max(1, (int) @shell_exec('nproc'));
                (new Analyzer($this->db, $storage, $this->logger, "$cache/tmp", $this->config->int('analyse', 'processus', $cores),
                    900, \Musique\Audio\Rhythm::fromConfig($this->config), \Musique\Audio\Fingerprint::fromConfig($this->config)))
                    ->run(null, $progress, $id);
                break;
            case 'import':
                $job = new JobLog($this->db, $this->logger, 'import', $params, $requestedBy, $id);
                try {
                    [$n, $errors] = $genres->importLegacy($storage, null, static function (string $key, string $msg) use ($job): void {
                        $job->error('import_failed', $msg, $key);
                    });
                    $job->count('albums_imported', $n);
                    $job->finish();
                } catch (\Throwable $e) {
                    $job->fail($e);
                    throw $e;
                }
                break;
            case 'organize':
                // Réorganisation par IA (IA-04) : le modèle de [ia], avec les outils du serveur MCP
                (new \Musique\Ai\Organizer($this->config, $this->db, $this->logger))->run($id, $params, $requestedBy, $progress);
                break;
            default:
                $this->db->run("UPDATE jobs SET status = 'cancelled', finished_at = CURRENT_TIMESTAMP(3) WHERE id = ?", [$id]);
                $this->logger->warning('Type de tâche non exécutable', ['job' => $id, 'type' => $type]);
        }
    }
}

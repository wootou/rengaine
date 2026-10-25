<?php
// Création et évolution du schéma :
//   - base vide : exécute db/schema.sql (qui enregistre la version 1) ;
//   - ensuite : applique dans l'ordre les fichiers db/migrations/NNNN_description.sql non encore passés.
// MariaDB valide chaque instruction DDL aussitôt : une migration interrompue ne s'annule pas. Une
// migration s'écrit donc pour pouvoir être relancée (IF NOT EXISTS…).

declare(strict_types=1);

namespace Musique\Db;

final class Migrator
{
    public function __construct(private readonly Database $db, private readonly string $dbDir)
    {
    }

    public function currentVersion(): ?int
    {
        if (!$this->tableExists('schema_migrations')) {
            return null;
        }
        $v = $this->db->value('SELECT MAX(version) FROM schema_migrations');
        return $v === null ? null : (int) $v;
    }

    /** @return array<int, string> version → fichier, pour les migrations pas encore appliquées */
    public function pending(): array
    {
        $current = $this->currentVersion() ?? 1;
        $out = [];
        foreach (glob($this->dbDir . '/migrations/[0-9][0-9][0-9][0-9]_*.sql') ?: [] as $file) {
            $version = (int) substr(basename($file), 0, 4);
            if ($version > $current) {
                $out[$version] = $file;
            }
        }
        ksort($out);
        return $out;
    }

    /** @return list<string> ce qui a été fait */
    public function migrate(): array
    {
        $done = [];
        if ($this->currentVersion() === null) {
            $tables = (int) $this->db->value(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'
            );
            if ($tables > 0) {
                throw new \RuntimeException(
                    "La base contient déjà $tables table(s) mais pas de table schema_migrations : refus de continuer."
                );
            }
            $this->runFile($this->dbDir . '/schema.sql');
            $done[] = 'schéma initial (version 1)';
        }
        foreach ($this->pending() as $version => $file) {
            $this->runFile($file);
            $this->db->run(
                'INSERT INTO schema_migrations (version, description) VALUES (?, ?)',
                [$version, substr(basename($file, '.sql'), 5)]
            );
            $done[] = basename($file);
        }
        return $done;
    }

    private function runFile(string $file): void
    {
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new \RuntimeException("Lecture impossible : $file");
        }
        foreach (SqlSplitter::split($sql) as $statement) {
            $this->db->exec($statement);
        }
    }

    private function tableExists(string $table): bool
    {
        return (bool) $this->db->value(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        );
    }
}

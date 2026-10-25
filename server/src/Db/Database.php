<?php
// Connexion MariaDB. Toujours en UTC et en utf8mb4 ; requêtes préparées uniquement.

declare(strict_types=1);

namespace Musique\Db;

use Musique\Config;
use PDO;
use PDOStatement;

final class Database
{
    private ?PDO $pdo = null;

    public function __construct(
        private readonly string $dsn,
        private readonly string $user,
        #[\SensitiveParameter] private readonly string $password,
    ) {
    }

    public static function fromConfig(Config $config): self
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config->string('base', 'hote', '127.0.0.1'),
            $config->int('base', 'port', 3306),
            $config->string('base', 'nom'),
        );
        return new self($dsn, $config->string('base', 'utilisateur'), $config->string('base', 'mot_de_passe'));
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $pdo = new PDO($this->dsn, $this->user, $this->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
            $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_uca1400_ai_ci, time_zone = '+00:00'");
            $this->pdo = $pdo;
        }
        return $this->pdo;
    }

    /** @param array<int|string, mixed> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<int|string, mixed> $params */
    public function value(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /** Exécute une instruction sans préparation (DDL des migrations). */
    public function exec(string $sql): void
    {
        $this->pdo()->exec($sql);
    }

    /**
     * @template T
     * @param callable(self): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        $pdo = $this->pdo();
        if ($pdo->inTransaction()) {
            return $fn($this); // déjà dans une transaction : elle englobe celle-ci
        }
        $pdo->beginTransaction();
        try {
            $result = $fn($this);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

<?php
// Exécution des outils MCP (IA-03), commune au serveur MCP (POST /mcp) et à la réorganisation
// lancée depuis l'admin (IA-04, tâche de fond) : chaque outil est une route de l'API appelée en
// interne, avec le compte donné et les mêmes règles de rôle que par HTTP.

declare(strict_types=1);

namespace Musique\Api;

use Musique\Auth\Principal;
use Musique\Config;
use Musique\Db\Database;
use Musique\Http\ApiError;
use Musique\Http\RateLimiter;
use Musique\Http\Request;
use Musique\Http\Router;
use Musique\Lang;
use Musique\Log\Logger;
use Musique\Log\RequestId;
use Musique\Log\SecurityLog;

final class McpExecutor
{
    private static ?Router $router = null;

    public function __construct(private readonly Context $ctx)
    {
    }

    /** Hors requête web (tâche de fond) : un contexte pour le compte donné. */
    public static function forPrincipal(Config $config, Database $db, Logger $logger, Principal $principal): self
    {
        $rid = RequestId::generate();
        $ctx = new Context($config, $db, $logger->withRequest($rid, $principal->userId), new Request('POST', Kernel::BASE . '/mcp'), $rid,
            new RateLimiter(), SecurityLog::fromConfig($config));
        $ctx->principal = $principal;
        return new self($ctx);
    }

    /** Descriptions des outils permis au compte (tools/list). @return list<array<string, mixed>> */
    public function describe(): array
    {
        $out = [];
        foreach (McpTools::all() as $name => $tool) {
            if ($this->allowed($tool)) {
                $out[] = McpTools::describe($name, $tool);
            }
        }
        return $out;
    }

    public function has(string $name): bool
    {
        $tool = McpTools::all()[$name] ?? null;
        return $tool !== null && $this->allowed($tool);
    }

    /**
     * Lance un outil. Une erreur (argument refusé, élément introuvable) est rendue comme texte, dans la
     * langue du serveur, pour que le modèle corrige son appel.
     * @param array<string, mixed> $args
     * @return array{text: string, error: bool, data: mixed}
     */
    public function run(string $name, array $args): array
    {
        $tool = McpTools::all()[$name] ?? null;
        if ($tool === null || !$this->allowed($tool)) {
            return self::failure('Outil inconnu : ' . $name);
        }
        $unknown = array_diff(array_keys($args), array_keys($tool['props']));
        if ($unknown !== []) {
            return self::failure('Argument inconnu : ' . implode(', ', $unknown));
        }
        foreach ($tool['required'] ?? [] as $r) {
            if (!array_key_exists($r, $args)) {
                return self::failure("Argument « $r » obligatoire");
            }
        }
        try {
            $data = $this->call($tool, $args);
        } catch (ApiError $e) {
            $text = Lang::message($e->getMessage());
            if (isset($e->details['field'])) {
                $text .= ' (' . $e->details['field'] . ')';
            }
            return ['text' => $text, 'error' => true, 'data' => null];
        }
        if ($tool['write'] ?? false) {
            $this->ctx->logger->info('MCP : lot proposé', ['tool' => $name, 'id' => is_array($data) ? ($data['id'] ?? null) : null]);
        }
        return [
            'text' => (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE),
            'error' => false,
            'data' => $data,
        ];
    }

    /** @return array{text: string, error: bool, data: mixed} */
    private static function failure(string $text): array
    {
        return ['text' => Lang::message($text), 'error' => true, 'data' => null];
    }

    /** @param array<string, mixed> $tool */
    private function allowed(array $tool): bool
    {
        [$method, $path] = $tool['route'];
        foreach (self::router()->routes() as $r) {
            if ($r['method'] === $method && $r['path'] === $path) {
                $roles = Kernel::roles($r['options']);
                return $roles === null || in_array($this->ctx->user()->role, $roles, true);
            }
        }
        return false;
    }

    /**
     * Appel interne d'une route : même compte, même journal, mêmes vérifications que par HTTP.
     * @param array<string, mixed> $tool
     * @param array<string, mixed> $args
     */
    private function call(array $tool, array $args): mixed
    {
        [$method, $path] = $tool['route'];
        if (str_contains($path, '{id}')) {
            $v = $args['id'] ?? null;
            if (!is_int($v) || $v < 1) {
                throw ApiError::invalid('id', 'Argument « id » : entier positif attendu');
            }
            $path = str_replace('{id}', (string) $v, $path);
        }
        $query = [];
        foreach ($tool['query'] ?? [] as $arg => $param) {
            if (!array_key_exists($arg, $args) || $args[$arg] === null) {
                continue;
            }
            $v = $args[$arg];
            $query[$param] = match (true) {
                is_bool($v) => $v ? 'true' : 'false',
                is_array($v) => implode(',', array_map(static fn ($x) => is_scalar($x) ? (string) $x : '', $v)),
                is_scalar($v) => (string) $v,
                default => throw ApiError::invalid($arg, "Argument « $arg » invalide"),
            };
        }
        $body = '';
        if (isset($tool['body'])) {
            $body = (string) json_encode(array_intersect_key($args, array_flip($tool['body'])), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $route = self::router()->match($method, $path);
        $parent = $this->ctx;
        $request = new Request($method, Kernel::BASE . $path, $query, ['content-type' => 'application/json'], $body, $parent->request->ip);
        $ctx = new Context($parent->config, $parent->db, $parent->logger, $request, $parent->requestId, $parent->limiter, $parent->security);
        $ctx->principal = $parent->principal;
        [$class, $fn] = $route['handler'];
        $params = array_map(static fn (string $v) => ctype_digit($v) ? (int) $v : $v, $route['params']);
        $response = (new $class($ctx))->$fn($params);
        return $response->body === null ? null : json_decode($response->body, true);
    }

    private static function router(): Router
    {
        return self::$router ??= Routes::build();
    }
}

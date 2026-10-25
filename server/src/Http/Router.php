<?php
// Routeur minimal : méthode + chemin (« /albums/{id} »). Les paramètres {id}, {tokenId} et {itemId} sont des
// entiers ≥ 1, {hash} une empreinte SHA-256 en hexadécimal ; un chemin qui ne colle pas donne 404.

declare(strict_types=1);

namespace Musique\Http;

final class Router
{
    private const PARAM_PATTERNS = [
        'id' => '[1-9][0-9]{0,18}',
        'tokenId' => '[1-9][0-9]{0,18}',
        'itemId' => '[1-9][0-9]{0,18}',
        'hash' => '[0-9a-f]{64}',
    ];

    /** @var list<array{method: string, path: string, regex: string, handler: mixed, options: array<string, mixed>}> */
    private array $routes = [];

    /** @param array<string, mixed> $options public, roles… */
    public function add(string $method, string $path, mixed $handler, array $options = []): void
    {
        $regex = preg_replace_callback('/\{(\w+)\}/', static function (array $m): string {
            return '(?P<' . $m[1] . '>' . (self::PARAM_PATTERNS[$m[1]] ?? '[^/]+') . ')';
        }, $path);
        $this->routes[] = ['method' => $method, 'path' => $path, 'regex' => '#^' . $regex . '$#', 'handler' => $handler, 'options' => $options];
    }

    /** @return list<array{method: string, path: string, handler: mixed, options: array<string, mixed>}> */
    public function routes(): array
    {
        return $this->routes;
    }

    /**
     * @return array{handler: mixed, params: array<string, string>, options: array<string, mixed>, path: string}
     */
    public function match(string $method, string $path): array
    {
        $allowed = [];
        foreach ($this->routes as $r) {
            if (!preg_match($r['regex'], $path, $m)) {
                continue;
            }
            if ($r['method'] === $method || ($method === 'HEAD' && $r['method'] === 'GET')) {
                $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
                return ['handler' => $r['handler'], 'params' => $params, 'options' => $r['options'], 'path' => $r['path']];
            }
            $allowed[] = $r['method'];
        }
        if ($allowed !== []) {
            throw new ApiError(405, 'method_not_allowed', 'Méthode non autorisée sur cette adresse', [], ['Allow' => implode(', ', array_unique($allowed))]);
        }
        throw ApiError::notFound('Adresse inconnue');
    }
}

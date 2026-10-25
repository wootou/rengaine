<?php
// Serveur MCP (IA-03) : POST /mcp, transport « Streamable HTTP » sans état, réponses JSON (pas de flux).
// Messages JSON-RPC 2.0 : initialize, ping, tools/list, tools/call ; les notifications reçoivent 202.
// Un outil appelle une route de l'API en interne, avec le même compte et les mêmes règles de rôle.
// Jeton seulement (Authorization: Bearer) : une session web n'y a pas accès.

declare(strict_types=1);

namespace Musique\Api\Controllers;

use Musique\Api\Context;
use Musique\Api\Kernel;
use Musique\Api\McpExecutor;
use Musique\Api\McpTools;
use Musique\Http\ApiError;
use Musique\Http\Response;
use Musique\Lang;

final class McpController
{
    /** Versions du protocole acceptées, la plus récente d'abord. */
    public const PROTOCOLS = ['2025-11-25', '2025-06-18', '2025-03-26'];

    public function __construct(private readonly Context $ctx)
    {
    }

    public function handle(array $params): Response
    {
        if ($this->ctx->user()->isWeb()) {
            throw ApiError::forbidden('Le serveur MCP s’utilise avec un jeton (Authorization: Bearer)');
        }
        $msg = $this->ctx->request->json();
        $id = $msg['id'] ?? null;
        if (($msg['jsonrpc'] ?? null) !== '2.0' || !is_string($msg['method'] ?? null)
            || ($id !== null && !is_string($id) && !is_int($id))) {
            return self::failure($id, -32600, 'Requête JSON-RPC invalide');
        }
        if (!array_key_exists('id', $msg)) {
            // Notification (notifications/initialized…) : rien à répondre
            return new Response(202);
        }
        $p = $msg['params'] ?? [];
        if (!is_array($p)) {
            return self::failure($id, -32602, 'Paramètres invalides');
        }
        return match ($msg['method']) {
            'initialize' => self::result($id, $this->initialize($p)),
            'ping' => self::result($id, new \stdClass()),
            'tools/list' => self::result($id, ['tools' => $this->executor()->describe()]),
            'tools/call' => $this->callTool($id, $p),
            default => self::failure($id, -32601, 'Méthode inconnue : ' . $msg['method']),
        };
    }

    /** @param array<string, mixed> $p */
    private function initialize(array $p): array
    {
        $asked = $p['protocolVersion'] ?? null;
        return [
            'protocolVersion' => in_array($asked, self::PROTOCOLS, true) ? $asked : self::PROTOCOLS[0],
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => 'rengaine', 'title' => 'Rengaine', 'version' => Kernel::VERSION],
            'instructions' => McpTools::INSTRUCTIONS,
        ];
    }

    private function executor(): McpExecutor
    {
        return new McpExecutor($this->ctx);
    }

    /** @param array<string, mixed> $p */
    private function callTool(int|string $id, array $p): Response
    {
        $name = $p['name'] ?? null;
        $executor = $this->executor();
        if (!is_string($name) || !$executor->has($name)) {
            return self::failure($id, -32602, 'Outil inconnu : ' . (is_string($name) ? $name : '?'));
        }
        $args = $p['arguments'] ?? [];
        if (!is_array($args) || ($args !== [] && array_is_list($args))) {
            return self::failure($id, -32602, 'Arguments invalides : un objet est attendu');
        }
        $r = $executor->run($name, $args);
        $result = ['content' => [['type' => 'text', 'text' => $r['text']]]];
        if ($r['error']) {
            $result['isError'] = true;
        }
        return self::result($id, $result);
    }

    private static function result(int|string $id, mixed $result): Response
    {
        return Response::json(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    private static function failure(mixed $id, int $code, string $message): Response
    {
        return Response::json(['jsonrpc' => '2.0', 'id' => is_int($id) || is_string($id) ? $id : null, 'error' => ['code' => $code, 'message' => Lang::message($message)]]);
    }
}

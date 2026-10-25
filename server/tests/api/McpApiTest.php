<?php
// Serveur MCP (IA-03) : protocole JSON-RPC, outils permis au jeton, réponses des outils conformes
// au contrat de la route appelée, proposition d'un lot qui attend l'admin.

declare(strict_types=1);

namespace Musique\Tests;

use Musique\Api\McpTools;
use Musique\Api\Routes;

final class McpApiTest extends ApiCase
{
    private int $next = 1;

    /** Appel JSON-RPC ; renvoie la réponse HTTP. */
    private function rpc(string $token, string $method, ?array $params = null, bool $notification = false): ApiResponse
    {
        $msg = ['jsonrpc' => '2.0', 'method' => $method];
        if (!$notification) {
            $msg['id'] = $this->next++;
        }
        if ($params !== null) {
            $msg['params'] = $params;
        }
        return $this->as($token, 'POST', '/api/v2/mcp', $msg, ['Accept' => 'application/json, text/event-stream']);
    }

    /** Appel d'outil : [résultat décodé, isError]. */
    private function tool(string $name, array $args = [], ?string $token = null): array
    {
        $r = $this->rpc($token ?? $this->token('agent'), 'tools/call', ['name' => $name, 'arguments' => (object) $args]);
        $this->assertContract($r, 'mcp', 200);
        $result = $r->data()['result'] ?? null;
        $this->assertTrue(is_array($result), "$name : " . mb_substr($r->raw, 0, 300));
        $text = $result['content'][0]['text'];
        $error = (bool) ($result['isError'] ?? false);
        return [$error ? $text : json_decode($text, false, 512, JSON_THROW_ON_ERROR), $error];
    }

    public function testInitialisationEtProtocole(): void
    {
        $agent = $this->token('agent');
        $r = $this->rpc($agent, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1']]);
        $this->assertContract($r, 'mcp', 200);
        $res = $r->data()['result'];
        $this->assertSame('2025-06-18', $res['protocolVersion']);
        $this->assertSame(['name' => 'rengaine', 'title' => 'Rengaine', 'version' => \Musique\Api\Kernel::VERSION], $res['serverInfo']);
        $this->assertSame(['tools' => ['listChanged' => false]], $res['capabilities']);
        $this->assertTrue(str_contains($res['instructions'], 'propose_changes'));
        // Version inconnue : le serveur propose la sienne
        $this->assertSame('2025-11-25', $this->rpc($agent, 'initialize', ['protocolVersion' => '2099-01-01'])->data()['result']['protocolVersion']);

        $r = $this->rpc($agent, 'notifications/initialized', null, true);
        $this->assertSame([202, ''], [$r->status, $r->raw]);
        $this->assertSame([], $this->rpc($agent, 'ping')->data()['result']);

        $this->assertSame(-32601, $this->rpc($agent, 'resources/list')->data()['error']['code']);
        $r = $this->as($agent, 'POST', '/api/v2/mcp', ['jsonrpc' => '1.0', 'id' => 1, 'method' => 'ping']);
        $this->assertSame(-32600, $r->data()['error']['code']);
        $this->assertSame(-32602, $this->rpc($agent, 'tools/call', ['name' => 'delete_everything'])->data()['error']['code']);
        // Pas de flux SSE
        $this->assertSame(405, $this->as($agent, 'GET', '/api/v2/mcp')->status);
    }

    public function testAccesReserveAuxJetonsAgentEtAdmin(): void
    {
        $this->assertError($this->call('POST', '/api/v2/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']), 401, 'unauthorized', 'mcp');
        $this->assertError($this->rpc($this->token('user'), 'ping'), 403, 'forbidden', 'mcp');
        $this->assertSame(200, $this->rpc($this->token('admin'), 'ping')->status);

        // Session web d'un admin : refusée même avec le jeton anti-CSRF
        $r = $this->call('POST', '/api/v2/auth/login', ['email' => 'admin@test.fr', 'password' => self::PASSWORD, 'client' => 'web']);
        $headers = ['Cookie' => 'musique_session=' . $r->sessionCookie(), 'X-CSRF-Token' => $r->data()['csrfToken']];
        $this->assertError($this->call('POST', '/api/v2/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], $headers), 403, 'forbidden', 'mcp');
    }

    public function testListeDesOutils(): void
    {
        $tools = $this->rpc($this->token('agent'), 'tools/list')->data()['result']['tools'];
        $names = array_column($tools, 'name');
        $this->assertSame(array_keys(McpTools::all()), $names);
        foreach ($tools as $t) {
            $this->assertSame('object', $t['inputSchema']['type'], $t['name']);
            $this->assertTrue($t['description'] !== '' && $t['title'] !== '', $t['name']);
            $this->assertSame($t['name'] !== 'propose_changes', $t['annotations']['readOnlyHint'], $t['name']);
        }
    }

    /** Chaque outil vise une route ouverte à l'agent, avec des paramètres que le contrat connaît. */
    public function testOutilsAlignesSurLeContrat(): void
    {
        $ops = [];
        foreach (Contract::operations() as $o) {
            $ops[$o['method'] . ' ' . $o['path']] = $o;
        }
        $routes = [];
        foreach (Routes::build()->routes() as $r) {
            $routes[$r['method'] . ' ' . $r['path']] = $r['options']['roles'] ?? null;
        }
        foreach (McpTools::all() as $name => $tool) {
            $key = $tool['route'][0] . ' ' . $tool['route'][1];
            $this->assertTrue(isset($ops[$key]), "$name : $key absent du contrat");
            $this->assertTrue(in_array('agent', $routes[$key] ?? [], true), "$name : $key fermé à l'agent");
            $known = [];
            foreach (array_merge($ops[$key]['op']['parameters'] ?? [], self::pathParams($key)) as $p) {
                $p = isset($p['$ref']) ? Contract::resolve($p['$ref']) : $p;
                $known[] = $p['name'];
            }
            foreach ($tool['query'] ?? [] as $param) {
                $this->assertTrue(in_array($param, $known, true), "$name : paramètre « $param » inconnu de $key");
            }
            foreach (array_keys($tool['props']) as $arg) {
                $this->assertTrue(isset($tool['query'][$arg]) || in_array($arg, $tool['body'] ?? [], true) || ($arg === 'id' && str_contains($key, '{id}')), "$name : argument « $arg » sans usage");
            }
        }
    }

    /** @return list<array{name: string}> */
    private static function pathParams(string $key): array
    {
        preg_match_all('/\{(\w+)\}/', $key, $m);
        return array_map(static fn ($n) => ['name' => $n], $m[1]);
    }

    public function testLectureConformeAuContrat(): void
    {
        $album = (int) $this->db->value("SELECT id FROM albums WHERE title = 'Lemonade'");
        $artist = (int) $this->db->value("SELECT id FROM artists WHERE name = 'Beyoncé'");
        $track = (int) $this->db->value('SELECT id FROM tracks WHERE album_id = ? ORDER BY id LIMIT 1', [$album]);
        $ops = [];
        foreach (Contract::operations() as $o) {
            $ops[$o['method'] . ' ' . $o['path']] = $o['operationId'];
        }
        $args = [
            'search_library' => ['q' => 'lemonade'], 'get_album' => ['id' => $album], 'get_artist' => ['id' => $artist],
            'get_track' => ['id' => $track], 'list_albums' => ['genreIds' => [1, 2], 'sort' => 'year', 'order' => 'desc', 'limit' => 2],
            'list_job_errors' => ['open' => false], 'list_genre_tags' => ['mapped' => true],
        ];
        foreach (McpTools::all() as $name => $tool) {
            if (($tool['write'] ?? false) || in_array($name, ['get_duplicates', 'get_change_set'], true)) {
                continue;
            }
            [$data, $error] = $this->tool($name, $args[$name] ?? []);
            $this->assertSame(false, $error, "$name : " . (is_string($data) ? $data : ''));
            $schema = Contract::responseSchema($ops[$tool['route'][0] . ' ' . $tool['route'][1]], 200);
            $this->assertSame([], Contract::validate($data, $schema), "$name : réponse hors contrat");
        }
        [$data] = $this->tool('search_library', ['q' => 'lemonade']);
        $this->assertSame('Lemonade', $data->albums[0]->title ?? null);
        [$data] = $this->tool('list_albums', ['sort' => 'year', 'order' => 'desc', 'limit' => 1]);
        $this->assertSame('Lemonade', $data->items[0]->title);

        // Erreurs de l'outil : rendues au modèle, pas en erreur de protocole
        [$text, $error] = $this->tool('get_album', ['id' => 999999]);
        $this->assertSame([true, 'Album introuvable'], [$error, $text]);
        $this->assertSame([true, 'Argument inconnu : genre'], $this->swap($this->tool('list_albums', ['genre' => 3])));
        $this->assertSame([true, 'Argument « id » obligatoire'], $this->swap($this->tool('get_album')));
        [$text, $error] = $this->tool('list_albums', ['limit' => 0]);
        $this->assertTrue($error && str_contains($text, '(limit)'), $text);
    }

    /** [texte, erreur] → [erreur, texte] */
    private function swap(array $r): array
    {
        return [$r[1], $r[0]];
    }

    public function testProposerUnLot(): void
    {
        $album = (int) $this->db->value("SELECT id FROM albums WHERE title = 'Album 10'");
        $before = (int) $this->db->value('SELECT COUNT(*) FROM change_sets');

        // Changement fautif : rien n'est créé, le changement est désigné
        [$text, $error] = $this->tool('propose_changes', ['title' => 'Essai', 'changes' => [['entityType' => 'album', 'entityId' => $album, 'field' => 'year', 'value' => 3000]]]);
        $this->assertTrue($error && str_contains($text, 'changes[0].value'), $text);
        $this->assertSame($before, (int) $this->db->value('SELECT COUNT(*) FROM change_sets'));

        [$set, $error] = $this->tool('propose_changes', [
            'title' => 'Année de Album 10', 'description' => 'Lue sur la pochette',
            'changes' => [['entityType' => 'album', 'entityId' => $album, 'field' => 'year', 'value' => 2011, 'reason' => 'Réédition']],
        ]);
        $this->assertSame(false, $error);
        $this->assertSame([], Contract::validate($set, Contract::responseSchema('adminCreateChangeSet', 201)));
        $this->assertSame(['pending', 1], [$set->status, $set->changeCount]);
        $this->assertSame($this->userId('agent'), $set->createdBy->id);
        // La bibliothèque n'a pas bougé : l'admin décide
        $this->assertSame(2010, (int) $this->db->value('SELECT year FROM albums WHERE id = ?', [$album]));
        $this->assertSame('change_set.create', $this->db->value('SELECT action FROM audit_log WHERE target_type = ? AND target_id = ?', ['change_set', $set->id]));
        [$read] = $this->tool('get_change_set', ['id' => $set->id]);
        $this->assertSame('Réédition', $read->changes[0]->reason);

        $this->as($this->token('admin'), 'POST', "/api/v2/admin/change-sets/{$set->id}/reject");
    }

    public function testMessagesDansLaLangueDuServeur(): void
    {
        $admin = $this->token('admin');
        $this->as($admin, 'PATCH', '/api/v2/admin/server-settings', ['language' => 'en']);
        try {
            $this->assertSame([true, 'Album not found'], $this->swap($this->tool('get_album', ['id' => 999999])));
            $this->assertSame([true, 'Unknown argument: genre'], $this->swap($this->tool('list_albums', ['genre' => 3])));
        } finally {
            $this->as($admin, 'PATCH', '/api/v2/admin/server-settings', ['language' => 'fr']);
        }
    }
}

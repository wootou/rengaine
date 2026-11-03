<?php
// Droits par fonction des jetons d'agent (IA-07, cahier des charges de la revue de sécurité) :
// chaque capacité décochée est refusée en appel direct ET par le MCP, et l'outil disparaît de tools/list ;
// une route agent sans capacité déclarée fait échouer la suite ; une capacité nouvelle est décochée sur un
// jeton existant ; l'agent ne touche pas à ses droits ; un changement de l'admin est audité et s'applique
// à la requête suivante ; un compte agent n'a pas besoin d'e-mail.

declare(strict_types=1);

namespace Musique\Tests;

use Musique\Api\Kernel;
use Musique\Api\McpTools;
use Musique\Api\Routes;
use Musique\Auth\Capabilities;
use Musique\Library\Corrections;

final class CapabilitiesApiTest extends ApiCase
{
    public function tearDown(): void
    {
        $this->grant(Capabilities::legacy());
    }

    /** @param list<string> $caps */
    private function grant(array $caps): void
    {
        $this->db->run('UPDATE access_tokens SET capabilities = ? WHERE id = ?', [json_encode($caps), self::$env['agentTokenId']]);
    }

    /** @return array{0: bool, 1: string} [isError, texte] d'un outil appelé par MCP */
    private function tool(string $name, array $args): array
    {
        $r = $this->as($this->token('agent'), 'POST', '/api/v2/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => (object) $args]],
            ['Accept' => 'application/json, text/event-stream']);
        $res = $r->data()['result'] ?? [];
        return [(bool) ($res['isError'] ?? false), (string) ($res['content'][0]['text'] ?? $r->raw)];
    }

    /** @return list<string> */
    private function toolList(): array
    {
        $r = $this->as($this->token('agent'), 'POST', '/api/v2/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['Accept' => 'application/json, text/event-stream']);
        return array_column($r->data()['result']['tools'], 'name');
    }

    public function testChaqueRouteAgentDeclareSaCapacite(): void
    {
        $known = [...array_keys(Capabilities::ALL), Capabilities::ANY, Capabilities::PROPOSE];
        $n = 0;
        foreach (Routes::build()->routes() as $r) {
            $roles = Kernel::roles($r['options']);
            if ($roles === null || !in_array('agent', $roles, true)) {
                continue;
            }
            $n++;
            $this->assertTrue(in_array($r['options']['cap'] ?? null, $known, true), "{$r['method']} {$r['path']} : capacité non déclarée (route refusée aux agents)");
        }
        $this->assertTrue($n >= 30, "$n routes agent");
    }

    /** Chaque champ qu'un lot peut changer a sa capacité ; un champ inconnu des agents est refusé avant toute vérification. */
    public function testChaqueChampDeclare(): void
    {
        $fields = [];
        foreach (Corrections::TYPES as $apiType => $stored) {
            foreach (array_keys(Corrections::fields($stored)) as $f) {
                $fields[] = [$apiType, $f];
            }
        }
        // Champs traités à part dans createChangeSet (pas des corrections stockées)
        array_push($fields, ['album', 'coverUrl'], ['album', 'mergedInto']);
        foreach ($fields as [$type, $f]) {
            $this->assertTrue(isset(Capabilities::ALL[(string) Capabilities::forChange($type, $f)]), "$type.$f : capacité non déclarée");
        }
        // Et rien dans la table qui ne soit un vrai champ (un nom faux masquerait un oubli)
        foreach (Capabilities::FIELDS as $type => $map) {
            foreach (array_keys($map) as $f) {
                $this->assertTrue(in_array([$type, $f], $fields, true), "$type.$f : déclaré mais inconnu de ChangeRules");
            }
        }
        $album = (int) $this->db->value("SELECT id FROM albums WHERE title = 'Lemonade'");
        $r = $this->as($this->token('agent'), 'POST', '/api/v2/admin/change-sets', ['title' => 'x', 'changes' => [['entityType' => 'album', 'entityId' => $album, 'field' => 'champInvente', 'value' => 1]]]);
        $this->assertSame([403, 'capability_disabled', 'changes[0].field'], [$r->status, $r->data()['error']['code'] ?? null, $r->data()['error']['details']['field'] ?? null], $r->raw);
        // L'admin, lui, reçoit l'erreur de validation habituelle
        $this->assertSame(400, $this->as($this->token('admin'), 'POST', '/api/v2/admin/change-sets', ['title' => 'x', 'changes' => [['entityType' => 'album', 'entityId' => $album, 'field' => 'champInvente', 'value' => 1]]])->status);
    }

    /** Un jeton d'agent dont le compte change de rôle ne vaut plus rien (il prendrait sinon tous les droits du rôle). */
    public function testJetonAgentDontLeCompteChangeDeRole(): void
    {
        $agentId = $this->userId('agent');
        $admin = $this->token('admin');
        try {
            $this->assertContract($this->as($admin, 'PATCH', "/api/v2/admin/users/$agentId", ['role' => 'user']), 'adminUpdateUser', 200);
            $this->assertSame(401, $this->as($this->token('agent'), 'GET', '/api/v2/albums')->status);
            $this->assertSame(401, $this->as($this->token('agent'), 'GET', '/api/v2/me/queue')->status, 'pas les routes d’un utilisateur');
            $this->as($admin, 'PATCH', "/api/v2/admin/users/$agentId", ['role' => 'admin']);
            $this->assertSame(401, $this->as($this->token('agent'), 'GET', '/api/v2/admin/users')->status, 'pas les routes d’un admin');
        } finally {
            $this->db->run("UPDATE users SET role = 'agent' WHERE id = ?", [$agentId]);
        }
        $this->assertSame(200, $this->as($this->token('agent'), 'GET', '/api/v2/albums')->status, 'de nouveau agent : le jeton revit');
    }

    public function testCapaciteDecocheeRefuseePartout(): void
    {
        $album = (int) $this->db->value("SELECT id FROM albums WHERE title = 'Lemonade'");
        // Pour chaque capacité : un appel direct, un outil MCP qui en dépend
        $cases = [
            'library.read' => ['GET', "/api/v2/admin/albums/$album", 'get_album', ['id' => $album]],
            'library.analysis' => ['GET', '/api/v2/admin/artist-lookalikes', 'list_artist_lookalikes', []],
            'admin.read' => ['GET', '/api/v2/admin/job-errors', 'list_job_errors', []],
            'cover.online' => ['GET', "/api/v2/admin/albums/$album/cover-candidates", 'find_cover', ['id' => $album]],
        ];
        foreach ($cases as $cap => [$method, $path, $tool, $args]) {
            $this->grant(array_values(array_diff(array_keys(Capabilities::ALL), [$cap])));
            $r = $this->as($this->token('agent'), $method, $path);
            $this->assertSame([403, 'capability_disabled', $cap], [$r->status, $r->data()['error']['code'] ?? null, $r->data()['error']['details']['capability'] ?? null], "$cap : appel direct");
            $this->assertFalse(in_array($tool, $this->toolList(), true), "$cap : $tool absent de tools/list");
            [$error, $text] = $this->tool($tool, $args);
            $this->assertTrue($error && str_contains($text, $cap), "$cap : $text");
        }

        // Propositions : chaque changement demande la sienne
        $changes = [
            'propose.tags' => ['entityType' => 'album', 'entityId' => $album, 'field' => 'year', 'value' => 2015],
            'propose.structure' => ['entityType' => 'album', 'entityId' => $album, 'field' => 'removed', 'value' => true],
            'propose.cover.library' => ['entityType' => 'album', 'entityId' => $album, 'field' => 'coverImageHash', 'value' => null],
            'cover.online' => ['entityType' => 'album', 'entityId' => $album, 'field' => 'coverUrl', 'value' => 'https://is1-ssl.mzstatic.com/a.jpg'],
        ];
        foreach ($changes as $cap => $change) {
            $this->grant(array_values(array_diff(array_keys(Capabilities::ALL), [$cap])));
            $r = $this->as($this->token('agent'), 'POST', '/api/v2/admin/change-sets', ['title' => 'x', 'changes' => [$change]]);
            $this->assertSame([403, $cap], [$r->status, $r->data()['error']['details']['capability'] ?? null], "$cap : lot direct");
            [$error, $text] = $this->tool('propose_changes', ['title' => 'x', 'changes' => [$change]]);
            $this->assertTrue($error && str_contains($text, $cap), "$cap : $text");
        }
        // Aucune capacité de proposition : propose_changes disparaît
        $this->grant(['library.read', 'library.analysis']);
        $this->assertFalse(in_array('propose_changes', $this->toolList(), true));
        $this->assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM change_sets WHERE title = 'x'"), 'aucun lot créé');
    }

    public function testCapaciteNouvelleDecocheeSurUnJetonExistant(): void
    {
        // Un jeton d'avant la migration 0020 reçoit l'ancien jeu : la nouveauté (pochettes en ligne) reste décochée
        $this->grant(Capabilities::legacy());
        $album = (int) $this->db->value("SELECT id FROM albums WHERE title = 'Lemonade'");
        $r = $this->as($this->token('agent'), 'GET', "/api/v2/admin/albums/$album/cover-candidates");
        $this->assertSame([403, 'cover.online'], [$r->status, $r->data()['error']['details']['capability'] ?? null]);
        // Jeton sans aucune capacité enregistrée : rien
        $this->db->run('UPDATE access_tokens SET capabilities = NULL WHERE id = ?', [self::$env['agentTokenId']]);
        $this->assertSame(403, $this->as($this->token('agent'), 'GET', '/api/v2/albums')->status);
        $this->assertSame(200, $this->as($this->token('agent'), 'GET', '/api/v2/me')->status, '/me reste lisible');
        $this->assertSame(200, $this->as($this->token('agent'), 'GET', '/api/v2/server')->status, 'route publique');
    }

    public function testAdminRegleLesDroitsAgentNePeutPas(): void
    {
        $tid = self::$env['agentTokenId'];
        $agent = $this->token('agent');
        $admin = $this->token('admin');
        $r = $this->as($admin, 'GET', '/api/v2/admin/capabilities');
        $this->assertContract($r, 'adminListCapabilities', 200);
        $this->assertSame(array_keys(Capabilities::ALL), array_column($r->data()['items'], 'id'));

        // L'agent ne lit pas le catalogue de l'admin et ne touche pas à ses droits ; il lit les siens
        $this->assertError($this->as($agent, 'PATCH', "/api/v2/admin/tokens/$tid", ['capabilities' => array_keys(Capabilities::ALL)]), 403, 'forbidden', 'adminUpdateToken');
        $this->assertError($this->as($agent, 'GET', '/api/v2/admin/capabilities'), 403, 'forbidden', 'adminListCapabilities');
        $me = $this->as($agent, 'GET', '/api/v2/me');
        $this->assertContract($me, 'getMe', 200);
        $this->assertSame(Capabilities::legacy(), $me->data()['capabilities']);

        // L'admin décoche library.read : refus dès la requête suivante, noté dans l'audit
        $this->assertSame(200, $this->as($agent, 'GET', '/api/v2/albums')->status);
        $r = $this->as($admin, 'PATCH', "/api/v2/admin/tokens/$tid", ['capabilities' => ['library.analysis', 'propose.tags']]);
        $this->assertContract($r, 'adminUpdateToken', 200);
        $this->assertSame(['library.analysis', 'propose.tags'], $r->data()['capabilities']);
        $this->assertSame(403, $this->as($agent, 'GET', '/api/v2/albums')->status);
        $audit = json_decode((string) $this->db->value("SELECT after_value FROM audit_log WHERE action = 'token.capabilities' AND target_id = ? ORDER BY id DESC LIMIT 1", [$tid]), true);
        $this->assertSame(['library.analysis', 'propose.tags'], $audit['capabilities']);
        $this->assertError($this->as($admin, 'PATCH', "/api/v2/admin/tokens/$tid", ['capabilities' => ['tout']]), 400, 'bad_request', 'adminUpdateToken');
        $adminTokenId = (int) $this->as($admin, 'GET', '/api/v2/me')->data()['currentTokenId'];
        $this->assertError($this->as($admin, 'PATCH', "/api/v2/admin/tokens/$adminTokenId", ['capabilities' => []]), 409, 'conflict', 'adminUpdateToken');
    }

    public function testCompteAgentSansEmail(): void
    {
        $admin = $this->token('admin');
        $r = $this->as($admin, 'POST', '/api/v2/admin/users', ['displayName' => 'Claude (essai)', 'role' => 'agent']);
        $this->assertContract($r, 'adminCreateUser', 201);
        $this->assertNull($r->data()['email']);
        $id = $r->data()['id'];
        try {
            $this->assertError($this->as($admin, 'POST', '/api/v2/admin/users', ['displayName' => 'Sans adresse', 'role' => 'user', 'password' => 'un-mot-de-passe']), 400, 'bad_request', 'adminCreateUser');
            // Jeton : capacités par défaut, ou choisies
            $t = $this->as($admin, 'POST', "/api/v2/admin/users/$id/tokens", ['name' => 'Claude Code']);
            $this->assertContract($t, 'adminCreateToken', 201);
            $this->assertSame(Capabilities::defaults(), $t->data()['capabilities']);
            $this->assertSame(200, $this->as($t->data()['token'], 'POST', '/api/v2/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], ['Accept' => 'application/json'])->status);
            $t2 = $this->as($admin, 'POST', "/api/v2/admin/users/$id/tokens", ['name' => 'Lecture seule', 'capabilities' => ['library.read']]);
            $this->assertSame(['library.read'], $t2->data()['capabilities']);
            $d = $this->as($admin, 'GET', "/api/v2/admin/users/$id");
            $this->assertContract($d, 'adminGetUser', 200);
            $caps = array_map(static fn ($x) => $x['capabilities'], $d->data()['devices']);
            usort($caps, static fn ($a, $b) => count($a) <=> count($b));
            $this->assertSame([['library.read'], Capabilities::defaults()], $caps, 'capacités de chaque jeton dans la fiche du compte');
            $this->assertError($this->as($admin, 'POST', '/api/v2/admin/users/' . $this->userId('user') . '/tokens', ['name' => 'x', 'capabilities' => ['library.read']]), 400, 'bad_request', 'adminCreateToken');
            // Sans adresse, le compte reste agent
            $this->assertError($this->as($admin, 'PATCH', "/api/v2/admin/users/$id", ['role' => 'admin']), 409, 'conflict', 'adminUpdateUser');
        } finally {
            $this->db->run('DELETE FROM users WHERE id = ?', [$id]);
        }
    }
}

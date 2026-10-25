<?php
// Réorganisation par IA lancée depuis l'admin (IA-04) : la vraie boucle, contre un faux fournisseur
// local (tests/fake-llm.php) aux formats Anthropic et compatible OpenAI. Rien ne part sur Internet.

declare(strict_types=1);

namespace Musique\Tests;

use Musique\Config;
use Musique\Jobs\Runner;
use Musique\Log\Logger;

final class OrganizeAiTest extends ApiCase
{
    /** @var resource|null */
    private static $fake = null;
    private static int $port = 0;
    private static string $log = '';

    public function setUp(): void
    {
        parent::setUp();
        $this->fake();
        \Musique\Ai\Http::$sleep = static function (int $s): void {}; // pas d'attente entre deux essais
    }

    private function fake(): void
    {
        if (self::$fake !== null) {
            return;
        }
        self::$port = self::freePort();
        self::$log = self::$env['base'] . '/journaux/fake-llm.jsonl';
        self::$fake = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . self::$port, __DIR__ . '/../fake-llm.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes, null,
            ['FAKE_LLM_LOG' => self::$log]);
        register_shutdown_function(static function (): void {
            proc_terminate(self::$fake);
        });
        self::waitPort(self::$port);
    }

    /** Lance une tâche organize avec le fournisseur donné ; renvoie la ligne de la tâche et les requêtes reçues. */
    private function organize(array $ia, string $instructions = 'Corrige les années'): array
    {
        $this->fake();
        @unlink(self::$log);
        $env = self::$env;
        $config = Config::load()->with('base', 'nom', $env['dbName'])
            ->with('stockage', 'mode', 'disque')->with('stockage', 'chemin', $env['root'])
            ->with('cache', 'dossier', $env['base'] . '/cache')->with('journaux', 'dossier', $env['base'] . '/journaux');
        foreach ($ia as $k => $v) {
            $config = $config->with('ia', $k, $v);
        }
        $agent = $this->userId('agent');
        $this->db->run("INSERT INTO jobs (type, status, params, requested_by) VALUES ('organize', 'queued', ?, ?)",
            [json_encode(['instructions' => $instructions, 'agentUserId' => $agent]), $this->userId('admin')]);
        $id = (int) $this->db->pdo()->lastInsertId();
        (new Runner($config, $this->db, new Logger($env['base'] . '/journaux/organize.log', 'debug')))->runQueued();
        $requests = array_map(static fn ($l) => json_decode($l, true), array_filter(explode("\n", (string) @file_get_contents(self::$log))));
        return [$this->db->one('SELECT * FROM jobs WHERE id = ?', [$id]), $requests];
    }

    public function testSansIaConfigureeLaTacheEstRefusee(): void
    {
        $admin = $this->token('admin');
        $s = $this->as($admin, 'GET', '/api/v2/admin/server-settings');
        $this->assertContract($s, 'adminGetServerSettings', 200);
        $this->assertNull($s->data()['assistant']);
        $r = $this->as($admin, 'POST', '/api/v2/admin/jobs', ['type' => 'organize', 'params' => ['instructions' => 'Range les genres']]);
        $this->assertError($r, 409, 'conflict', 'adminCreateJob');
        $this->assertStringContainsString('[ia]', $r->data()['error']['message']);
        $this->assertError($this->as($admin, 'POST', '/api/v2/admin/jobs', ['type' => 'organize']), 400, 'bad_request', 'adminCreateJob');
    }

    public function testBoucleAnthropic(): void
    {
        $album = (int) $this->db->value("SELECT id FROM albums WHERE title = 'Album 2'");
        [$job, $req] = $this->organize(['fournisseur' => 'anthropic', 'cle' => 'cle-de-test', 'url' => 'http://127.0.0.1:' . self::$port . "/ok-$album"]);
        $this->assertSame('done', $job['status'], (string) $job['summary']);
        $stats = json_decode((string) $job['stats'], true);
        $this->assertSame(['turns' => 3, 'inputTokens' => 300, 'outputTokens' => 30, 'toolCalls' => 4, 'changeSets' => 1], $stats);

        // Le lot attend l'admin, au nom du compte agent ; la bibliothèque n'a pas bougé
        $set = $this->db->one('SELECT * FROM change_sets ORDER BY id DESC LIMIT 1');
        $this->assertSame(['pending', $this->userId('agent')], [$set['status'], (int) $set['created_by']]);
        $this->assertSame(2002, (int) $this->db->value('SELECT year FROM albums WHERE id = ?', [$album]));
        $this->assertStringContainsString('Fini : un lot proposé', (string) $job['summary']);
        $this->assertStringContainsString("Lots proposés : n° {$set['id']}", (string) $job['summary']);
        $this->assertNull($this->db->value('SELECT actor_token_id FROM audit_log WHERE target_type = ? AND target_id = ?', ['change_set', $set['id']]));

        // Forme des requêtes : en-têtes, outils, réflexion renvoyée telle quelle, résultats regroupés
        $this->assertSame(3, count($req));
        $this->assertSame(['cle-de-test', '2026-10-21', null], [$req[0]['key'], $req[0]['version'], $req[0]['beta']]);
        $b = $req[0]['body'];
        $this->assertSame(['claude-opus-5-5', ['effort' => 'high']], [$b['model'], $b['output_config']]);
        $this->assertSame(20, count($b['tools']));
        $this->assertStringContainsString('propose_changes', $b['system']);
        $this->assertSame([['role' => 'user', 'content' => 'Corrige les années']], $b['messages']);
        $second = $req[1]['body']['messages'];
        $this->assertSame(['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-0'], $second[1]['content'][0]);
        $results = $second[2]['content'];
        $this->assertSame(['tool_result', 'tool_result'], array_column($results, 'type'));
        $this->assertSame(['toolu_0_0', 'toolu_0_1'], array_column($results, 'tool_use_id'));
        $last = $req[2]['body']['messages'][4]['content'];
        $this->assertSame([false, true], array_column($last, 'is_error'));
        $this->assertSame('Album introuvable', $last[1]['content']);
        // La clé n'apparaît ni dans la tâche ni dans le journal
        $this->assertFalse(str_contains((string) $job['params'] . $job['summary'] . file_get_contents(self::$env['base'] . '/journaux/organize.log'), 'cle-de-test'));
    }

    public function testBoucleCompatibleOpenAi(): void
    {
        $album = (int) $this->db->value("SELECT id FROM albums WHERE title = 'Album 10'");
        [$job, $req] = $this->organize(['fournisseur' => 'openai', 'modele' => 'mistral-large', 'url' => 'http://127.0.0.1:' . self::$port . "/ok-$album"]);
        $this->assertSame('done', $job['status'], (string) $job['summary']);
        $this->assertSame(1, json_decode((string) $job['stats'], true)['changeSets']);
        $this->assertNull($req[0]['key'], 'pas de clé : pas d’en-tête Authorization');
        $b = $req[0]['body'];
        $this->assertSame(['mistral-large', 'system', 'user'], [$b['model'], $b['messages'][0]['role'], $b['messages'][1]['role']]);
        $this->assertSame('function', $b['tools'][0]['type']);
        $msgs = $req[1]['body']['messages'];
        $this->assertSame(['system', 'user', 'assistant', 'tool', 'tool'], array_column($msgs, 'role'));
        $this->assertSame(['call_0_0', 'call_0_1'], [$msgs[3]['tool_call_id'], $msgs[4]['tool_call_id']]);
    }

    public function testArretsEtEchecs(): void
    {
        [$job] = $this->organize(['fournisseur' => 'anthropic', 'cle' => 'x', 'url' => 'http://127.0.0.1:' . self::$port . '/loop-1', 'tours_max' => '3']);
        $this->assertSame('done', $job['status']);
        $this->assertSame(3, json_decode((string) $job['stats'], true)['turns']);
        $this->assertStringContainsString('Arrêt après 3 échanges', (string) $job['summary']);

        [$job] = $this->organize(['fournisseur' => 'anthropic', 'cle' => 'x', 'url' => 'http://127.0.0.1:' . self::$port . '/deny-1']);
        $this->assertSame('failed', $job['status']);
        $this->assertSame('Échec : HTTP 401 : invalid x-api-key', $job['summary']);
        $this->assertSame('ai_failed', $this->db->value('SELECT code FROM job_errors WHERE job_id = ?', [$job['id']]));

        // Par l'API : la tâche et son compte rendu suivent le contrat
        $r = $this->as($this->token('admin'), 'GET', '/api/v2/admin/jobs/' . $job['id']);
        $this->assertContract($r, 'adminGetJob', 200);
        $this->assertSame('Échec : HTTP 401 : invalid x-api-key', $r->data()['summary']);
    }
}

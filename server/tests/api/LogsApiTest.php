<?php
// Journaux techniques dans l'admin (JRN-07) : GET /admin/logs sur des fichiers d'essai (courant,
// tourné, compressé), filtres, pagination, journal de sécurité.

declare(strict_types=1);

namespace Musique\Tests;

final class LogsApiTest extends ApiCase
{
    private const RID = '01M5ESSA1JRN07ABCDEFGHJKMN';
    private string $dir;
    /** @var list<string> */
    private array $created = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->dir = $this->testConfig()->string('journaux', 'dossier');
        $line = static fn (string $ts, string $level, string $msg, array $extra = []): string
            => json_encode(['ts' => $ts, 'level' => $level, 'channel' => 'cli', 'msg' => $msg] + $extra, JSON_UNESCAPED_UNICODE);
        // Le plus ancien, compressé
        $this->write('cli.log.2.gz', gzencode(implode("\n", [
            $line('2026-10-01T10:00:00.000Z', 'info', 'jrn07 a1'),
            $line('2026-10-01T10:00:01.000Z', 'info', 'jrn07 a2'),
        ]) . "\n"));
        $this->write('cli.log.1', implode("\n", [
            $line('2026-10-02T10:00:00.000Z', 'warning', 'jrn07 b1', ['ctx' => ['key' => 'Artiste/Album/01.mp3']]),
            'pas du JSON jrn07',
            $line('2026-10-02T10:00:01.000Z', 'info', 'jrn07 b2'),
        ]) . "\n");
        file_put_contents("$this->dir/cli.log", implode("\n", [
            $line('2026-10-03T10:00:00.000Z', 'debug', 'jrn07 c1'),
            $line('2026-10-03T10:00:01.000Z', 'error', 'jrn07 c2', ['requestId' => self::RID, 'userId' => 6, 'ctx' => ['code' => 'probe_failed']]),
        ]) . "\n", FILE_APPEND);
        $this->created[] = "$this->dir/cli.log";
        file_put_contents("$this->dir/securite.log", "2026-10-03T11:00:00Z musique event=login_failed ip=203.0.113.7 rid=" . self::RID . "\n", FILE_APPEND);
        $this->created[] = "$this->dir/securite.log";
    }

    private function write(string $name, string $content): void
    {
        $path = "$this->dir/$name";
        if (file_exists($path)) {
            $this->fail("$path existe déjà");
        }
        file_put_contents($path, $content);
        $this->created[] = $path;
    }

    public function tearDown(): void
    {
        foreach ($this->created as $f) {
            @unlink($f);
        }
    }

    /** @return array{0: list<string>, 1: ?string} messages et curseur suivant */
    private function page(string $query): array
    {
        $r = $this->as($this->token('admin'), 'GET', "/api/v2/admin/logs?$query");
        $this->assertContract($r, 'adminListLogs', 200);
        return [array_column($r->data()['items'], 'message'), $r->data()['next']];
    }

    public function testDuPlusRecentAuPlusAncienAvecFiltres(): void
    {
        [$all, $next] = $this->page('source=cli&q=JRN07&limit=200');
        $this->assertSame(['jrn07 c2', 'jrn07 c1', 'jrn07 b2', 'jrn07 b1', 'jrn07 a2', 'jrn07 a1'], $all, 'trois fichiers, la ligne illisible écartée');
        $this->assertNull($next);

        $this->assertSame(['jrn07 c2', 'jrn07 b1'], $this->page('source=cli&q=jrn07&level=warning')[0]);
        $r = $this->as($this->token('admin'), 'GET', '/api/v2/admin/logs?source=cli&requestId=' . self::RID);
        $this->assertSame([['jrn07 c2', 'error', 6, ['code' => 'probe_failed']]],
            array_map(static fn ($e) => [$e['message'], $e['level'], $e['userId'], $e['context']], $r->data()['items']));
        $this->assertSame(['jrn07 b1'], $this->page('source=cli&q=' . rawurlencode('artiste/album'))[0], 'recherche dans le contexte');
    }

    public function testPagesSuccessives(): void
    {
        $seen = [];
        $cursor = null;
        $pages = 0;
        do {
            [$items, $cursor] = $this->page('source=cli&q=jrn07&limit=2' . ($cursor !== null ? '&cursor=' . rawurlencode($cursor) : ''));
            array_push($seen, ...$items);
            $pages++;
        } while ($cursor !== null && $pages < 10);
        $this->assertSame(['jrn07 c2', 'jrn07 c1', 'jrn07 b2', 'jrn07 b1', 'jrn07 a2', 'jrn07 a1'], $seen);
        $this->assertTrue($pages >= 3, "pages : $pages");
    }

    public function testJournalDeSecuriteEtDroits(): void
    {
        $r = $this->as($this->token('admin'), 'GET', '/api/v2/admin/logs?source=securite&q=203.0.113.7');
        $this->assertContract($r, 'adminListLogs', 200);
        $e = $r->data()['items'][0];
        $this->assertSame(['login_failed', 'warning', self::RID, ['ip' => '203.0.113.7'], '2026-10-03T11:00:00.000Z'],
            [$e['message'], $e['level'], $e['requestId'], $e['context'], $e['ts']]);

        $this->assertError($this->as($this->token('admin'), 'GET', '/api/v2/admin/logs?source=apache'), 400, 'bad_request', 'adminListLogs');
        $this->assertError($this->as($this->token('admin'), 'GET', '/api/v2/admin/logs?cursor=../../etc'), 400, 'bad_request', 'adminListLogs');
        $this->assertError($this->as($this->token('admin'), 'GET', '/api/v2/admin/logs?requestId=abc'), 400, 'bad_request', 'adminListLogs');
        $this->assertError($this->as($this->token('user'), 'GET', '/api/v2/admin/logs'), 403, 'forbidden', 'adminListLogs');
        $this->assertError($this->as($this->token('agent'), 'GET', '/api/v2/admin/logs'), 403, 'forbidden', 'adminListLogs');
    }
}

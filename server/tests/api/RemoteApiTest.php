<?php
// Télécommande partagée (FUT-02) : liens, invité (cookie + anti-CSRF), droits, commandes relayées
// par le vrai hub Mercure du dev (sujets sous https://musique.test : ils ne croisent pas ceux du site).

declare(strict_types=1);

namespace Musique\Tests;

use Musique\Config;
use Musique\Realtime\Hub;

final class RemoteApiTest extends ApiCase
{
    private const ALL = ['add' => true, 'edit' => true, 'control' => true];

    /** @var list<array{proc: resource, file: string}> */
    private array $listeners = [];

    public function setUp(): void
    {
        parent::setUp();
        if (Hub::fromConfig(Config::load()) === null) {
            $this->skip('pas de section [mercure] dans la configuration');
        }
    }

    public function tearDown(): void
    {
        foreach ($this->listeners as $l) {
            proc_terminate($l['proc']);
            proc_close($l['proc']);
            @unlink($l['file']);
        }
        $this->db->run('DELETE FROM queue_shares');
        $this->db->run('DELETE FROM player_states');
        $this->db->run('DELETE FROM play_queues');
    }

    private function track(string $title): int
    {
        return (int) $this->db->value('SELECT id FROM tracks WHERE title = ?', [$title]);
    }

    /** @param array<string, bool> $rights @return array<string, mixed> QueueShareCreated */
    private function share(string $owner = 'user', array $rights = self::ALL, int $hours = 2): array
    {
        $r = $this->as($this->token($owner), 'POST', '/api/v2/me/shares', ['label' => 'Soirée', 'hours' => $hours, 'rights' => $rights]);
        $this->assertContract($r, 'createMyShare', 201);
        return $r->data();
    }

    /** Ouvre la télécommande : cookie et jeton anti-CSRF de l'invité. @return array{cookie: string, csrf: string, state: array<string, mixed>} */
    private function guest(string $secret): array
    {
        $r = $this->call('POST', '/api/v2/remote/session', ['token' => $secret]);
        $this->assertContract($r, 'openRemoteSession', 200);
        $cookie = null;
        foreach ($r->cookies as $c) {
            if (preg_match('/^musique_remote=([^;]+)/', $c, $m)) {
                $cookie = $m[1];
                $this->assertTrue(str_contains($c, 'HttpOnly') && str_contains($c, 'Secure') && str_contains($c, 'SameSite=Strict'), "cookie protégé : $c");
            }
        }
        $this->assertTrue($cookie !== null, 'cookie musique_remote posé');
        return ['cookie' => (string) $cookie, 'csrf' => $r->data()['csrfToken'], 'state' => $r->data()];
    }

    /** @param array{cookie: string, csrf: string} $g */
    private function asGuest(array $g, string $method, string $path, mixed $body = null, bool $csrf = true): ApiResponse
    {
        $h = ['Cookie' => 'musique_remote=' . $g['cookie']];
        if ($csrf) {
            $h['X-CSRF-Token'] = $g['csrf'];
        }
        return $this->call($method, $path, $body, $h);
    }

    /** Écoute un canal (flux SSE du hub, en local) jusqu'à la fin du test. @param array<string, mixed> $channel */
    private function listen(array $channel): int
    {
        $hub = Config::load()->string('mercure', 'hub_interne');
        $url = $hub . '?' . implode('&', array_map(static fn ($t) => 'match=' . rawurlencode($t), $channel['topics']));
        $file = tempnam(sys_get_temp_dir(), 'sse');
        $proc = proc_open(['curl', '-sN', '--max-time', '20', '-H', 'Authorization: Bearer ' . $channel['token'], $url],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $file, 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $this->listeners[] = ['proc' => $proc, 'file' => $file];
        // Le flux s'ouvre par un commentaire « : » : l'abonnement est en place
        for ($i = 0; $i < 50 && trim((string) file_get_contents($file)) === ''; $i++) {
            usleep(50_000);
        }
        return count($this->listeners) - 1;
    }

    /** Messages reçus par un canal (attend qu'il y en ait au moins $atLeast). @return list<array<string, mixed>> */
    private function received(int $listener, int $atLeast = 1): array
    {
        $out = [];
        for ($i = 0; $i < 40; $i++) {
            $out = [];
            foreach (explode("\n", (string) file_get_contents($this->listeners[$listener]['file'])) as $line) {
                if (str_starts_with($line, 'data: ')) {
                    $out[] = json_decode(substr($line, 6), true);
                }
            }
            if (count($out) >= $atLeast) {
                break;
            }
            usleep(50_000);
        }
        return $out;
    }

    private function report(string $who, string $device, bool $playing, ?int $trackId = null, ?int $volume = null): ApiResponse
    {
        $body = ['deviceKey' => $device, 'trackId' => $trackId, 'playing' => $playing, 'positionMs' => 1200, 'queueVersion' => 0];
        return $this->as($this->token($who), 'PUT', '/api/v2/me/player', $volume === null ? $body : $body + ['volume' => $volume]);
    }

    public function testAnnonceeParLeServeur(): void
    {
        $this->assertTrue($this->call('GET', '/api/v2/server')->data()['features']['remoteControl']);
    }

    public function testLiensCreerListerRevoquer(): void
    {
        $t = $this->token('user');
        $this->assertError($this->as($t, 'POST', '/api/v2/me/shares', ['hours' => 0, 'rights' => self::ALL]), 400, 'bad_request', 'createMyShare');
        $this->assertError($this->as($t, 'POST', '/api/v2/me/shares', ['hours' => 2]), 400, 'bad_request', 'createMyShare');
        $s = $this->share();
        $this->assertSame(['Soirée', self::ALL], [$s['label'], $s['rights']]);
        $this->assertSame('https://musique.test/telecommande#' . $s['token'], $s['url']);
        $this->assertSame(substr($s['token'], 0, 8), $s['prefix']);
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM queue_shares WHERE token_prefix <> LEFT(?, 8)', [$s['token']]));
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM queue_shares WHERE token_hash = ?', [hash('sha256', $s['token'], true)]), 'seule l’empreinte est gardée');

        $r = $this->as($t, 'GET', '/api/v2/me/shares');
        $this->assertContract($r, 'listMyShares', 200);
        $this->assertSame([$s['id']], array_column($r->data()['items'], 'id'));
        $this->assertSame([], $this->as($this->token('user2'), 'GET', '/api/v2/me/shares')->data()['items'], 'les liens des autres sont invisibles');
        $this->assertError($this->as($this->token('user2'), 'DELETE', "/api/v2/me/shares/{$s['id']}"), 404, 'not_found', 'revokeMyShare');

        // Sans nom : « Invité » ; au plus 10 liens valables
        $this->assertSame('Invité', $this->as($t, 'POST', '/api/v2/me/shares', ['hours' => 1, 'rights' => self::ALL])->data()['label']);
        for ($i = 0; $i < 8; $i++) {
            $this->share();
        }
        $this->assertError($this->as($t, 'POST', '/api/v2/me/shares', ['hours' => 1, 'rights' => self::ALL]), 409, 'conflict', 'createMyShare');

        $this->assertContract($this->as($t, 'DELETE', "/api/v2/me/shares/{$s['id']}"), 'revokeMyShare', 204);
        $this->assertSame(9, count($this->as($t, 'GET', '/api/v2/me/shares')->data()['items']));
        $this->assertError($this->as($t, 'DELETE', "/api/v2/me/shares/{$s['id']}"), 404, 'not_found', 'revokeMyShare');
    }

    public function testInviteVoitLaFileEtLesPochettes(): void
    {
        $t = $this->token('user');
        $ids = [$this->track('Formation'), $this->track('Dix'), $this->track('Formation')];
        $this->as($t, 'PUT', '/api/v2/me/queue', ['trackIds' => $ids, 'currentIndex' => 1, 'positionMs' => 0, 'mode' => 'normal'], ['If-Match' => '"0"']);
        $s = $this->share();

        $this->assertError($this->call('POST', '/api/v2/remote/session', ['token' => 'mr_' . str_repeat('x', 40)]), 401, 'invalid_token', 'openRemoteSession');
        $g = $this->guest($s['token']);
        $st = $g['state'];
        $this->assertSame(['Soirée', 'Utilisateur'], [$st['share']['label'], $st['share']['ownerName']]);
        $this->assertSame([1, 1], [$st['queue']['version'], $st['queue']['currentIndex']]);
        $this->assertSame(['Formation', 'Dix', 'Formation'], array_column($st['queue']['tracks'], 'title'), 'ordre et doublons');
        $this->assertSame(false, $st['player']['online']);
        $this->assertSame(['https://musique.test/shares/' . $s['id']], $st['channel']['topics']);

        $r = $this->asGuest($g, 'GET', '/api/v2/remote');
        $this->assertContract($r, 'getRemote', 200);
        $this->assertError($this->call('GET', '/api/v2/remote'), 401, 'unauthorized', 'getRemote');
        // Une session de compte ne vaut pas lien d'invité
        $this->assertError($this->as($t, 'GET', '/api/v2/remote'), 401, 'unauthorized', 'getRemote');

        // Pochettes de la file : accessibles à l'invité, pas sans rien
        $cover = $st['queue']['tracks'][0]['coverUrl'];
        $this->assertTrue(is_string($cover), 'la piste a une pochette');
        $this->assertSame(200, $this->asGuest($g, 'GET', $cover . '?size=s')->status);
        $this->assertSame(401, $this->call('GET', $cover . '?size=s')->status);
    }

    public function testRechercheDeLInvite(): void
    {
        $g = $this->guest($this->share()['token']);
        $r = $this->asGuest($g, 'GET', '/api/v2/remote/search?q=beyonce');
        $this->assertContract($r, 'searchRemote', 200);
        $this->assertSame(['Formation', 'Forward'], array_column($r->data()['tracks'], 'title'), 'par artiste, sans accent');
        $this->assertSame(['Lemonade'], array_column($r->data()['albums'], 'title'));
        $this->assertError($this->asGuest($g, 'GET', '/api/v2/remote/search?q='), 400, 'bad_request', 'searchRemote');

        $g2 = $this->guest($this->share('user', ['add' => false, 'edit' => true, 'control' => true])['token']);
        $this->assertError($this->asGuest($g2, 'GET', '/api/v2/remote/search?q=dix'), 403, 'forbidden', 'searchRemote');
    }

    public function testCommandesRelayeesAuLecteurQuiJoue(): void
    {
        $t = $this->token('user');
        $s = $this->share();
        $g = $this->guest($s['token']);

        // Lecteur éteint : refusé
        $this->assertError($this->asGuest($g, 'POST', '/api/v2/remote/commands', ['type' => 'pause']), 409, 'player_offline', 'sendRemoteCommand');

        // Le lecteur s'abonne à son canal et donne des nouvelles
        $ch = $this->as($t, 'GET', '/api/v2/me/realtime');
        $this->assertContract($ch, 'getMyRealtimeChannel', 200);
        $this->assertSame(true, $ch->data()['sharesActive']);
        $player = $this->listen($ch->data());
        $guestChannel = $this->listen($g['state']['channel']);
        $this->assertContract($this->report('user', 'ordinateur-1', true, $this->track('Dix')), 'reportMyPlayer', 204);
        $this->assertSame(['type' => 'changed'], $this->received($guestChannel)[0] ?? null, 'l’invité est prévenu');
        $st = $this->asGuest($g, 'GET', '/api/v2/remote')->data()['player'];
        $this->assertSame([true, true, $this->track('Dix')], [$st['online'], $st['playing'], $st['trackId']]);

        // Un second appareil en pause ne prend pas la main
        $this->assertSame(204, $this->report('user', 'telephone-2', false)->status);

        // Sans jeton anti-CSRF : refusé
        $this->assertError($this->asGuest($g, 'POST', '/api/v2/remote/commands', ['type' => 'pause'], false), 403, 'csrf_invalid', 'sendRemoteCommand');

        $r = $this->asGuest($g, 'POST', '/api/v2/remote/commands', ['type' => 'pause']);
        $this->assertContract($r, 'sendRemoteCommand', 202);
        $album = (int) $this->db->value('SELECT album_id FROM tracks WHERE title = ?', ['Deux']);
        $this->assertSame(202, $this->asGuest($g, 'POST', '/api/v2/remote/commands', ['type' => 'add', 'albumId' => $album, 'where' => 'next'])->status);
        $this->assertSame(202, $this->asGuest($g, 'POST', '/api/v2/remote/commands', ['type' => 'move', 'from' => 2, 'to' => 0, 'trackId' => $this->track('Dix')])->status);
        $this->assertSame(202, $this->asGuest($g, 'POST', '/api/v2/remote/commands', ['type' => 'jump', 'index' => 1, 'trackId' => $this->track('Deux'), 'name' => "  Paul\u{0007} "])->status);
        $this->assertError($this->asGuest($g, 'POST', '/api/v2/remote/commands', ['type' => 'pause', 'name' => str_repeat('x', 41)]), 400, 'bad_request', 'sendRemoteCommand');
        $this->assertSame(202, $this->asGuest($g, 'POST', '/api/v2/remote/commands', ['type' => 'add', 'trackIds' => [$this->track('Dix')], 'where' => 'now'])->status);
        $this->assertError($this->asGuest($g, 'POST', '/api/v2/remote/commands', ['type' => 'jump', 'index' => 1]), 400, 'bad_request', 'sendRemoteCommand');
        $this->assertError($this->asGuest($g, 'POST', '/api/v2/remote/commands', ['type' => 'add', 'trackIds' => [999999]]), 400, 'bad_request', 'sendRemoteCommand');
        $this->assertError($this->asGuest($g, 'POST', '/api/v2/remote/commands', ['type' => 'remove', 'index' => 0]), 400, 'bad_request', 'sendRemoteCommand');
        $this->assertError($this->asGuest($g, 'POST', '/api/v2/remote/commands', ['type' => 'shuffle']), 400, 'bad_request', 'sendRemoteCommand');

        $msgs = array_values(array_filter($this->received($player, 5), static fn ($m) => $m['type'] === 'command'));
        $this->assertSame(5, count($msgs), 'cinq commandes valables relayées');
        $this->assertSame(['type' => 'command', 'target' => 'ordinateur-1', 'by' => 'Soirée', 'command' => ['type' => 'pause']], $msgs[0], 'sans pistes pour une pause');
        $this->assertSame(['type' => 'add', 'trackIds' => [$this->track('Deux'), $this->track('Deux bis')], 'where' => 'next'], $msgs[1]['command']);
        $this->assertSame(['Deux', 'Deux bis'], array_column($msgs[1]['tracks'], 'title'), 'pistes complètes jointes');
        $this->assertSame([], Contract::validate(json_decode((string) json_encode($msgs[1]['tracks'][0])), Contract::resolve('#/components/schemas/Track')), 'piste conforme au contrat');
        $this->assertSame(['type' => 'move', 'from' => 2, 'to' => 0, 'trackId' => $this->track('Dix')], $msgs[2]['command']);
        $this->assertSame(['type' => 'jump', 'index' => 1, 'trackId' => $this->track('Deux')], $msgs[3]['command']);
        $this->assertSame('Paul', $msgs[3]['by'], 'prénom de l’invité, nettoyé');
        $this->assertSame('Soirée', $msgs[4]['by'], 'sans prénom : nom du lien');
        $this->assertSame(['type' => 'add', 'trackIds' => [$this->track('Dix')], 'where' => 'now'], $msgs[4]['command']);

        // Le lecteur enregistre la file : l'invité est prévenu
        $before = count($this->received($guestChannel));
        $this->as($t, 'PUT', '/api/v2/me/queue', ['trackIds' => [$this->track('Dix')], 'currentIndex' => 0, 'positionMs' => 0, 'mode' => 'normal'], ['If-Match' => '"0"']);
        $this->assertSame($before + 1, count($this->received($guestChannel, $before + 1)));

        // Révocation : l'invité est prévenu et ne peut plus rien
        $this->as($t, 'DELETE', "/api/v2/me/shares/{$s['id']}");
        $last = $this->received($guestChannel, $before + 2);
        $this->assertSame(['type' => 'revoked'], end($last));
        $r = $this->asGuest($g, 'POST', '/api/v2/remote/commands', ['type' => 'play']);
        $this->assertError($r, 401, 'unauthorized', 'sendRemoteCommand');
        $this->assertTrue(str_contains(implode("\n", $r->cookies), 'musique_remote=;'), 'cookie effacé');
        $this->assertError($this->call('POST', '/api/v2/remote/session', ['token' => $s['token']]), 401, 'invalid_token', 'openRemoteSession');
        $shares = array_values(array_filter($this->received($player, 4), static fn ($m) => $m['type'] === 'shares'));
        $this->assertTrue(count($shares) >= 1, 'le lecteur apprend la révocation');
    }

    public function testVolumeDuLecteur(): void
    {
        $g = $this->guest($this->share()['token']);
        // Lecteur qui ne donne pas son volume : null pour l'invité
        $this->assertSame(204, $this->report('user', 'ordinateur-1', true)->status);
        $st = $this->asGuest($g, 'GET', '/api/v2/remote');
        $this->assertContract($st, 'getRemote', 200);
        $this->assertSame(null, $st->data()['player']['volume']);

        $this->assertContract($this->report('user', 'ordinateur-1', true, null, 65), 'reportMyPlayer', 204);
        $st = $this->asGuest($g, 'GET', '/api/v2/remote');
        $this->assertContract($st, 'getRemote', 200);
        $this->assertSame(65, $st->data()['player']['volume']);
        $this->assertError($this->report('user', 'ordinateur-1', true, null, 101), 400, 'bad_request', 'reportMyPlayer');

        // Commande relayée au lecteur
        $player = $this->listen($this->as($this->token('user'), 'GET', '/api/v2/me/realtime')->data());
        $this->assertContract($this->asGuest($g, 'POST', '/api/v2/remote/commands', ['type' => 'volume', 'volume' => 30]), 'sendRemoteCommand', 202);
        $this->assertError($this->asGuest($g, 'POST', '/api/v2/remote/commands', ['type' => 'volume']), 400, 'bad_request', 'sendRemoteCommand');
        $this->assertError($this->asGuest($g, 'POST', '/api/v2/remote/commands', ['type' => 'volume', 'volume' => 120]), 400, 'bad_request', 'sendRemoteCommand');
        $msgs = array_values(array_filter($this->received($player), static fn ($m) => $m['type'] === 'command'));
        $this->assertSame(['type' => 'volume', 'volume' => 30], $msgs[0]['command'] ?? null);

        // Sans le droit « commander la lecture » : refusé
        $g2 = $this->guest($this->share('user', ['add' => true, 'edit' => true, 'control' => false])['token']);
        $this->assertError($this->asGuest($g2, 'POST', '/api/v2/remote/commands', ['type' => 'volume', 'volume' => 30]), 403, 'forbidden', 'sendRemoteCommand');
    }

    public function testDroitsDuLien(): void
    {
        $this->assertSame(204, $this->report('user', 'ordinateur-1', true)->status);
        $g = $this->guest($this->share('user', ['add' => true, 'edit' => false, 'control' => false])['token']);
        $this->assertError($this->asGuest($g, 'POST', '/api/v2/remote/commands', ['type' => 'next']), 403, 'forbidden', 'sendRemoteCommand');
        $this->assertError($this->asGuest($g, 'POST', '/api/v2/remote/commands', ['type' => 'jump', 'index' => 0, 'trackId' => 1]), 403, 'forbidden', 'sendRemoteCommand');
        $this->assertError($this->asGuest($g, 'POST', '/api/v2/remote/commands', ['type' => 'add', 'trackIds' => [$this->track('Dix')], 'where' => 'now']), 403, 'forbidden', 'sendRemoteCommand');
        $this->assertError($this->asGuest($g, 'POST', '/api/v2/remote/commands', ['type' => 'remove', 'index' => 0, 'trackId' => 1]), 403, 'forbidden', 'sendRemoteCommand');
        $this->assertSame(202, $this->asGuest($g, 'POST', '/api/v2/remote/commands', ['type' => 'add', 'trackIds' => [$this->track('Dix')]])->status);

        // Lien expiré : plus rien
        $s = $this->share();
        $this->db->run('UPDATE queue_shares SET expires_at = CURRENT_TIMESTAMP(3) - INTERVAL 1 SECOND WHERE id = ?', [$s['id']]);
        $this->assertError($this->call('POST', '/api/v2/remote/session', ['token' => $s['token']]), 401, 'invalid_token', 'openRemoteSession');
        $this->assertSame([], array_values(array_filter($this->as($this->token('user'), 'GET', '/api/v2/me/shares')->data()['items'], static fn ($i) => $i['id'] === $s['id'])));
    }

    public function testAdminDesactiveLaTelecommande(): void
    {
        $s = $this->share();
        $g = $this->guest($s['token']);
        $guestListener = $this->listen($g['state']['channel']);
        $owner = $this->as($this->token('user'), 'GET', '/api/v2/me/realtime')->data();
        $ownerListener = $this->listen($owner);
        $admin = $this->token('admin');
        $state = self::$env['base'] . '/mercure-etat';
        @unlink($state);
        $this->assertError($this->as($this->token('user'), 'PATCH', '/api/v2/admin/server-settings', ['remoteControlEnabled' => false]), 403, 'forbidden', 'adminUpdateServerSettings');

        try {
            $r = $this->as($admin, 'PATCH', '/api/v2/admin/server-settings', ['remoteControlEnabled' => false]);
            $this->assertContract($r, 'adminUpdateServerSettings', 200);
            $this->assertFalse($r->data()['remoteControlEnabled']);
            $this->assertSame("off\n", (string) @file_get_contents($state), 'arrêt du service demandé');
            $this->assertTrue(in_array(['type' => 'disabled'], $this->received($guestListener), true), 'l’invité est prévenu');
            $this->assertTrue(in_array(['type' => 'disabled'], $this->received($ownerListener), true), 'le lecteur est prévenu');

            $this->assertFalse($this->call('GET', '/api/v2/server')->data()['features']['remoteControl']);
            $this->assertError($this->as($this->token('user'), 'GET', '/api/v2/me/realtime'), 503, 'realtime_unavailable', 'getMyRealtimeChannel');
            $this->assertError($this->as($this->token('user'), 'POST', '/api/v2/me/shares', ['hours' => 1, 'rights' => self::ALL]), 503, 'realtime_unavailable', 'createMyShare');
            $this->assertError($this->call('POST', '/api/v2/remote/session', ['token' => $s['token']]), 503, 'realtime_unavailable', 'openRemoteSession');
            $this->assertError($this->asGuest($g, 'GET', '/api/v2/remote'), 503, 'realtime_unavailable', 'getRemote');
            $this->assertError($this->asGuest($g, 'GET', '/api/v2/remote/search?q=a'), 503, 'realtime_unavailable', 'searchRemote');
            $this->assertError($this->asGuest($g, 'POST', '/api/v2/remote/commands', ['type' => 'pause']), 503, 'realtime_unavailable', 'sendRemoteCommand');
            $this->assertSame([$s['id']], array_column($this->as($this->token('user'), 'GET', '/api/v2/me/shares')->data()['items'], 'id'), 'les liens ne sont pas révoqués');
            $this->assertTrue((int) $this->db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'server.settings' AND JSON_EXTRACT(after_value, '$.remoteControlEnabled') = 'false'") >= 1, 'inscrit au journal d’audit');
        } finally {
            $r = $this->as($admin, 'PATCH', '/api/v2/admin/server-settings', ['remoteControlEnabled' => true]);
        }
        $this->assertContract($r, 'adminUpdateServerSettings', 200);
        $this->assertSame("on\n", (string) @file_get_contents($state), 'démarrage demandé');
        $this->assertSame('running', $r->data()['realtimeService']);
        $this->assertTrue($this->call('GET', '/api/v2/server')->data()['features']['remoteControl']);
        $this->assertContract($this->asGuest($g, 'GET', '/api/v2/remote'), 'getRemote', 200);
    }
}

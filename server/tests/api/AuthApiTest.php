<?php
// Authentification (API-02, CPT-01, CPT-02, CPT-05) et socle (API-01, API-07) par HTTP.

declare(strict_types=1);

namespace Musique\Tests;

require_once __DIR__ . '/ApiCase.php';

final class AuthApiTest extends ApiCase
{
    public function testServeurSansAuthentification(): void
    {
        $r = $this->call('GET', '/api/v2/server');
        $this->assertContract($r, 'getServer', 200);
        $this->assertSame(\Musique\Api\Kernel::VERSION, $r->data()['apiVersion']);
        $this->assertTrue(!isset($r->data()['library']), 'taille de la bibliothèque seulement pour un client connecté');
        $r = $this->as($this->token('user'), 'GET', '/api/v2/server');
        $this->assertContract($r, 'getServer', 200);
        $this->assertSame(5, $r->data()['library']['tracks']);
    }

    public function testErreursUniformesEtEnTetes(): void
    {
        $r = $this->call('GET', '/api/v2/albums');
        $this->assertError($r, 401, 'unauthorized', 'listAlbums');
        $this->assertSame('nosniff', $r->headers['x-content-type-options'] ?? null);
        $this->assertSame('no-store', $r->headers['cache-control'] ?? null);
        $this->assertTrue(!isset($r->headers['access-control-allow-origin']), 'pas de CORS');

        $r = $this->as($this->token('user'), 'GET', '/api/v2/albums/999999');
        $this->assertError($r, 404, 'not_found', 'getAlbum');
        $r = $this->as($this->token('user'), 'GET', '/api/v2/albums?limit=9999');
        $this->assertError($r, 400, 'bad_request', 'listAlbums');
        $this->assertSame('limit', $r->data()['error']['details']['field']);
        $r = $this->as($this->token('user'), 'GET', '/api/v2/albums?sort=pif');
        $this->assertError($r, 400, 'bad_request', 'listAlbums');

        $r = $this->call('GET', '/api/v2/rien-ici');
        $this->assertSame(404, $r->status);
        $r = $this->call('DELETE', '/api/v2/server');
        $this->assertSame(405, $r->status);
        $this->assertTrue(str_contains($r->headers['allow'] ?? '', 'GET'), 'en-tête Allow');
        $r = $this->call('POST', '/api/v2/auth/login', '{pas du json', []);
        $this->assertSame(400, $r->status);
    }

    public function testRoles(): void
    {
        $r = $this->as($this->token('user'), 'GET', '/api/v2/admin/users');
        $this->assertError($r, 403, 'forbidden', 'adminListUsers');
        $r = $this->as($this->token('agent'), 'GET', '/api/v2/admin/users');
        $this->assertError($r, 403, 'forbidden', 'adminListUsers');
        // L'agent lit les lots et en propose, sans pouvoir les appliquer ; un utilisateur n'y a pas accès
        $this->assertContract($this->as($this->token('agent'), 'GET', '/api/v2/admin/change-sets'), 'adminListChangeSets', 200);
        $this->assertError($this->as($this->token('user'), 'GET', '/api/v2/admin/change-sets'), 403, 'forbidden', 'adminListChangeSets');
        $this->assertContract($this->as($this->token('agent'), 'GET', '/api/v2/admin/genre-aliases'), 'adminListGenreAliases', 200);
        $r = $this->as($this->token('agent'), 'GET', '/api/v2/albums');
        $this->assertContract($r, 'listAlbums', 200);
    }

    public function testAgentLimiteALaBibliotheque(): void
    {
        $agent = $this->token('agent');
        // Ce qu'il peut faire : lire le catalogue et la bibliothèque côté admin, se présenter
        $album = (int) $this->db->value('SELECT id FROM albums ORDER BY id LIMIT 1');
        $track = (int) $this->db->value('SELECT id FROM tracks ORDER BY id LIMIT 1');
        foreach ([
            ['/api/v2/me', 'getMe'], ['/api/v2/albums', 'listAlbums'], ["/api/v2/albums/$album", 'getAlbum'],
            ['/api/v2/artists', 'listArtists'], ["/api/v2/tracks/$track", 'getTrack'], ['/api/v2/genres', 'listGenres'],
            ['/api/v2/search?q=di', 'search'], ['/api/v2/collections', 'listCollections'],
            ['/api/v2/admin/duplicates', 'adminListDuplicates'], ['/api/v2/admin/same-recordings', 'adminListSameRecordings'],
            ['/api/v2/admin/damaged-tracks', 'adminListDamagedTracks'], ['/api/v2/admin/inbox', 'adminListInbox'],
            ["/api/v2/admin/albums/$album", 'adminGetAlbum'], ['/api/v2/server', 'getServer'],
        ] as [$url, $op]) {
            $this->assertContract($this->as($agent, 'GET', $url), $op, 200);
        }
        $this->assertSame('agent', $this->as($agent, 'GET', '/api/v2/me')->data()['user']['role'] ?? null);

        // Ce qu'il ne peut pas faire : écouter, toucher aux données d'un compte, appairer un appareil
        foreach ([
            ['GET', "/api/v2/tracks/$track/stream"], ['GET', '/api/v2/me/settings'], ['GET', '/api/v2/me/queue'],
            ['GET', '/api/v2/me/favorites'], ['PUT', "/api/v2/me/favorites/album/$album"], ['GET', '/api/v2/playlists'],
            ['POST', '/api/v2/playlists', ['name' => 'x']], ['GET', '/api/v2/me/history'], ['GET', '/api/v2/me/devices'],
            ['POST', '/api/v2/me/pairing-codes'], ['GET', '/api/v2/me/shares'], ['POST', '/api/v2/me/shares', ['label' => 'x']],
            ['PUT', '/api/v2/me/player', ['deviceKey' => 'abcdefgh', 'trackId' => null, 'playing' => false, 'positionMs' => 0, 'queueVersion' => 0]],
            ['PATCH', '/api/v2/me', ['displayName' => 'x']], ['GET', '/api/v2/random/tracks'], ['GET', '/api/v2/backgrounds'],
            ['GET', '/api/v2/admin/jobs'], ['GET', '/api/v2/admin/audit'],
        ] as $c) {
            $r = $this->as($agent, $c[0], $c[1], $c[2] ?? null);
            $this->assertSame([403, 'forbidden'], [$r->status, $r->data()['error']['code'] ?? null], "{$c[0]} {$c[1]}");
        }
        // Un utilisateur garde tout ce qui n'est pas admin
        $this->assertSame(200, $this->as($this->token('user'), 'GET', '/api/v2/me/settings')->status);
    }

    public function testAgentRefuseSurToutesLesRoutesQuiNeLeCitentPas(): void
    {
        $agent = $this->token('agent');
        $n = 0;
        foreach (\Musique\Api\Routes::build()->routes() as $route) {
            $o = $route['options'];
            if (($o['public'] ?? false) || ($o['guest'] ?? null) === 'only' || in_array('agent', $o['roles'] ?? ['admin', 'user'], true)) {
                continue;
            }
            $url = '/api/v2' . preg_replace('/\{[^}]+\}/', '1', $route['path']);
            $r = $this->as($agent, $route['method'], $url, in_array($route['method'], ['GET', 'DELETE'], true) ? null : []);
            $this->assertSame(403, $r->status, "{$route['method']} {$route['path']}");
            $n++;
        }
        $this->assertTrue($n > 50, "routes vérifiées : $n");
    }

    public function testConnexionWebCookieEtAntiCsrf(): void
    {
        $r = $this->call('POST', '/api/v2/auth/login', ['email' => 'USER@test.fr', 'password' => self::PASSWORD, 'client' => 'web'],
            ['User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) Gecko/20100101 Firefox/140.0']);
        $this->assertContract($r, 'login', 200);
        $cookieLine = implode("\n", $r->cookies);
        foreach (['HttpOnly', 'Secure', 'SameSite=Strict', 'Path=/api/'] as $flag) {
            $this->assertTrue(str_contains($cookieLine, $flag), "cookie : $flag");
        }
        $session = (string) $r->sessionCookie();
        $csrf = $r->data()['csrfToken'];
        $this->assertTrue(!isset($r->data()['token']), 'pas de jeton en clair pour le web');
        $cookie = ['Cookie' => "musique_session=$session"];

        $me = $this->call('GET', '/api/v2/me', null, $cookie);
        $this->assertContract($me, 'getMe', 200);
        $this->assertSame($csrf, $me->data()['csrfToken'], 'GET /me redonne le même jeton anti-CSRF');

        $r = $this->call('PATCH', '/api/v2/me', ['displayName' => 'Nouveau nom'], $cookie);
        $this->assertError($r, 403, 'csrf_invalid', 'updateMe');
        $r = $this->call('PATCH', '/api/v2/me', ['displayName' => 'Nouveau nom'], $cookie + ['X-CSRF-Token' => 'faux']);
        $this->assertSame(403, $r->status);
        $r = $this->call('PATCH', '/api/v2/me', ['displayName' => '  Utilisateur  '], $cookie + ['X-CSRF-Token' => $csrf]);
        $this->assertContract($r, 'updateMe', 200);
        $this->assertSame('Utilisateur', $r->data()['displayName']);

        $devices = $this->call('GET', '/api/v2/me/devices', null, $cookie);
        $this->assertContract($devices, 'listMyDevices', 200);
        $current = array_values(array_filter($devices->data()['items'], static fn ($d) => $d['current']));
        $this->assertSame('Firefox sur Linux', $current[0]['name'] ?? null);

        // Un cookie ne vaut pas jeton Bearer, et inversement
        $r = $this->call('GET', '/api/v2/me', null, ['Authorization' => "Bearer $session"]);
        $this->assertSame(401, $r->status);

        $r = $this->call('POST', '/api/v2/auth/logout', null, $cookie + ['X-CSRF-Token' => $csrf]);
        $this->assertContract($r, 'logout', 204);
        $this->assertTrue(str_contains(implode("\n", $r->cookies), 'Max-Age=0'), 'cookie effacé');
        $r = $this->call('GET', '/api/v2/me', null, $cookie);
        $this->assertError($r, 401, 'unauthorized', 'getMe');
    }

    public function testConnexionAppliEtRevocation(): void
    {
        $r = $this->call('POST', '/api/v2/auth/login', ['email' => 'user@test.fr', 'password' => self::PASSWORD, 'client' => 'app']);
        $this->assertError($r, 400, 'bad_request', 'login');
        $this->assertSame('deviceName', $r->data()['error']['details']['field']);

        $r = $this->call('POST', '/api/v2/auth/login', ['email' => 'user@test.fr', 'password' => self::PASSWORD, 'client' => 'app', 'deviceName' => 'Pixel']);
        $this->assertContract($r, 'login', 200);
        $token = $r->data()['token'];
        $id = $r->data()['tokenId'];
        $hash = $this->db->value('SELECT HEX(token_hash) FROM access_tokens WHERE id = ?', [$id]);
        $this->assertSame(strtoupper(hash('sha256', $token)), $hash, 'la base ne garde que l’empreinte');

        $this->assertContract($this->as($token, 'GET', '/api/v2/me'), 'getMe', 200);
        // Révoqué depuis un autre appareil du même compte
        $r = $this->as($this->token('user'), 'DELETE', "/api/v2/me/devices/$id");
        $this->assertContract($r, 'revokeMyDevice', 204);
        $this->assertError($this->as($token, 'GET', '/api/v2/me'), 401, 'unauthorized', 'getMe');
        // Pas les appareils des autres
        $other = $this->db->value('SELECT id FROM access_tokens WHERE user_id = ? AND revoked_at IS NULL LIMIT 1', [$this->userId('agent')]);
        $this->assertError($this->as($this->token('user'), 'DELETE', "/api/v2/me/devices/$other"), 404, 'not_found', 'revokeMyDevice');
    }

    public function testMauvaisMotDePassePuisVerrouillage(): void
    {
        $login = fn (string $pw) => $this->call('POST', '/api/v2/auth/login', ['email' => 'verrou@test.fr', 'password' => $pw, 'client' => 'app', 'deviceName' => 'x']);
        for ($i = 1; $i < \Musique\Api\Controllers\AuthController::MAX_FAILURES; $i++) {
            $this->assertError($login('faux'), 401, 'invalid_credentials', 'login');
        }
        $this->assertError($login('faux'), 401, 'invalid_credentials', 'login'); // celui-ci verrouille ce réseau
        // Bon mot de passe depuis le même réseau : refusé, comme un mauvais (le verrou ne se trahit pas)
        $this->assertError($login(self::PASSWORD), 401, 'invalid_credentials', 'login');
        // Depuis un autre réseau, le titulaire se connecte : un inconnu ne peut pas le bloquer
        $ch = curl_init(self::$env['url'] . '/api/v2/auth/login');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_INTERFACE => '127.0.0.2',
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['email' => 'verrou@test.fr', 'password' => self::PASSWORD, 'client' => 'app', 'deviceName' => 'x'])]);
        curl_exec($ch);
        $this->assertSame(200, curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'connexion depuis 127.0.0.2');
        $this->assertSame(['127.0.0.1'], array_column($this->db->all(
            'SELECT network FROM login_failures WHERE user_id = (SELECT id FROM users WHERE email = ?)', ['verrou@test.fr']), 'network'));
        // Compte inconnu : même réponse qu'un mauvais mot de passe
        $r = $this->call('POST', '/api/v2/auth/login', ['email' => 'inconnu@test.fr', 'password' => 'x', 'client' => 'web']);
        $this->assertError($r, 401, 'invalid_credentials', 'login');
        $log = (string) file_get_contents(self::$env['base'] . '/journaux/securite.log');
        $this->assertTrue(str_contains($log, 'event=account_locked'), 'verrouillage au journal de sécurité');
        $this->assertTrue(!str_contains($log, 'verrou@test.fr'), 'jamais d’adresse e-mail au journal de sécurité');
    }

    public function testComptesEnAttenteEtSuspendus(): void
    {
        $r = $this->call('POST', '/api/v2/auth/login', ['email' => 'attente@test.fr', 'password' => self::PASSWORD, 'client' => 'web']);
        $this->assertError($r, 403, 'account_pending', 'login');
        $r = $this->call('POST', '/api/v2/auth/login', ['email' => 'suspendu@test.fr', 'password' => self::PASSWORD, 'client' => 'web']);
        $this->assertError($r, 403, 'account_suspended', 'login');
        // Un compte agent n'a pas de mot de passe
        $r = $this->call('POST', '/api/v2/auth/login', ['email' => 'agent@test.fr', 'password' => '', 'client' => 'web']);
        $this->assertError($r, 401, 'invalid_credentials', 'login');
    }

    public function testDemandeDeCompteLimiteeParReseau(): void
    {
        $ask = fn (string $email) => $this->call('POST', '/api/v2/auth/register',
            ['email' => $email, 'displayName' => 'Nouveau', 'password' => 'un-long-mot-de-passe', 'message' => 'Bonjour']);
        $this->assertContract($ask('nouveau1@test.fr'), 'register', 202);
        $this->assertContract($ask('nouveau2@test.fr'), 'register', 202);
        $this->assertError($ask('nouveau3@test.fr'), 429, 'pending_limit', 'register');
        $row = $this->db->one("SELECT status, request_network, password_hash FROM users WHERE email = 'nouveau1@test.fr'");
        $this->assertSame('pending', $row['status']);
        $this->assertSame('::ffff:127.0.0.1', $row['request_network']);
        $this->assertTrue(str_starts_with((string) $row['password_hash'], '$argon2id$'), 'argon2id');

        // Adresse déjà connue : même réponse, rien de créé (une fois la limite libérée)
        $this->db->run("UPDATE users SET status = 'refused', request_network = NULL WHERE email = 'nouveau2@test.fr'");
        $before = (int) $this->db->value('SELECT COUNT(*) FROM users');
        $this->assertContract($ask('user@test.fr'), 'register', 202);
        $this->assertSame($before, (int) $this->db->value('SELECT COUNT(*) FROM users'));

        $r = $this->call('POST', '/api/v2/auth/register', ['email' => 'pas-une-adresse', 'displayName' => 'X', 'password' => 'un-long-mot-de-passe']);
        $this->assertError($r, 400, 'bad_request', 'register');
        $r = $this->call('POST', '/api/v2/auth/register', ['email' => 'court@test.fr', 'displayName' => 'X', 'password' => 'court']);
        $this->assertSame('password', $r->data()['error']['details']['field'] ?? null);
    }

    public function testReseauDesDemandes(): void
    {
        $this->assertSame('203.0.113.7', \Musique\Api\Format::network('203.0.113.7'));
        $this->assertSame('203.0.113.7', \Musique\Api\Format::network('::ffff:203.0.113.7'));
        $this->assertSame('2001:db8:1:2::', \Musique\Api\Format::network('2001:db8:1:2:aaaa:bbbb:cccc:dddd'));
        $this->assertSame(\Musique\Api\Format::network('2001:db8:1:2::1'), \Musique\Api\Format::network('2001:db8:1:2:ffff::9'));
    }

    public function testAppairageParCode(): void
    {
        $r = $this->as($this->token('user2'), 'POST', '/api/v2/me/pairing-codes');
        $this->assertContract($r, 'createPairingCode', 201);
        $code = $r->data()['code'];
        $this->assertTrue((bool) preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}$/', $code), "format du code : $code");
        $this->assertTrue(str_starts_with($r->data()['qrPayload'], 'rengaine://pair?server=https%3A%2F%2Fmusique.test&code='), 'contenu du QR');

        $r = $this->call('POST', '/api/v2/auth/pair', ['code' => strtolower(str_replace('-', ' ', $code)), 'deviceName' => 'Téléphone']);
        $this->assertContract($r, 'pairDevice', 200);
        $this->assertSame($this->userId('user2'), $r->data()['user']['id']);
        $this->assertContract($this->as($r->data()['token'], 'GET', '/api/v2/me'), 'getMe', 200);

        $r = $this->call('POST', '/api/v2/auth/pair', ['code' => $code, 'deviceName' => 'Autre']);
        $this->assertError($r, 401, 'invalid_code', 'pairDevice');
    }

    public function testChangementDeMotDePasseRevoqueLesAutres(): void
    {
        $other = $this->call('POST', '/api/v2/auth/login', ['email' => 'user2@test.fr', 'password' => self::PASSWORD, 'client' => 'app', 'deviceName' => 'Tablette'])->data()['token'];
        $r = $this->as($this->token('user2'), 'POST', '/api/v2/me/password', ['currentPassword' => 'faux', 'newPassword' => 'nouveau-mot-de-passe']);
        $this->assertError($r, 401, 'invalid_credentials', 'changePassword');
        $r = $this->as($this->token('user2'), 'POST', '/api/v2/me/password', ['currentPassword' => self::PASSWORD, 'newPassword' => 'nouveau-mot-de-passe']);
        $this->assertContract($r, 'changePassword', 204);
        $this->assertSame(200, $this->as($this->token('user2'), 'GET', '/api/v2/me')->status, 'l’appareil qui change garde son accès');
        $this->assertSame(401, $this->as($other, 'GET', '/api/v2/me')->status, 'les autres sont déconnectés');
        $this->db->run('UPDATE users SET password_hash = ? WHERE id = ?', [\Musique\Auth\Passwords::hash(self::PASSWORD), $this->userId('user2')]);
    }

    public function testLimitationDuDebit(): void
    {
        $url = self::server(['connexions_par_10min' => 3]);
        $codes = [];
        for ($i = 0; $i < 4; $i++) {
            $codes[] = $this->call('POST', '/api/v2/auth/login', ['email' => 'inconnu@test.fr', 'password' => 'x', 'client' => 'web'], [], $url)->status;
        }
        $this->assertSame([401, 401, 401, 429], $codes);
        $r = $this->call('POST', '/api/v2/auth/login', ['email' => 'inconnu@test.fr', 'password' => 'x', 'client' => 'web'], [], $url);
        $this->assertError($r, 429, 'rate_limited', 'login');
        $this->assertTrue((int) ($r->headers['retry-after'] ?? 0) > 0, 'Retry-After');
    }

    public function testAudit(): void
    {
        $this->token('user');
        $n = (int) $this->db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'auth.login' AND actor_user_id = ? AND request_id IS NOT NULL", [$this->userId('user')]);
        $this->assertTrue($n >= 1, 'connexion au journal d’audit, avec le requestId');
        $leak = (int) $this->db->value("SELECT COUNT(*) FROM audit_log WHERE after_value LIKE '%mu\\_%' OR after_value LIKE '%password%'");
        $this->assertSame(0, $leak, 'aucun secret dans l’audit');
    }
}

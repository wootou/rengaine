<?php
// Suppression de compte par son titulaire (CPT-09, DELETE /me) : immédiate, totale, mot de passe redemandé.

declare(strict_types=1);

namespace Musique\Tests;

use Musique\Auth\Passwords;

require_once __DIR__ . '/ApiCase.php';

final class AccountDeleteApiTest extends ApiCase
{
    /** Compte jetable (les comptes de test partagés ne sont jamais supprimés). @return array{id: int, email: string, token: string} */
    private function throwaway(string $role = 'user'): array
    {
        $email = 'jetable-' . bin2hex(random_bytes(4)) . '@test.fr';
        $this->db->run("INSERT INTO users (email, display_name, password_hash, role, status) VALUES (?, 'Jetable', ?, ?, 'active')",
            [$email, Passwords::hash(self::PASSWORD), $role]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $r = $this->call('POST', '/api/v2/auth/login', ['email' => $email, 'password' => self::PASSWORD, 'client' => 'app', 'deviceName' => 'Jetable']);
        $this->assertSame(200, $r->status, $r->raw);
        return ['id' => $id, 'email' => $email, 'token' => $r->data()['token']];
    }

    /** Lignes restantes du compte, table par table. @return array<string, int> */
    private function leftovers(int $id, string $email): array
    {
        $out = [];
        foreach (['users' => 'id', 'access_tokens' => 'user_id', 'user_settings' => 'user_id', 'play_queues' => 'user_id', 'favorites' => 'user_id',
            'playlists' => 'user_id', 'play_history' => 'user_id', 'pairing_codes' => 'user_id', 'queue_shares' => 'user_id',
            'player_states' => 'user_id', 'mail_queue' => 'user_id', 'audit_log' => 'actor_user_id'] as $table => $col) {
            $n = (int) $this->db->value("SELECT COUNT(*) FROM $table WHERE $col = ?", [$id]);
            if ($n > 0) {
                $out[$table] = $n;
            }
        }
        $n = (int) $this->db->value('SELECT COUNT(*) FROM mail_queue WHERE to_address = ?', [$email]);
        if ($n > 0) {
            $out['mail_queue (adresse)'] = $n;
        }
        return $out;
    }

    public function testToutPartAvecLeCompte(): void
    {
        $u = $this->throwaway();
        $t = $u['token'];
        $track = (int) $this->db->value("SELECT id FROM tracks WHERE title = 'Deux'");
        $album = (int) $this->db->value('SELECT album_id FROM tracks WHERE id = ?', [$track]);
        // Un peu de tout : favoris, playlist, historique, file, réglages, appairage, lien, état du lecteur
        $this->assertSame(204, $this->as($t, 'PUT', "/api/v2/me/favorites/album/$album")->status);
        $pl = $this->as($t, 'POST', '/api/v2/playlists', ['name' => 'À moi'])->data()['id'];
        $this->assertSame(true, in_array($this->as($t, 'POST', "/api/v2/playlists/$pl/items", ['trackIds' => [$track]])->status, [200, 201, 204], true));
        $this->assertSame(204, $this->as($t, 'POST', '/api/v2/me/history', ['plays' => [['trackId' => $track, 'startedAt' => '2026-10-19T08:30:00Z', 'playedMs' => 9000]]])->status);
        $this->as($t, 'PUT', '/api/v2/me/queue', ['trackIds' => [$track], 'currentIndex' => 0, 'positionMs' => 0, 'mode' => 'normal'], ['If-Match' => '"0"']);
        $this->as($t, 'POST', '/api/v2/me/pairing-codes');
        $this->as($t, 'POST', '/api/v2/me/shares', ['label' => 'Soirée', 'hours' => 1, 'rights' => ['add' => true, 'edit' => true, 'control' => true]]);
        $this->as($t, 'PUT', '/api/v2/me/player', ['deviceKey' => 'jetable-1', 'trackId' => $track, 'playing' => false, 'positionMs' => 0, 'queueVersion' => 0]);
        $this->db->run("INSERT INTO mail_queue (kind, user_id, to_address, subject, body) VALUES ('test', ?, ?, 'x', 'x')", [$u['id'], $u['email']]);
        $this->assertTrue(count($this->leftovers($u['id'], $u['email'])) >= 8, 'données créées : ' . json_encode($this->leftovers($u['id'], $u['email'])));

        // Mauvais mot de passe, ou sans mot de passe : rien ne change
        $this->assertError($this->as($t, 'DELETE', '/api/v2/me', ['password' => 'faux']), 401, 'invalid_credentials', 'deleteMe');
        $this->assertError($this->as($t, 'DELETE', '/api/v2/me', []), 400, 'bad_request', 'deleteMe');
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM users WHERE id = ?', [$u['id']]));

        $this->assertContract($this->as($t, 'DELETE', '/api/v2/me', ['password' => self::PASSWORD]), 'deleteMe', 204);
        $this->assertSame([], $this->leftovers($u['id'], $u['email']), 'plus rien du compte');
        $this->assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM audit_log WHERE target_type = 'user' AND target_id = ? AND action <> 'user.delete'", [$u['id']]));
        $trace = $this->db->one("SELECT actor_user_id, ip, before_value FROM audit_log WHERE action = 'user.delete' AND target_id = ?", [$u['id']]);
        $this->assertTrue($trace !== null, 'trace gardée');
        $this->assertSame([null, null], [$trace['actor_user_id'], $trace['ip']], 'trace anonyme');
        $this->assertFalse(str_contains((string) $trace['before_value'], $u['email']));
        // Le jeton ne vaut plus rien, la connexion non plus
        $this->assertSame(401, $this->as($t, 'GET', '/api/v2/me')->status);
        $this->assertSame(401, $this->call('POST', '/api/v2/auth/login', ['email' => $u['email'], 'password' => self::PASSWORD, 'client' => 'app', 'deviceName' => 'Jetable'])->status);
    }

    public function testSessionWebCookieEfface(): void
    {
        $u = $this->throwaway();
        $r = $this->call('POST', '/api/v2/auth/login', ['email' => $u['email'], 'password' => self::PASSWORD, 'client' => 'web']);
        $cookie = ['Cookie' => 'musique_session=' . $r->sessionCookie()];
        $csrf = $r->data()['csrfToken'];
        $this->assertError($this->call('DELETE', '/api/v2/me', ['password' => self::PASSWORD], $cookie), 403, 'csrf_invalid', 'deleteMe');
        $r = $this->call('DELETE', '/api/v2/me', ['password' => self::PASSWORD], $cookie + ['X-CSRF-Token' => $csrf]);
        $this->assertContract($r, 'deleteMe', 204);
        $this->assertTrue(str_contains(implode("\n", $r->cookies), 'musique_session=;'), 'cookie effacé');
        $this->assertSame([], $this->leftovers($u['id'], $u['email']));
    }

    public function testDernierAdminEtAgent(): void
    {
        // L'agent n'a pas cette opération
        $this->assertError($this->as($this->token('agent'), 'DELETE', '/api/v2/me', ['password' => '']), 403, 'forbidden', 'deleteMe');

        // Un admin qui a corrigé la bibliothèque : ses corrections restent, l'auteur devient « Compte supprimé »
        $a = $this->throwaway('admin');
        $track = (int) $this->db->value("SELECT id FROM tracks WHERE title = 'Deux'");
        $bpm = $this->db->value('SELECT bpm FROM track_analysis WHERE track_id = ?', [$track]);
        $this->assertSame(200, $this->as($a['token'], 'PATCH', "/api/v2/admin/tracks/$track", ['bpm' => 99])->status);
        $correction = (int) $this->db->value('SELECT MAX(id) FROM corrections WHERE created_by = ?', [$a['id']]);
        $this->assertContract($this->as($a['token'], 'DELETE', '/api/v2/me', ['password' => self::PASSWORD]), 'deleteMe', 204);
        $this->assertSame(null, $this->db->value('SELECT created_by FROM corrections WHERE id = ?', [$correction]));
        $r = $this->as($this->token('admin'), 'GET', '/api/v2/admin/corrections');
        $this->assertContract($r, 'adminListCorrections', 200);
        $mine = array_values(array_filter($r->data()['items'], static fn ($c) => $c['id'] === $correction));
        $this->assertSame(['id' => 0, 'displayName' => 'Compte supprimé', 'role' => 'user'], $mine[0]['createdBy'] ?? null);
        // Remise en état : la correction est annulée par l'admin de test
        $this->as($this->token('admin'), 'POST', "/api/v2/admin/corrections/$correction/revert");

        // Le dernier admin actif ne peut pas se supprimer
        $others = $this->db->all("SELECT id FROM users WHERE role = 'admin' AND status = 'active' AND id <> ?", [$this->userId('admin')]);
        $this->db->run("UPDATE users SET status = 'suspended' WHERE role = 'admin' AND status = 'active' AND id <> ?", [$this->userId('admin')]);
        try {
            $this->assertError($this->as($this->token('admin'), 'DELETE', '/api/v2/me', ['password' => self::PASSWORD]), 409, 'last_admin', 'deleteMe');
            $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM users WHERE id = ?', [$this->userId('admin')]));
        } finally {
            foreach ($others as $o) {
                $this->db->run("UPDATE users SET status = 'active' WHERE id = ?", [$o['id']]);
            }
        }
    }
}

<?php
// Export des données du compte (CPT-10, GET /me/export) : tout ce qui le concerne, lisible, sans secret.

declare(strict_types=1);

namespace Musique\Tests;

use Musique\Auth\Passwords;

require_once __DIR__ . '/ApiCase.php';

final class AccountExportApiTest extends ApiCase
{
    public function testToutEstDansLExport(): void
    {
        $email = 'export-' . bin2hex(random_bytes(4)) . '@test.fr';
        $this->db->run("INSERT INTO users (email, display_name, password_hash, role, status, request_message) VALUES (?, 'Exporté', ?, 'user', 'active', 'Bonjour')",
            [$email, Passwords::hash(self::PASSWORD)]);
        $id = (int) $this->db->pdo()->lastInsertId();
        try {
            $login = $this->call('POST', '/api/v2/auth/login', ['email' => $email, 'password' => self::PASSWORD, 'client' => 'app', 'deviceName' => 'Téléphone de test']);
            $t = $login->data()['token'];
            $track = (int) $this->db->value("SELECT id FROM tracks WHERE title = 'Deux'");
            $album = (int) $this->db->value('SELECT album_id FROM tracks WHERE id = ?', [$track]);
            $this->as($t, 'PUT', "/api/v2/me/favorites/album/$album");
            $this->as($t, 'PUT', "/api/v2/me/favorites/track/$track");
            $pl = $this->as($t, 'POST', '/api/v2/playlists', ['name' => 'Ma liste'])->data()['id'];
            $this->as($t, 'POST', "/api/v2/playlists/$pl/items", ['trackIds' => [$track]]);
            $this->as($t, 'POST', '/api/v2/me/history', ['plays' => [['trackId' => $track, 'startedAt' => '2026-10-19T08:30:00Z', 'playedMs' => 9000, 'completed' => true, 'context' => 'album']]]);
            $this->as($t, 'PUT', '/api/v2/me/queue', ['trackIds' => [$track], 'currentIndex' => 0, 'positionMs' => 1500, 'mode' => 'normal'], ['If-Match' => '"0"']);
            // Lien de télécommande : seulement si le serveur a un hub temps réel ([mercure]) ; sinon 503
            $share = $this->as($t, 'POST', '/api/v2/me/shares', ['label' => 'Soirée', 'hours' => 1, 'rights' => ['add' => true, 'edit' => false, 'control' => true]]);

            $r = $this->as($t, 'GET', '/api/v2/me/export');
            $this->assertContract($r, 'exportMyData', 200);
            $this->assertTrue((bool) preg_match('/^attachment; filename="rengaine-export-\d{8}\.json"$/', $r->headers['content-disposition'] ?? ''), 'fichier à télécharger');
            $d = $r->data();
            $this->assertSame(['rengaine-export', 1], [$d['format'], $d['formatVersion']]);
            $this->assertSame([$email, 'Exporté', 'Bonjour'], [$d['account']['email'], $d['account']['displayName'], $d['account']['requestMessage']]);
            $deux = $d['history'][0]['track'] ?? null;
            $this->assertSame('Deux', $deux['title'] ?? null);
            $this->assertTrue(is_string($deux['artist']) && is_string($deux['album']), 'piste lisible hors de Rengaine');
            $this->assertSame([9000, true, 'album'], [$d['history'][0]['playedMs'], $d['history'][0]['completed'], $d['history'][0]['context']]);
            $this->assertSame(['Ma liste'], array_column($d['playlists'], 'name'));
            $this->assertSame('Deux', $d['playlists'][0]['tracks'][0]['track']['title'] ?? null);
            $this->assertSame(['album', 'track'], array_values(array_unique(array_column($d['favorites'], 'type'))));
            $this->assertTrue(!in_array(null, array_column($d['favorites'], 'name'), true), 'favoris nommés');
            $this->assertSame([1500, 'Deux'], [$d['queue']['positionMs'], $d['queue']['tracks'][0]['title'] ?? null]);
            $this->assertSame('Téléphone de test', $d['devices'][0]['name']);
            if ($share->status === 201) {
                $this->assertSame(['Soirée', ['add' => true, 'edit' => false, 'control' => true]], [$d['remoteShares'][0]['label'], $d['remoteShares'][0]['rights']]);
            } else {
                $this->assertSame([503, []], [$share->status, $d['remoteShares']]);
            }
            // Jamais de secret
            $raw = $r->raw;
            foreach ([$t, 'password_hash', 'token_hash', 'csrf', '$argon2'] as $secret) {
                $this->assertFalse(str_contains($raw, $secret), "pas de « $secret »");
            }
            $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'user.export' AND actor_user_id = ?", [$id]));

            // Agent : refusé ; sans session : 401
            $this->assertError($this->as($this->token('agent'), 'GET', '/api/v2/me/export'), 403, 'forbidden', 'exportMyData');
            $this->assertError($this->call('GET', '/api/v2/me/export'), 401, 'unauthorized', 'exportMyData');
        } finally {
            $this->db->run("DELETE FROM audit_log WHERE actor_user_id = ? OR (target_type = 'user' AND target_id = ?)", [$id, $id]);
            $this->db->run('DELETE FROM users WHERE id = ?', [$id]);
        }
    }
}

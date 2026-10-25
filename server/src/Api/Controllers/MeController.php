<?php
// Le compte connecté (CPT-02, CPT-04, CPT-05) : profil, mot de passe, appareils, appairage,
// réglages et file d'attente versionnés (If-Match / 412), favoris, historique.

declare(strict_types=1);

namespace Musique\Api\Controllers;

use Musique\Account\ServerSettings;
use Musique\Account\UserSettings;
use Musique\Api\Context;
use Musique\Api\Format;
use Musique\Auth\Passwords;
use Musique\Auth\Tokens;
use Musique\Catalog\Serializer;
use Musique\Http\ApiError;
use Musique\Http\Input;
use Musique\Http\Response;

final class MeController
{
    public const MAX_QUEUE = 5000;
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTVWXYZ23456789'; // sans 0/O, 1/I/L, U
    private const CODE_MINUTES = 5;

    public function __construct(private readonly Context $ctx)
    {
    }

    // ---------------------------------------------------------------- profil

    public function getMe(array $params): Response
    {
        $p = $this->ctx->user();
        $out = ['user' => $p->user(), 'currentTokenId' => $p->tokenId];
        if ($p->isWeb()) {
            $out['csrfToken'] = Tokens::csrfFor((string) $p->sessionSecret);
        }
        return Response::json($out);
    }

    public function updateMe(array $params): Response
    {
        $p = $this->ctx->user();
        $in = new Input($this->ctx->request->json());
        if (!$in->has('displayName')) {
            throw ApiError::badRequest('Rien à modifier');
        }
        $name = (string) $in->text('displayName', 1, 100);
        $this->ctx->db->run('UPDATE users SET display_name = ? WHERE id = ?', [$name, $p->userId]);
        $this->ctx->audit('user.update', 'user', $p->userId, ['displayName' => $p->displayName], ['displayName' => $name]);
        return Response::json(['id' => $p->userId, 'email' => $p->email, 'displayName' => $name, 'role' => $p->role]);
    }

    public function changePassword(array $params): Response
    {
        $p = $this->ctx->user();
        $this->ctx->limiter->hit('password', (string) $p->userId, $this->ctx->config->int('limites', 'changements_mdp_par_10min', 10), 600);
        $in = new Input($this->ctx->request->json());
        $current = (string) $in->string('currentPassword', 0, 200);
        $new = (string) $in->string('newPassword', 10, 200);
        $hash = $this->ctx->db->value('SELECT password_hash FROM users WHERE id = ?', [$p->userId]);
        if (!Passwords::verify($current, $hash === null ? null : (string) $hash)) {
            $this->ctx->security->event('password_change_failed', $this->ctx->request->ip, ['user' => $p->userId, 'rid' => $this->ctx->requestId]);
            throw ApiError::unauthorized('Mot de passe actuel incorrect', 'invalid_credentials');
        }
        $this->ctx->db->transaction(function () use ($p, $new): void {
            $this->ctx->db->run('UPDATE users SET password_hash = ? WHERE id = ?', [Passwords::hash($new), $p->userId]);
            $revoked = $this->ctx->tokens()->revokeAll($p->userId, $p->tokenId, $p->userId);
            $this->ctx->audit('user.password', 'user', $p->userId, null, ['revokedTokens' => $revoked]);
        });
        $this->ctx->notices()->passwordChanged($p->userId, false);
        return Response::noContent();
    }

    /**
     * Suppression du compte par son titulaire (CPT-09) : immédiate et totale. Les tables du compte
     * suivent par clés étrangères (CASCADE) ; on efface en plus son audit et ses mails, et on ne
     * garde qu'une trace anonyme. Le délai d'annulation de 15 s est côté client (voir le contrat).
     */
    public function deleteMe(array $params): Response
    {
        $p = $this->ctx->user();
        $db = $this->ctx->db;
        $this->ctx->limiter->hit('password', (string) $p->userId, $this->ctx->config->int('limites', 'changements_mdp_par_10min', 10), 600);
        $in = new Input($this->ctx->request->json());
        $password = (string) $in->string('password', 0, 200);
        $u = $db->one('SELECT email, role, password_hash FROM users WHERE id = ?', [$p->userId]) ?? throw ApiError::unauthorized();
        if (!Passwords::verify($password, $u['password_hash'] === null ? null : (string) $u['password_hash'])) {
            $this->ctx->security->event('account_delete_failed', $this->ctx->request->ip, ['user' => $p->userId, 'rid' => $this->ctx->requestId]);
            throw ApiError::unauthorized('Mot de passe incorrect', 'invalid_credentials');
        }
        $db->transaction(function () use ($db, $p, $u): void {
            if ($u['role'] === 'admin' && (int) $db->value(
                "SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active' AND id <> ? FOR UPDATE", [$p->userId]
            ) === 0) {
                throw new ApiError(409, 'last_admin', 'C’est le dernier compte admin : nommez d’abord un autre admin');
            }
            $db->run("DELETE FROM audit_log WHERE actor_user_id = ? OR (target_type = 'user' AND target_id = ?)", [$p->userId, $p->userId]);
            $db->run('DELETE FROM mail_queue WHERE user_id = ? OR to_address = ?', [$p->userId, $u['email']]);
            $db->run('DELETE FROM users WHERE id = ?', [$p->userId]);
            // Trace anonyme : ni nom, ni adresse, ni IP
            $db->run("INSERT INTO audit_log (action, target_type, target_id, before_value, request_id) VALUES ('user.delete', 'user', ?, ?, ?)",
                [$p->userId, json_encode(['role' => $u['role'], 'via' => 'self']), $this->ctx->requestId]);
        });
        $this->ctx->logger->info('Compte supprimé par son titulaire', ['user' => $p->userId]);
        $r = Response::noContent();
        return $p->isWeb() ? $r->cookie(Tokens::clearCookie()) : $r;
    }

    /**
     * Export de toutes les données du compte (CPT-10, RGPD) : un fichier JSON lisible hors de
     * Rengaine (pistes décrites par titre, artiste et album). Jamais de secret.
     */
    public function exportMyData(array $params): Response
    {
        $p = $this->ctx->user();
        $db = $this->ctx->db;
        $uid = $p->userId;
        $this->ctx->limiter->hit('export', (string) $uid, 10, 3600);
        $u = $db->one('SELECT id, email, display_name, role, status, request_message, created_at, last_login_at FROM users WHERE id = ?', [$uid])
            ?? throw ApiError::unauthorized();

        $settings = $db->one('SELECT settings FROM user_settings WHERE user_id = ?', [$uid]);
        $queue = $db->one('SELECT track_ids, current_index, position_ms, mode, updated_at FROM play_queues WHERE user_id = ?', [$uid]);
        $queueIds = $queue === null ? [] : array_map('intval', (array) json_decode((string) $queue['track_ids'], true));
        $favorites = $db->all('SELECT item_type, item_id, created_at FROM favorites WHERE user_id = ? ORDER BY created_at, item_type, item_id', [$uid]);
        $playlists = $db->all('SELECT id, name, description, shared, created_at, updated_at FROM playlists WHERE user_id = ? ORDER BY id', [$uid]);
        $items = $playlists === [] ? [] : $db->all(
            'SELECT i.playlist_id, i.track_id, i.added_at FROM playlist_items i JOIN playlists p ON p.id = i.playlist_id
              WHERE p.user_id = ? ORDER BY i.playlist_id, i.position, i.id', [$uid]);
        $history = $db->all('SELECT track_id, started_at, played_ms, completed, skipped, context FROM play_history WHERE user_id = ? ORDER BY started_at, id', [$uid]);

        // Description lisible de chaque piste citée (titre, artiste, album)
        $ids = array_values(array_unique(array_merge(
            $queueIds,
            array_map(static fn ($f) => (int) $f['item_id'], array_filter($favorites, static fn ($f) => $f['item_type'] === 'track')),
            array_map(static fn ($i) => (int) $i['track_id'], $items),
            array_map(static fn ($h) => (int) $h['track_id'], $history),
        )));
        $tracks = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach ((new Serializer($db, $uid))->tracks($chunk) as $t) {
                $tracks[$t['id']] = ['id' => $t['id'], 'title' => $t['title'], 'artist' => $t['artistCredit'], 'album' => $t['album']['title']];
            }
        }
        $track = static fn (int $id): ?array => $tracks[$id] ?? null;
        $names = ['album' => [], 'artist' => []];
        foreach (['album' => 'SELECT id, title AS n FROM albums WHERE id IN', 'artist' => 'SELECT id, name AS n FROM artists WHERE id IN'] as $type => $sql) {
            $want = array_map(static fn ($f) => (int) $f['item_id'], array_filter($favorites, static fn ($f) => $f['item_type'] === $type));
            if ($want !== []) {
                foreach ($db->all("$sql (" . implode(',', array_fill(0, count($want), '?')) . ')', $want) as $r) {
                    $names[$type][(int) $r['id']] = (string) $r['n'];
                }
            }
        }

        $byPlaylist = [];
        foreach ($items as $i) {
            $byPlaylist[(int) $i['playlist_id']][] = ['addedAt' => Format::date($i['added_at']), 'track' => $track((int) $i['track_id'])];
        }
        $server = new ServerSettings($db);
        $doc = [
            'format' => 'rengaine-export',
            'formatVersion' => 1,
            'exportedAt' => gmdate('Y-m-d\TH:i:s.v\Z'),
            'server' => ['name' => (string) $server->get('server_name'), 'url' => $this->ctx->publicUrl(), 'apiVersion' => \Musique\Api\Kernel::VERSION],
            'account' => [
                'id' => (int) $u['id'], 'email' => $u['email'], 'displayName' => $u['display_name'], 'role' => $u['role'], 'status' => $u['status'],
                'createdAt' => Format::date($u['created_at']), 'lastLoginAt' => Format::date($u['last_login_at']), 'requestMessage' => $u['request_message'],
            ],
            'settings' => $settings === null ? null : json_decode((string) $settings['settings'], true),
            'queue' => $queue === null ? null : [
                'mode' => $queue['mode'], 'currentIndex' => Format::int($queue['current_index']), 'positionMs' => (int) $queue['position_ms'],
                'updatedAt' => Format::date($queue['updated_at']), 'tracks' => array_map($track, $queueIds),
            ],
            'favorites' => array_map(static fn ($f) => [
                'type' => $f['item_type'], 'id' => (int) $f['item_id'],
                'name' => $f['item_type'] === 'track' ? ($tracks[(int) $f['item_id']]['title'] ?? null) : ($names[$f['item_type']][(int) $f['item_id']] ?? null),
                'addedAt' => Format::date($f['created_at']),
            ], $favorites),
            'playlists' => array_map(static fn ($pl) => [
                'id' => (int) $pl['id'], 'name' => $pl['name'], 'description' => $pl['description'], 'shared' => (bool) $pl['shared'],
                'createdAt' => Format::date($pl['created_at']), 'updatedAt' => Format::date($pl['updated_at']), 'tracks' => $byPlaylist[(int) $pl['id']] ?? [],
            ], $playlists),
            'history' => array_map(static fn ($h) => [
                'startedAt' => Format::date($h['started_at']), 'playedMs' => (int) $h['played_ms'], 'completed' => (bool) $h['completed'],
                'skipped' => (bool) $h['skipped'], 'context' => $h['context'], 'track' => $track((int) $h['track_id']),
            ], $history),
            'devices' => array_map(static fn ($d) => [
                'name' => $d['name'], 'kind' => $d['kind'], 'createdAt' => Format::date($d['created_at']), 'lastUsedAt' => Format::date($d['last_used_at']),
                'lastIp' => Format::ip($d['last_ip']), 'userAgent' => $d['user_agent'], 'expiresAt' => Format::date($d['expires_at']), 'revokedAt' => Format::date($d['revoked_at']),
            ], $db->all('SELECT name, kind, created_at, last_used_at, last_ip, user_agent, expires_at, revoked_at FROM access_tokens WHERE user_id = ? ORDER BY id', [$uid])),
            'remoteShares' => array_map(static fn ($s) => [
                'label' => $s['label'], 'rights' => ['add' => (bool) $s['can_add'], 'edit' => (bool) $s['can_edit'], 'control' => (bool) $s['can_control']],
                'createdAt' => Format::date($s['created_at']), 'expiresAt' => Format::date($s['expires_at']),
                'revokedAt' => Format::date($s['revoked_at']), 'lastUsedAt' => Format::date($s['last_used_at']),
            ], $db->all('SELECT label, can_add, can_edit, can_control, created_at, expires_at, revoked_at, last_used_at FROM queue_shares WHERE user_id = ? ORDER BY id', [$uid])),
        ];
        $this->ctx->audit('user.export', 'user', $uid);
        return Response::json($doc)
            ->header('Content-Disposition', 'attachment; filename="rengaine-export-' . gmdate('Ymd') . '.json"')
            ->header('Cache-Control', 'no-store');
    }

    // ---------------------------------------------------------------- appareils

    public function listMyDevices(array $params): Response
    {
        $p = $this->ctx->user();
        $items = [];
        foreach ($this->ctx->db->all(
            'SELECT id, kind, name, token_prefix, created_at, last_used_at, last_ip, user_agent, expires_at FROM access_tokens
              WHERE user_id = ? AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP(3))
              ORDER BY last_used_at DESC, id DESC', [$p->userId]
        ) as $t) {
            $items[] = [
                'id' => (int) $t['id'], 'kind' => $t['kind'], 'name' => $t['name'], 'prefix' => $t['token_prefix'],
                'createdAt' => Format::date($t['created_at']), 'lastUsedAt' => Format::date($t['last_used_at']),
                'lastIp' => Format::ip($t['last_ip']), 'userAgent' => $t['user_agent'],
                'expiresAt' => Format::date($t['expires_at']), 'current' => (int) $t['id'] === $p->tokenId,
            ];
        }
        return Response::json(['items' => $items]);
    }

    public function revokeMyDevice(array $params): Response
    {
        $p = $this->ctx->user();
        $id = (int) $params['tokenId'];
        $owner = $this->ctx->db->value('SELECT user_id FROM access_tokens WHERE id = ? AND revoked_at IS NULL', [$id]);
        if ($owner === null || (int) $owner !== $p->userId) {
            throw ApiError::notFound('Appareil introuvable');
        }
        $this->ctx->tokens()->revoke($id, $p->userId);
        $this->ctx->audit('token.revoke', 'token', $id);
        $r = Response::noContent();
        return $id === $p->tokenId && $p->isWeb() ? $r->cookie(Tokens::clearCookie()) : $r;
    }

    /** « k7qf 2m9x » → « K7QF2M9X » (forme hachée) */
    public static function normalizeCode(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    public function createPairingCode(array $params): Response
    {
        $p = $this->ctx->user();
        $this->ctx->limiter->hit('pairing-code', (string) $p->userId, $this->ctx->config->int('limites', 'codes_appairage_par_heure', 10), 3600);
        $raw = '';
        for ($i = 0; $i < 8; $i++) {
            $raw .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        }
        $display = substr($raw, 0, 4) . '-' . substr($raw, 4);
        $this->ctx->db->run(
            'INSERT INTO pairing_codes (user_id, code_hash, expires_at) VALUES (?, ?, CURRENT_TIMESTAMP(3) + INTERVAL ' . self::CODE_MINUTES . ' MINUTE)',
            [$p->userId, hash('sha256', $raw, true)]
        );
        $id = (int) $this->ctx->db->pdo()->lastInsertId();
        $expires = $this->ctx->db->value('SELECT expires_at FROM pairing_codes WHERE id = ?', [$id]);
        $this->ctx->audit('pairing.create', 'pairing_code', $id);
        return Response::json([
            'code' => $display,
            'expiresAt' => Format::date((string) $expires),
            'qrPayload' => 'rengaine://pair?server=' . rawurlencode($this->ctx->publicUrl()) . '&code=' . $display,
        ], 201);
    }

    // ---------------------------------------------------------------- versions (If-Match)

    private function ifMatch(): int
    {
        $h = $this->ctx->request->header('if-match');
        if ($h === null || $h === '') {
            throw new ApiError(428, 'precondition_required', 'En-tête If-Match obligatoire (ETag reçu à la dernière lecture)');
        }
        if (!preg_match('/^(?:W\/)?"?(\d+)"?$/', trim($h), $m)) {
            throw ApiError::invalid('If-Match', 'En-tête If-Match invalide');
        }
        return (int) $m[1];
    }

    private static function conflict(array $current, int $version): ApiError
    {
        return new ApiError(412, 'version_conflict', 'Modifié entre-temps sur un autre appareil : fusionnez puis réessayez',
            ['current' => $current], ['ETag' => '"' . $version . '"']);
    }

    // ---------------------------------------------------------------- réglages

    /** @return array{version: int, updatedAt: ?string, settings: array<string, mixed>} */
    private function settingsDocument(int $userId): array
    {
        $row = $this->ctx->db->one('SELECT settings, version, updated_at FROM user_settings WHERE user_id = ?', [$userId]);
        if ($row === null) {
            return ['version' => 0, 'updatedAt' => Format::date((string) $this->ctx->db->value('SELECT created_at FROM users WHERE id = ?', [$userId])),
                'settings' => UserSettings::defaults()];
        }
        return ['version' => (int) $row['version'], 'updatedAt' => Format::date($row['updated_at']),
            'settings' => UserSettings::withDefaults((array) json_decode((string) $row['settings'], true))];
    }

    public function getMySettings(array $params): Response
    {
        $doc = $this->settingsDocument($this->ctx->user()->userId);
        return Response::json($doc)->header('ETag', '"' . $doc['version'] . '"');
    }

    public function putMySettings(array $params): Response
    {
        $uid = $this->ctx->user()->userId;
        $expected = $this->ifMatch();
        $settings = UserSettings::validate($this->ctx->request->json());
        $json = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        $doc = $this->ctx->db->transaction(function () use ($uid, $expected, $json): array {
            $current = $this->ctx->db->value('SELECT version FROM user_settings WHERE user_id = ? FOR UPDATE', [$uid]);
            $version = $current === null ? 0 : (int) $current;
            if ($version !== $expected) {
                throw self::conflict($this->settingsDocument($uid), $version);
            }
            if ($current === null) {
                $this->ctx->db->run('INSERT INTO user_settings (user_id, settings, version) VALUES (?, ?, 1)', [$uid, $json]);
            } else {
                $this->ctx->db->run('UPDATE user_settings SET settings = ?, version = version + 1, updated_at = CURRENT_TIMESTAMP(3) WHERE user_id = ?', [$json, $uid]);
            }
            return $this->settingsDocument($uid);
        });
        return Response::json($doc)->header('ETag', '"' . $doc['version'] . '"');
    }

    // ---------------------------------------------------------------- file d'attente

    /** @return array<string, mixed> PlayQueue */
    private function queueDocument(int $userId): array
    {
        $row = $this->ctx->db->one(
            'SELECT q.track_ids, q.current_index, q.position_ms, q.mode, q.version, q.updated_at, t.name AS device
               FROM play_queues q LEFT JOIN access_tokens t ON t.id = q.updated_by WHERE q.user_id = ?', [$userId]
        );
        if ($row === null) {
            return ['trackIds' => [], 'currentIndex' => null, 'positionMs' => 0, 'mode' => 'normal', 'version' => 0,
                'updatedAt' => Format::date((string) $this->ctx->db->value('SELECT created_at FROM users WHERE id = ?', [$userId])),
                'updatedBy' => null, 'tracks' => []];
        }
        $ids = array_map('intval', (array) json_decode((string) $row['track_ids'], true));
        $tracks = [];
        foreach ((new Serializer($this->ctx->db, $userId))->tracks($ids) as $t) {
            $tracks[$t['id']] = $t;
        }
        return [
            'trackIds' => $ids,
            'currentIndex' => Format::int($row['current_index']),
            'positionMs' => (int) $row['position_ms'],
            'mode' => $row['mode'],
            'version' => (int) $row['version'],
            'updatedAt' => Format::date($row['updated_at']),
            'updatedBy' => $row['device'],
            // Même ordre que trackIds, doublons compris
            'tracks' => array_values(array_filter(array_map(static fn (int $id) => $tracks[$id] ?? null, $ids))),
        ];
    }

    public function getMyQueue(array $params): Response
    {
        $doc = $this->queueDocument($this->ctx->user()->userId);
        return Response::json($doc)->header('ETag', '"' . $doc['version'] . '"');
    }

    public function putMyQueue(array $params): Response
    {
        $p = $this->ctx->user();
        $expected = $this->ifMatch();
        $in = new Input($this->ctx->request->json());
        $ids = (array) $in->intList('trackIds', self::MAX_QUEUE);
        $index = $in->int('currentIndex', 0, max(0, count($ids) - 1), true, true);
        if ($index !== null && $ids === []) {
            throw ApiError::invalid('currentIndex', 'File vide : currentIndex doit être null');
        }
        $position = (int) $in->int('positionMs', 0, 2_000_000_000);
        $mode = (string) $in->enum('mode', ['normal', 'repeat_all', 'repeat_one', 'continuous']);
        $unique = array_values(array_unique($ids));
        if ($unique !== []) {
            $known = (int) $this->ctx->db->value('SELECT COUNT(*) FROM tracks WHERE id IN (' . implode(',', array_fill(0, count($unique), '?')) . ')', $unique);
            if ($known !== count($unique)) {
                throw ApiError::invalid('trackIds', 'La file contient des pistes inconnues');
            }
        }
        $json = json_encode($ids);
        $doc = $this->ctx->db->transaction(function () use ($p, $expected, $json, $index, $position, $mode): array {
            $current = $this->ctx->db->value('SELECT version FROM play_queues WHERE user_id = ? FOR UPDATE', [$p->userId]);
            $version = $current === null ? 0 : (int) $current;
            if ($version !== $expected) {
                throw self::conflict($this->queueDocument($p->userId), $version);
            }
            if ($current === null) {
                $this->ctx->db->run('INSERT INTO play_queues (user_id, track_ids, current_index, position_ms, mode, version, updated_by) VALUES (?, ?, ?, ?, ?, 1, ?)',
                    [$p->userId, $json, $index, $position, $mode, $p->tokenId]);
            } else {
                $this->ctx->db->run('UPDATE play_queues SET track_ids = ?, current_index = ?, position_ms = ?, mode = ?, version = version + 1,
                                            updated_at = CURRENT_TIMESTAMP(3), updated_by = ? WHERE user_id = ?',
                    [$json, $index, $position, $mode, $p->tokenId, $p->userId]);
            }
            return $this->queueDocument($p->userId);
        });
        RemoteController::notifyGuests($this->ctx, $p->userId); // télécommande (FUT-02)
        return Response::json($doc)->header('ETag', '"' . $doc['version'] . '"');
    }

    // ---------------------------------------------------------------- favoris

    private const FAVORITE_TABLES = ['track' => 'tracks', 'album' => 'albums', 'artist' => 'artists'];

    private static function favoriteType(mixed $type): string
    {
        if (!is_string($type) || !isset(self::FAVORITE_TABLES[$type])) {
            throw ApiError::invalid('type', 'Type de favori : track, album ou artist');
        }
        return $type;
    }

    public function listMyFavorites(array $params): Response
    {
        $uid = $this->ctx->user()->userId;
        $type = self::favoriteType($this->ctx->request->queryEnum('type', ['track', 'album', 'artist']) ?? throw ApiError::invalid('type', 'Paramètre « type » obligatoire'));
        [$limit, $offset] = $this->ctx->request->page();
        $total = (int) $this->ctx->db->value('SELECT COUNT(*) FROM favorites WHERE user_id = ? AND item_type = ?', [$uid, $type]);
        $rows = $this->ctx->db->all("SELECT item_id, created_at FROM favorites WHERE user_id = ? AND item_type = ? ORDER BY created_at DESC, item_id DESC LIMIT $limit OFFSET $offset", [$uid, $type]);
        $ids = array_map('intval', array_column($rows, 'item_id'));
        $s = new Serializer($this->ctx->db, $uid);
        $objects = [];
        foreach (match ($type) { 'track' => $s->tracks($ids), 'album' => $s->albums($ids), 'artist' => $s->artists($ids) } as $o) {
            $objects[$o['id']] = $o;
        }
        $items = [];
        foreach ($rows as $r) {
            if (isset($objects[(int) $r['item_id']])) {
                $items[] = ['type' => $type, 'addedAt' => Format::date($r['created_at']), $type => $objects[(int) $r['item_id']]];
            }
        }
        return Response::json(['items' => $items, 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
    }

    public function addFavorite(array $params): Response
    {
        $uid = $this->ctx->user()->userId;
        $type = self::favoriteType($params['type']);
        $id = (int) $params['id'];
        if ($this->ctx->db->value('SELECT id FROM ' . self::FAVORITE_TABLES[$type] . ' WHERE id = ?', [$id]) === null) {
            throw ApiError::notFound('Élément introuvable');
        }
        $this->ctx->db->run('INSERT IGNORE INTO favorites (user_id, item_type, item_id) VALUES (?, ?, ?)', [$uid, $type, $id]);
        return Response::noContent();
    }

    public function removeFavorite(array $params): Response
    {
        $this->ctx->db->run('DELETE FROM favorites WHERE user_id = ? AND item_type = ? AND item_id = ?',
            [$this->ctx->user()->userId, self::favoriteType($params['type']), (int) $params['id']]);
        return Response::noContent();
    }

    // ---------------------------------------------------------------- historique

    public function listMyHistory(array $params): Response
    {
        $uid = $this->ctx->user()->userId;
        [$limit, $offset] = $this->ctx->request->page();
        $total = (int) $this->ctx->db->value('SELECT COUNT(*) FROM play_history WHERE user_id = ?', [$uid]);
        $rows = $this->ctx->db->all(
            "SELECT h.id, h.track_id, h.started_at, h.played_ms, h.completed, h.skipped, h.context, t.name AS device
               FROM play_history h LEFT JOIN access_tokens t ON t.id = h.token_id
              WHERE h.user_id = ? ORDER BY h.started_at DESC, h.id DESC LIMIT $limit OFFSET $offset", [$uid]
        );
        $tracks = [];
        foreach ((new Serializer($this->ctx->db, $uid))->tracks(array_map('intval', array_column($rows, 'track_id'))) as $t) {
            $tracks[$t['id']] = $t;
        }
        $items = [];
        foreach ($rows as $r) {
            if (isset($tracks[(int) $r['track_id']])) {
                $items[] = ['id' => (int) $r['id'], 'startedAt' => Format::date($r['started_at']), 'playedMs' => (int) $r['played_ms'], 'completed' => (bool) $r['completed'],
                    'skipped' => (bool) $r['skipped'], 'context' => $r['context'], 'device' => $r['device'], 'track' => $tracks[(int) $r['track_id']]];
            }
        }
        return Response::json(['items' => $items, 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
    }

    /** Efface tout l'historique d'écoute du compte (2.26.0). */
    public function clearMyHistory(array $params): Response
    {
        $uid = $this->ctx->user()->userId;
        $n = $this->ctx->db->run('DELETE FROM play_history WHERE user_id = ?', [$uid])->rowCount();
        $this->ctx->audit('history.clear', 'user', $uid, null, ['deleted' => $n]);
        return Response::json(['deleted' => $n]);
    }

    /** Efface une écoute du compte (2.26.0). */
    public function deleteMyPlay(array $params): Response
    {
        $n = $this->ctx->db->run('DELETE FROM play_history WHERE id = ? AND user_id = ?', [(int) $params['id'], $this->ctx->user()->userId])->rowCount();
        if ($n === 0) {
            throw ApiError::notFound('Écoute introuvable');
        }
        return Response::noContent();
    }

    public function recordPlays(array $params): Response
    {
        $p = $this->ctx->user();
        $plays = (new Input($this->ctx->request->json()))->raw()['plays'] ?? null;
        if (!is_array($plays) || !array_is_list($plays) || $plays === [] || count($plays) > 500) {
            throw ApiError::invalid('plays', 'Champ « plays » : de 1 à 500 écoutes');
        }
        $rows = [];
        foreach ($plays as $i => $play) {
            if (!is_array($play)) {
                throw ApiError::invalid("plays[$i]", 'Écoute invalide');
            }
            $in = new Input($play, "plays[$i].");
            $trackId = (int) $in->int('trackId', 1);
            $startedRaw = (string) $in->string('startedAt', 10, 40);
            $started = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.uP', str_replace('Z', '+00:00', $startedRaw))
                ?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', str_replace('Z', '+00:00', $startedRaw));
            if ($started === false) {
                throw ApiError::invalid("plays[$i].startedAt", 'Date ISO 8601 attendue');
            }
            $rows[] = [$trackId, $started->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v'), (int) $in->int('playedMs', 0, 86_400_000),
                (bool) ($in->bool('completed', false) ?? false), (bool) ($in->bool('skipped', false) ?? false),
                $in->enum('context', ['album', 'artist', 'search', 'random', 'continuous', 'queue', 'other'], false) ?? 'other'];
        }
        // Les pistes inconnues sont ignorées : l'appli peut envoyer des écoutes anciennes, hors connexion
        $known = [];
        $ids = array_values(array_unique(array_column($rows, 0)));
        foreach ($this->ctx->db->all('SELECT id FROM tracks WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) as $r) {
            $known[(int) $r['id']] = true;
        }
        $this->ctx->db->transaction(function () use ($rows, $known, $p): void {
            foreach ($rows as [$trackId, $started, $played, $completed, $skipped, $context]) {
                if (isset($known[$trackId])) {
                    $this->ctx->db->run('INSERT INTO play_history (user_id, track_id, token_id, started_at, played_ms, completed, skipped, context) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                        [$p->userId, $trackId, $p->tokenId, $started, $played, (int) $completed, (int) $skipped, $context]);
                }
            }
        });
        return Response::noContent();
    }
}

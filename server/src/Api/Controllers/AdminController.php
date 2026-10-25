<?php
// Admin des comptes (CPT-01, CPT-03), réglages du serveur, tâches de fond et journaux (JRN-02, JRN-03).

declare(strict_types=1);

namespace Musique\Api\Controllers;

use Musique\Account\ServerSettings;
use Musique\Api\Context;
use Musique\Api\Format;
use Musique\Lang;
use Musique\Auth\Passwords;
use Musique\Http\ApiError;
use Musique\Http\Input;
use Musique\Http\Response;
use Musique\Audio\Fingerprint;
use Musique\Catalog\Serializer;
use Musique\Library\Duplicates;
use Musique\Library\SameRecordings;
use Musique\Log\LogReader;
use Musique\Log\Logger;

final class AdminController
{
    /** Changements de statut permis (de → vers). */
    private const TRANSITIONS = [
        'pending' => ['active', 'refused'],
        'active' => ['suspended'],
        'suspended' => ['active'],
        'refused' => ['active'],
    ];

    public function __construct(private readonly Context $ctx)
    {
    }

    private function page(array $items, int $total, int $limit, int $offset): Response
    {
        return Response::json(['items' => $items, 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
    }

    /** @param list<int> $ids @return array<int, array<string, mixed>> UserRef */
    private function userRefs(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->ctx->db->all('SELECT id, display_name, role FROM users WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) as $u) {
            $out[(int) $u['id']] = ['id' => (int) $u['id'], 'displayName' => $u['display_name'], 'role' => $u['role']];
        }
        return $out;
    }

    // ---------------------------------------------------------------- comptes

    private const USER_COLUMNS = 'id, email, display_name, role, status, request_message, created_at, decided_at, last_login_at';

    private static function userOut(array $u): array
    {
        return [
            'id' => (int) $u['id'], 'email' => $u['email'], 'displayName' => $u['display_name'], 'role' => $u['role'], 'status' => $u['status'],
            'requestMessage' => $u['request_message'], 'createdAt' => Format::date($u['created_at']),
            'decidedAt' => Format::date($u['decided_at']), 'lastLoginAt' => Format::date($u['last_login_at']),
        ];
    }

    private function loadUser(int $id): array
    {
        return $this->ctx->db->one('SELECT ' . self::USER_COLUMNS . ' FROM users WHERE id = ?', [$id]) ?? throw ApiError::notFound('Compte introuvable');
    }

    public function listUsers(array $params): Response
    {
        $r = $this->ctx->request;
        [$limit, $offset] = $r->page();
        $where = ['1'];
        $args = [];
        if (($s = $r->queryEnum('status', ['pending', 'active', 'suspended', 'refused'])) !== null) {
            $where[] = 'status = ?';
            $args[] = $s;
        }
        if (($q = $r->queryString('q')) !== null) {
            $where[] = '(email LIKE ? OR display_name LIKE ?)';
            array_push($args, Format::like($q), Format::like($q));
        }
        $w = implode(' AND ', $where);
        $total = (int) $this->ctx->db->value("SELECT COUNT(*) FROM users WHERE $w", $args);
        // Demandes en attente d'abord, puis les plus récents
        $rows = $this->ctx->db->all('SELECT ' . self::USER_COLUMNS . " FROM users WHERE $w ORDER BY status = 'pending' DESC, created_at DESC, id DESC LIMIT $limit OFFSET $offset", $args);
        return $this->page(array_map(self::userOut(...), $rows), $total, $limit, $offset);
    }

    public function getUser(array $params): Response
    {
        $u = self::userOut($this->loadUser((int) $params['id']));
        $u['devices'] = array_map(fn ($t) => [
            'id' => (int) $t['id'], 'kind' => $t['kind'], 'name' => $t['name'], 'prefix' => $t['token_prefix'],
            'createdAt' => Format::date($t['created_at']), 'lastUsedAt' => Format::date($t['last_used_at']),
            'lastIp' => Format::ip($t['last_ip']), 'userAgent' => $t['user_agent'], 'expiresAt' => Format::date($t['expires_at']),
            'current' => (int) $t['id'] === $this->ctx->user()->tokenId,
        ], $this->ctx->db->all(
            'SELECT id, kind, name, token_prefix, created_at, last_used_at, last_ip, user_agent, expires_at FROM access_tokens
              WHERE user_id = ? AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP(3)) ORDER BY last_used_at DESC, id DESC',
            [(int) $params['id']]
        ));
        return Response::json($u);
    }

    public function updateUser(array $params): Response
    {
        $id = (int) $params['id'];
        $me = $this->ctx->user();
        $before = $this->loadUser($id);
        $in = new Input($this->ctx->request->json());
        $status = $in->enum('status', ['pending', 'active', 'suspended', 'refused'], false);
        $role = $in->enum('role', ['admin', 'user', 'agent'], false);
        $name = $in->text('displayName', 1, 100, false);
        if ($status === null && $role === null && $name === null) {
            throw ApiError::badRequest('Rien à modifier');
        }
        if ($id === $me->userId && (($status !== null && $status !== 'active') || ($role !== null && $role !== 'admin'))) {
            throw new ApiError(409, 'conflict', 'Un admin ne peut ni se suspendre ni retirer son propre rôle d’admin');
        }
        if ($status !== null && $status !== $before['status'] && !in_array($status, self::TRANSITIONS[$before['status']] ?? [], true)) {
            throw new ApiError(409, 'conflict', "Passage de « {$before['status']} » à « $status » impossible");
        }

        $revoked = 0;
        $this->ctx->db->transaction(function () use ($id, $me, $before, $status, $role, $name, &$revoked): void {
            $db = $this->ctx->db;
            if ($name !== null) {
                $db->run('UPDATE users SET display_name = ? WHERE id = ?', [$name, $id]);
            }
            if ($role !== null) {
                $db->run('UPDATE users SET role = ? WHERE id = ?', [$role, $id]);
            }
            if ($status !== null && $status !== $before['status']) {
                $db->run('UPDATE users SET status = ? WHERE id = ?', [$status, $id]);
                if ($before['status'] === 'pending') {
                    // Demande traitée : l'IP n'a plus de raison d'être gardée (décision de Rémy)
                    $db->run('UPDATE users SET decided_at = CURRENT_TIMESTAMP(3), decided_by = ?, request_ip = NULL, request_network = NULL WHERE id = ?', [$me->userId, $id]);
                }
                if ($status === 'suspended') {
                    $revoked = $this->ctx->tokens()->revokeAll($id, null, $me->userId);
                }
            }
        });
        $after = $this->loadUser($id);
        $action = match (true) {
            $status === 'active' && $before['status'] === 'pending' => 'user.approve',
            $status === 'refused' => 'user.refuse',
            $status === 'suspended' => 'user.suspend',
            $status === 'active' => 'user.reactivate',
            default => 'user.update',
        };
        $this->ctx->audit($action, 'user', $id,
            ['status' => $before['status'], 'role' => $before['role'], 'displayName' => $before['display_name']],
            ['status' => $after['status'], 'role' => $after['role'], 'displayName' => $after['display_name'], 'revokedTokens' => $revoked]);
        if ($action === 'user.approve') {
            $this->ctx->notices()->accountApproved($id);
        } elseif ($action === 'user.refuse' && $before['status'] === 'pending') {
            $this->ctx->notices()->accountRefused($id);
        }
        return Response::json(self::userOut($after));
    }

    public function createUser(array $params): Response
    {
        $in = new Input($this->ctx->request->json());
        $email = $in->email('email');
        $name = (string) $in->text('displayName', 1, 100);
        $role = (string) $in->enum('role', ['admin', 'user', 'agent']);
        $password = $role === 'agent' ? null : $in->string('password', 10, 200);
        if ($role === 'agent' && $in->has('password')) {
            throw ApiError::invalid('password', 'Un compte agent n’a pas de mot de passe : créez-lui un jeton');
        }
        if ($this->ctx->db->value('SELECT id FROM users WHERE email = ?', [$email]) !== null) {
            throw new ApiError(409, 'conflict', 'Un compte existe déjà avec cette adresse');
        }
        $this->ctx->db->run(
            "INSERT INTO users (email, display_name, password_hash, role, status, decided_at, decided_by) VALUES (?, ?, ?, ?, 'active', CURRENT_TIMESTAMP(3), ?)",
            [$email, $name, $password === null ? null : Passwords::hash($password), $role, $this->ctx->user()->userId]
        );
        $id = (int) $this->ctx->db->pdo()->lastInsertId();
        $this->ctx->audit('user.create', 'user', $id, null, ['role' => $role, 'displayName' => $name]);
        return Response::json(self::userOut($this->loadUser($id)), 201);
    }

    public function createToken(array $params): Response
    {
        $id = (int) $params['id'];
        $user = $this->loadUser($id);
        $in = new Input($this->ctx->request->json());
        $name = (string) $in->text('name', 1, 100);
        $expires = $in->string('expiresAt', 10, 40, false, true);
        $expiresAt = null;
        if ($expires !== null) {
            $d = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.uP', str_replace('Z', '+00:00', $expires))
                ?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', str_replace('Z', '+00:00', $expires));
            if ($d === false || $d->getTimestamp() <= time()) {
                throw ApiError::invalid('expiresAt', 'Date ISO 8601 future attendue');
            }
            $expiresAt = $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
        }
        if ($user['status'] !== 'active') {
            throw new ApiError(409, 'conflict', 'Compte non actif');
        }
        $token = $this->ctx->tokens()->create($id, $user['role'] === 'agent' ? 'agent' : 'app', $name, null, null);
        if ($expiresAt !== null) {
            $this->ctx->db->run('UPDATE access_tokens SET expires_at = ? WHERE id = ?', [$expiresAt, $token['id']]);
        }
        $this->ctx->audit('token.create', 'token', $token['id'], null, ['userId' => $id, 'name' => $name]);
        return Response::json(['token' => $token['secret'], 'tokenId' => $token['id'],
            'user' => ['id' => $id, 'email' => $user['email'], 'displayName' => $user['display_name'], 'role' => $user['role']]], 201);
    }

    public function revokeToken(array $params): Response
    {
        $id = (int) $params['tokenId'];
        $owner = $this->ctx->db->value('SELECT user_id FROM access_tokens WHERE id = ? AND revoked_at IS NULL', [$id]) ?? throw ApiError::notFound('Jeton introuvable ou déjà révoqué');
        $this->ctx->tokens()->revoke($id, $this->ctx->user()->userId);
        $this->ctx->audit('token.revoke', 'token', $id, null, ['userId' => (int) $owner]);
        return Response::noContent();
    }

    // ---------------------------------------------------------------- réglages du serveur

    private function settingsOut(): array
    {
        $s = new ServerSettings($this->ctx->db);
        return ['serverName' => (string) $s->get('server_name'), 'registrationOpen' => (bool) $s->get('registration_open'),
            'pendingRequestsPerNetwork' => (int) $s->get('registration_pending_per_network'),
            'remoteControlEnabled' => (bool) $s->get('remote_control_enabled'), 'language' => (string) $s->get('language')];
    }

    /** État du hub temps réel, pour l'admin (absent du journal d'audit). */
    private function realtimeService(?bool $expect = null): string
    {
        $hub = \Musique\Realtime\Hub::fromConfig($this->ctx->config);
        if ($hub === null) {
            return 'absent';
        }
        // Juste après une demande, systemd met un instant à démarrer ou arrêter le service
        for ($i = 0; $i < 15; $i++) {
            $running = $hub->running();
            if ($expect === null || $running === $expect) {
                break;
            }
            usleep(200_000);
        }
        return $running ? 'running' : 'stopped';
    }

    public function getServerSettings(array $params): Response
    {
        return Response::json($this->settingsOut() + ['realtimeService' => $this->realtimeService(), 'assistant' => \Musique\Ai\Factory::describe($this->ctx->config)]);
    }

    public function updateServerSettings(array $params): Response
    {
        $in = new Input($this->ctx->request->json());
        $changes = array_filter([
            'server_name' => $in->text('serverName', 1, 100, false),
            'language' => $in->enum('language', ['fr', 'en'], false),
            'registration_open' => $in->bool('registrationOpen', false),
            'registration_pending_per_network' => $in->int('pendingRequestsPerNetwork', 1, 20, false),
            'remote_control_enabled' => $in->bool('remoteControlEnabled', false),
        ], static fn ($v) => $v !== null);
        if ($changes === []) {
            throw ApiError::badRequest('Rien à modifier');
        }
        $before = $this->settingsOut();
        $s = new ServerSettings($this->ctx->db);
        foreach ($changes as $k => $v) {
            $s->set($k, $v, $this->ctx->user()->userId);
        }
        $after = $this->settingsOut();
        $this->ctx->audit('server.settings', 'server', null, $before, $after);
        // Télécommande coupée : invités et lecteurs prévenus, puis arrêt du service (et l'inverse)
        $expect = null;
        if ($after['remoteControlEnabled'] !== $before['remoteControlEnabled']) {
            if (!$after['remoteControlEnabled']) {
                RemoteController::announceDisabled($this->ctx);
            }
            if (\Musique\Realtime\Hub::requestService($this->ctx->config, $after['remoteControlEnabled'])) {
                $expect = $after['remoteControlEnabled'];
            }
        }
        return Response::json($after + ['realtimeService' => $this->realtimeService($expect), 'assistant' => \Musique\Ai\Factory::describe($this->ctx->config)]);
    }

    // ---------------------------------------------------------------- tâches

    private function jobsOut(array $rows): array
    {
        $refs = $this->userRefs(array_map(static fn ($j) => (int) $j['requested_by'], $rows));
        return array_map(static fn ($j) => [
            'id' => (int) $j['id'], 'type' => $j['type'], 'status' => $j['status'],
            'params' => $j['params'] === null ? null : (object) json_decode((string) $j['params'], true),
            'stats' => $j['stats'] === null ? null : (object) json_decode((string) $j['stats'], true),
            'requestedBy' => $j['requested_by'] === null ? null : ($refs[(int) $j['requested_by']] ?? null),
            'summary' => $j['summary'] ?? null,
            'createdAt' => Format::date($j['created_at']), 'startedAt' => Format::date($j['started_at']), 'finishedAt' => Format::date($j['finished_at']),
        ], $rows);
    }

    /** État du dépôt, tel que l'a laissé le dernier passage (la requête ne parcourt pas le stockage). */
    public function listInbox(array $params): Response
    {
        $rows = $this->ctx->db->all(
            "SELECT * FROM inbox_items ORDER BY status = 'filed', COALESCE(filed_at, updated_at) DESC, id DESC LIMIT 500"
        );
        return Response::json(['items' => array_map(static fn ($i) => [
            'id' => (int) $i['id'], 'sourceDir' => $i['source_dir'], 'status' => $i['status'],
            'fileCount' => (int) $i['file_count'], 'sizeBytes' => (int) $i['size_bytes'], 'message' => $i['message'] === null ? null : Lang::message((string) $i['message']),
            'albumTitle' => $i['album_title'], 'albumArtist' => $i['album_artist'], 'targetDir' => $i['target_dir'],
            'firstSeenAt' => Format::date($i['first_seen_at']), 'updatedAt' => Format::date($i['updated_at']), 'filedAt' => Format::date($i['filed_at']),
        ], $rows)]);
    }

    /** MP3 en double (LEC-05) : résumé par album. */
    public function listDuplicates(array $params): Response
    {
        $albums = (new Duplicates($this->ctx->db, $this->ctx->storage(), $this->ctx->logger))->albums();
        return Response::json(['items' => $albums, 'removableBytes' => array_sum(array_column($albums, 'removableBytes'))]);
    }

    /** Même enregistrement dans plusieurs pistes (BAS-03), d'après l'empreinte acoustique. */
    public function listSameRecordings(array $params): Response
    {
        $r = $this->ctx->request;
        $min = $r->queryNumber('minScore', 0.5, 1) ?? Fingerprint::CLOSE;
        $limit = (int) $r->queryInt('limit', 50, 1, 500);
        $offset = (int) $r->queryInt('offset', 0, 0);
        $groups = (new SameRecordings($this->ctx->db))->groups($min);
        $slice = array_slice($groups, $offset, $limit);
        $ids = array_merge(...array_map(static fn ($g) => $g['trackIds'], $slice ?: [['trackIds' => []]]));
        $tracks = [];
        foreach ((new Serializer($this->ctx->db, $this->ctx->principal?->userId))->tracks($ids) as $t) {
            $tracks[$t['id']] = $t;
        }
        $items = [];
        foreach ($slice as $g) {
            $items[] = [
                'score' => $g['score'],
                'verdict' => $g['score'] >= Fingerprint::SAME ? 'same' : 'close',
                'tracks' => array_values(array_filter(array_map(static fn (int $id) => $tracks[$id] ?? null, $g['trackIds']))),
            ];
        }
        return Response::json([
            'items' => $items, 'total' => count($groups), 'limit' => $limit, 'offset' => $offset,
            'fingerprinted' => (int) $this->ctx->db->value('SELECT COUNT(*) FROM track_analysis ta JOIN tracks t ON t.id = ta.track_id WHERE ta.chromaprint IS NOT NULL AND t.missing_since IS NULL'),
            'pending' => (int) $this->ctx->db->value("SELECT COUNT(*) FROM analysis_queue WHERE FIND_IN_SET('fingerprint', steps)"),
        ]);
    }

    /** Pistes dont le fichier servi a des trames illisibles (relevé par la mesure). */
    public function listDamagedTracks(array $params): Response
    {
        $r = $this->ctx->request;
        $limit = (int) $r->queryInt('limit', 50, 1, 500);
        $offset = (int) $r->queryInt('offset', 0, 0);
        $where = 'FROM track_analysis ta JOIN tracks t ON t.id = ta.track_id AND t.missing_since IS NULL
                  JOIN track_files tf ON tf.id = ta.file_id JOIN storage_objects so ON so.id = tf.object_id
                 WHERE ta.decode_errors > 0';
        $rows = $this->ctx->db->all("SELECT ta.track_id, ta.decode_errors, ta.decode_detail, so.storage_key $where
            ORDER BY ta.decode_errors DESC, so.storage_key LIMIT $limit OFFSET $offset");
        $tracks = [];
        foreach ((new Serializer($this->ctx->db, $this->ctx->principal?->userId))->tracks(array_map('intval', array_column($rows, 'track_id'))) as $t) {
            $tracks[$t['id']] = $t;
        }
        $items = [];
        foreach ($rows as $row) {
            if (isset($tracks[(int) $row['track_id']])) {
                $items[] = ['track' => $tracks[(int) $row['track_id']], 'storageKey' => (string) $row['storage_key'],
                    'errors' => (int) $row['decode_errors'], 'detail' => $row['decode_detail'] === null ? null : Lang::message((string) $row['decode_detail'])];
            }
        }
        return Response::json([
            'items' => $items, 'total' => (int) $this->ctx->db->value("SELECT COUNT(*) $where"), 'limit' => $limit, 'offset' => $offset,
            'checked' => (int) $this->ctx->db->value('SELECT COUNT(*) FROM track_analysis ta JOIN tracks t ON t.id = ta.track_id WHERE ta.decode_errors IS NOT NULL AND t.missing_since IS NULL'),
            'pending' => (int) $this->ctx->db->value('SELECT COUNT(*) FROM tracks t LEFT JOIN track_analysis ta ON ta.track_id = t.id WHERE t.missing_since IS NULL AND ta.decode_errors IS NULL'),
        ]);
    }

    /** MP3 en double d'un album, piste par piste. */
    public function getDuplicates(array $params): Response
    {
        $id = (int) $params['id'];
        $dup = new Duplicates($this->ctx->db, $this->ctx->storage(), $this->ctx->logger);
        $album = $this->ctx->db->one('SELECT a.id, a.title, ar.name AS artist FROM albums a LEFT JOIN artists ar ON ar.id = a.album_artist_id WHERE a.id = ?', [$id])
            ?? throw ApiError::notFound('Album introuvable');
        $files = $dup->files($id);
        $removable = array_values(array_filter($files, static fn ($f) => $f['removable']));
        return Response::json([
            'albumId' => $id, 'title' => (string) $album['title'], 'artist' => $album['artist'],
            'removable' => count($removable), 'kept' => count($files) - count($removable),
            'removableBytes' => array_sum(array_map(static fn ($f) => (int) $f['mp3_size'], $removable)),
            'files' => array_map(static fn ($f) => [
                'trackId' => (int) $f['track_id'], 'discNumber' => $f['disc_number'] === null ? null : (int) $f['disc_number'],
                'trackNumber' => $f['track_number'] === null ? null : (int) $f['track_number'], 'title' => (string) $f['title'],
                'mp3' => ['storageKey' => (string) $f['mp3_key'], 'sizeBytes' => (int) $f['mp3_size'], 'durationMs' => (int) $f['mp3_duration'],
                    'bitrateKbps' => $f['mp3_bitrate'] === null ? null : (int) $f['mp3_bitrate']],
                'lossless' => ['storageKey' => (string) $f['ll_key'], 'format' => (string) $f['ll_format'], 'durationMs' => (int) $f['ll_duration'],
                    'available' => $f['ll_missing'] === null],
                'removable' => (bool) $f['removable'], 'reason' => $f['reason'] === null ? null : Lang::message((string) $f['reason']),
            ], $files),
        ]);
    }

    public function listJobs(array $params): Response
    {
        $r = $this->ctx->request;
        [$limit, $offset] = $r->page();
        $where = ['1'];
        $args = [];
        if (($t = $r->queryEnum('type', ['index', 'analyze', 'inbox', 'thumbnails', 'import', 'mail', 'dedup', 'purge', 'organize'])) !== null) {
            $where[] = 'type = ?';
            $args[] = $t;
        }
        if (($s = $r->queryEnum('status', ['queued', 'running', 'done', 'failed', 'cancelled'])) !== null) {
            $where[] = 'status = ?';
            $args[] = $s;
        }
        $w = implode(' AND ', $where);
        $total = (int) $this->ctx->db->value("SELECT COUNT(*) FROM jobs WHERE $w", $args);
        $rows = $this->ctx->db->all("SELECT * FROM jobs WHERE $w ORDER BY id DESC LIMIT $limit OFFSET $offset", $args);
        return $this->page($this->jobsOut($rows), $total, $limit, $offset);
    }

    public function getJob(array $params): Response
    {
        $row = $this->ctx->db->one('SELECT * FROM jobs WHERE id = ?', [(int) $params['id']]) ?? throw ApiError::notFound('Tâche introuvable');
        return Response::json($this->jobsOut([$row])[0]);
    }

    public function createJob(array $params): Response
    {
        $in = new Input($this->ctx->request->json());
        $type = (string) $in->enum('type', ['index', 'analyze', 'import', 'inbox', 'dedup', 'organize']);
        $jobParams = [];
        $p = $in->object('params', $type === 'dedup' || $type === 'organize');
        if ($p !== null && $type === 'organize') {
            // Réorganisation par IA (IA-04) : consigne de l'admin, travail fait sous le compte agent
            $jobParams['instructions'] = (string) $p->text('instructions', 1, 4000);
            if (\Musique\Ai\Factory::fromConfig($this->ctx->config) === null) {
                throw new ApiError(409, 'conflict', 'Aucune IA configurée : ajoutez la section [ia] de rengaine.ini');
            }
            $agent = $this->ctx->db->value("SELECT id FROM users WHERE role = 'agent' AND status = 'active' ORDER BY id LIMIT 1")
                ?? throw new ApiError(409, 'conflict', 'Créez d’abord un compte agent (Admin > Comptes) : l’IA travaille sous son nom');
            $jobParams['agentUserId'] = (int) $agent;
        }
        if ($p !== null && $type === 'dedup') {
            $albumId = (int) $p->int('albumId', 1);
            $album = $this->ctx->db->one('SELECT id FROM albums WHERE id = ?', [$albumId]) ?? throw ApiError::invalid('params.albumId', 'Album introuvable');
            $jobParams['albumId'] = (int) $album['id'];
            $removable = array_filter((new Duplicates($this->ctx->db, $this->ctx->storage(), $this->ctx->logger))->files($albumId), static fn ($f) => $f['removable']);
            if ($removable === []) {
                throw new ApiError(409, 'conflict', 'Aucun MP3 retirable dans cet album');
            }
        }
        if ($p !== null) {
            if ($type === 'index') {
                $prefix = $p->string('prefix', 0, 1024, false);
                if ($prefix !== null && $prefix !== '') {
                    if (str_contains($prefix, '..') || str_starts_with($prefix, '/')) {
                        throw ApiError::invalid('params.prefix', 'Dossier invalide');
                    }
                    $jobParams['prefix'] = $prefix;
                }
                if ($p->bool('force', false) === true) {
                    $jobParams['force'] = true;
                }
            }
        }
        $id = $this->ctx->db->transaction(function () use ($type, $jobParams): int {
            // Un retrait de doublons par album à la fois ; les autres tâches : une seule de chaque type
            $same = $type === 'dedup'
                ? $this->ctx->db->value("SELECT id FROM jobs WHERE type = 'dedup' AND status IN ('queued', 'running') AND JSON_VALUE(params, '$.albumId') = ? LIMIT 1 FOR UPDATE", [$jobParams['albumId']])
                : $this->ctx->db->value("SELECT id FROM jobs WHERE type = ? AND status IN ('queued', 'running') LIMIT 1 FOR UPDATE", [$type]);
            if ($same !== null) {
                throw new ApiError(409, 'conflict', 'Une tâche de ce type est déjà en file ou en cours');
            }
            $this->ctx->db->run("INSERT INTO jobs (type, status, params, requested_by) VALUES (?, 'queued', ?, ?)",
                [$type, $jobParams === [] ? null : json_encode($jobParams, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $this->ctx->user()->userId]);
            return (int) $this->ctx->db->pdo()->lastInsertId();
        });
        $this->ctx->audit('job.create', 'job', $id, null, ['type' => $type, 'params' => $jobParams]);
        return Response::json($this->jobsOut([$this->ctx->db->one('SELECT * FROM jobs WHERE id = ?', [$id])])[0], 202);
    }

    public function listJobErrors(array $params): Response
    {
        $r = $this->ctx->request;
        [$limit, $offset] = $r->page();
        $where = [];
        $args = [];
        if (($j = $r->queryInt('job', null, 1)) !== null) {
            $where[] = 'job_id = ?';
            $args[] = $j;
        }
        if ($r->queryBool('open') !== false) {
            $where[] = 'resolved_at IS NULL';
        }
        $w = $where === [] ? '1' : implode(' AND ', $where);
        $total = (int) $this->ctx->db->value("SELECT COUNT(*) FROM job_errors WHERE $w", $args);
        $rows = $this->ctx->db->all("SELECT * FROM job_errors WHERE $w ORDER BY id DESC LIMIT $limit OFFSET $offset", $args);
        return $this->page(array_map(static fn ($e) => [
            'id' => (int) $e['id'], 'jobId' => (int) $e['job_id'], 'storageKey' => $e['storage_key'], 'code' => $e['code'],
            'message' => Lang::message((string) $e['message']), 'createdAt' => Format::date($e['created_at']), 'resolvedAt' => Format::date($e['resolved_at']),
        ], $rows), $total, $limit, $offset);
    }

    // ---------------------------------------------------------------- audit

    private static function parseDate(?string $v, string $field): ?string
    {
        if ($v === null) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.uP', str_replace('Z', '+00:00', $v))
            ?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', str_replace('Z', '+00:00', $v));
        if ($d === false) {
            throw ApiError::invalid($field, 'Date ISO 8601 attendue');
        }
        return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }

    /** Journaux techniques (JRN-07). */
    public function listLogs(array $params): Response
    {
        $r = $this->ctx->request;
        $source = (string) $r->queryEnum('source', LogReader::SOURCES, 'api');
        $level = $r->queryEnum('level', array_keys(Logger::LEVELS));
        $rid = $r->queryString('requestId', 26);
        if ($rid !== null && !preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $rid)) {
            throw ApiError::invalid('requestId', 'Paramètre « requestId » : identifiant de requête (26 caractères) attendu');
        }
        $cursor = $r->queryString('cursor', 40);
        if (!LogReader::validCursor($cursor)) {
            throw ApiError::invalid('cursor', 'Paramètre « cursor » : reprendre la valeur « next » de la page précédente');
        }
        $limit = (int) $r->queryInt('limit', 50, 1, 200);
        return Response::json(LogReader::fromConfig($this->ctx->config)
            ->read($source, ['level' => $level, 'q' => $r->queryString('q', 200), 'requestId' => $rid], $cursor, $limit));
    }

    public function listAudit(array $params): Response
    {
        $r = $this->ctx->request;
        [$limit, $offset] = $r->page();
        $where = ['1'];
        $args = [];
        if (($a = $r->queryString('action', 64)) !== null) {
            $where[] = 'action LIKE ?';
            $args[] = addcslashes($a, '%_\\') . '%';
        }
        if (($actor = $r->queryInt('actor', null, 1)) !== null) {
            $where[] = 'actor_user_id = ?';
            $args[] = $actor;
        }
        if (($tt = $r->queryString('targetType', 32)) !== null) {
            $where[] = 'target_type = ?';
            $args[] = $tt;
        }
        if (($ti = $r->queryInt('targetId', null, 1)) !== null) {
            $where[] = 'target_id = ?';
            $args[] = $ti;
        }
        if (($from = self::parseDate($r->queryString('from', 40), 'from')) !== null) {
            $where[] = 'created_at >= ?';
            $args[] = $from;
        }
        if (($to = self::parseDate($r->queryString('to', 40), 'to')) !== null) {
            $where[] = 'created_at <= ?';
            $args[] = $to;
        }
        $w = implode(' AND ', $where);
        $total = (int) $this->ctx->db->value("SELECT COUNT(*) FROM audit_log WHERE $w", $args);
        $rows = $this->ctx->db->all("SELECT * FROM audit_log WHERE $w ORDER BY id DESC LIMIT $limit OFFSET $offset", $args);
        $refs = $this->userRefs(array_map(static fn ($e) => (int) $e['actor_user_id'], $rows));
        return $this->page(array_map(static fn ($e) => [
            'id' => (int) $e['id'], 'createdAt' => Format::date($e['created_at']),
            'actor' => $e['actor_user_id'] === null ? null : ($refs[(int) $e['actor_user_id']] ?? null),
            'action' => $e['action'], 'targetType' => $e['target_type'], 'targetId' => Format::int($e['target_id']),
            'before' => json_decode((string) ($e['before_value'] ?? 'null'), true), 'after' => json_decode((string) ($e['after_value'] ?? 'null'), true),
            'ip' => Format::ip($e['ip']), 'requestId' => $e['request_id'],
        ], $rows), $total, $limit, $offset);
    }
}

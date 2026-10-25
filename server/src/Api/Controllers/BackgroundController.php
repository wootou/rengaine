<?php
// Images de fond du site (Admin > Fonds). Gardées en base, servies avec un cache permanent :
// l'adresse porte l'empreinte de l'image, qui ne change jamais.

declare(strict_types=1);

namespace Musique\Api\Controllers;

use Musique\Api\Context;
use Musique\Api\Format;
use Musique\Http\ApiError;
use Musique\Http\Input;
use Musique\Http\Response;

final class BackgroundController
{
    private const MAX_COUNT = 30;
    private const MAX_SIDE = 8000;
    private const TYPES = [IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png', IMAGETYPE_WEBP => 'image/webp'];
    private const COLUMNS = 'id, name, mime_type, width, height, size_bytes, sha256, active, created_at';

    public function __construct(private readonly Context $ctx)
    {
    }

    /** Fonds actifs : le client en tire un au hasard. */
    public function listBackgrounds(array $params): Response
    {
        $rows = $this->ctx->db->all('SELECT id, sha256 FROM backgrounds WHERE active = 1 ORDER BY id');
        return Response::json(['items' => array_map(static fn ($b) => ['id' => (int) $b['id'], 'imageUrl' => self::url($b)], $rows)]);
    }

    public function getBackgroundImage(array $params): Response
    {
        $row = $this->ctx->db->one('SELECT mime_type, sha256, data FROM backgrounds WHERE id = ?', [(int) $params['id']])
            ?? throw ApiError::notFound('Image de fond introuvable');
        $etag = '"' . bin2hex((string) $row['sha256']) . '"';
        $headers = ['ETag' => $etag, 'Cache-Control' => 'private, max-age=31536000, immutable'];
        $inm = (string) $this->ctx->request->header('if-none-match');
        if ($inm !== '' && in_array($etag, array_map('trim', explode(',', $inm)), true)) {
            $r = new Response(304);
        } else {
            $r = new Response(200, (string) $row['data']);
            $headers += ['Content-Type' => (string) $row['mime_type'], 'Content-Length' => (string) strlen((string) $row['data'])];
        }
        foreach ($headers as $k => $v) {
            $r->header($k, $v);
        }
        return $r;
    }

    public function adminListBackgrounds(array $params): Response
    {
        $rows = $this->ctx->db->all('SELECT ' . self::COLUMNS . ' FROM backgrounds ORDER BY id DESC');
        return Response::json(['items' => array_map(self::out(...), $rows)]);
    }

    public function adminCreateBackground(array $params): Response
    {
        $bytes = $this->ctx->request->image();
        $info = @getimagesizefromstring($bytes);
        $mime = $info === false ? null : (self::TYPES[$info[2]] ?? null);
        if ($info === false || $mime === null) {
            throw ApiError::badRequest('Image illisible : JPEG, PNG ou WebP seulement');
        }
        [$w, $h] = $info;
        if ($w < 1 || $h < 1 || $w > self::MAX_SIDE || $h > self::MAX_SIDE) {
            throw ApiError::badRequest(sprintf('Image de %d × %d px : 8000 px de côté au plus', $w, $h));
        }
        $name = $this->ctx->request->queryString('name', 100);
        $name = $name === null || trim($name) === '' ? null : trim($name);
        $sha = hash('sha256', $bytes, true);

        $id = $this->ctx->db->transaction(function () use ($bytes, $mime, $w, $h, $sha, $name): int {
            if ((int) $this->ctx->db->value('SELECT COUNT(*) FROM backgrounds FOR UPDATE') >= self::MAX_COUNT) {
                throw new ApiError(409, 'conflict', 'Trente images de fond au plus : supprimez-en une d’abord');
            }
            if ($this->ctx->db->value('SELECT id FROM backgrounds WHERE sha256 = ?', [$sha]) !== null) {
                throw new ApiError(409, 'conflict', 'Cette image est déjà dans les fonds');
            }
            $this->ctx->db->run(
                'INSERT INTO backgrounds (name, mime_type, width, height, size_bytes, sha256, data, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$name ?? 'Fond', $mime, $w, $h, strlen($bytes), $sha, $bytes, $this->ctx->user()->userId]
            );
            $id = (int) $this->ctx->db->pdo()->lastInsertId();
            if ($name === null) {
                $this->ctx->db->run('UPDATE backgrounds SET name = ? WHERE id = ?', ["Fond n° $id", $id]);
            }
            return $id;
        });
        $this->ctx->audit('background.create', 'background', $id, null, ['width' => $w, 'height' => $h, 'sizeBytes' => strlen($bytes)]);
        return Response::json($this->one($id), 201);
    }

    public function adminUpdateBackground(array $params): Response
    {
        $id = (int) $params['id'];
        $before = $this->one($id);
        $in = new Input($this->ctx->request->json());
        $name = $in->text('name', 1, 100, false);
        $active = $in->bool('active', false);
        if ($name === null && $active === null) {
            throw ApiError::badRequest('Rien à modifier (name ou active)');
        }
        $this->ctx->db->run('UPDATE backgrounds SET name = COALESCE(?, name), active = COALESCE(?, active) WHERE id = ?',
            [$name, $active === null ? null : (int) $active, $id]);
        $after = $this->one($id);
        $this->ctx->audit('background.update', 'background', $id,
            ['name' => $before['name'], 'active' => $before['active']], ['name' => $after['name'], 'active' => $after['active']]);
        return Response::json($after);
    }

    public function adminDeleteBackground(array $params): Response
    {
        $id = (int) $params['id'];
        $before = $this->one($id);
        $this->ctx->db->run('DELETE FROM backgrounds WHERE id = ?', [$id]);
        $this->ctx->audit('background.delete', 'background', $id, ['name' => $before['name']], null);
        return Response::noContent();
    }

    /** @return array<string, mixed> */
    private function one(int $id): array
    {
        $row = $this->ctx->db->one('SELECT ' . self::COLUMNS . ' FROM backgrounds WHERE id = ?', [$id])
            ?? throw ApiError::notFound('Image de fond introuvable');
        return self::out($row);
    }

    /** @param array<string, mixed> $b @return array<string, mixed> */
    private static function out(array $b): array
    {
        return [
            'id' => (int) $b['id'], 'name' => (string) $b['name'], 'active' => (bool) $b['active'],
            'width' => (int) $b['width'], 'height' => (int) $b['height'], 'sizeBytes' => (int) $b['size_bytes'],
            'mimeType' => (string) $b['mime_type'], 'imageUrl' => self::url($b), 'createdAt' => Format::date($b['created_at']),
        ];
    }

    /** @param array<string, mixed> $b */
    private static function url(array $b): string
    {
        return '/api/v2/backgrounds/' . (int) $b['id'] . '/image?v=' . substr(bin2hex((string) $b['sha256']), 0, 12);
    }
}

<?php
// Playlists (FUT-01) : privées par défaut ; « partagée » = visible et écoutable par tous les comptes.
// Seul le créateur modifie une playlist ; les autres la copient (création avec ses trackIds).
// Une playlist privée d'un autre compte répond 404, comme si elle n'existait pas.
// Les positions vont de 0 à n-1 sans trou : chaque modification les renumérote.

declare(strict_types=1);

namespace Musique\Api\Controllers;

use Musique\Api\Context;
use Musique\Api\Format;
use Musique\Catalog\Serializer;
use Musique\Http\ApiError;
use Musique\Http\Input;
use Musique\Http\Response;

final class PlaylistController
{
    public const MAX_ITEMS = 5000;

    public function __construct(private readonly Context $ctx)
    {
    }

    private function uid(): int
    {
        return (int) $this->ctx->user()->userId;
    }

    // ---------------------------------------------------------------- lecture

    public function listPlaylists(array $params): Response
    {
        $uid = $this->uid();
        $scope = $this->ctx->request->queryEnum('scope', ['all', 'mine', 'shared'], 'all');
        [$where, $args] = match ($scope) {
            'mine' => ['p.user_id = ?', [$uid]],
            'shared' => ['p.shared AND p.user_id <> ?', [$uid]],
            default => ['(p.user_id = ? OR p.shared)', [$uid]],
        };
        $rows = $this->ctx->db->all("SELECT p.id FROM playlists p WHERE $where ORDER BY p.user_id = ? DESC, p.updated_at DESC, p.id DESC LIMIT 1000", [...$args, $uid]);
        return Response::json(['items' => $this->summaries(array_map('intval', array_column($rows, 'id')))]);
    }

    public function getPlaylist(array $params): Response
    {
        return Response::json($this->detail($this->visible((int) $params['id'])));
    }

    // ---------------------------------------------------------------- modification

    public function createPlaylist(array $params): Response
    {
        $in = new Input($this->ctx->request->json());
        $name = trim((string) $in->string('name', 1, 200));
        if ($name === '') {
            throw ApiError::invalid('name', 'Nom obligatoire');
        }
        $description = $in->string('description', 0, 1000, false, true);
        $shared = $in->bool('shared', false) ?? false;
        $trackIds = $in->intList('trackIds', self::MAX_ITEMS, false) ?? [];
        $this->checkTracks($trackIds);
        $id = $this->ctx->db->transaction(function () use ($name, $description, $shared, $trackIds): int {
            $this->ctx->db->run('INSERT INTO playlists (user_id, name, description, shared) VALUES (?, ?, ?, ?)',
                [$this->uid(), $name, self::blankToNull($description), $shared ? 1 : 0]);
            $id = (int) $this->ctx->db->pdo()->lastInsertId();
            $this->insertItems($id, $trackIds, 0);
            return $id;
        });
        return Response::json($this->detail($id), 201);
    }

    public function updatePlaylist(array $params): Response
    {
        $id = $this->owned((int) $params['id']);
        $in = new Input($this->ctx->request->json());
        $set = [];
        $args = [];
        if ($in->has('name')) {
            $name = trim((string) $in->string('name', 1, 200));
            if ($name === '') {
                throw ApiError::invalid('name', 'Nom obligatoire');
            }
            $set[] = 'name = ?';
            $args[] = $name;
        }
        if ($in->has('description')) {
            $set[] = 'description = ?';
            $args[] = self::blankToNull($in->string('description', 0, 1000, true, true));
        }
        if ($in->has('shared')) {
            $set[] = 'shared = ?';
            $args[] = $in->bool('shared') ? 1 : 0;
        }
        if ($set === []) {
            throw ApiError::badRequest('Rien à modifier : name, description ou shared');
        }
        $this->ctx->db->run('UPDATE playlists SET ' . implode(', ', $set) . ', updated_at = CURRENT_TIMESTAMP(3) WHERE id = ?', [...$args, $id]);
        return Response::json($this->detail($id));
    }

    public function deletePlaylist(array $params): Response
    {
        $id = $this->owned((int) $params['id']);
        $this->ctx->db->run('DELETE FROM playlists WHERE id = ?', [$id]);
        return Response::noContent();
    }

    public function addPlaylistItems(array $params): Response
    {
        $id = $this->owned((int) $params['id']);
        $in = new Input($this->ctx->request->json());
        $trackIds = (array) $in->intList('trackIds', self::MAX_ITEMS);
        if ($trackIds === []) {
            throw ApiError::invalid('trackIds', 'Au moins une piste');
        }
        $position = $in->int('position', 0, PHP_INT_MAX, false);
        $this->checkTracks($trackIds);
        $this->ctx->db->transaction(function () use ($id, $trackIds, $position): void {
            $count = (int) $this->ctx->db->value('SELECT COUNT(*) FROM playlist_items WHERE playlist_id = ? FOR UPDATE', [$id]);
            if ($count + count($trackIds) > self::MAX_ITEMS) {
                throw new ApiError(409, 'conflict', 'Une playlist compte ' . self::MAX_ITEMS . ' pistes au plus');
            }
            $at = $position === null ? $count : min($position, $count);
            if ($at < $count) {
                $this->ctx->db->run('UPDATE playlist_items SET position = position + ? WHERE playlist_id = ? AND position >= ? ORDER BY position DESC',
                    [count($trackIds), $id, $at]);
            }
            $this->insertItems($id, $trackIds, $at);
            $this->touch($id);
        });
        return Response::json($this->detail($id));
    }

    public function reorderPlaylistItems(array $params): Response
    {
        $id = $this->owned((int) $params['id']);
        $in = new Input($this->ctx->request->json());
        $itemIds = (array) $in->intList('itemIds', self::MAX_ITEMS);
        $this->ctx->db->transaction(function () use ($id, $itemIds): void {
            $current = array_map('intval', array_column(
                $this->ctx->db->all('SELECT id FROM playlist_items WHERE playlist_id = ? FOR UPDATE', [$id]), 'id'));
            $a = $current;
            $b = $itemIds;
            sort($a);
            sort($b);
            if ($a !== $b) {
                throw ApiError::invalid('itemIds', 'Toutes les entrées de la playlist, chacune une fois');
            }
            $this->renumber($id, $itemIds);
            $this->touch($id);
        });
        return Response::json($this->detail($id));
    }

    public function removePlaylistItem(array $params): Response
    {
        $id = $this->owned((int) $params['id']);
        $this->ctx->db->transaction(function () use ($id, $params): void {
            $pos = $this->ctx->db->value('SELECT position FROM playlist_items WHERE id = ? AND playlist_id = ? FOR UPDATE', [(int) $params['itemId'], $id])
                ?? throw ApiError::notFound('Entrée introuvable dans cette playlist');
            $this->ctx->db->run('DELETE FROM playlist_items WHERE id = ?', [(int) $params['itemId']]);
            $this->ctx->db->run('UPDATE playlist_items SET position = position - 1 WHERE playlist_id = ? AND position > ? ORDER BY position', [$id, (int) $pos]);
            $this->touch($id);
        });
        return Response::json($this->detail($id));
    }

    // ---------------------------------------------------------------- outils

    /** Playlist que le compte peut voir (la sienne, ou partagée). */
    private function visible(int $id): int
    {
        $p = $this->ctx->db->one('SELECT id, user_id, shared FROM playlists WHERE id = ?', [$id]);
        if ($p === null || ((int) $p['user_id'] !== $this->uid() && !(bool) $p['shared'])) {
            throw ApiError::notFound('Playlist introuvable');
        }
        return $id;
    }

    /** Playlist que le compte peut modifier : la sienne seulement. */
    private function owned(int $id): int
    {
        $this->visible($id);
        if ((int) $this->ctx->db->value('SELECT user_id FROM playlists WHERE id = ?', [$id]) !== $this->uid()) {
            throw ApiError::forbidden('Seul le créateur modifie cette playlist ; copiez-la pour la changer');
        }
        return $id;
    }

    /** @param list<int> $trackIds */
    private function checkTracks(array $trackIds): void
    {
        $unique = array_values(array_unique($trackIds));
        if ($unique === []) {
            return;
        }
        $found = 0;
        foreach (array_chunk($unique, 1000) as $chunk) {
            $found += (int) $this->ctx->db->value('SELECT COUNT(*) FROM tracks WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk);
        }
        if ($found !== count($unique)) {
            throw ApiError::invalid('trackIds', 'Piste inconnue');
        }
    }

    /** @param list<int> $trackIds */
    private function insertItems(int $playlistId, array $trackIds, int $from): void
    {
        foreach (array_chunk($trackIds, 500, true) as $chunk) {
            $values = [];
            $args = [];
            foreach ($chunk as $i => $trackId) {
                $values[] = '(?, ?, ?)';
                array_push($args, $playlistId, $trackId, $from + $i);
            }
            $this->ctx->db->run('INSERT INTO playlist_items (playlist_id, track_id, position) VALUES ' . implode(', ', $values), $args);
        }
    }

    /** @param list<int> $itemIds dans le nouvel ordre */
    private function renumber(int $playlistId, array $itemIds): void
    {
        foreach (array_chunk($itemIds, 500, true) as $chunk) {
            $case = [];
            $args = [];
            foreach ($chunk as $pos => $itemId) {
                $case[] = 'WHEN ? THEN ?';
                array_push($args, $itemId, $pos);
            }
            $this->ctx->db->run('UPDATE playlist_items SET position = CASE id ' . implode(' ', $case) . ' END
                                  WHERE playlist_id = ? AND id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')',
                [...$args, $playlistId, ...array_values($chunk)]);
        }
    }

    private function touch(int $id): void
    {
        $this->ctx->db->run('UPDATE playlists SET updated_at = CURRENT_TIMESTAMP(3) WHERE id = ?', [$id]);
    }

    private static function blankToNull(?string $s): ?string
    {
        $s = $s === null ? null : trim($s);
        return $s === '' ? null : $s;
    }

    /**
     * @param list<int> $ids
     * @return list<array<string, mixed>> dans l'ordre de $ids
     */
    private function summaries(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $rows = [];
        foreach ($this->ctx->db->all(
            "SELECT p.id, p.user_id, p.name, p.description, p.shared, p.created_at, p.updated_at, u.display_name,
                    COUNT(i.id) AS n, COALESCE(SUM(t.duration_ms), 0) AS dur
               FROM playlists p JOIN users u ON u.id = p.user_id
               LEFT JOIN playlist_items i ON i.playlist_id = p.id LEFT JOIN tracks t ON t.id = i.track_id
              WHERE p.id IN ($in) GROUP BY p.id", $ids) as $r) {
            $rows[(int) $r['id']] = $r;
        }
        $covers = [];
        foreach ($this->ctx->db->all(
            "SELECT i.playlist_id, ci.sha256 FROM playlist_items i JOIN tracks t ON t.id = i.track_id
               JOIN albums a ON a.id = t.album_id JOIN images ci ON ci.id = a.cover_image_id
              WHERE i.playlist_id IN ($in) AND i.position < 200 ORDER BY i.playlist_id, i.position", $ids) as $c) {
            $list = &$covers[(int) $c['playlist_id']];
            $url = Format::imageUrl($c['sha256']);
            if (count($list ?? []) < 4 && !in_array($url, $list ?? [], true)) {
                $list[] = $url;
            }
            unset($list);
        }
        $uid = $this->uid();
        $out = [];
        foreach ($ids as $id) {
            $r = $rows[$id] ?? null;
            if ($r === null) {
                continue;
            }
            $out[] = [
                'id' => $id, 'name' => (string) $r['name'], 'description' => $r['description'],
                'owner' => ['id' => (int) $r['user_id'], 'displayName' => (string) $r['display_name']],
                'mine' => (int) $r['user_id'] === $uid, 'shared' => (bool) $r['shared'],
                'trackCount' => (int) $r['n'], 'durationMs' => (int) $r['dur'], 'coverUrls' => $covers[$id] ?? [],
                'createdAt' => Format::date($r['created_at']), 'updatedAt' => Format::date($r['updated_at']),
            ];
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function detail(int $id): array
    {
        $summary = $this->summaries([$id])[0] ?? throw ApiError::notFound('Playlist introuvable');
        $items = $this->ctx->db->all('SELECT id, track_id, position, added_at FROM playlist_items WHERE playlist_id = ? ORDER BY position, id', [$id]);
        $tracks = [];
        foreach ((new Serializer($this->ctx->db, $this->uid()))->tracks(array_map('intval', array_column($items, 'track_id'))) as $t) {
            $tracks[$t['id']] = $t;
        }
        $summary['items'] = [];
        foreach ($items as $i) {
            if (isset($tracks[(int) $i['track_id']])) {
                $summary['items'][] = ['id' => (int) $i['id'], 'position' => (int) $i['position'], 'addedAt' => Format::date($i['added_at']), 'track' => $tracks[(int) $i['track_id']]];
            }
        }
        return $summary;
    }
}

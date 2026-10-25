<?php
// Collections d'albums (CAT-08) : créées par l'admin, visibles de tous, dans l'ordre choisi.

declare(strict_types=1);

namespace Musique\Catalog;

use Musique\Api\Format;
use Musique\Db\Database;

final class Collections
{
    public const MAX_ALBUMS = 2000;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Résumés (avec la mosaïque des premières pochettes), dans l'ordre des identifiants donnés.
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public function summaries(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $in = implode(', ', array_fill(0, count($ids), '?'));
        $rows = [];
        foreach ($this->db->all(
            "SELECT c.id, c.kind, c.name, c.description, c.parent_id,
                    (SELECT COUNT(*) FROM collection_albums ca JOIN albums a ON a.id = ca.album_id WHERE ca.collection_id = c.id AND a.missing_since IS NULL) AS album_count
               FROM collections c WHERE c.id IN ($in)", $ids) as $r) {
            $rows[(int) $r['id']] = $r;
        }
        $covers = [];
        foreach ($this->db->all(
            "SELECT ca.collection_id, i.sha256 FROM collection_albums ca JOIN albums a ON a.id = ca.album_id JOIN images i ON i.id = a.cover_image_id
              WHERE ca.collection_id IN ($in) AND a.missing_since IS NULL ORDER BY ca.collection_id, ca.position, ca.album_id", $ids) as $c) {
            $list = &$covers[(int) $c['collection_id']];
            $url = (string) Format::imageUrl($c['sha256']);
            if (count($list ?? []) < 4 && !in_array($url, $list ?? [], true)) {
                $list[] = $url;
            }
            unset($list);
        }
        $out = [];
        foreach ($ids as $id) {
            if (!isset($rows[$id])) {
                continue;
            }
            $r = $rows[$id];
            $out[] = ['id' => $id, 'kind' => $r['kind'], 'name' => $r['name'], 'description' => $r['description'],
                'parentId' => Format::int($r['parent_id']), 'albumCount' => (int) $r['album_count'], 'coverUrls' => $covers[$id] ?? []];
        }
        return $out;
    }

    /** @return list<int> albums présents de la collection, dans l'ordre */
    public function albumIds(int $id): array
    {
        return array_map('intval', array_column($this->db->all(
            'SELECT ca.album_id FROM collection_albums ca JOIN albums a ON a.id = ca.album_id
              WHERE ca.collection_id = ? AND a.missing_since IS NULL ORDER BY ca.position, ca.album_id', [$id]
        ), 'album_id'));
    }

    /** @return ?array<string, mixed> */
    public function detail(int $id, ?int $userId): ?array
    {
        $summary = $this->summaries([$id])[0] ?? null;
        if ($summary === null) {
            return null;
        }
        $summary['albums'] = (new Serializer($this->db, $userId))->albums($this->albumIds($id));
        return $summary;
    }
}

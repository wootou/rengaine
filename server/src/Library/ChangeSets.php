<?php
// Lots de changements (IA-02) : les propositions de l'agent, et les fusions et séparations faites
// par l'admin (lots déjà appliqués). Un lot regroupe des corrections ; l'annuler les annule toutes.

declare(strict_types=1);

namespace Musique\Library;

use Musique\Api\Format;
use Musique\Db\Database;
use Musique\Http\ApiError;

final class ChangeSets
{
    public function __construct(private readonly Database $db, private readonly Corrections $corrections)
    {
    }

    /** Crée un lot ; un lot créé « applied » (fusion de l'admin) est décidé par son auteur. */
    public function create(string $title, ?string $description, string $status, int $userId): int
    {
        $applied = $status === 'applied';
        $this->db->run(
            'INSERT INTO change_sets (title, description, status, created_by, decided_by, decided_at) VALUES (?, ?, ?, ?, ?, ' . ($applied ? 'CURRENT_TIMESTAMP(3)' : 'NULL') . ')',
            [mb_substr($title, 0, 255), $description, $status, $userId, $applied ? $userId : null]
        );
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** Lot au format du contrat, avec le détail des changements si demandé. */
    public function out(int $id, bool $withChanges = true): array
    {
        $row = $this->db->one('SELECT * FROM change_sets WHERE id = ?', [$id]) ?? throw ApiError::notFound('Lot introuvable');
        return $this->outRows([$row], $withChanges)[0];
    }

    /**
     * @param list<array<string, mixed>> $rows lignes de change_sets
     * @return list<array<string, mixed>>
     */
    public function outRows(array $rows, bool $withChanges = false): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn ($r) => (int) $r['id'], $rows);
        $counts = [];
        foreach ($this->db->all(
            'SELECT change_set_id, COUNT(*) AS n FROM corrections WHERE change_set_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') GROUP BY change_set_id', $ids
        ) as $c) {
            $counts[(int) $c['change_set_id']] = (int) $c['n'];
        }
        $users = $this->corrections->users([...array_map(static fn ($r) => (int) $r['created_by'], $rows), ...array_map(static fn ($r) => (int) $r['decided_by'], $rows)]);
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $set = [
                'id' => $id,
                'title' => $r['title'],
                'description' => $r['description'],
                'status' => $r['status'],
                'createdBy' => $users[(int) $r['created_by']] ?? self::deletedAuthor((int) $r['created_by']),
                'createdAt' => Format::date($r['created_at']),
                'decidedAt' => Format::date($r['decided_at']),
                'decidedBy' => $r['decided_by'] === null ? null : ($users[(int) $r['decided_by']] ?? null),
                'changeCount' => $counts[$id] ?? 0,
            ];
            if ($withChanges) {
                $set['changes'] = $this->changes($id);
            }
            $out[] = $set;
        }
        return $out;
    }

    /** @return list<array<string, mixed>> changements d'un lot, avec la valeur actuelle en regard */
    private function changes(int $setId): array
    {
        $out = [];
        $rows = $this->db->all('SELECT * FROM corrections WHERE change_set_id = ? ORDER BY id', [$setId]);
        foreach ($this->corrections->serialize($rows) as $i => $c) {
            $r = $rows[$i];
            $type = (string) $r['entity_type'];
            $id = $type === 'genre_alias' ? (string) $r['entity_key'] : (int) $r['entity_id'];
            $change = ['entityType' => $c['entityType']];
            if ($type === 'genre_alias') {
                $change['alias'] = $id;
            } else {
                $change['entityId'] = $id;
            }
            $change += [
                'field' => $c['field'],
                'value' => $c['value'],
                'previousValue' => $c['previousValue'],
                'currentValue' => $this->corrections->currentForApi($type, $id, (string) $r['field']),
                'correctionId' => $c['id'],
                'status' => $c['status'],
            ];
            if ($c['reason'] !== null) {
                $change['reason'] = $c['reason'];
            }
            if (($label = $this->label($type, $id)) !== null) {
                $change['label'] = $label;
            }
            $out[] = $change;
        }
        return $out;
    }

    /** Nom lisible de l'élément (titre d'album ou de piste avec l'artiste, nom d'artiste), pour l'admin. */
    private function label(string $type, int|string $id): ?string
    {
        $row = match ($type) {
            'album' => $this->db->one('SELECT a.title AS name, ar.name AS artist FROM albums a LEFT JOIN artists ar ON ar.id = a.album_artist_id WHERE a.id = ?', [$id]),
            'track' => $this->db->one('SELECT t.title AS name, COALESCE(t.artist_credit, ar.name) AS artist FROM tracks t
                LEFT JOIN albums a ON a.id = t.album_id LEFT JOIN artists ar ON ar.id = a.album_artist_id WHERE t.id = ?', [$id]),
            'artist' => $this->db->one('SELECT name, NULL AS artist FROM artists WHERE id = ?', [$id]),
            default => null,
        };
        if ($row === null || $row['name'] === null) {
            return null;
        }
        return $row['artist'] !== null && $row['artist'] !== '' ? "{$row['name']} — {$row['artist']}" : (string) $row['name'];
    }

    /**
     * Auteur introuvable : compte supprimé (DELETE /me, created_by NULL → id 0).
     * @return array{id: int, displayName: string, role: string}
     */
    private static function deletedAuthor(int $id): array
    {
        return ['id' => $id, 'displayName' => $id === 0 ? \Musique\Lang::tr('Compte supprimé') : '?', 'role' => 'user'];
    }
}

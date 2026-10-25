<?php
// Corrections de l'admin (CAT-06) : chaque champ modifié devient une ligne de `corrections`,
// puis l'album est reconstruit exactement comme le ferait l'indexeur (AlbumBuilder lit les
// corrections actives). Une correction survit donc aux scans, et l'annuler fait revenir la
// valeur lue dans les tags. On ne supprime jamais une correction : l'annulation la marque.
//
// Une proposition de l'agent est une correction « proposed », rattachée à un lot : elle ne
// change rien avant d'être appliquée. Appliquer ou annuler plusieurs corrections à la fois ne
// reconstruit chaque dossier qu'une fois (fusion d'un album de vingt pistes, par exemple).

declare(strict_types=1);

namespace Musique\Library;

use Musique\Api\Format;
use Musique\Db\Database;
use Musique\Http\ApiError;

final class Corrections
{
    /** Nom du champ dans l'API → nom stocké. */
    public const ALBUM_FIELDS = ['title' => 'title', 'year' => 'year', 'albumArtist' => 'album_artist',
        'isCompilation' => 'is_compilation', 'genreIds' => 'genre_ids', 'coverImageHash' => 'cover'];
    public const TRACK_FIELDS = ['title' => 'title', 'artistCredit' => 'artist_credit', 'discNumber' => 'disc_number',
        'trackNumber' => 'track_number', 'bpm' => 'bpm', 'key' => 'key', 'genreIds' => 'genre_ids', 'albumId' => 'album_id'];
    public const ARTIST_FIELDS = ['name' => 'name', 'sortName' => 'sort_name', 'mergedInto' => 'merged_into'];
    public const ALIAS_FIELDS = ['genreId' => 'genre_id'];
    /** Type dans l'API → type stocké. */
    public const TYPES = ['album' => 'album', 'track' => 'track', 'artist' => 'artist', 'genreAlias' => 'genre_alias'];

    /** Notation anglaise → Camelot. */
    private const CAMELOT = [
        'G#m' => '1A', 'Abm' => '1A', 'D#m' => '2A', 'Ebm' => '2A', 'A#m' => '3A', 'Bbm' => '3A', 'Fm' => '4A', 'Cm' => '5A',
        'Gm' => '6A', 'Dm' => '7A', 'Am' => '8A', 'Em' => '9A', 'Bm' => '10A', 'F#m' => '11A', 'Gbm' => '11A', 'C#m' => '12A', 'Dbm' => '12A',
        'B' => '1B', 'Cb' => '1B', 'F#' => '2B', 'Gb' => '2B', 'Db' => '3B', 'C#' => '3B', 'Ab' => '4B', 'G#' => '4B', 'Eb' => '5B', 'D#' => '5B',
        'Bb' => '6B', 'A#' => '6B', 'F' => '7B', 'C' => '8B', 'G' => '9B', 'D' => '10B', 'A' => '11B', 'E' => '12B',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly Genres $genres,
        private readonly AlbumBuilder $builder,
    ) {
    }

    public static function camelot(?string $key): ?string
    {
        return $key === null ? null : (self::CAMELOT[$key] ?? null);
    }

    /** @return array<string, string> champs d'un type stocké (nom d'API → nom stocké) */
    public static function fields(string $type): array
    {
        return match ($type) {
            'album' => self::ALBUM_FIELDS,
            'track' => self::TRACK_FIELDS,
            'artist' => self::ARTIST_FIELDS,
            'genre_alias' => self::ALIAS_FIELDS,
        };
    }

    // ---------------------------------------------------------------- valeurs actuelles

    /** Valeur actuelle d'un champ (forme stockée). Pour genre_alias, $id est la valeur de tag. */
    public function current(string $type, int|string $id, string $field): mixed
    {
        $db = $this->db;
        if ($type === 'album') {
            return match ($field) {
                'title' => $db->value('SELECT title FROM albums WHERE id = ?', [$id]),
                'year' => Format::int($db->value('SELECT year FROM albums WHERE id = ?', [$id])),
                'album_artist' => $db->value('SELECT ar.name FROM albums a LEFT JOIN artists ar ON ar.id = a.album_artist_id WHERE a.id = ?', [$id]),
                'is_compilation' => (bool) $db->value('SELECT is_compilation FROM albums WHERE id = ?', [$id]),
                'cover' => ($h = $db->value('SELECT HEX(i.sha256) FROM albums a JOIN images i ON i.id = a.cover_image_id WHERE a.id = ?', [$id])) === null ? null : strtolower((string) $h),
                // Lignes complètes (genre + source) : l'annulation les remet telles quelles
                'genre_ids' => array_map(static fn ($r) => ['id' => (int) $r['genre_id'], 'source' => $r['source']],
                    $db->all('SELECT genre_id, source FROM album_genres WHERE album_id = ? ORDER BY genre_id', [$id])),
                default => throw new \InvalidArgumentException($field),
            };
        }
        if ($type === 'artist') {
            return match ($field) {
                'name' => $db->value('SELECT name FROM artists WHERE id = ?', [$id]),
                'sort_name' => $db->value('SELECT sort_name FROM artists WHERE id = ?', [$id]),
                'merged_into' => ($v = $db->value(
                    "SELECT value FROM corrections WHERE entity_type = 'artist' AND entity_id = ? AND field = 'merged_into' AND status = 'active' ORDER BY id DESC LIMIT 1", [$id]
                )) === null ? null : (int) json_decode((string) $v, true),
                default => throw new \InvalidArgumentException($field),
            };
        }
        if ($type === 'genre_alias') {
            return match ($field) {
                'genre_id' => Format::int($db->value('SELECT genre_id FROM genre_aliases WHERE alias = ?', [$id])),
                default => throw new \InvalidArgumentException($field),
            };
        }
        return match ($field) {
            'title' => $db->value('SELECT title FROM tracks WHERE id = ?', [$id]),
            'artist_credit' => $db->value('SELECT artist_credit FROM tracks WHERE id = ?', [$id]),
            'disc_number' => (int) $db->value('SELECT disc_number FROM tracks WHERE id = ?', [$id]),
            'track_number' => Format::int($db->value('SELECT track_number FROM tracks WHERE id = ?', [$id])),
            'bpm' => Format::float($db->value('SELECT bpm FROM track_analysis WHERE track_id = ?', [$id])),
            'key' => $db->value('SELECT musical_key FROM track_analysis WHERE track_id = ?', [$id]),
            'album_id' => (int) $db->value('SELECT album_id FROM tracks WHERE id = ?', [$id]),
            // Genres propres à la piste ; null = ceux de l'album
            'genre_ids' => ($own = array_map('intval', array_column($db->all("SELECT genre_id FROM track_genres WHERE track_id = ? AND source <> 'album' ORDER BY genre_id", [$id]), 'genre_id'))) === [] ? null : $own,
            default => throw new \InvalidArgumentException($field),
        };
    }

    /** Valeur actuelle au format de l'API (genres d'album : identifiants seuls). */
    public function currentForApi(string $type, int|string $id, string $field): mixed
    {
        $v = $this->current($type, $id, $field);
        return $type === 'album' && $field === 'genre_ids' ? array_column($v, 'id') : $v;
    }

    // ---------------------------------------------------------------- écriture

    /**
     * Enregistre les corrections d'un élément puis l'applique. Les champs inchangés sont ignorés.
     * @param array<string, mixed> $changes champ stocké → nouvelle valeur
     * @return list<int> identifiants des corrections créées
     */
    public function apply(string $type, int|string $id, array $changes, int $userId, ?int $changeSetId = null): array
    {
        $items = [];
        foreach ($changes as $field => $value) {
            $items[] = ['type' => $type, 'id' => $id, 'field' => $field, 'value' => $value];
        }
        return $this->applyMany($items, $userId, $changeSetId);
    }

    /**
     * Applique plusieurs changements, puis recalcule une seule fois chaque dossier touché.
     * Un changement qui vient d'une proposition (`proposal` : identifiant de la correction
     * proposée) devient actif même s'il ne change rien ; les autres sont ignorés s'ils ne changent rien.
     * @param list<array{type: string, id: int|string, field: string, value: mixed, proposal?: int}> $items
     * @return list<int> corrections créées ou activées
     */
    public function applyMany(array $items, int $userId, ?int $changeSetId = null): array
    {
        return $this->db->transaction(function () use ($items, $userId, $changeSetId): array {
            $targets = [];
            $done = [];
            foreach ($items as $it) {
                ['type' => $type, 'id' => $id, 'field' => $field, 'value' => $value] = $it;
                $proposal = $it['proposal'] ?? null;
                $previous = $this->current($type, $id, $field);
                $comparable = $field === 'genre_ids' && $type === 'album' ? array_column($previous, 'id') : $previous;
                if ($proposal === null && self::same($comparable, $value)) {
                    continue;
                }
                $this->collect($targets, $type, $id, $field, $value, $previous);
                // Ancien nom d'un artiste renommé : les tags qui le portent mènent toujours à lui
                $lookup = $type === 'artist' && $field === 'name' ? $previous : null;
                $json = json_encode($value, JSON_UNESCAPED_UNICODE);
                $prevJson = json_encode($previous, JSON_UNESCAPED_UNICODE);
                if ($proposal !== null) {
                    $this->db->run(
                        "UPDATE corrections SET status = 'active', value = ?, previous_value = ?, lookup_name = ? WHERE id = ? AND status = 'proposed'",
                        [$json, $prevJson, $lookup, $proposal]
                    );
                    $done[] = $proposal;
                } else {
                    $this->db->run(
                        'INSERT INTO corrections (entity_type, entity_id, entity_key, field, value, previous_value, change_set_id, lookup_name, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        [$type, is_int($id) ? $id : null, is_int($id) ? null : $id, $field, $json, $prevJson, $changeSetId, $lookup, $userId]
                    );
                    $done[] = (int) $this->db->pdo()->lastInsertId();
                }
                $this->setStored($type, $id, $field, $value, true);
            }
            if ($done !== []) {
                $this->refresh($targets);
            }
            return $done;
        });
    }

    /** Enregistre une proposition (rien ne change dans la bibliothèque). */
    public function propose(string $type, int|string $id, string $field, mixed $value, int $userId, int $changeSetId, ?string $reason): int
    {
        $this->db->run(
            "INSERT INTO corrections (entity_type, entity_id, entity_key, field, value, previous_value, change_set_id, reason, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'proposed', ?)",
            [$type, is_int($id) ? $id : null, is_int($id) ? null : $id, $field, json_encode($value, JSON_UNESCAPED_UNICODE),
                json_encode($this->currentForApi($type, $id, $field), JSON_UNESCAPED_UNICODE), $changeSetId, $reason, $userId]
        );
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** Annule une correction ; la valeur lue par l'indexeur (ou la correction précédente) revient. */
    public function revert(int $correctionId, int $userId): void
    {
        $this->revertMany([$correctionId], $userId, true);
    }

    /**
     * Annule des corrections, de la plus récente à la plus ancienne, puis recalcule une fois.
     * $strict : une correction introuvable (404) ou déjà annulée (409) est une erreur ;
     * sinon elle est ignorée (annulation d'un lot dont une correction a déjà été annulée seule).
     * @param list<int> $ids
     * @return int corrections annulées
     */
    public function revertMany(array $ids, int $userId, bool $strict = false): int
    {
        return $this->db->transaction(function () use ($ids, $userId, $strict): int {
            rsort($ids);
            $targets = [];
            $n = 0;
            foreach ($ids as $correctionId) {
                $c = $this->db->one('SELECT * FROM corrections WHERE id = ? FOR UPDATE', [$correctionId]);
                if ($c === null || $c['status'] !== 'active') {
                    if (!$strict) {
                        continue;
                    }
                    throw $c === null ? ApiError::notFound('Correction introuvable') : new ApiError(409, 'conflict', 'Cette correction n’est pas active');
                }
                $type = (string) $c['entity_type'];
                $id = $type === 'genre_alias' ? (string) $c['entity_key'] : (int) $c['entity_id'];
                $field = (string) $c['field'];
                $previous = json_decode((string) $c['previous_value'], true);
                $this->collect($targets, $type, $id, $field, json_decode((string) $c['value'], true), $previous);
                $newer = $this->db->value(
                    "SELECT id FROM corrections WHERE entity_type = ? AND (entity_id = ? OR entity_key = ?) AND field = ? AND status = 'active' AND id > ? LIMIT 1",
                    [$type, is_int($id) ? $id : null, is_int($id) ? null : $id, $field, $correctionId]
                );
                $this->db->run("UPDATE corrections SET status = 'reverted', reverted_by = ?, reverted_at = CURRENT_TIMESTAMP(3) WHERE id = ?", [$userId, $correctionId]);
                if ($newer === null) {
                    // Champs que l'indexeur ne recalcule pas : on remet la valeur d'avant
                    $this->setStored($type, $id, $field, $previous, false);
                }
                $n++;
            }
            if ($n > 0) {
                $this->refresh($targets);
            }
            return $n;
        });
    }

    /**
     * Pose directement les valeurs que l'indexeur ne recalcule pas (les autres viennent de la
     * reconstruction). $manual : valeur d'une correction ; sinon valeur d'avant remise.
     */
    private function setStored(string $type, int|string $id, string $field, mixed $value, bool $manual): void
    {
        if ($type === 'album' && $field === 'genre_ids') {
            $rows = $manual ? array_map(static fn ($g) => ['id' => (int) $g, 'source' => 'manual'], (array) $value) : (array) $value;
            $this->setAlbumGenres((int) $id, $rows);
        } elseif ($type === 'track' && in_array($field, ['bpm', 'key'], true)) {
            $this->setTrackDj((int) $id, $field, $value, $manual ? 'manual' : null);
        } elseif ($type === 'artist' && $field === 'name') {
            if ($this->db->value('SELECT id FROM artists WHERE name = ? AND id <> ?', [$value, $id]) !== null) {
                throw new ApiError(409, 'conflict', "Un autre artiste s’appelle déjà « $value » : fusionnez plutôt les deux");
            }
            $this->db->run('UPDATE artists SET name = ? WHERE id = ?', [$value, $id]);
        } elseif ($type === 'artist' && $field === 'sort_name') {
            $this->db->run('UPDATE artists SET sort_name = ? WHERE id = ?', [$value, $id]);
        } elseif ($type === 'genre_alias') {
            $this->db->run('UPDATE genre_aliases SET genre_id = (SELECT id FROM genres WHERE id = ?) WHERE alias = ?', [$value, $id]);
        }
    }

    /**
     * Note ce qu'il faudra recalculer, avant le changement (album d'origine d'une piste…).
     * @param array<string, array<int|string, mixed>> $t
     */
    private function collect(array &$t, string $type, int|string $id, string $field, mixed $value, mixed $previous): void
    {
        match ($type) {
            'album' => $t['albums'][(int) $id] = true,
            'track' => $t['tracks'][(int) $id] ??= (int) $this->current('track', $id, 'album_id'),
            'genre_alias' => $t['aliases'][(string) $id] = true,
            'artist' => null,
        };
        if ($type === 'artist' && $field === 'merged_into') {
            foreach ([(int) $id, $value, $previous] as $a) {
                if ($a !== null) {
                    $t['artists'][(int) $a] = true;
                }
            }
        }
    }

    /**
     * Recalcule ce qui a changé : chaque dossier une seule fois, puis les albums sans dossier,
     * les compteurs et les genres.
     * @param array<string, array<int|string, mixed>> $t
     */
    private function refresh(array $t): void
    {
        $dirs = [];
        $dirless = [];
        $albums = [];
        foreach (array_keys($t['albums'] ?? []) as $id) {
            $dir = $this->db->value('SELECT source_dir FROM albums WHERE id = ?', [$id]);
            if ($dir !== null) {
                $dirs[(string) $dir] = true;
            } else {
                $dirless[(int) $id] = true;
            }
        }
        $trackIds = array_keys($t['tracks'] ?? []);
        if ($trackIds !== []) {
            // Piste : on reconstruit le ou les dossiers de ses fichiers (déplacement compris)
            foreach ($this->db->all(
                'SELECT DISTINCT so.album_dir FROM track_files tf JOIN storage_objects so ON so.id = tf.object_id
                  WHERE tf.track_id IN (' . self::marks($trackIds) . ') AND so.album_dir IS NOT NULL', $trackIds
            ) as $r) {
                $dirs[(string) $r['album_dir']] = true;
            }
            foreach ($t['tracks'] as $old) {
                $albums[(int) $old] = true;
            }
        }
        $artists = array_keys($t['artists'] ?? []);
        if ($artists !== []) {
            // Fusion d'artistes : tout ce qui porte l'un ou l'autre est reconstruit
            $m = self::marks($artists);
            foreach ($this->db->all(
                "SELECT DISTINCT so.album_dir AS d FROM track_artists ta JOIN track_files tf ON tf.track_id = ta.track_id
                   JOIN storage_objects so ON so.id = tf.object_id WHERE ta.artist_id IN ($m) AND so.album_dir IS NOT NULL
                 UNION SELECT source_dir FROM albums WHERE album_artist_id IN ($m) AND source_dir IS NOT NULL", [...$artists, ...$artists]
            ) as $r) {
                $dirs[(string) $r['d']] = true;
            }
            foreach ($this->db->all("SELECT id FROM albums WHERE album_artist_id IN ($m) AND source_dir IS NULL", $artists) as $r) {
                $dirless[(int) $r['id']] = true;
            }
        }
        if (($t['aliases'] ?? []) !== []) {
            $this->genres->forgetAliases();
            foreach (array_keys($t['aliases']) as $alias) {
                foreach ($this->genres->albumsWithAlias((string) $alias) as $a) {
                    $albums[$a] = true;
                }
            }
        }

        foreach (array_keys($dirs) as $dir) {
            $this->builder->rebuild((string) $dir);
        }
        foreach (array_keys($dirless) as $id) {
            $this->applyToAlbumWithoutDir($id);
            $albums[$id] = true;
        }
        foreach ($trackIds as $id) {
            $albums[(int) $this->current('track', $id, 'album_id')] = true;
        }
        foreach (array_keys($albums) as $id) {
            $this->builder->updateCounts($id);
            $this->genres->refreshAlbum($id);
        }
    }

    /** Album créé dans l'admin (sans dossier) : rien à reconstruire, on pose les valeurs corrigées. */
    private function applyToAlbumWithoutDir(int $id): void
    {
        $values = $this->builder->corrections('album', [$id])[$id] ?? [];
        if (array_key_exists('title', $values)) {
            $this->db->run('UPDATE albums SET title = ? WHERE id = ?', [mb_substr((string) $values['title'], 0, 255), $id]);
        }
        if (array_key_exists('year', $values)) {
            $this->db->run('UPDATE albums SET year = ? WHERE id = ?', [$values['year'], $id]);
        }
        if (array_key_exists('album_artist', $values)) {
            $this->db->run('UPDATE albums SET album_artist_id = ? WHERE id = ?', [$values['album_artist'] === null ? null : $this->builder->artistId((string) $values['album_artist']), $id]);
        }
        if (array_key_exists('is_compilation', $values)) {
            $this->db->run('UPDATE albums SET is_compilation = ? WHERE id = ?', [(int) (bool) $values['is_compilation'], $id]);
        }
        if (array_key_exists('cover', $values)) {
            $this->db->run('UPDATE albums SET cover_image_id = (SELECT id FROM images WHERE sha256 = UNHEX(?)) WHERE id = ?', [$values['cover'], $id]);
        }
        $this->genres->refreshAlbum($id);
    }

    /** @param list<array{id: int, source: string}> $rows */
    private function setAlbumGenres(int $albumId, array $rows): void
    {
        $this->db->run('DELETE FROM album_genres WHERE album_id = ?', [$albumId]);
        foreach ($rows as $r) {
            $this->db->run('INSERT IGNORE INTO album_genres (album_id, genre_id, source) SELECT ?, id, ? FROM genres WHERE id = ?', [$albumId, $r['source'], $r['id']]);
        }
    }

    private function setTrackDj(int $trackId, string $field, mixed $value, ?string $source): void
    {
        $this->db->run('INSERT IGNORE INTO track_analysis (track_id) VALUES (?)', [$trackId]);
        if ($field === 'bpm') {
            $this->db->run('UPDATE track_analysis SET bpm = ?, bpm_source = ? WHERE track_id = ?', [$value, $value === null ? null : $source, $trackId]);
        } else {
            $this->db->run('UPDATE track_analysis SET musical_key = ?, camelot = ?, key_source = ? WHERE track_id = ?',
                [$value, self::camelot($value === null ? null : (string) $value), $value === null ? null : $source, $trackId]);
        }
    }

    private static function same(mixed $a, mixed $b): bool
    {
        if (is_array($a) && is_array($b)) {
            sort($a);
            sort($b);
        }
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return (float) $a === (float) $b;
        }
        return $a === $b;
    }

    /** @param list<mixed> $ids */
    private static function marks(array $ids): string
    {
        return implode(',', array_fill(0, count($ids), '?'));
    }

    // ---------------------------------------------------------------- lecture

    /**
     * Corrections au format du contrat (type et champ sous leur nom d'API).
     * @param list<array<string, mixed>> $rows lignes de `corrections`
     * @return list<array<string, mixed>>
     */
    public function serialize(array $rows): array
    {
        $userIds = array_values(array_unique(array_map(static fn ($r) => (int) $r['created_by'], $rows)));
        $users = $this->users($userIds);
        $out = [];
        foreach ($rows as $r) {
            $type = (string) $r['entity_type'];
            $map = array_flip(self::fields($type));
            $previous = json_decode((string) ($r['previous_value'] ?? 'null'), true);
            if ($r['field'] === 'genre_ids' && $type === 'album' && is_array($previous)) {
                $previous = array_map(static fn ($g) => is_array($g) ? (int) $g['id'] : (int) $g, $previous);
            }
            $c = [
                'id' => (int) $r['id'],
                'entityType' => array_flip(self::TYPES)[$type],
                'entityId' => Format::int($r['entity_id']),
                'field' => $map[$r['field']] ?? $r['field'],
                'value' => json_decode((string) $r['value'], true),
                'previousValue' => $previous,
                'status' => $r['status'],
                'changeSetId' => Format::int($r['change_set_id']),
                'reason' => $r['reason'],
                'createdBy' => $users[(int) $r['created_by']] ?? self::deletedAuthor((int) $r['created_by']),
                'createdAt' => Format::date($r['created_at']),
                'revertedAt' => Format::date($r['reverted_at']),
            ];
            if ($type === 'genre_alias') {
                $c['alias'] = (string) $r['entity_key'];
            }
            $out[] = $c;
        }
        return $out;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array{id: int, displayName: string, role: string}>
     */
    public function users(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        $users = [];
        if ($ids !== []) {
            foreach ($this->db->all('SELECT id, display_name, role FROM users WHERE id IN (' . self::marks($ids) . ')', $ids) as $u) {
                $users[(int) $u['id']] = ['id' => (int) $u['id'], 'displayName' => $u['display_name'], 'role' => $u['role']];
            }
        }
        return $users;
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

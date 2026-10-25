<?php
// Vérification d'un changement de la bibliothèque, commune aux corrections directes de l'admin
// (PATCH) et aux lots proposés par l'agent : élément existant, champ autorisé, valeur valide.
// Rend la valeur sous sa forme stockée. Les erreurs désignent le champ fautif tel que le client
// l'a envoyé (« year » dans un PATCH, « changes[3].value » dans un lot).

declare(strict_types=1);

namespace Musique\Library;

use Musique\Db\Database;
use Musique\Http\ApiError;
use Musique\Http\Input;

final class ChangeRules
{
    public function __construct(private readonly Database $db)
    {
    }

    /** L'élément existe-t-il ? (album ou piste disparus exclus) */
    public function exists(string $type, int|string $id): bool
    {
        return match ($type) {
            'album' => $this->db->value('SELECT id FROM albums WHERE id = ? AND missing_since IS NULL', [$id]) !== null,
            'track' => $this->db->value('SELECT id FROM tracks WHERE id = ?', [$id]) !== null,
            'artist' => $this->db->value('SELECT id FROM artists WHERE id = ?', [$id]) !== null,
            'genre_alias' => $this->db->value('SELECT alias FROM genre_aliases WHERE alias = ?', [$id]) !== null,
        };
    }

    /**
     * Valeur stockée d'un champ, lue dans $in sous la clé $key.
     * @param string $type type stocké (album, track, artist, genre_alias)
     * @param string $apiField nom du champ dans l'API (title, genreIds, mergedInto…)
     * @return array{0: string, 1: mixed} champ stocké, valeur
     */
    public function value(string $type, int|string $id, string $apiField, Input $in, string $key): array
    {
        $field = Corrections::fields($type)[$apiField] ?? null;
        if ($field === null) {
            throw ApiError::invalid($key, "Champ « $apiField » impossible à corriger ici");
        }
        $raw = $in->raw()[$key] ?? null;
        $value = match ("$type.$field") {
            'album.title', 'track.title' => $in->text($key, 1, 255),
            'album.year' => $in->int($key, 1000, 2100, true, true),
            'album.album_artist' => $raw === null ? $in->string($key, 0, 0, true, true) : $in->text($key, 1, 255),
            'album.is_compilation' => $in->bool($key),
            'album.genre_ids' => $this->genreIds((array) $in->intList($key, 50), $key),
            'album.cover' => $this->cover($in->string($key, 64, 64, true, true), $key),
            'track.artist_credit' => $raw === null ? $in->string($key, 0, 0, true, true) : $in->text($key, 1, 500),
            'track.disc_number' => $in->int($key, 1, 99),
            'track.track_number' => $in->int($key, 0, 999, true, true),
            'track.bpm' => $raw === null ? $in->string($key, 0, 0, true, true) : round((float) $in->number($key, 20, 300), 2),
            'track.key' => $this->key($in->string($key, 1, 3, true, true), $key),
            'track.genre_ids' => $raw === null ? $in->string($key, 0, 0, true, true) : $this->genreIds((array) $in->intList($key, 50), $key),
            'track.album_id' => $this->albumId((int) $in->int($key, 1), $key),
            'artist.name' => $in->text($key, 1, 255),
            'artist.sort_name' => $raw === null ? $in->string($key, 0, 0, true, true) : $in->text($key, 1, 255),
            'artist.merged_into' => $this->mergeTarget((int) $id, (int) $in->int($key, 1), $key),
            'genre_alias.genre_id' => $this->genreId($in->int($key, 1, PHP_INT_MAX, true, true), $key),
        };
        return [$field, $value];
    }

    /** @param list<int> $ids @return list<int> */
    private function genreIds(array $ids, string $key): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids !== [] && (int) $this->db->value('SELECT COUNT(*) FROM genres WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) !== count($ids)) {
            throw ApiError::invalid($key, 'Genre inconnu dans la liste');
        }
        return $ids;
    }

    private function genreId(?int $id, string $key): ?int
    {
        if ($id !== null && $this->db->value('SELECT id FROM genres WHERE id = ?', [$id]) === null) {
            throw ApiError::invalid($key, 'Genre inconnu');
        }
        return $id;
    }

    private function cover(?string $hash, string $key): ?string
    {
        if ($hash !== null && (!preg_match('/^[0-9a-f]{64}$/', $hash) || $this->db->value('SELECT id FROM images WHERE sha256 = UNHEX(?)', [$hash]) === null)) {
            throw ApiError::invalid($key, 'Image inconnue');
        }
        return $hash;
    }

    private function key(?string $key, string $field): ?string
    {
        if ($key !== null && !preg_match('/^[A-G][#b]?m?$/', $key)) {
            throw ApiError::invalid($field, 'Clé en notation anglaise attendue (C, Am, F#m…)');
        }
        return $key;
    }

    private function albumId(int $id, string $key): int
    {
        if ($this->db->value('SELECT id FROM albums WHERE id = ?', [$id]) === null) {
            throw ApiError::invalid($key, 'Album de destination inconnu');
        }
        return $id;
    }

    /** Fusion d'artistes : pas dans soi-même, ni dans un artiste déjà fusionné ailleurs. */
    private function mergeTarget(int $id, int $into, string $key): int
    {
        if ($into === $id || $this->db->value('SELECT id FROM artists WHERE id = ?', [$into]) === null) {
            throw ApiError::invalid($key, 'Artiste de destination inconnu');
        }
        if ($this->mergedInto($into) !== null) {
            throw ApiError::invalid($key, 'L’artiste de destination est lui-même fusionné dans un autre');
        }
        if ($this->mergedInto($id) !== null) {
            throw ApiError::invalid($key, 'Cet artiste est déjà fusionné dans un autre');
        }
        return $into;
    }

    /** Artiste dans lequel celui-ci est fusionné (correction active), ou null. */
    public function mergedInto(int $id): ?int
    {
        $v = $this->db->value(
            "SELECT value FROM corrections WHERE entity_type = 'artist' AND entity_id = ? AND field = 'merged_into' AND status = 'active' ORDER BY id DESC LIMIT 1", [$id]
        );
        return $v === null ? null : (int) json_decode((string) $v, true);
    }
}

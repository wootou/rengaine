<?php
// Pistes, albums et artistes au format du contrat (Track, AlbumSummary, ArtistSummary).
// Tout se charge par lots (une requête par type d'information, jamais une par piste) : une page de
// 500 pistes coûte une dizaine de requêtes.

declare(strict_types=1);

namespace Musique\Catalog;

use Musique\Api\Format;
use Musique\Db\Database;

final class Serializer
{
    public const VARIOUS = 'Artistes divers';
    public const UNKNOWN = 'Artiste inconnu';

    public function __construct(private readonly Database $db, private readonly ?int $userId)
    {
    }

    /** @param list<int> $ids */
    private static function in(array $ids): string
    {
        return implode(',', array_fill(0, count($ids), '?'));
    }

    /**
     * Favoris de l'utilisateur parmi $ids.
     * @param list<int> $ids
     * @return array<int, true>
     */
    private function favorites(string $type, array $ids): array
    {
        if ($this->userId === null || $ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->all('SELECT item_id FROM favorites WHERE user_id = ? AND item_type = ? AND item_id IN (' . self::in($ids) . ')',
            [$this->userId, $type, ...$ids]) as $r) {
            $out[(int) $r['item_id']] = true;
        }
        return $out;
    }

    // ---------------------------------------------------------------- pistes

    /**
     * @param list<int> $ids
     * @return list<array<string, mixed>> dans l'ordre de $ids (les inconnus sont ignorés)
     */
    public function tracks(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $in = self::in($ids);
        $rows = [];
        foreach ($this->db->all(
            "SELECT t.id, t.title, t.artist_credit, t.disc_number, t.track_number, t.duration_ms, t.missing_since,
                    a.id AS album_id, a.title AS album_title, a.year, aa.name AS album_artist,
                    COALESCE(ti.sha256, ci.sha256) AS cover,
                    an.loudness_lufs, an.true_peak_dbtp, an.audio_start_ms, an.audio_end_ms, an.bpm, an.musical_key, an.camelot, an.energy, an.danceability, an.beat_grid,
                    a.loudness_lufs AS album_lufs, a.true_peak_dbtp AS album_peak
               FROM tracks t
               JOIN albums a ON a.id = t.album_id
               LEFT JOIN artists aa ON aa.id = a.album_artist_id
               LEFT JOIN images ti ON ti.id = t.image_id
               LEFT JOIN images ci ON ci.id = a.cover_image_id
               LEFT JOIN track_analysis an ON an.track_id = t.id
              WHERE t.id IN ($in)", $ids) as $r) {
            $rows[(int) $r['id']] = $r;
        }

        $artists = [];
        foreach ($this->db->all(
            "SELECT ta.track_id, ar.id, ar.name, ta.role FROM track_artists ta JOIN artists ar ON ar.id = ta.artist_id
              WHERE ta.track_id IN ($in) ORDER BY ta.track_id, ta.position", $ids) as $r) {
            $artists[(int) $r['track_id']][] = ['id' => (int) $r['id'], 'name' => $r['name'], 'role' => $r['role']];
        }
        $genres = [];
        foreach ($this->db->all(
            "SELECT tg.track_id, g.id, g.name, tg.source FROM track_genres tg JOIN genres g ON g.id = tg.genre_id
              WHERE tg.track_id IN ($in) ORDER BY g.name", $ids) as $r) {
            $genres[(int) $r['track_id']][] = ['id' => (int) $r['id'], 'name' => $r['name'], 'inherited' => $r['source'] === 'album'];
        }
        $formats = [];
        foreach ($this->db->all(
            "SELECT tf.track_id, tf.format, tf.lossless, tf.bitrate_kbps, tf.sample_rate_hz, tf.bit_depth, tf.channels, so.size_bytes
               FROM track_files tf JOIN storage_objects so ON so.id = tf.object_id
              WHERE tf.track_id IN ($in) AND so.missing_since IS NULL
              ORDER BY tf.lossless DESC, tf.bitrate_kbps DESC, tf.id", $ids) as $r) {
            $formats[(int) $r['track_id']][] = [
                'format' => $r['format'], 'lossless' => (bool) $r['lossless'],
                'bitrateKbps' => Format::int($r['bitrate_kbps']), 'sampleRateHz' => Format::int($r['sample_rate_hz']),
                'bitDepth' => Format::int($r['bit_depth']), 'channels' => Format::int($r['channels']),
                'sizeBytes' => (int) $r['size_bytes'],
            ];
        }
        $favorites = $this->favorites('track', $ids);

        $out = [];
        foreach ($ids as $id) {
            $r = $rows[$id] ?? null;
            if ($r === null) {
                continue;
            }
            $trackArtists = $artists[$id] ?? [];
            $credit = $r['artist_credit'];
            if ($credit === null) {
                $main = array_column(array_filter($trackArtists, static fn ($a) => $a['role'] === 'main'), 'name');
                $credit = $main !== [] ? implode(', ', $main) : ($r['album_artist'] ?? self::UNKNOWN);
            }
            $out[] = [
                'id' => $id,
                'title' => $r['title'],
                'artistCredit' => $credit,
                'artists' => $trackArtists,
                'album' => ['id' => (int) $r['album_id'], 'title' => $r['album_title'], 'artistName' => $r['album_artist'] ?? self::VARIOUS, 'year' => Format::int($r['year'])],
                'genres' => $genres[$id] ?? [],
                'discNumber' => (int) $r['disc_number'],
                'trackNumber' => Format::int($r['track_number']),
                'durationMs' => (int) $r['duration_ms'],
                'coverUrl' => Format::imageUrl($r['cover']),
                'streamUrl' => "/api/v2/tracks/$id/stream",
                'formats' => $formats[$id] ?? [],
                'playback' => [
                    'track' => ['lufs' => Format::float($r['loudness_lufs']), 'truePeakDbtp' => Format::float($r['true_peak_dbtp'])],
                    'album' => ['lufs' => Format::float($r['album_lufs']), 'truePeakDbtp' => Format::float($r['album_peak'])],
                    'audioStartMs' => Format::int($r['audio_start_ms']),
                    'audioEndMs' => Format::int($r['audio_end_ms']),
                    'bpm' => Format::float($r['bpm']),
                    'key' => $r['musical_key'],
                    'camelot' => $r['camelot'],
                    'energy' => Format::float($r['energy']),
                    'danceability' => Format::float($r['danceability']),
                    'beatGrid' => self::beatGrid($r['beat_grid']),
                ],
                'favorite' => isset($favorites[$id]),
                'available' => $r['missing_since'] === null && isset($formats[$id]),
            ];
        }
        return $out;
    }

    // ---------------------------------------------------------------- albums

    /**
     * @param list<int> $ids
     * @return list<array<string, mixed>> AlbumSummary (+ champs internes préfixés « _ » pour le détail)
     */
    public function albums(array $ids, bool $detail = false): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $in = self::in($ids);
        $rows = [];
        foreach ($this->db->all(
            "SELECT a.id, a.title, a.kind, a.album_artist_id, ar.name AS artist_name, a.year, a.track_count, a.disc_count,
                    a.duration_ms, a.has_lossless, i.sha256 AS cover, a.added_at, a.is_compilation, a.loudness_lufs, a.true_peak_dbtp
               FROM albums a LEFT JOIN artists ar ON ar.id = a.album_artist_id LEFT JOIN images i ON i.id = a.cover_image_id
              WHERE a.id IN ($in)", $ids) as $r) {
            $rows[(int) $r['id']] = $r;
        }
        $genres = [];
        foreach ($this->db->all(
            "SELECT ag.album_id, g.id, g.name FROM album_genres ag JOIN genres g ON g.id = ag.genre_id
              WHERE ag.album_id IN ($in) ORDER BY g.name", $ids) as $r) {
            $genres[(int) $r['album_id']][] = ['id' => (int) $r['id'], 'name' => $r['name']];
        }
        $favorites = $this->favorites('album', $ids);

        $out = [];
        foreach ($ids as $id) {
            $r = $rows[$id] ?? null;
            if ($r === null) {
                continue;
            }
            $album = [
                'id' => $id,
                'title' => $r['title'],
                'kind' => $r['kind'],
                'artist' => $r['album_artist_id'] === null ? null : ['id' => (int) $r['album_artist_id'], 'name' => $r['artist_name']],
                'artistName' => $r['artist_name'] ?? self::VARIOUS,
                'year' => Format::int($r['year']),
                'trackCount' => (int) $r['track_count'],
                'discCount' => (int) $r['disc_count'],
                'durationMs' => (int) $r['duration_ms'],
                'hasLossless' => (bool) $r['has_lossless'],
                'coverUrl' => Format::imageUrl($r['cover']),
                'genres' => $genres[$id] ?? [],
                'addedAt' => Format::date($r['added_at']),
                'favorite' => isset($favorites[$id]),
            ];
            if ($detail) {
                $album['isCompilation'] = (bool) $r['is_compilation'];
                $album['loudness'] = ['lufs' => Format::float($r['loudness_lufs']), 'truePeakDbtp' => Format::float($r['true_peak_dbtp'])];
            }
            $out[] = $album;
        }
        return $out;
    }

    // ---------------------------------------------------------------- artistes

    /**
     * @param list<int> $ids
     * @return list<array<string, mixed>> ArtistSummary
     */
    public function artists(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $in = self::in($ids);
        $rows = [];
        foreach ($this->db->all(
            "SELECT ar.id, ar.name, ar.sort_name, i.sha256 AS image,
                    (SELECT COUNT(*) FROM albums a WHERE a.album_artist_id = ar.id AND a.missing_since IS NULL) AS album_count
               FROM artists ar LEFT JOIN images i ON i.id = ar.image_id WHERE ar.id IN ($in)", $ids) as $r) {
            $rows[(int) $r['id']] = $r;
        }
        $out = [];
        foreach ($ids as $id) {
            if (($r = $rows[$id] ?? null) !== null) {
                $out[] = ['id' => $id, 'name' => $r['name'], 'sortName' => $r['sort_name'], 'albumCount' => (int) $r['album_count'], 'imageUrl' => Format::imageUrl($r['image'])];
            }
        }
        return $out;
    }

    /** Grilles des temps (LEC-06), telles qu'enregistrées par l'analyseur. @return ?array<string, mixed> */
    private static function beatGrid(?string $json): ?array
    {
        $g = $json === null ? null : json_decode($json, true);
        return is_array($g) ? \Musique\Audio\Rhythm::grid($g) : null;
    }
}

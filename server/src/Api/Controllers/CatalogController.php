<?php
// Catalogue (API-03) : albums, artistes, pistes, genres, collections, recherche, aléatoire.
// Recherche insensible à la casse et aux accents grâce à la collation de la base ; tri naturel par
// les colonnes sort_key. Seuls les éléments présents dans le stockage sont listés.

declare(strict_types=1);

namespace Musique\Api\Controllers;

use Musique\Api\Context;
use Musique\Api\Format;
use Musique\Audio\Measure;
use Musique\Audio\Rhythm;
use Musique\Catalog\Collections;
use Musique\Catalog\Moods;
use Musique\Catalog\Serializer;
use Musique\Http\ApiError;
use Musique\Http\Response;
use Musique\Library\Genres;

final class CatalogController
{
    private Serializer $out;

    public function __construct(private readonly Context $ctx)
    {
        $this->out = new Serializer($ctx->db, $ctx->userId());
    }

    /** @param list<int> $ids */
    private static function in(array $ids): string
    {
        return implode(',', array_fill(0, count($ids), '?'));
    }

    /** Tri aléatoire stable : la même graine donne le même ordre d'une page à l'autre. */
    private static function randomOrder(string $idColumn, int $seed): string
    {
        return "CRC32(CONCAT($idColumn, ':', $seed)), $idColumn";
    }

    /** @return array{0: string, 1: list<mixed>} */
    private function favoriteFilter(string $type, string $idColumn): array
    {
        $uid = $this->ctx->user()->userId;
        return ["EXISTS (SELECT 1 FROM favorites f WHERE f.user_id = ? AND f.item_type = '$type' AND f.item_id = $idColumn)", [$uid]];
    }

    private function page(array $items, int $total, int $limit, int $offset): Response
    {
        return Response::json(['items' => $items, 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
    }

    // ---------------------------------------------------------------- albums

    public function listAlbums(array $params): Response
    {
        $r = $this->ctx->request;
        [$limit, $offset] = $r->page();
        $where = ['a.missing_since IS NULL'];
        $args = [];
        if (($q = $r->queryString('q')) !== null) {
            $where[] = '(a.title LIKE ? OR ar.name LIKE ?)';
            array_push($args, Format::like($q), Format::like($q));
        }
        if (($genres = (new Genres($this->ctx->db))->withDescendants($r->queryIntList('genre', 100))) !== []) {
            $where[] = 'EXISTS (SELECT 1 FROM album_genres ag WHERE ag.album_id = a.id AND ag.genre_id IN (' . self::in($genres) . '))';
            array_push($args, ...$genres);
        }
        if (($artist = $r->queryInt('artist', null, 1)) !== null) {
            $where[] = 'a.album_artist_id = ?';
            $args[] = $artist;
        }
        if (($collection = $r->queryInt('collection', null, 1)) !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM collection_albums ca WHERE ca.album_id = a.id AND ca.collection_id = ?)';
            $args[] = $collection;
        }
        if (($yearFrom = $r->queryInt('yearFrom', null, 0, 9999)) !== null) {
            $where[] = 'a.year >= ?';
            $args[] = $yearFrom;
        }
        if (($yearTo = $r->queryInt('yearTo', null, 0, 9999)) !== null) {
            $where[] = 'a.year <= ?';
            $args[] = $yearTo;
        }
        if ($r->queryBool('lossless') === true) {
            $where[] = 'a.has_lossless = 1';
        }
        if ($r->queryBool('favorite') === true) {
            [$sql, $a] = $this->favoriteFilter('album', 'a.id');
            $where[] = $sql;
            array_push($args, ...$a);
        }
        $sort = $r->queryEnum('sort', ['name', 'added', 'year', 'artist', 'random'], 'name');
        $dir = $r->queryEnum('order', ['asc', 'desc'], 'asc') === 'desc' ? 'DESC' : 'ASC';
        $order = match ($sort) {
            'name' => "a.sort_key $dir, a.id $dir",
            'added' => "a.added_at $dir, a.id $dir",
            'year' => "a.year IS NULL, a.year $dir, a.sort_key, a.id",
            'artist' => "ar.sort_key IS NULL, ar.sort_key $dir, a.year, a.sort_key, a.id",
            'random' => self::randomOrder('a.id', (int) $r->queryInt('seed', random_int(0, 1_000_000), 0)),
        };

        $from = 'FROM albums a LEFT JOIN artists ar ON ar.id = a.album_artist_id WHERE ' . implode(' AND ', $where);
        $total = (int) $this->ctx->db->value("SELECT COUNT(*) $from", $args);
        $ids = array_column($this->ctx->db->all("SELECT a.id $from ORDER BY $order LIMIT $limit OFFSET $offset", $args), 'id');
        return $this->page($this->out->albums($ids), $total, $limit, $offset);
    }

    public function getAlbum(array $params): Response
    {
        $id = (int) $params['id'];
        $album = $this->out->albums([$id], true)[0] ?? null;
        if ($album === null || $this->ctx->db->value('SELECT missing_since FROM albums WHERE id = ?', [$id]) !== null) {
            throw ApiError::notFound('Album introuvable');
        }
        $album['collections'] = array_map(static fn ($c) => ['id' => (int) $c['id'], 'kind' => $c['kind'], 'name' => $c['name']], $this->ctx->db->all(
            'SELECT c.id, c.kind, c.name FROM collection_albums ca JOIN collections c ON c.id = ca.collection_id WHERE ca.album_id = ? ORDER BY c.kind, c.name', [$id]
        ));
        $trackIds = array_column($this->ctx->db->all(
            'SELECT id FROM tracks WHERE album_id = ? AND missing_since IS NULL ORDER BY disc_number, track_number IS NULL, track_number, title, id', [$id]
        ), 'id');
        $album['tracks'] = $this->out->tracks($trackIds);
        return Response::json($album);
    }

    // ---------------------------------------------------------------- artistes

    private const ARTIST_LIVE = '(EXISTS (SELECT 1 FROM albums a WHERE a.album_artist_id = ar.id AND a.missing_since IS NULL)
        OR EXISTS (SELECT 1 FROM track_artists ta JOIN tracks t ON t.id = ta.track_id WHERE ta.artist_id = ar.id AND t.missing_since IS NULL))';

    public function listArtists(array $params): Response
    {
        $r = $this->ctx->request;
        [$limit, $offset] = $r->page();
        $where = [self::ARTIST_LIVE];
        $args = [];
        if (($q = $r->queryString('q')) !== null) {
            $where[] = 'ar.name LIKE ?';
            $args[] = Format::like($q);
        }
        $sort = $r->queryEnum('sort', ['name', 'albumCount', 'added'], 'name');
        $dir = $r->queryEnum('order', ['asc', 'desc'], 'asc') === 'desc' ? 'DESC' : 'ASC';
        $order = match ($sort) {
            'name' => "ar.sort_key $dir, ar.id",
            'added' => "ar.created_at $dir, ar.id",
            'albumCount' => "(SELECT COUNT(*) FROM albums a WHERE a.album_artist_id = ar.id AND a.missing_since IS NULL) $dir, ar.sort_key, ar.id",
        };
        $from = 'FROM artists ar WHERE ' . implode(' AND ', $where);
        $total = (int) $this->ctx->db->value("SELECT COUNT(*) $from", $args);
        $ids = array_column($this->ctx->db->all("SELECT ar.id $from ORDER BY $order LIMIT $limit OFFSET $offset", $args), 'id');
        return $this->page($this->out->artists($ids), $total, $limit, $offset);
    }

    public function getArtist(array $params): Response
    {
        $id = (int) $params['id'];
        $artist = $this->out->artists([$id])[0] ?? throw ApiError::notFound('Artiste introuvable');
        $db = $this->ctx->db;
        $artist['favorite'] = $this->ctx->userId() !== null
            && $db->value("SELECT 1 FROM favorites WHERE user_id = ? AND item_type = 'artist' AND item_id = ?", [$this->ctx->userId(), $id]) !== null;
        $artist['albums'] = $this->out->albums(array_column($db->all(
            'SELECT id FROM albums WHERE album_artist_id = ? AND missing_since IS NULL ORDER BY year IS NULL, year, sort_key, id', [$id]
        ), 'id'));
        $artist['appearsOn'] = $this->out->albums(array_column($db->all(
            'SELECT DISTINCT a.id, a.year, a.sort_key FROM track_artists ta JOIN tracks t ON t.id = ta.track_id JOIN albums a ON a.id = t.album_id
              WHERE ta.artist_id = ? AND t.missing_since IS NULL AND a.missing_since IS NULL AND (a.album_artist_id IS NULL OR a.album_artist_id <> ?)
              ORDER BY a.year IS NULL, a.year, a.sort_key, a.id', [$id, $id]
        ), 'id'));
        return Response::json($artist);
    }

    // ---------------------------------------------------------------- pistes

    public function listTracks(array $params): Response
    {
        $r = $this->ctx->request;
        [$limit, $offset] = $r->page();
        $where = ['t.missing_since IS NULL'];
        $args = [];
        if (($q = $r->queryString('q')) !== null) {
            $like = Format::like($q);
            $where[] = '(t.title LIKE ? OR t.artist_credit LIKE ? OR a.title LIKE ?
                OR EXISTS (SELECT 1 FROM track_artists ta JOIN artists ar ON ar.id = ta.artist_id WHERE ta.track_id = t.id AND ar.name LIKE ?))';
            array_push($args, $like, $like, $like, $like);
        }
        if (($album = $r->queryInt('album', null, 1)) !== null) {
            $where[] = 't.album_id = ?';
            $args[] = $album;
        }
        if (($artist = $r->queryInt('artist', null, 1)) !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM track_artists ta WHERE ta.track_id = t.id AND ta.artist_id = ?)';
            $args[] = $artist;
        }
        if (($genres = (new Genres($this->ctx->db))->withDescendants($r->queryIntList('genre', 100))) !== []) {
            $where[] = 'EXISTS (SELECT 1 FROM track_genres tg WHERE tg.track_id = t.id AND tg.genre_id IN (' . self::in($genres) . '))';
            array_push($args, ...$genres);
        }
        if (($min = $r->queryNumber('bpmMin', 0)) !== null) {
            $where[] = 'an.bpm >= ?';
            $args[] = $min;
        }
        if (($max = $r->queryNumber('bpmMax', 0)) !== null) {
            $where[] = 'an.bpm <= ?';
            $args[] = $max;
        }
        if (($camelot = $r->queryString('camelot')) !== null) {
            $keys = array_unique(explode(',', strtoupper($camelot)));
            foreach ($keys as $k) {
                if (!preg_match('/^(1[0-2]|[1-9])[AB]$/', $k)) {
                    throw ApiError::invalid('camelot', 'Paramètre « camelot » : clés de 1A à 12B attendues');
                }
            }
            $where[] = 'an.camelot IN (' . self::in($keys) . ')';
            array_push($args, ...$keys);
        }
        if ($r->queryBool('favorite') === true) {
            [$sql, $a] = $this->favoriteFilter('track', 't.id');
            $where[] = $sql;
            array_push($args, ...$a);
        }
        $sort = $r->queryEnum('sort', ['name', 'added', 'bpm', 'duration', 'random']);
        $dir = $r->queryEnum('order', ['asc', 'desc'], 'asc') === 'desc' ? 'DESC' : 'ASC';
        $order = match ($sort) {
            // Sans tri demandé, les pistes d'un album restent dans l'ordre du disque
            null => $album !== null ? 't.disc_number, t.track_number IS NULL, t.track_number, t.title, t.id' : "t.title $dir, t.id",
            'name' => "t.title $dir, t.id",
            'added' => "t.added_at $dir, t.id $dir",
            'bpm' => "an.bpm IS NULL, an.bpm $dir, t.id",
            'duration' => "t.duration_ms $dir, t.id",
            'random' => self::randomOrder('t.id', (int) $r->queryInt('seed', random_int(0, 1_000_000), 0)),
        };
        $from = 'FROM tracks t JOIN albums a ON a.id = t.album_id LEFT JOIN track_analysis an ON an.track_id = t.id WHERE ' . implode(' AND ', $where);
        $total = (int) $this->ctx->db->value("SELECT COUNT(*) $from", $args);
        $ids = array_column($this->ctx->db->all("SELECT t.id $from ORDER BY $order LIMIT $limit OFFSET $offset", $args), 'id');
        return $this->page($this->out->tracks($ids), $total, $limit, $offset);
    }

    public function getTrack(array $params): Response
    {
        $track = $this->out->tracks([(int) $params['id']])[0] ?? throw ApiError::notFound('Piste introuvable');
        return Response::json($track);
    }

    /** Forme d'onde (AUD-07) : niveaux en dBFS, ETag sur leur contenu. */
    public function getTrackWaveform(array $params): Response
    {
        $id = (int) $params['id'];
        $row = $this->ctx->db->one(
            'SELECT t.duration_ms, an.waveform, an.waveform_step_ms FROM tracks t
               JOIN track_analysis an ON an.track_id = t.id WHERE t.id = ? AND an.waveform IS NOT NULL',
            [$id]
        ) ?? throw ApiError::notFound('Forme d’onde pas encore relevée');
        $etag = '"w' . substr(md5($row['waveform'] . '|' . $row['waveform_step_ms']), 0, 16) . '"';
        $match = $this->ctx->request->header('If-None-Match');
        if ($match !== null && in_array($etag, array_map('trim', explode(',', $match)), true)) {
            return (new Response(304))->header('ETag', $etag);
        }
        return Response::json([
            'trackId' => $id,
            'durationMs' => (int) $row['duration_ms'],
            'stepMs' => (float) $row['waveform_step_ms'],
            'levels' => Measure::waveLevels($row['waveform']),
        ])->header('ETag', $etag)->header('Cache-Control', 'private, no-cache');
    }

    public function randomTracks(array $params): Response
    {
        $r = $this->ctx->request;
        $count = (int) $r->queryInt('count', 50, 1, 200);
        $where = ['t.missing_since IS NULL'];
        $args = [];
        if (($genres = (new Genres($this->ctx->db))->withDescendants($r->queryIntList('genre', 100))) !== []) {
            $where[] = 'EXISTS (SELECT 1 FROM track_genres tg WHERE tg.track_id = t.id AND tg.genre_id IN (' . self::in($genres) . '))';
            array_push($args, ...$genres);
        }
        if (($exclude = $r->queryIntList('exclude', 1000)) !== []) {
            $where[] = 't.id NOT IN (' . self::in($exclude) . ')';
            array_push($args, ...$exclude);
        }
        if (($albums = $r->queryIntList('excludeAlbum', 500)) !== []) {
            $where[] = 't.album_id NOT IN (' . self::in($albums) . ')';
            array_push($args, ...$albums);
        }
        // Ambiance, années, collection (CAT-08)
        if (($mood = $r->queryEnum('mood', array_keys(Moods::ALL))) !== null) {
            $where[] = Moods::where($mood);
        }
        if (($yearFrom = $r->queryInt('yearFrom', null, 0, 9999)) !== null) {
            $where[] = 'a.year >= ?';
            $args[] = $yearFrom;
        }
        if (($yearTo = $r->queryInt('yearTo', null, 0, 9999)) !== null) {
            $where[] = 'a.year <= ?';
            $args[] = $yearTo;
        }
        if (($collection = $r->queryInt('collection', null, 1)) !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM collection_albums ca WHERE ca.album_id = t.album_id AND ca.collection_id = ?)';
            $args[] = $collection;
        }
        $ids = array_column($this->ctx->db->all(
            'SELECT t.id FROM tracks t JOIN albums a ON a.id = t.album_id LEFT JOIN track_analysis an ON an.track_id = t.id WHERE '
                . implode(' AND ', $where) . " ORDER BY RAND() LIMIT $count", $args), 'id');
        return Response::json(['items' => $this->out->tracks($ids)]);
    }

    // ---------------------------------------------------------------- genres, collections

    public function listGenres(array $params): Response
    {
        $aliases = [];
        foreach ($this->ctx->db->all('SELECT alias, genre_id FROM genre_aliases WHERE genre_id IS NOT NULL ORDER BY alias') as $a) {
            $aliases[(int) $a['genre_id']][] = $a['alias'];
        }
        $items = [];
        foreach ($this->ctx->db->all(
            'SELECT g.id, g.name, g.parent_id,
                    (SELECT COUNT(*) FROM album_genres ag JOIN albums a ON a.id = ag.album_id WHERE ag.genre_id = g.id AND a.missing_since IS NULL) AS album_count,
                    (SELECT COUNT(*) FROM track_genres tg JOIN tracks t ON t.id = tg.track_id WHERE tg.genre_id = g.id AND t.missing_since IS NULL) AS track_count
               FROM genres g ORDER BY g.name'
        ) as $g) {
            $items[] = [
                'id' => (int) $g['id'], 'name' => $g['name'], 'parentId' => Format::int($g['parent_id']),
                'albumCount' => (int) $g['album_count'], 'trackCount' => (int) $g['track_count'],
                'aliases' => $aliases[(int) $g['id']] ?? [],
            ];
        }
        return Response::json(['items' => $items]);
    }

    public function listCollections(array $params): Response
    {
        $kind = $this->ctx->request->queryEnum('kind', ['category', 'mood', 'collection']);
        $ids = array_map('intval', array_column($this->ctx->db->all(
            'SELECT id FROM collections' . ($kind === null ? '' : ' WHERE kind = ?') . ' ORDER BY kind, name',
            $kind === null ? [] : [$kind]
        ), 'id'));
        return Response::json(['items' => (new Collections($this->ctx->db))->summaries($ids)]);
    }

    public function getCollection(array $params): Response
    {
        return Response::json((new Collections($this->ctx->db))->detail((int) $params['id'], $this->ctx->userId())
            ?? throw ApiError::notFound('Collection introuvable'));
    }

    /** Ambiances calculées (CAT-08) ; aucune tant que l'énergie n'est pas mesurée. */
    public function listMoods(array $params): Response
    {
        return Response::json(['items' => Rhythm::fromConfig($this->ctx->config) === null ? [] : Moods::list($this->ctx->db)]);
    }

    public function listDecades(array $params): Response
    {
        $items = [];
        foreach ($this->ctx->db->all(
            'SELECT FLOOR(a.year / 10) * 10 AS decade, COUNT(DISTINCT a.id) AS albums, COUNT(t.id) AS tracks
               FROM albums a JOIN tracks t ON t.album_id = a.id AND t.missing_since IS NULL
              WHERE a.missing_since IS NULL AND a.year IS NOT NULL GROUP BY decade ORDER BY decade'
        ) as $d) {
            $items[] = ['decade' => (int) $d['decade'], 'albumCount' => (int) $d['albums'], 'trackCount' => (int) $d['tracks']];
        }
        return Response::json(['items' => $items]);
    }

    // ---------------------------------------------------------------- recherche

    public function search(array $params): Response
    {
        $r = $this->ctx->request;
        $q = $r->queryString('q') ?? throw ApiError::invalid('q', 'Paramètre « q » obligatoire');
        $limit = (int) $r->queryInt('limit', 8, 1, 50);
        $like = Format::like($q);
        $prefix = addcslashes($q, '%_\\') . '%';
        $this->ctx->limiter->hit('search', (string) $this->ctx->user()->userId, $this->ctx->config->int('limites', 'recherches_par_minute', 600), 60);
        $db = $this->ctx->db;

        // Ce qui commence par le texte tapé passe avant ce qui le contient
        $artists = array_column($db->all(
            'SELECT ar.id FROM artists ar WHERE ar.name LIKE ? AND ' . self::ARTIST_LIVE . " ORDER BY ar.name LIKE ? DESC, ar.sort_key LIMIT $limit",
            [$like, $prefix]
        ), 'id');
        $albums = array_column($db->all(
            "SELECT a.id FROM albums a WHERE a.title LIKE ? AND a.missing_since IS NULL ORDER BY a.title LIKE ? DESC, a.sort_key LIMIT $limit",
            [$like, $prefix]
        ), 'id');
        $tracks = array_column($db->all(
            "SELECT t.id FROM tracks t WHERE t.title LIKE ? AND t.missing_since IS NULL ORDER BY t.title LIKE ? DESC, t.title LIMIT $limit",
            [$like, $prefix]
        ), 'id');
        return Response::json([
            'artists' => $this->out->artists($artists),
            'albums' => $this->out->albums($albums),
            'tracks' => $this->out->tracks($tracks),
        ]);
    }
}

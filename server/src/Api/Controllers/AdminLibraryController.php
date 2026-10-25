<?php
// Admin de la bibliothèque (CPT-07) : alias de genres, genres, corrections d'albums et de pistes,
// historique et annulation des corrections. Toute modification passe par Library\Corrections
// (ou recalcule les genres), et laisse une trace dans le journal d'audit.

declare(strict_types=1);

namespace Musique\Api\Controllers;

use Musique\Api\Context;
use Musique\Api\Format;
use Musique\Catalog\Collections;
use Musique\Catalog\Serializer;
use Musique\Http\ApiError;
use Musique\Http\Input;
use Musique\Http\Response;
use Musique\Library\AlbumBuilder;
use Musique\Library\ChangeRules;
use Musique\Library\ChangeSets;
use Musique\Library\Corrections;
use Musique\Library\Genres;
use Musique\Library\Images;

final class AdminLibraryController
{
    public function __construct(private readonly Context $ctx)
    {
    }

    private function genres(): Genres
    {
        return new Genres($this->ctx->db);
    }

    private function corrections(): Corrections
    {
        $genres = $this->genres();
        $builder = new AlbumBuilder($this->ctx->db, $genres, new Images($this->ctx->db, $this->ctx->storage(), $this->ctx->cacheDir()));
        return new Corrections($this->ctx->db, $genres, $builder);
    }

    private function rules(): ChangeRules
    {
        return new ChangeRules($this->ctx->db);
    }

    private function changeSets(Corrections $corrections): ChangeSets
    {
        return new ChangeSets($this->ctx->db, $corrections);
    }

    /**
     * Champs corrigés d'un PATCH, sous leur forme stockée.
     * @param list<string> $ignore champs du corps qui ne sont pas des corrections
     * @return array<string, mixed>
     */
    private function patchChanges(string $type, int $id, Input $in, array $ignore = []): array
    {
        $changes = [];
        foreach (array_keys($in->raw()) as $apiField) {
            if (in_array($apiField, $ignore, true)) {
                continue;
            }
            [$field, $value] = $this->rules()->value($type, $id, (string) $apiField, $in, (string) $apiField);
            $changes[$field] = $value;
        }
        return $changes;
    }

    /** @param list<int> $albumIds */
    private function refreshAlbums(array $albumIds): void
    {
        $genres = $this->genres(); // nouvelle instance : rattachements relus
        foreach (array_unique($albumIds) as $id) {
            $genres->refreshAlbum((int) $id);
        }
    }

    // ---------------------------------------------------------------- alias de genres

    private function aliasOut(array $r): array
    {
        return [
            'alias' => $r['alias'],
            'genre' => $r['genre_id'] === null ? null : ['id' => (int) $r['genre_id'], 'name' => $r['genre_name']],
            'trackCount' => (int) $r['track_count'],
            'firstSeenAt' => Format::date($r['first_seen_at']),
        ];
    }

    public function listGenreAliases(array $params): Response
    {
        $r = $this->ctx->request;
        [$limit, $offset] = $r->page();
        $where = ['1'];
        $args = [];
        $mapped = $r->queryBool('mapped');
        if ($mapped !== null) {
            $where[] = $mapped ? 'ga.genre_id IS NOT NULL' : 'ga.genre_id IS NULL';
        }
        if (($q = $r->queryString('q')) !== null) {
            $where[] = 'ga.alias LIKE ?';
            $args[] = Format::like($q);
        }
        $w = implode(' AND ', $where);
        $total = (int) $this->ctx->db->value("SELECT COUNT(*) FROM genre_aliases ga WHERE $w", $args);
        $rows = $this->ctx->db->all(
            "SELECT ga.alias, ga.genre_id, g.name AS genre_name, ga.track_count, ga.first_seen_at
               FROM genre_aliases ga LEFT JOIN genres g ON g.id = ga.genre_id WHERE $w
              ORDER BY ga.track_count DESC, ga.alias LIMIT $limit OFFSET $offset", $args
        );
        return Response::json(['items' => array_map($this->aliasOut(...), $rows), 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
    }

    public function mapGenreAlias(array $params): Response
    {
        $in = new Input($this->ctx->request->json());
        $alias = (string) $in->string('alias', 1, 100);
        if (!$in->has('genreId')) {
            throw ApiError::invalid('genreId', 'Champ « genreId » obligatoire (null pour détacher)');
        }
        $genreId = $in->int('genreId', 1, PHP_INT_MAX, true, true);
        $row = $this->ctx->db->one('SELECT alias, genre_id FROM genre_aliases WHERE alias = ?', [$alias]) ?? throw ApiError::notFound('Valeur de tag inconnue');
        if ($genreId !== null && $this->ctx->db->value('SELECT id FROM genres WHERE id = ?', [$genreId]) === null) {
            throw ApiError::notFound('Genre introuvable');
        }
        $this->ctx->db->transaction(function () use ($row, $genreId): void {
            $this->ctx->db->run('UPDATE genre_aliases SET genre_id = ? WHERE alias = ?', [$genreId, $row['alias']]);
            $this->refreshAlbums($this->genres()->albumsWithAlias((string) $row['alias']));
        });
        $this->ctx->audit('genre_alias.map', 'genre_alias', null, ['alias' => $row['alias'], 'genreId' => Format::int($row['genre_id'])], ['alias' => $row['alias'], 'genreId' => $genreId]);
        $out = $this->ctx->db->one('SELECT ga.alias, ga.genre_id, g.name AS genre_name, ga.track_count, ga.first_seen_at FROM genre_aliases ga LEFT JOIN genres g ON g.id = ga.genre_id WHERE ga.alias = ?', [$row['alias']]);
        return Response::json($this->aliasOut($out));
    }

    // ---------------------------------------------------------------- genres

    private function genreOut(int $id): array
    {
        $g = $this->ctx->db->one(
            'SELECT g.id, g.name, g.parent_id,
                    (SELECT COUNT(*) FROM album_genres ag JOIN albums a ON a.id = ag.album_id WHERE ag.genre_id = g.id AND a.missing_since IS NULL) AS album_count,
                    (SELECT COUNT(*) FROM track_genres tg JOIN tracks t ON t.id = tg.track_id WHERE tg.genre_id = g.id AND t.missing_since IS NULL) AS track_count
               FROM genres g WHERE g.id = ?', [$id]
        ) ?? throw ApiError::notFound('Genre introuvable');
        return ['id' => (int) $g['id'], 'name' => $g['name'], 'parentId' => Format::int($g['parent_id']),
            'albumCount' => (int) $g['album_count'], 'trackCount' => (int) $g['track_count'],
            'aliases' => array_column($this->ctx->db->all('SELECT alias FROM genre_aliases WHERE genre_id = ? ORDER BY alias', [$id]), 'alias')];
    }

    private function checkParent(?int $parentId, ?int $self): void
    {
        if ($parentId === null) {
            return;
        }
        // Le parent doit exister, et la hiérarchie ne doit pas boucler
        $seen = [];
        for ($p = $parentId; $p !== null; $p = Format::int($this->ctx->db->value('SELECT parent_id FROM genres WHERE id = ?', [$p]))) {
            if ($self !== null && $p === $self) {
                throw ApiError::invalid('parentId', 'Un genre ne peut pas être son propre parent (même indirectement)');
            }
            if (isset($seen[$p]) || $this->ctx->db->value('SELECT id FROM genres WHERE id = ?', [$p]) === null) {
                if (!isset($seen[$p])) {
                    throw ApiError::invalid('parentId', 'Genre parent inconnu');
                }
                break;
            }
            $seen[$p] = true;
        }
    }

    public function createGenre(array $params): Response
    {
        $in = new Input($this->ctx->request->json());
        $name = (string) $in->text('name', 1, 100);
        $parent = $in->int('parentId', 1, PHP_INT_MAX, false, true);
        if ($this->ctx->db->value('SELECT id FROM genres WHERE name = ?', [$name]) !== null) {
            throw new ApiError(409, 'conflict', 'Un genre porte déjà ce nom (sans tenir compte de la casse ni des accents)');
        }
        $this->checkParent($parent, null);
        $id = $this->ctx->db->transaction(function () use ($name, $parent): int {
            $this->ctx->db->run('INSERT INTO genres (name, parent_id) VALUES (?, ?)', [$name, $parent]);
            $id = (int) $this->ctx->db->pdo()->lastInsertId();
            // Les valeurs de tag de même nom sont rattachées d'office (règle de l'indexeur)
            $aliases = array_column($this->ctx->db->all('SELECT alias FROM genre_aliases WHERE genre_id IS NULL AND alias = ?', [$name]), 'alias');
            $this->ctx->db->run('UPDATE genre_aliases SET genre_id = ? WHERE genre_id IS NULL AND alias = ?', [$id, $name]);
            foreach ($aliases as $a) {
                $this->refreshAlbums($this->genres()->albumsWithAlias((string) $a));
            }
            return $id;
        });
        $this->ctx->audit('genre.create', 'genre', $id, null, ['name' => $name, 'parentId' => $parent]);
        return Response::json($this->genreOut($id), 201);
    }

    public function updateGenre(array $params): Response
    {
        $id = (int) $params['id'];
        $before = $this->genreOut($id);
        $in = new Input($this->ctx->request->json());
        if (!$in->has('name') && !$in->has('parentId')) {
            throw ApiError::badRequest('Rien à modifier');
        }
        $name = $in->has('name') ? (string) $in->text('name', 1, 100) : $before['name'];
        $parent = $in->has('parentId') ? $in->int('parentId', 1, PHP_INT_MAX, true, true) : $before['parentId'];
        if ($this->ctx->db->value('SELECT id FROM genres WHERE name = ? AND id <> ?', [$name, $id]) !== null) {
            throw new ApiError(409, 'conflict', 'Un autre genre porte déjà ce nom');
        }
        $this->checkParent($parent, $id);
        $this->ctx->db->run('UPDATE genres SET name = ?, parent_id = ? WHERE id = ?', [$name, $parent, $id]);
        $this->ctx->audit('genre.update', 'genre', $id, ['name' => $before['name'], 'parentId' => $before['parentId']], ['name' => $name, 'parentId' => $parent]);
        return Response::json($this->genreOut($id));
    }

    public function deleteGenre(array $params): Response
    {
        $id = (int) $params['id'];
        $before = $this->genreOut($id);
        $into = $this->ctx->request->queryInt('mergeInto', null, 1);
        if ($into !== null && ($into === $id || $this->ctx->db->value('SELECT id FROM genres WHERE id = ?', [$into]) === null)) {
            throw ApiError::invalid('mergeInto', 'Genre de destination inconnu');
        }
        $db = $this->ctx->db;
        $db->transaction(function () use ($db, $id, $into): void {
            $albums = array_map('intval', array_column($db->all(
                'SELECT album_id FROM album_genres WHERE genre_id = ? UNION SELECT t.album_id FROM track_genres tg JOIN tracks t ON t.id = tg.track_id WHERE tg.genre_id = ?', [$id, $id]
            ), 'album_id'));
            if ($into !== null) {
                $db->run('INSERT IGNORE INTO album_genres (album_id, genre_id, source) SELECT album_id, ?, source FROM album_genres WHERE genre_id = ?', [$into, $id]);
                $db->run('UPDATE genres SET parent_id = ? WHERE parent_id = ?', [$into, $id]);
            }
            $db->run('UPDATE genre_aliases SET genre_id = ? WHERE genre_id = ?', [$into, $id]);
            // Genres propres des pistes (corrections actives) : l'identifiant est remplacé ou retiré
            foreach ($db->all("SELECT id, value FROM corrections WHERE entity_type = 'track' AND field = 'genre_ids' AND status = 'active'") as $c) {
                $ids = json_decode((string) $c['value'], true);
                if (is_array($ids) && in_array($id, array_map('intval', $ids), true)) {
                    $ids = array_values(array_unique(array_filter(array_map(static fn ($g) => (int) $g === $id ? $into : (int) $g, $ids))));
                    $db->run('UPDATE corrections SET value = ? WHERE id = ?', [json_encode($ids), $c['id']]);
                }
            }
            $db->run('DELETE FROM genres WHERE id = ?', [$id]);
            $this->refreshAlbums($albums);
        });
        $this->ctx->audit('genre.delete', 'genre', $id, ['name' => $before['name']], ['mergeInto' => $into]);
        return Response::noContent();
    }

    // ---------------------------------------------------------------- albums et pistes

    public function updateAlbum(array $params): Response
    {
        $id = (int) $params['id'];
        if ($this->ctx->db->value('SELECT id FROM albums WHERE id = ? AND missing_since IS NULL', [$id]) === null) {
            throw ApiError::notFound('Album introuvable');
        }
        $in = new Input($this->ctx->request->json());
        $changes = $this->patchChanges('album', $id, $in, ['collectionIds']);
        $collections = $in->has('collectionIds') ? array_values(array_unique((array) $in->intList('collectionIds', 200))) : null;
        if ($changes === [] && $collections === null) {
            throw ApiError::badRequest('Rien à modifier');
        }
        if ($collections !== null && $collections !== []
            && (int) $this->ctx->db->value('SELECT COUNT(*) FROM collections WHERE id IN (' . implode(',', array_fill(0, count($collections), '?')) . ')', $collections) !== count($collections)) {
            throw ApiError::invalid('collectionIds', 'Collection inconnue dans la liste');
        }

        $uid = $this->ctx->user()->userId;
        $created = $this->corrections()->apply('album', $id, $changes, $uid);
        if ($collections !== null) {
            $this->ctx->db->transaction(function () use ($id, $collections): void {
                $this->ctx->db->run('DELETE FROM collection_albums WHERE album_id = ?', [$id]);
                foreach ($collections as $c) {
                    $this->ctx->db->run('INSERT INTO collection_albums (collection_id, album_id) VALUES (?, ?)', [$c, $id]);
                }
            });
        }
        $this->ctx->audit('album.update', 'album', $id, null, ['fields' => array_keys($changes), 'corrections' => $created, 'collectionIds' => $collections]);
        return (new CatalogController($this->ctx))->getAlbum(['id' => $id]);
    }

    public function updateTrack(array $params): Response
    {
        $id = (int) $params['id'];
        if ($this->ctx->db->value('SELECT id FROM tracks WHERE id = ?', [$id]) === null) {
            throw ApiError::notFound('Piste introuvable');
        }
        $in = new Input($this->ctx->request->json());
        $changes = $this->patchChanges('track', $id, $in);
        if ($changes === []) {
            throw ApiError::badRequest('Rien à modifier');
        }
        $created = $this->corrections()->apply('track', $id, $changes, $this->ctx->user()->userId);
        $this->ctx->audit('track.update', 'track', $id, null, ['fields' => array_keys($changes), 'corrections' => $created]);
        return Response::json((new Serializer($this->ctx->db, $this->ctx->userId()))->tracks([$id])[0]);
    }

    /**
     * Séparer : un nouvel album (sans dossier) reçoit les pistes indiquées. Les déplacements, et
     * l'artiste et l'année choisis, forment un lot appliqué : l'annuler défait la séparation.
     */
    public function createAlbum(array $params): Response
    {
        $db = $this->ctx->db;
        $in = new Input($this->ctx->request->json());
        $title = (string) $in->text('title', 1, 255);
        $artist = $in->has('albumArtist') && $in->raw()['albumArtist'] !== null ? $in->text('albumArtist', 1, 255) : null;
        $year = $in->int('year', 1000, 2100, false, true);
        $trackIds = array_values(array_unique((array) $in->intList('trackIds', 2000)));
        if ($trackIds === []) {
            throw ApiError::invalid('trackIds', 'Au moins une piste');
        }
        $live = (int) $db->value('SELECT COUNT(*) FROM tracks WHERE missing_since IS NULL AND id IN (' . implode(',', array_fill(0, count($trackIds), '?')) . ')', $trackIds);
        if ($live !== count($trackIds)) {
            throw ApiError::invalid('trackIds', 'Piste inconnue ou disparue dans la liste');
        }
        $uid = $this->ctx->user()->userId;
        $corrections = $this->corrections();
        [$albumId, $setId] = $db->transaction(function () use ($db, $corrections, $title, $artist, $year, $trackIds, $uid): array {
            // Jaquette reprise de l'album d'origine de la première piste
            $cover = $db->value('SELECT a.cover_image_id FROM tracks t JOIN albums a ON a.id = t.album_id WHERE t.id = ?', [$trackIds[0]]);
            $db->run("INSERT INTO albums (title, kind, source_dir, cover_image_id) VALUES (?, 'album', NULL, ?)", [$title, $cover]);
            $albumId = (int) $db->pdo()->lastInsertId();
            $setId = $this->changeSets($corrections)->create("Séparation : nouvel album « $title »", null, 'applied', $uid);
            $items = [];
            foreach (['album_artist' => $artist, 'year' => $year] as $field => $value) {
                if ($value !== null) {
                    $items[] = ['type' => 'album', 'id' => $albumId, 'field' => $field, 'value' => $value];
                }
            }
            foreach ($trackIds as $t) {
                $items[] = ['type' => 'track', 'id' => $t, 'field' => 'album_id', 'value' => $albumId];
            }
            $corrections->applyMany($items, $uid, $setId);
            return [$albumId, $setId];
        });
        $this->ctx->audit('album.create', 'album', $albumId, null, ['title' => $title, 'trackIds' => $trackIds, 'changeSetId' => $setId]);
        $r = (new CatalogController($this->ctx))->getAlbum(['id' => $albumId]);
        return Response::json(json_decode((string) $r->body, true), 201);
    }

    /** Fusionner un album dans un autre : ses pistes y sont déplacées, en un lot annulable. */
    public function mergeAlbum(array $params): Response
    {
        $db = $this->ctx->db;
        $id = (int) $params['id'];
        $from = $db->one('SELECT id, title FROM albums WHERE id = ? AND missing_since IS NULL', [$id]) ?? throw ApiError::notFound('Album introuvable');
        $in = new Input($this->ctx->request->json());
        $into = (int) $in->int('intoAlbumId', 1);
        $target = $into === $id ? null : $db->one('SELECT id, title FROM albums WHERE id = ? AND missing_since IS NULL', [$into]);
        if ($target === null) {
            throw ApiError::invalid('intoAlbumId', 'Album de destination inconnu (ou identique)');
        }
        $tracks = array_map('intval', array_column($db->all('SELECT id FROM tracks WHERE album_id = ? AND missing_since IS NULL ORDER BY id', [$id]), 'id'));
        $uid = $this->ctx->user()->userId;
        $corrections = $this->corrections();
        $sets = $this->changeSets($corrections);
        $setId = $db->transaction(function () use ($sets, $corrections, $from, $target, $tracks, $uid): int {
            $setId = $sets->create("Fusion de l’album « {$from['title']} » dans « {$target['title']} »", null, 'applied', $uid);
            $corrections->applyMany(array_map(static fn ($t) => ['type' => 'track', 'id' => $t, 'field' => 'album_id', 'value' => (int) $target['id']], $tracks), $uid, $setId);
            return $setId;
        });
        $this->ctx->audit('album.merge', 'album', $id, ['title' => $from['title']], ['intoAlbumId' => $into, 'trackIds' => $tracks, 'changeSetId' => $setId]);
        return Response::json($sets->out($setId));
    }

    public function updateArtist(array $params): Response
    {
        $id = (int) $params['id'];
        $before = $this->ctx->db->one('SELECT name, sort_name FROM artists WHERE id = ?', [$id]) ?? throw ApiError::notFound('Artiste introuvable');
        $in = new Input($this->ctx->request->json());
        foreach (array_keys($in->raw()) as $f) {
            if (!in_array($f, ['name', 'sortName'], true)) {
                throw ApiError::invalid((string) $f, "Champ « $f » inconnu (pour fusionner : POST /admin/artists/{id}/merge)");
            }
        }
        $changes = $this->patchChanges('artist', $id, $in);
        if ($changes === []) {
            throw ApiError::badRequest('Rien à modifier');
        }
        if (isset($changes['name']) && $this->ctx->db->value('SELECT id FROM artists WHERE name = ? AND id <> ?', [$changes['name'], $id]) !== null) {
            throw new ApiError(409, 'conflict', "Un autre artiste s’appelle déjà « {$changes['name']} » : fusionnez plutôt les deux");
        }
        $created = $this->corrections()->apply('artist', $id, $changes, $this->ctx->user()->userId);
        $this->ctx->audit('artist.update', 'artist', $id, ['name' => $before['name'], 'sortName' => $before['sort_name']], ['fields' => array_keys($changes), 'corrections' => $created]);
        return Response::json((new Serializer($this->ctx->db, $this->ctx->userId()))->artists([$id])[0]);
    }

    /** Fusionner un artiste dans un autre (doublon, variante d'orthographe), en un lot annulable. */
    public function mergeArtist(array $params): Response
    {
        $db = $this->ctx->db;
        $id = (int) $params['id'];
        $from = $db->one('SELECT id, name FROM artists WHERE id = ?', [$id]) ?? throw ApiError::notFound('Artiste introuvable');
        if ($this->rules()->mergedInto($id) !== null) {
            throw new ApiError(409, 'conflict', 'Cet artiste est déjà fusionné dans un autre');
        }
        $in = new Input($this->ctx->request->json());
        [, $into] = $this->rules()->value('artist', $id, 'mergedInto', $in, 'intoArtistId');
        $toName = (string) $db->value('SELECT name FROM artists WHERE id = ?', [$into]);
        $uid = $this->ctx->user()->userId;
        $corrections = $this->corrections();
        $sets = $this->changeSets($corrections);
        $setId = $db->transaction(function () use ($sets, $corrections, $from, $into, $toName, $uid): int {
            $setId = $sets->create("Fusion de l’artiste « {$from['name']} » dans « $toName »", null, 'applied', $uid);
            $corrections->apply('artist', (int) $from['id'], ['merged_into' => $into], $uid, $setId);
            return $setId;
        });
        $this->ctx->audit('artist.merge', 'artist', $id, ['name' => $from['name']], ['intoArtistId' => $into, 'changeSetId' => $setId]);
        return Response::json($sets->out($setId));
    }

    // ---------------------------------------------------------------- lots de changements (IA-02)

    public function listChangeSets(array $params): Response
    {
        $r = $this->ctx->request;
        [$limit, $offset] = $r->page();
        $status = $r->queryEnum('status', ['draft', 'pending', 'applied', 'rejected', 'reverted']);
        $where = $status === null ? '1' : 'status = ?';
        $args = $status === null ? [] : [$status];
        $total = (int) $this->ctx->db->value("SELECT COUNT(*) FROM change_sets WHERE $where", $args);
        $rows = $this->ctx->db->all("SELECT * FROM change_sets WHERE $where ORDER BY id DESC LIMIT $limit OFFSET $offset", $args);
        return Response::json(['items' => $this->changeSets($this->corrections())->outRows($rows), 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
    }

    public function getChangeSet(array $params): Response
    {
        return Response::json($this->changeSets($this->corrections())->out((int) $params['id']));
    }

    /**
     * Un changement d'un lot, vérifié : [type stocké, id, champ stocké, valeur].
     * @return array{0: string, 1: int|string, 2: string, 3: mixed}
     */
    private function checkChange(array $c, int $i): array
    {
        $p = "changes[$i].";
        $in = new Input($c, $p);
        $apiType = (string) $in->enum('entityType', array_keys(Corrections::TYPES));
        $type = Corrections::TYPES[$apiType];
        $id = $type === 'genre_alias' ? (string) $in->string('alias', 1, 100) : (int) $in->int('entityId', 1);
        if (!$this->rules()->exists($type, $id)) {
            throw ApiError::invalid($p . ($type === 'genre_alias' ? 'alias' : 'entityId'), 'Élément introuvable');
        }
        $apiField = (string) $in->string('field', 1, 64);
        if (!isset(Corrections::fields($type)[$apiField])) {
            throw ApiError::invalid($p . 'field', "Champ « $apiField » impossible à changer pour « $apiType »");
        }
        if (!$in->has('value')) {
            throw ApiError::invalid($p . 'value', "Champ « {$p}value » obligatoire");
        }
        [$field, $value] = $this->rules()->value($type, $id, $apiField, $in, 'value');
        return [$type, $id, $field, $value];
    }

    public function createChangeSet(array $params): Response
    {
        $in = new Input($this->ctx->request->json());
        $title = (string) $in->text('title', 1, 255);
        $description = $in->string('description', 0, 10000, false, true);
        $changes = $in->raw()['changes'] ?? null;
        if (!is_array($changes) || !array_is_list($changes) || $changes === [] || count($changes) > 2000) {
            throw ApiError::invalid('changes', 'Champ « changes » : liste de 1 à 2000 changements');
        }
        $checked = [];
        foreach ($changes as $i => $c) {
            if (!is_array($c) || array_is_list($c)) {
                throw ApiError::invalid("changes[$i]", 'Objet attendu');
            }
            $reason = (new Input($c, "changes[$i]."))->string('reason', 0, 1000, false, true);
            $checked[] = [...$this->checkChange($c, $i), $reason === '' ? null : $reason];
        }
        $uid = $this->ctx->user()->userId;
        $corrections = $this->corrections();
        $sets = $this->changeSets($corrections);
        $setId = $this->ctx->db->transaction(function () use ($sets, $corrections, $title, $description, $checked, $uid): int {
            $setId = $sets->create($title, $description === '' ? null : $description, 'pending', $uid);
            foreach ($checked as [$type, $id, $field, $value, $reason]) {
                $corrections->propose($type, $id, $field, $value, $uid, $setId, $reason);
            }
            return $setId;
        });
        $this->ctx->audit('change_set.create', 'change_set', $setId, null, ['title' => $title, 'changeCount' => count($checked)]);
        return Response::json($sets->out($setId), 201);
    }

    public function decideChangeSet(array $params): Response
    {
        $id = (int) $params['id'];
        $action = (string) $params['action'];
        if (!in_array($action, ['apply', 'reject', 'revert'], true)) {
            throw ApiError::notFound('Action inconnue (apply, reject ou revert)');
        }
        $db = $this->ctx->db;
        $uid = $this->ctx->user()->userId;
        $corrections = $this->corrections();
        $sets = $this->changeSets($corrections);
        $before = $db->transaction(function () use ($db, $corrections, $id, $action, $uid): string {
            $set = $db->one('SELECT status FROM change_sets WHERE id = ? FOR UPDATE', [$id]) ?? throw ApiError::notFound('Lot introuvable');
            $status = (string) $set['status'];
            $expected = $action === 'revert' ? 'applied' : 'pending';
            if ($status !== $expected) {
                throw new ApiError(409, 'conflict', "Lot « $status » : impossible de faire « $action »");
            }
            $rows = $db->all('SELECT * FROM corrections WHERE change_set_id = ? ORDER BY id', [$id]);
            if ($action === 'apply') {
                // Chaque changement est vérifié de nouveau : la bibliothèque a pu changer depuis la proposition
                $items = [];
                foreach ($rows as $i => $r) {
                    if ($r['status'] !== 'proposed') {
                        continue;
                    }
                    $type = (string) $r['entity_type'];
                    $change = ['entityType' => array_flip(Corrections::TYPES)[$type], 'field' => array_flip(Corrections::fields($type))[$r['field']] ?? $r['field'],
                        'value' => json_decode((string) $r['value'], true)];
                    $change += $type === 'genre_alias' ? ['alias' => (string) $r['entity_key']] : ['entityId' => (int) $r['entity_id']];
                    try {
                        [$t, $eid, $field, $value] = $this->checkChange($change, $i);
                    } catch (ApiError $e) {
                        throw new ApiError(409, 'conflict', 'Changement n° ' . ($i + 1) . ' plus applicable : ' . $e->getMessage() . '. Refusez le lot ou demandez-en un nouveau.');
                    }
                    $items[] = ['type' => $t, 'id' => $eid, 'field' => $field, 'value' => $value, 'proposal' => (int) $r['id']];
                }
                $corrections->applyMany($items, $uid, $id);
                $db->run("UPDATE change_sets SET status = 'applied', decided_by = ?, decided_at = CURRENT_TIMESTAMP(3) WHERE id = ?", [$uid, $id]);
            } elseif ($action === 'reject') {
                $db->run("UPDATE corrections SET status = 'rejected' WHERE change_set_id = ? AND status = 'proposed'", [$id]);
                $db->run("UPDATE change_sets SET status = 'rejected', decided_by = ?, decided_at = CURRENT_TIMESTAMP(3) WHERE id = ?", [$uid, $id]);
            } else {
                $corrections->revertMany(array_map(static fn ($r) => (int) $r['id'], array_filter($rows, static fn ($r) => $r['status'] === 'active')), $uid);
                $db->run("UPDATE change_sets SET status = 'reverted' WHERE id = ?", [$id]);
            }
            return $status;
        });
        $after = ['apply' => 'applied', 'reject' => 'rejected', 'revert' => 'reverted'][$action];
        $this->ctx->audit("change_set.$action", 'change_set', $id, ['status' => $before], ['status' => $after]);
        return Response::json($sets->out($id));
    }

    public function getAdminAlbum(array $params): Response
    {
        $id = (int) $params['id'];
        $album = json_decode((string) (new CatalogController($this->ctx))->getAlbum(['id' => $id])->body, true);
        $album['sourceDir'] = (string) $this->ctx->db->value('SELECT source_dir FROM albums WHERE id = ?', [$id]);
        $album['files'] = array_map(static fn ($f) => [
            'trackId' => (int) $f['track_id'], 'storageKey' => $f['storage_key'], 'format' => $f['format'], 'sizeBytes' => (int) $f['size_bytes'],
            'tags' => (object) (json_decode((string) $f['tags_raw'], true) ?: []),
        ], $this->ctx->db->all(
            'SELECT tf.track_id, so.storage_key, tf.format, so.size_bytes, tf.tags_raw FROM tracks t JOIN track_files tf ON tf.track_id = t.id
               JOIN storage_objects so ON so.id = tf.object_id WHERE t.album_id = ? AND so.missing_since IS NULL ORDER BY so.storage_key', [$id]
        ));
        $album['activeCorrections'] = $this->corrections()->serialize($this->ctx->db->all(
            "SELECT * FROM corrections WHERE status = 'active' AND ((entity_type = 'album' AND entity_id = ?)
                OR (entity_type = 'track' AND entity_id IN (SELECT id FROM tracks WHERE album_id = ?))) ORDER BY id", [$id, $id]
        ));
        return Response::json($album);
    }

    // ---------------------------------------------------------------- corrections

    public function listCorrections(array $params): Response
    {
        $r = $this->ctx->request;
        [$limit, $offset] = $r->page();
        $where = ['1'];
        $args = [];
        if (($t = $r->queryEnum('entityType', array_keys(Corrections::TYPES))) !== null) {
            $where[] = 'entity_type = ?';
            $args[] = Corrections::TYPES[$t];
        }
        if (($e = $r->queryInt('entityId', null, 1)) !== null) {
            $where[] = 'entity_id = ?';
            $args[] = $e;
        }
        if (($s = $r->queryEnum('status', ['proposed', 'active', 'rejected', 'reverted'])) !== null) {
            $where[] = 'status = ?';
            $args[] = $s;
        }
        $w = implode(' AND ', $where);
        $total = (int) $this->ctx->db->value("SELECT COUNT(*) FROM corrections WHERE $w", $args);
        $rows = $this->ctx->db->all("SELECT * FROM corrections WHERE $w ORDER BY id DESC LIMIT $limit OFFSET $offset", $args);
        return Response::json(['items' => $this->corrections()->serialize($rows), 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
    }

    public function revertCorrection(array $params): Response
    {
        $id = (int) $params['id'];
        $corrections = $this->corrections();
        $corrections->revert($id, $this->ctx->user()->userId);
        $row = $this->ctx->db->one('SELECT * FROM corrections WHERE id = ?', [$id]);
        $this->ctx->audit('correction.revert', 'correction', $id, ['status' => 'active'], ['status' => 'reverted']);
        return Response::json($corrections->serialize([$row])[0]);
    }

    // ---------------------------------------------------------------- photo d'artiste (CAT-04)

    public function setArtistImage(array $params): Response
    {
        $id = (int) $params['id'];
        $before = $this->ctx->db->value('SELECT image_id FROM artists WHERE id = ?', [$id]);
        if ($before === null && $this->ctx->db->value('SELECT 1 FROM artists WHERE id = ?', [$id]) === null) {
            throw ApiError::notFound('Artiste introuvable');
        }
        $bytes = $this->ctx->request->image();
        $info = @getimagesizefromstring($bytes);
        if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw ApiError::badRequest('Image illisible : JPEG, PNG ou WebP seulement');
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] > 8000 || $info[1] > 8000) {
            throw ApiError::badRequest(sprintf('Image de %d × %d px : 8000 px de côté au plus', $info[0], $info[1]));
        }
        $imageId = (new Images($this->ctx->db, $this->ctx->storage(), $this->ctx->cacheDir()))->fromUpload($bytes)
            ?? throw ApiError::badRequest('Image illisible');
        $this->ctx->db->run('UPDATE artists SET image_id = ? WHERE id = ?', [$imageId, $id]);
        $this->ctx->audit('artist.image', 'artist', $id, ['imageId' => $before === null ? null : (int) $before], ['imageId' => $imageId]);
        return Response::json((new Serializer($this->ctx->db, $this->ctx->userId()))->artists([$id])[0]);
    }

    public function deleteArtistImage(array $params): Response
    {
        $id = (int) $params['id'];
        $row = $this->ctx->db->one('SELECT image_id FROM artists WHERE id = ?', [$id]) ?? throw ApiError::notFound('Artiste introuvable');
        if ($row['image_id'] !== null) {
            $this->ctx->db->run('UPDATE artists SET image_id = NULL WHERE id = ?', [$id]);
            $this->ctx->audit('artist.image', 'artist', $id, ['imageId' => (int) $row['image_id']], ['imageId' => null]);
        }
        return new Response(204);
    }

    // ---------------------------------------------------------------- collections (CAT-08)

    public function createCollection(array $params): Response
    {
        $in = new Input($this->ctx->request->json());
        $name = (string) $in->text('name', 1, 100);
        $description = $in->has('description') ? $this->description($in) : null;
        if ($this->ctx->db->value("SELECT id FROM collections WHERE kind = 'collection' AND name = ?", [$name]) !== null) {
            throw new ApiError(409, 'conflict', 'Une collection porte déjà ce nom');
        }
        $this->ctx->db->run("INSERT INTO collections (kind, name, description) VALUES ('collection', ?, ?)", [$name, $description]);
        $id = (int) $this->ctx->db->pdo()->lastInsertId();
        $this->ctx->audit('collection.create', 'collection', $id, null, ['name' => $name]);
        return Response::json((new Collections($this->ctx->db))->summaries([$id])[0], 201);
    }

    public function updateCollection(array $params): Response
    {
        $id = (int) $params['id'];
        $row = $this->ctx->db->one('SELECT name, description FROM collections WHERE id = ?', [$id]) ?? throw ApiError::notFound('Collection introuvable');
        $in = new Input($this->ctx->request->json());
        if (!$in->has('name') && !$in->has('description')) {
            throw ApiError::badRequest('Rien à modifier');
        }
        $name = $in->has('name') ? (string) $in->text('name', 1, 100) : (string) $row['name'];
        $description = $in->has('description') ? $this->description($in) : $row['description'];
        if ($this->ctx->db->value("SELECT id FROM collections WHERE kind = 'collection' AND name = ? AND id <> ?", [$name, $id]) !== null) {
            throw new ApiError(409, 'conflict', 'Une collection porte déjà ce nom');
        }
        $this->ctx->db->run('UPDATE collections SET name = ?, description = ? WHERE id = ?', [$name, $description, $id]);
        $this->ctx->audit('collection.update', 'collection', $id, ['name' => $row['name'], 'description' => $row['description']], ['name' => $name, 'description' => $description]);
        return Response::json((new Collections($this->ctx->db))->summaries([$id])[0]);
    }

    public function deleteCollection(array $params): Response
    {
        $id = (int) $params['id'];
        $name = $this->ctx->db->value('SELECT name FROM collections WHERE id = ?', [$id]) ?? throw ApiError::notFound('Collection introuvable');
        $this->ctx->db->run('DELETE FROM collections WHERE id = ?', [$id]);
        $this->ctx->audit('collection.delete', 'collection', $id, ['name' => $name], null);
        return new Response(204);
    }

    public function setCollectionAlbums(array $params): Response
    {
        $id = (int) $params['id'];
        if ($this->ctx->db->value('SELECT id FROM collections WHERE id = ?', [$id]) === null) {
            throw ApiError::notFound('Collection introuvable');
        }
        $in = new Input($this->ctx->request->json());
        $albumIds = array_map('intval', (array) $in->intList('albumIds', Collections::MAX_ALBUMS));
        if (count(array_unique($albumIds)) !== count($albumIds)) {
            throw ApiError::invalid('albumIds', 'Un album figure deux fois');
        }
        if ($albumIds !== []) {
            $known = (int) $this->ctx->db->value('SELECT COUNT(*) FROM albums WHERE id IN (' . implode(', ', array_fill(0, count($albumIds), '?')) . ')', $albumIds);
            if ($known !== count($albumIds)) {
                throw ApiError::invalid('albumIds', 'Album inconnu dans la liste');
            }
        }
        $before = (new Collections($this->ctx->db))->albumIds($id);
        $this->ctx->db->transaction(function () use ($id, $albumIds): void {
            $this->ctx->db->run('DELETE FROM collection_albums WHERE collection_id = ?', [$id]);
            foreach ($albumIds as $pos => $albumId) {
                $this->ctx->db->run('INSERT INTO collection_albums (collection_id, album_id, position) VALUES (?, ?, ?)', [$id, $albumId, $pos]);
            }
        });
        $this->ctx->audit('collection.albums', 'collection', $id, ['albumIds' => $before], ['albumIds' => $albumIds]);
        return Response::json((new Collections($this->ctx->db))->detail($id, $this->ctx->userId()));
    }

    private function description(Input $in): ?string
    {
        $d = $in->string('description', 0, 1000, true, true);
        return $d === null || trim($d) === '' ? null : trim($d);
    }
}

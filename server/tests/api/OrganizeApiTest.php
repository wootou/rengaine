<?php
// Organisation au-delà du genre (CAT-08) : ambiances, décennies, familles de genres, collections.
// Bibliothèque de test : Lemonade (2016, Pop), Album 10 (2010, Rock), Album 2 (2002, Rock).

declare(strict_types=1);

namespace Musique\Tests;

final class OrganizeApiTest extends ApiCase
{
    private function track(string $title): int
    {
        return (int) $this->db->value('SELECT id FROM tracks WHERE title = ?', [$title]);
    }

    /** @return list<string> titres triés */
    private function titles(ApiResponse $r): array
    {
        $t = array_map(static fn ($x) => $x['title'], $r->data()['items']);
        sort($t);
        return $t;
    }

    public function tearDown(): void
    {
        $this->db->run('UPDATE track_analysis SET energy = NULL, danceability = NULL, bpm = NULL');
        $this->db->run("DELETE FROM collections");
        $this->db->run("UPDATE genres SET parent_id = NULL");
        $this->db->run("DELETE FROM genres WHERE name = 'Guitares'");
    }

    public function testAmbiancesCalculees(): void
    {
        $set = fn (string $t, float $e, float $d, float $bpm) => $this->db->run(
            'UPDATE track_analysis SET energy = ?, danceability = ?, bpm = ? WHERE track_id = ?', [$e, $d, $bpm, $this->track($t)]);
        $set('Formation', 0.8, 0.9, 120);   // énergique, pour danser
        $set('Forward', 0.2, 0.3, 70);      // calme
        $set('Dix', 0.45, 0.4, 100);        // pour se concentrer
        $t = $this->token('user');
        $r = $this->as($t, 'GET', '/api/v2/moods');
        $this->assertContract($r, 'listMoods', 200);
        $counts = array_column($r->data()['items'], 'trackCount', 'id');
        if ($counts === []) {
            $this->skip('Essentia absent : pas d’ambiances');
        }
        $this->assertSame(['calme' => 1, 'concentration' => 1, 'energique' => 1, 'danser' => 1], $counts);
        $this->assertSame(['Formation'], $this->titles($this->as($t, 'GET', '/api/v2/random/tracks?mood=danser')));
        $this->assertSame(['Forward'], $this->titles($this->as($t, 'GET', '/api/v2/random/tracks?mood=calme')));
        $this->assertError($this->as($t, 'GET', '/api/v2/random/tracks?mood=triste'), 400, 'bad_request', 'randomTracks');

        // Lecture continue limitée à une ambiance
        $r = $this->as($t, 'POST', '/api/v2/playback/next', ['count' => 10, 'overrides' => ['mood' => 'energique']]);
        $this->assertContract($r, 'nextTracks', 200);
        $this->assertSame(['Formation'], $this->titles($r));
    }

    public function testDecennies(): void
    {
        $t = $this->token('user');
        $r = $this->as($t, 'GET', '/api/v2/decades');
        $this->assertContract($r, 'listDecades', 200);
        $this->assertSame([[2000, 1, 2], [2010, 2, 3]], array_map(static fn ($d) => [$d['decade'], $d['albumCount'], $d['trackCount']], $r->data()['items']));
        $this->assertSame(['Deux', 'Deux bis'], $this->titles($this->as($t, 'GET', '/api/v2/random/tracks?yearFrom=2000&yearTo=2009')));
        $this->assertSame(['Dix', 'Formation', 'Forward'], $this->titles($this->as($t, 'GET', '/api/v2/random/tracks?yearFrom=2010&yearTo=2019')));
    }

    public function testFamillesDeGenres(): void
    {
        $admin = $this->token('admin');
        $rock = (int) $this->db->value("SELECT id FROM genres WHERE name = 'Rock'");
        $family = $this->as($admin, 'POST', '/api/v2/admin/genres', ['name' => 'Guitares'])->data()['id'];
        $this->as($admin, 'PATCH', "/api/v2/admin/genres/$rock", ['parentId' => $family]);
        $t = $this->token('user');
        // Le genre parent comprend ses sous-genres, pour les pistes comme pour les albums
        $this->assertSame(['Deux', 'Deux bis', 'Dix'], $this->titles($this->as($t, 'GET', "/api/v2/random/tracks?genre=$family")));
        $this->assertSame(['Album 10', 'Album 2'], (static function (array $a): array { sort($a); return $a; })(
            array_column($this->as($t, 'GET', "/api/v2/albums?genre=$family")->data()['items'], 'title')));
        $r = $this->as($t, 'POST', '/api/v2/playback/next', ['count' => 10, 'overrides' => ['genres' => [$family]]]);
        $this->assertSame(['Deux', 'Deux bis', 'Dix'], $this->titles($r));
    }

    public function testCollectionsDeLAdmin(): void
    {
        $admin = $this->token('admin');
        $r = $this->as($admin, 'POST', '/api/v2/admin/collections', ['name' => 'Soirée', 'description' => 'Pour recevoir']);
        $this->assertContract($r, 'adminCreateCollection', 201);
        $id = $r->data()['id'];
        $this->assertSame(['collection', 0, []], [$r->data()['kind'], $r->data()['albumCount'], $r->data()['coverUrls']]);
        $this->assertError($this->as($admin, 'POST', '/api/v2/admin/collections', ['name' => 'Soirée']), 409, 'conflict', 'adminCreateCollection');

        $albums = array_column($this->db->all("SELECT id, title FROM albums WHERE title IN ('Album 2', 'Lemonade')"), 'id', 'title');
        $r = $this->as($admin, 'PUT', "/api/v2/admin/collections/$id/albums", ['albumIds' => [(int) $albums['Lemonade'], (int) $albums['Album 2']]]);
        $this->assertContract($r, 'adminSetCollectionAlbums', 200);
        $this->assertSame(['Lemonade', 'Album 2'], array_column($r->data()['albums'], 'title'), 'dans l’ordre donné');
        $this->assertTrue(count($r->data()['coverUrls']) >= 1, 'mosaïque');
        $this->assertError($this->as($admin, 'PUT', "/api/v2/admin/collections/$id/albums", ['albumIds' => [999999]]), 400, 'bad_request', 'adminSetCollectionAlbums');

        $t = $this->token('user');
        $r = $this->as($t, 'GET', "/api/v2/collections/$id");
        $this->assertContract($r, 'getCollection', 200);
        $this->assertSame(['Soirée', 'Pour recevoir', 2], [$r->data()['name'], $r->data()['description'], $r->data()['albumCount']]);
        $this->assertContract($this->as($t, 'GET', '/api/v2/collections?kind=collection'), 'listCollections', 200);
        $this->assertSame(['Deux', 'Deux bis', 'Formation', 'Forward'], $this->titles($this->as($t, 'GET', "/api/v2/random/tracks?collection=$id")));
        $this->assertSame(2, count($this->as($t, 'GET', "/api/v2/albums?collection=$id")->data()['items']));
        $this->assertError($this->as($t, 'POST', '/api/v2/admin/collections', ['name' => 'X']), 403, 'forbidden', 'adminCreateCollection');

        $r = $this->as($admin, 'PATCH', "/api/v2/admin/collections/$id", ['name' => 'Fête', 'description' => '']);
        $this->assertContract($r, 'adminUpdateCollection', 200);
        $this->assertSame(['Fête', null], [$r->data()['name'], $r->data()['description']]);
        $this->assertContract($this->as($admin, 'DELETE', "/api/v2/admin/collections/$id"), 'adminDeleteCollection', 204);
        $this->assertError($this->as($t, 'GET', "/api/v2/collections/$id"), 404, 'not_found', 'getCollection');
        $this->assertSame(3, (int) $this->db->value('SELECT COUNT(*) FROM albums'), 'les albums restent');
    }
}

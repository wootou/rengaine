<?php
// Playlists (FUT-01) : privées par défaut, partagées en lecture seule, ordre et doublons.

declare(strict_types=1);

namespace Musique\Tests;

final class PlaylistApiTest extends ApiCase
{
    private function track(string $title): int
    {
        return (int) $this->db->value('SELECT id FROM tracks WHERE title = ?', [$title]);
    }

    /** @return list<string> titres, dans l'ordre */
    private static function titles(ApiResponse $r): array
    {
        return array_map(static fn ($i) => $i['track']['title'], $r->data()['items']);
    }

    public function tearDown(): void
    {
        $this->db->run('DELETE FROM playlists');
    }

    public function testCreerAjouterReordonnerRetirer(): void
    {
        $t = $this->token('user');
        $r = $this->as($t, 'POST', '/api/v2/playlists', ['name' => '  Route  ', 'trackIds' => [$this->track('Formation'), $this->track('Deux')]]);
        $this->assertContract($r, 'createPlaylist', 201);
        $p = $r->data();
        $this->assertSame(['Route', false, true, 2], [$p['name'], $p['shared'], $p['mine'], $p['trackCount']]);
        $this->assertSame(['Formation', 'Deux'], self::titles($r));
        $this->assertTrue(count($p['coverUrls']) >= 1, 'mosaïque de pochettes');
        $id = $p['id'];

        // Ajout à une position, et la même piste deux fois
        $r = $this->as($t, 'POST', "/api/v2/playlists/$id/items", ['trackIds' => [$this->track('Dix'), $this->track('Formation')], 'position' => 1]);
        $this->assertContract($r, 'addPlaylistItems', 200);
        $this->assertSame(['Formation', 'Dix', 'Formation', 'Deux'], self::titles($r));
        $this->assertSame([0, 1, 2, 3], array_column($r->data()['items'], 'position'));
        $items = array_column($r->data()['items'], 'id');
        $r = $this->as($t, 'POST', "/api/v2/playlists/$id/items", ['trackIds' => [$this->track('Deux bis')]]);
        $this->assertSame('Deux bis', self::titles($r)[4], 'sans position : à la fin');
        $items[] = $r->data()['items'][4]['id'];

        $r = $this->as($t, 'PUT', "/api/v2/playlists/$id/items", ['itemIds' => array_reverse($items)]);
        $this->assertContract($r, 'reorderPlaylistItems', 200);
        $this->assertSame(['Deux bis', 'Deux', 'Formation', 'Dix', 'Formation'], self::titles($r));
        $this->assertError($this->as($t, 'PUT', "/api/v2/playlists/$id/items", ['itemIds' => array_slice($items, 1)]), 400, 'bad_request', 'reorderPlaylistItems');

        $r = $this->as($t, 'DELETE', "/api/v2/playlists/$id/items/{$items[2]}");
        $this->assertContract($r, 'removePlaylistItem', 200);
        $this->assertSame(['Deux bis', 'Deux', 'Dix', 'Formation'], self::titles($r), 'une seule des deux « Formation » retirée');
        $this->assertSame([0, 1, 2, 3], array_column($r->data()['items'], 'position'));
        $this->assertError($this->as($t, 'DELETE', "/api/v2/playlists/$id/items/{$items[2]}"), 404, 'not_found', 'removePlaylistItem');

        $r = $this->as($t, 'PATCH', "/api/v2/playlists/$id", ['name' => 'Voiture', 'description' => 'Pour la route']);
        $this->assertContract($r, 'updatePlaylist', 200);
        $this->assertSame(['Voiture', 'Pour la route'], [$r->data()['name'], $r->data()['description']]);
        $this->assertError($this->as($t, 'PATCH', "/api/v2/playlists/$id", ['name' => '  ']), 400, 'bad_request', 'updatePlaylist');
        $this->assertError($this->as($t, 'POST', "/api/v2/playlists/$id/items", ['trackIds' => [999999]]), 400, 'bad_request', 'addPlaylistItems');
        $this->assertError($this->as($t, 'POST', '/api/v2/playlists', ['name' => '']), 400, 'bad_request', 'createPlaylist');

        $this->assertContract($this->as($t, 'DELETE', "/api/v2/playlists/$id"), 'deletePlaylist', 204);
        $this->assertError($this->as($t, 'GET', "/api/v2/playlists/$id"), 404, 'not_found', 'getPlaylist');
        $this->assertSame(5, (int) $this->db->value('SELECT COUNT(*) FROM tracks WHERE title IN (?, ?, ?, ?, ?)', ['Formation', 'Forward', 'Dix', 'Deux', 'Deux bis']), 'les pistes restent');
    }

    public function testPriveeOuPartageeEnLectureSeule(): void
    {
        $mine = $this->token('user');
        $other = $this->token('user2');
        $id = $this->as($mine, 'POST', '/api/v2/playlists', ['name' => 'Secrète', 'trackIds' => [$this->track('Dix')]])->data()['id'];

        // Privée : invisible pour les autres, comme si elle n'existait pas
        $this->assertSame([], $this->as($other, 'GET', '/api/v2/playlists')->data()['items']);
        $this->assertError($this->as($other, 'GET', "/api/v2/playlists/$id"), 404, 'not_found', 'getPlaylist');
        $this->assertError($this->as($other, 'DELETE', "/api/v2/playlists/$id"), 404, 'not_found', 'deletePlaylist');

        // Partagée : visible et lisible, pas modifiable
        $this->as($mine, 'PATCH', "/api/v2/playlists/$id", ['shared' => true]);
        $r = $this->as($other, 'GET', '/api/v2/playlists');
        $this->assertContract($r, 'listPlaylists', 200);
        $this->assertSame([[$id, false, 'Utilisateur']], array_map(static fn ($p) => [$p['id'], $p['mine'], $p['owner']['displayName']], $r->data()['items']));
        $this->assertSame([], $this->as($other, 'GET', '/api/v2/playlists?scope=mine')->data()['items']);
        $this->assertSame(['Dix'], self::titles($this->as($other, 'GET', "/api/v2/playlists/$id")));
        $this->assertError($this->as($other, 'POST', "/api/v2/playlists/$id/items", ['trackIds' => [$this->track('Deux')]]), 403, 'forbidden', 'addPlaylistItems');
        $this->assertError($this->as($other, 'PATCH', "/api/v2/playlists/$id", ['shared' => false]), 403, 'forbidden', 'updatePlaylist');
        $this->assertError($this->as($other, 'DELETE', "/api/v2/playlists/$id"), 403, 'forbidden', 'deletePlaylist');

        // Copie : une nouvelle playlist, privée, à soi
        $copy = $this->as($other, 'POST', '/api/v2/playlists', ['name' => 'Secrète (copie)', 'trackIds' => [$this->track('Dix')]])->data();
        $this->assertSame([true, false], [$copy['mine'], $copy['shared']]);
        $this->assertSame(['Secrète (copie)', 'Secrète'], array_column($this->as($other, 'GET', '/api/v2/playlists')->data()['items'], 'name'), 'les miennes d’abord');
        $this->assertSame(['Secrète'], array_column($this->as($mine, 'GET', '/api/v2/playlists')->data()['items'], 'name'));
    }
}

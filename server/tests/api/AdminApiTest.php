<?php
// Admin par HTTP : comptes, réglages du serveur, genres et alias, corrections (et leur survie à un
// scan), tâches, journaux. Chaque test remet la bibliothèque de test dans son état de départ.

declare(strict_types=1);

namespace Musique\Tests;

use Musique\Jobs\Runner;
use Musique\Library\AlbumBuilder;
use Musique\Library\Genres;
use Musique\Library\Images;
use Musique\Library\Indexer;
use Musique\Library\Probe;
use Musique\Log\Logger;
use Musique\Storage\DiskStorage;

require_once __DIR__ . '/ApiCase.php';

final class AdminApiTest extends ApiCase
{
    private function admin(string $method, string $path, mixed $body = null, array $headers = []): ApiResponse
    {
        return $this->as($this->token('admin'), $method, $path, $body, $headers);
    }

    private function albumId(string $title): int
    {
        return (int) $this->db->value('SELECT id FROM albums WHERE title = ?', [$title]);
    }

    private function trackId(string $title): int
    {
        return (int) $this->db->value('SELECT id FROM tracks WHERE title = ?', [$title]);
    }

    // ---------------------------------------------------------------- comptes

    public function testApprobationDUneDemande(): void
    {
        $this->db->run("INSERT INTO users (email, display_name, password_hash, status, request_message, request_ip, request_network)
                        VALUES ('demande@test.fr', 'Demande', ?, 'pending', 'Bonjour Rémy', '198.51.100.7', '198.51.100.7')", [\Musique\Auth\Passwords::hash(self::PASSWORD)]);
        $id = (int) $this->db->pdo()->lastInsertId();

        $r = $this->admin('GET', '/api/v2/admin/users?status=pending');
        $this->assertContract($r, 'adminListUsers', 200);
        $this->assertTrue(in_array('Bonjour Rémy', array_column($r->data()['items'], 'requestMessage'), true), 'message de la demande');

        $r = $this->admin('PATCH', "/api/v2/admin/users/$id", ['status' => 'active']);
        $this->assertContract($r, 'adminUpdateUser', 200);
        $this->assertTrue($r->data()['decidedAt'] !== null, 'date de décision');
        $row = $this->db->one('SELECT request_ip, request_network, decided_by FROM users WHERE id = ?', [$id]);
        $this->assertSame([null, null, $this->userId('admin')], [$row['request_ip'], $row['request_network'], (int) $row['decided_by']], 'IP effacée après la décision');
        $login = $this->call('POST', '/api/v2/auth/login', ['email' => 'demande@test.fr', 'password' => self::PASSWORD, 'client' => 'web']);
        $this->assertSame(200, $login->status, 'connexion possible une fois approuvé');

        $this->assertError($this->admin('PATCH', "/api/v2/admin/users/$id", ['status' => 'pending']), 409, 'conflict', 'adminUpdateUser');
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_log WHERE action = 'user.approve' AND target_id = ?", [$id]));
    }

    public function testSuspensionRevoqueLesJetonsEtGardeFous(): void
    {
        $r = $this->admin('POST', '/api/v2/admin/users', ['email' => 'cible@test.fr', 'displayName' => 'Cible', 'role' => 'user', 'password' => self::PASSWORD]);
        $this->assertContract($r, 'adminCreateUser', 201);
        $id = $r->data()['id'];
        $this->assertError($this->admin('POST', '/api/v2/admin/users', ['email' => 'CIBLE@test.fr', 'displayName' => 'X', 'role' => 'user', 'password' => self::PASSWORD]), 409, 'conflict', 'adminCreateUser');
        $token = $this->call('POST', '/api/v2/auth/login', ['email' => 'cible@test.fr', 'password' => self::PASSWORD, 'client' => 'app', 'deviceName' => 'Tel'])->data()['token'];

        $detail = $this->admin('GET', "/api/v2/admin/users/$id");
        $this->assertContract($detail, 'adminGetUser', 200);
        $this->assertSame(['Tel'], array_column($detail->data()['devices'], 'name'));

        $this->assertContract($this->admin('PATCH', "/api/v2/admin/users/$id", ['status' => 'suspended']), 'adminUpdateUser', 200);
        $this->assertSame(401, $this->as($token, 'GET', '/api/v2/me')->status, 'jetons révoqués');
        $this->assertContract($this->admin('PATCH', "/api/v2/admin/users/$id", ['status' => 'active', 'role' => 'admin', 'displayName' => 'Cible 2']), 'adminUpdateUser', 200);

        $me = $this->userId('admin');
        $this->assertError($this->admin('PATCH', "/api/v2/admin/users/$me", ['status' => 'suspended']), 409, 'conflict', 'adminUpdateUser');
        $this->assertError($this->admin('PATCH', "/api/v2/admin/users/$me", ['role' => 'user']), 409, 'conflict', 'adminUpdateUser');
        $this->assertError($this->admin('GET', '/api/v2/admin/users/999999'), 404, 'not_found', 'adminGetUser');
    }

    public function testJetonDAgentCreeEtRevoqueParLAdmin(): void
    {
        $agent = $this->userId('agent');
        $r = $this->admin('POST', "/api/v2/admin/users/$agent/tokens", ['name' => 'Claude (second)']);
        $this->assertContract($r, 'adminCreateToken', 201);
        $token = $r->data()['token'];
        $this->assertSame('agent', $this->db->value('SELECT kind FROM access_tokens WHERE id = ?', [$r->data()['tokenId']]));
        $this->assertSame(200, $this->as($token, 'GET', '/api/v2/albums')->status);
        $this->assertContract($this->admin('DELETE', '/api/v2/admin/tokens/' . $r->data()['tokenId']), 'adminRevokeToken', 204);
        $this->assertSame(401, $this->as($token, 'GET', '/api/v2/albums')->status);
        $this->assertError($this->admin('DELETE', '/api/v2/admin/tokens/' . $r->data()['tokenId']), 404, 'not_found', 'adminRevokeToken');
        $r = $this->admin('POST', "/api/v2/admin/users/$agent/tokens", ['name' => 'x', 'expiresAt' => '2001-01-01T00:00:00Z']);
        $this->assertSame('expiresAt', $r->data()['error']['details']['field'] ?? null);
    }

    public function testReglagesDuServeur(): void
    {
        $r = $this->admin('GET', '/api/v2/admin/server-settings');
        $this->assertContract($r, 'adminGetServerSettings', 200);
        $this->assertSame(['Rengaine', true, 2, true], array_values(array_slice($r->data(), 0, 4)));
        $this->assertTrue(in_array($r->data()['realtimeService'], ['running', 'stopped', 'absent'], true));
        $r = $this->admin('PATCH', '/api/v2/admin/server-settings', ['registrationOpen' => false, 'serverName' => 'Chez Rémy']);
        $this->assertContract($r, 'adminUpdateServerSettings', 200);
        $this->assertSame('Chez Rémy', $this->call('GET', '/api/v2/server')->data()['name']);
        $r = $this->call('POST', '/api/v2/auth/register', ['email' => 'ferme@test.fr', 'displayName' => 'X', 'password' => 'un-long-mot-de-passe']);
        $this->assertError($r, 403, 'registration_closed', 'register');
        $this->assertError($this->admin('PATCH', '/api/v2/admin/server-settings', ['pendingRequestsPerNetwork' => 50]), 400, 'bad_request', 'adminUpdateServerSettings');
        $this->admin('PATCH', '/api/v2/admin/server-settings', ['registrationOpen' => true, 'serverName' => 'Rengaine']);
        $this->assertError($this->as($this->token('user'), 'GET', '/api/v2/admin/server-settings'), 403, 'forbidden', 'adminGetServerSettings');
    }

    public function testLangueDuServeur(): void
    {
        $this->assertSame('fr', $this->call('GET', '/api/v2/server')->data()['language']);
        $r = $this->admin('PATCH', '/api/v2/admin/server-settings', ['language' => 'en']);
        // Toujours revenir au français, même si une vérification échoue : les tests suivants l'attendent
        try {
            $this->assertContract($r, 'adminUpdateServerSettings', 200);
            $this->assertSame('en', $r->data()['language']);
            $r = $this->call('GET', '/api/v2/server');
            $this->assertContract($r, 'getServer', 200);
            $this->assertSame('en', $r->data()['language']);
            $r = $this->admin('PATCH', '/api/v2/admin/server-settings', ['language' => 'de']);
            $this->assertError($r, 400, 'bad_request', 'adminUpdateServerSettings');
            // Messages et ambiances dans la langue du serveur ; le code d'erreur ne change pas
            $this->assertSame('Field “language”: allowed values fr, en', $r->data()['error']['message']);
            $this->assertSame('Album not found', $this->admin('GET', '/api/v2/albums/999999')->data()['error']['message']);
            // Les ambiances demandent l'énergie (Essentia) : sans elle, la liste est vide
            $moods = $this->admin('GET', '/api/v2/moods')->data()['items'];
            if ($moods !== []) {
                $this->assertSame('Calm', $moods[0]['name']);
            }
        } finally {
            $this->admin('PATCH', '/api/v2/admin/server-settings', ['language' => 'fr']);
        }
        $this->assertSame('fr', $this->call('GET', '/api/v2/server')->data()['language']);
    }

    // ---------------------------------------------------------------- genres

    public function testGenresEtAlias(): void
    {
        $r = $this->admin('POST', '/api/v2/admin/genres', ['name' => 'Électro']);
        $this->assertContract($r, 'adminCreateGenre', 201);
        $electro = $r->data()['id'];
        $this->assertError($this->admin('POST', '/api/v2/admin/genres', ['name' => 'electro']), 409, 'conflict', 'adminCreateGenre');
        $sub = $this->admin('POST', '/api/v2/admin/genres', ['name' => 'Électro douce', 'parentId' => $electro])->data()['id'];
        $r = $this->admin('PATCH', "/api/v2/admin/genres/$electro", ['parentId' => $sub]);
        $this->assertError($r, 400, 'bad_request', 'adminUpdateGenre');
        $r = $this->admin('PATCH', "/api/v2/admin/genres/$sub", ['name' => 'Électro calme']);
        $this->assertContract($r, 'adminUpdateGenre', 200);

        // Alias « Rock » détaché : les albums rock perdent leur genre ; rattaché à Électro : ils le prennent
        $album2 = $this->albumId('Album 2');
        $rock = (int) $this->db->value("SELECT id FROM genres WHERE name = 'Rock'");
        $r = $this->admin('POST', '/api/v2/admin/genre-aliases', ['alias' => 'Rock', 'genreId' => null]);
        $this->assertContract($r, 'adminMapGenreAlias', 200);
        $this->assertSame([], $this->admin('GET', "/api/v2/albums/$album2")->data()['genres']);
        $r = $this->admin('GET', '/api/v2/admin/genre-aliases?mapped=false');
        $this->assertContract($r, 'adminListGenreAliases', 200);
        $this->assertSame(['Rock'], array_column($r->data()['items'], 'alias'));
        $this->admin('POST', '/api/v2/admin/genre-aliases', ['alias' => 'rock', 'genreId' => $electro]);
        $this->assertSame(['Électro'], array_column($this->admin('GET', "/api/v2/albums/$album2")->data()['genres'], 'name'));
        $this->assertError($this->admin('POST', '/api/v2/admin/genre-aliases', ['alias' => 'Inconnu', 'genreId' => $rock]), 404, 'not_found', 'adminMapGenreAlias');

        // Suppression d'Électro en le fusionnant dans Rock : tout revient comme avant
        $this->assertContract($this->admin('DELETE', "/api/v2/admin/genres/$electro?mergeInto=$rock"), 'adminDeleteGenre', 204);
        $this->assertSame(['Rock'], array_column($this->admin('GET', "/api/v2/albums/$album2")->data()['genres'], 'name'));
        $this->assertSame($rock, (int) $this->db->value("SELECT genre_id FROM genre_aliases WHERE alias = 'Rock'"));
        $this->assertSame($rock, (int) $this->db->value('SELECT parent_id FROM genres WHERE id = ?', [$sub]), 'sous-genre rattaché à Rock');
        $this->admin('DELETE', "/api/v2/admin/genres/$sub");
        $this->assertSame(404, $this->admin('DELETE', "/api/v2/admin/genres/$sub")->status);
    }

    // ---------------------------------------------------------------- corrections

    public function testCorrectionDAlbumSurvitAuScanPuisAnnulee(): void
    {
        $id = $this->albumId('Lemonade');
        $pop = (int) $this->db->value("SELECT id FROM genres WHERE name = 'Pop'");
        $rock = (int) $this->db->value("SELECT id FROM genres WHERE name = 'Rock'");
        $r = $this->admin('PATCH', "/api/v2/admin/albums/$id", ['title' => 'Lemonade (édition)', 'year' => 2017, 'genreIds' => [$rock], 'coverImageHash' => null]);
        $this->assertContract($r, 'adminUpdateAlbum', 200);
        $this->assertSame(['Lemonade (édition)', 2017, ['Rock'], null], [$r->data()['title'], $r->data()['year'], array_column($r->data()['genres'], 'name'), $r->data()['coverUrl']]);
        $this->assertSame([['Rock', true]], array_map(static fn ($g) => [$g['name'], $g['inherited']], $r->data()['tracks'][0]['genres']), 'les pistes héritent');

        $this->reindex(true);
        $a = $this->admin('GET', "/api/v2/albums/$id")->data();
        $this->assertSame(['Lemonade (édition)', 2017, ['Rock'], null], [$a['title'], $a['year'], array_column($a['genres'], 'name'), $a['coverUrl']], 'la correction survit au scan');

        $r = $this->admin('GET', "/api/v2/admin/corrections?entityType=album&entityId=$id&status=active");
        $this->assertContract($r, 'adminListCorrections', 200);
        $byField = array_column($r->data()['items'], null, 'field');
        $this->assertSame('Lemonade', $byField['title']['previousValue']);
        $this->assertSame([$pop], $byField['genreIds']['previousValue']);
        $this->assertSame('Admin', $byField['title']['createdBy']['displayName']);
        $this->assertContract($this->admin('GET', "/api/v2/admin/albums/$id"), 'adminGetAlbum', 200);

        foreach ($r->data()['items'] as $c) {
            $this->assertContract($this->admin('POST', '/api/v2/admin/corrections/' . $c['id'] . '/revert'), 'adminRevertCorrection', 200);
        }
        $a = $this->admin('GET', "/api/v2/albums/$id")->data();
        $this->assertSame(['Lemonade', 2016, ['Pop']], [$a['title'], $a['year'], array_column($a['genres'], 'name')], 'valeurs des tags revenues');
        $this->assertTrue($a['coverUrl'] !== null, 'jaquette revenue');
        $this->assertError($this->admin('POST', '/api/v2/admin/corrections/' . $byField['title']['id'] . '/revert'), 409, 'conflict', 'adminRevertCorrection');
        $this->assertError($this->admin('PATCH', "/api/v2/admin/albums/$id", ['genreIds' => [999999]]), 400, 'bad_request', 'adminUpdateAlbum');
    }

    public function testPisteDeplaceeDansUnAutreAlbum(): void
    {
        $track = $this->trackId('Deux bis');
        $album2 = $this->albumId('Album 2');
        $album10 = $this->albumId('Album 10');
        $r = $this->admin('PATCH', "/api/v2/admin/tracks/$track", ['albumId' => $album10, 'trackNumber' => 2, 'bpm' => 120, 'key' => 'Am']);
        $this->assertContract($r, 'adminUpdateTrack', 200);
        $this->assertSame([$album10, 120.0, 'Am', '8A'], [$r->data()['album']['id'], $r->data()['playback']['bpm'], $r->data()['playback']['key'], $r->data()['playback']['camelot']]);

        $this->reindex(true);
        $this->assertSame(['Dix', 'Deux bis'], array_column($this->admin('GET', "/api/v2/albums/$album10")->data()['tracks'], 'title'), 'déplacement gardé après le scan');
        $this->assertSame(1, $this->admin('GET', "/api/v2/albums/$album2")->data()['trackCount']);
        $this->assertSame(['8A'], array_column(array_column($this->admin('GET', '/api/v2/tracks?camelot=8A')->data()['items'], 'playback'), 'camelot'));

        foreach ($this->admin('GET', "/api/v2/admin/corrections?entityType=track&entityId=$track&status=active")->data()['items'] as $c) {
            $this->admin('POST', '/api/v2/admin/corrections/' . $c['id'] . '/revert');
        }
        $t = $this->admin('GET', "/api/v2/tracks/$track")->data();
        $this->assertSame([$album2, null, null], [$t['album']['id'], $t['playback']['bpm'], $t['playback']['key']]);
        $this->assertSame(2, $this->admin('GET', "/api/v2/albums/$album2")->data()['trackCount']);
        $this->assertSame(1, $this->admin('GET', "/api/v2/albums/$album10")->data()['trackCount']);

        $this->assertError($this->admin('PATCH', "/api/v2/admin/tracks/$track", ['key' => 'H']), 400, 'bad_request', 'adminUpdateTrack');
        $this->assertError($this->as($this->token('agent'), 'PATCH', "/api/v2/admin/tracks/$track", ['title' => 'x']), 403, 'forbidden', 'adminUpdateTrack');
        $this->assertContract($this->as($this->token('agent'), 'GET', '/api/v2/admin/corrections'), 'adminListCorrections', 200);
    }

    public function testGenresPropresAUnePiste(): void
    {
        $track = $this->trackId('Formation');
        $rock = (int) $this->db->value("SELECT id FROM genres WHERE name = 'Rock'");
        $r = $this->admin('PATCH', "/api/v2/admin/tracks/$track", ['genreIds' => [$rock]]);
        $this->assertSame([['Rock', false]], array_map(static fn ($g) => [$g['name'], $g['inherited']], $r->data()['genres']));
        $r = $this->admin('PATCH', "/api/v2/admin/tracks/$track", ['genreIds' => null]);
        $this->assertSame([['Pop', true]], array_map(static fn ($g) => [$g['name'], $g['inherited']], $r->data()['genres']), 'null = genres de l’album');
    }

    // ---------------------------------------------------------------- séparation, fusions, lots

    private function artistId(string $name): ?int
    {
        $id = $this->db->value('SELECT id FROM artists WHERE name = ?', [$name]);
        return $id === null ? null : (int) $id;
    }

    public function testSeparerUnAlbumPuisAnnuler(): void
    {
        $album2 = $this->albumId('Album 2');
        $bis = $this->trackId('Deux bis');
        $r = $this->admin('POST', '/api/v2/admin/albums', ['title' => 'Album 2 (bonus)', 'albumArtist' => 'Groupe', 'year' => 2003, 'trackIds' => [$bis]]);
        $this->assertContract($r, 'adminCreateAlbum', 201);
        $new = $r->data()['id'];
        $this->assertSame(['Album 2 (bonus)', 'Groupe', 2003, ['Deux bis']], [$r->data()['title'], $r->data()['artistName'], $r->data()['year'], array_column($r->data()['tracks'], 'title')]);
        $this->assertTrue($r->data()['coverUrl'] === $this->admin('GET', "/api/v2/albums/$album2")->data()['coverUrl'], 'jaquette reprise');
        $this->assertSame(1, $this->admin('GET', "/api/v2/albums/$album2")->data()['trackCount']);

        $this->reindex(true);
        $this->assertSame(['Deux bis'], array_column($this->admin('GET', "/api/v2/albums/$new")->data()['tracks'], 'title'), 'séparation gardée après le scan');

        $sets = $this->admin('GET', '/api/v2/admin/change-sets?status=applied');
        $this->assertContract($sets, 'adminListChangeSets', 200);
        $set = $sets->data()['items'][0];
        $this->assertSame(['Séparation : nouvel album « Album 2 (bonus) »', 3], [$set['title'], $set['changeCount']]);
        $r = $this->admin('POST', '/api/v2/admin/change-sets/' . $set['id'] . '/revert');
        $this->assertContract($r, 'adminDecideChangeSet', 200);
        $this->assertSame(['reverted', ['reverted', 'reverted', 'reverted']], [$r->data()['status'], array_column($r->data()['changes'], 'status')]);
        $this->assertSame(2, $this->admin('GET', "/api/v2/albums/$album2")->data()['trackCount']);
        $this->assertSame(404, $this->admin('GET', "/api/v2/albums/$new")->status, 'album vidé masqué');

        $this->assertError($this->admin('POST', '/api/v2/admin/albums', ['title' => 'x', 'trackIds' => []]), 400, 'bad_request', 'adminCreateAlbum');
        $this->assertError($this->admin('POST', '/api/v2/admin/albums', ['title' => 'x', 'trackIds' => [999999]]), 400, 'bad_request', 'adminCreateAlbum');
        $this->assertError($this->as($this->token('agent'), 'POST', '/api/v2/admin/albums', ['title' => 'x', 'trackIds' => [$bis]]), 403, 'forbidden', 'adminCreateAlbum');
    }

    public function testFusionnerDeuxAlbumsPuisAnnuler(): void
    {
        $album2 = $this->albumId('Album 2');
        $album10 = $this->albumId('Album 10');
        $r = $this->admin('POST', "/api/v2/admin/albums/$album10/merge", ['intoAlbumId' => $album2]);
        $this->assertContract($r, 'adminMergeAlbum', 200);
        $this->assertSame(['applied', 1, 'albumId', $album2], [$r->data()['status'], $r->data()['changeCount'], $r->data()['changes'][0]['field'], $r->data()['changes'][0]['value']]);
        $setId = $r->data()['id'];
        $this->assertSame(['Deux', 'Dix', 'Deux bis'], array_column($this->admin('GET', "/api/v2/albums/$album2")->data()['tracks'], 'title'), 'triées par numéro');
        $this->assertSame(404, $this->admin('GET', "/api/v2/albums/$album10")->status);

        $this->reindex(true);
        $this->assertSame(3, $this->admin('GET', "/api/v2/albums/$album2")->data()['trackCount'], 'fusion gardée après le scan');
        $this->assertSame(404, $this->admin('GET', "/api/v2/albums/$album10")->status);
        $this->assertError($this->admin('POST', "/api/v2/admin/albums/$album10/merge", ['intoAlbumId' => $album2]), 404, 'not_found', 'adminMergeAlbum');

        $this->assertContract($this->admin('POST', "/api/v2/admin/change-sets/$setId/revert"), 'adminDecideChangeSet', 200);
        $this->assertSame(['Dix'], array_column($this->admin('GET', "/api/v2/albums/$album10")->data()['tracks'], 'title'), 'album réapparu');
        $this->assertSame(2, $this->admin('GET', "/api/v2/albums/$album2")->data()['trackCount']);
        $this->assertError($this->admin('POST', "/api/v2/admin/change-sets/$setId/revert"), 409, 'conflict', 'adminDecideChangeSet');
        $this->assertError($this->admin('POST', "/api/v2/admin/albums/$album2/merge", ['intoAlbumId' => $album2]), 400, 'bad_request', 'adminMergeAlbum');
    }

    public function testArtisteRenommePuisFusionne(): void
    {
        $groupe = (int) $this->artistId('Groupe');
        $album2 = $this->albumId('Album 2');
        $r = $this->admin('PATCH', "/api/v2/admin/artists/$groupe", ['name' => 'Le Groupe', 'sortName' => 'Groupe, Le']);
        $this->assertContract($r, 'adminUpdateArtist', 200);
        $this->assertSame(['Le Groupe', 'Groupe, Le'], [$r->data()['name'], $r->data()['sortName']]);
        $this->reindex(true);
        $this->assertNull($this->artistId('Groupe'), 'les tags « Groupe » mènent à l’artiste renommé');
        $this->assertSame([$groupe, 'Le Groupe'], [$this->admin('GET', "/api/v2/albums/$album2")->data()['artist']['id'], $this->admin('GET', "/api/v2/albums/$album2")->data()['artistName']]);
        $this->assertError($this->admin('PATCH', "/api/v2/admin/artists/$groupe", ['name' => 'Beyonce']), 409, 'conflict', 'adminUpdateArtist');
        $this->assertError($this->admin('PATCH', "/api/v2/admin/artists/$groupe", ['mergedInto' => 1]), 400, 'bad_request', 'adminUpdateArtist');

        // Fusion dans Beyoncé : albums, pistes et tags passent à elle
        $bey = (int) $this->artistId('Beyoncé');
        $r = $this->admin('POST', "/api/v2/admin/artists/$groupe/merge", ['intoArtistId' => $bey]);
        $this->assertContract($r, 'adminMergeArtist', 200);
        $setId = $r->data()['id'];
        $this->assertSame([$bey, 'mergedInto'], [$this->admin('GET', "/api/v2/albums/$album2")->data()['artist']['id'], $r->data()['changes'][0]['field']]);
        $this->assertTrue(!in_array('Le Groupe', array_column($this->admin('GET', '/api/v2/artists?limit=100')->data()['items'], 'name'), true), 'artiste absorbé caché');
        $this->reindex(true);
        $this->assertSame($bey, $this->admin('GET', "/api/v2/albums/$album2")->data()['artist']['id'], 'fusion gardée après le scan');
        $this->assertNull($this->artistId('Groupe'));
        $this->assertError($this->admin('POST', "/api/v2/admin/artists/$groupe/merge", ['intoArtistId' => $bey]), 409, 'conflict', 'adminMergeArtist');
        $this->assertError($this->admin('POST', "/api/v2/admin/artists/$bey/merge", ['intoArtistId' => $groupe]), 400, 'bad_request', 'adminMergeArtist');

        // Annulations : la fusion, puis le renommage
        $this->assertContract($this->admin('POST', "/api/v2/admin/change-sets/$setId/revert"), 'adminDecideChangeSet', 200);
        $this->assertSame($groupe, $this->admin('GET', "/api/v2/albums/$album2")->data()['artist']['id']);
        $this->assertSame([$groupe], array_unique(array_column(array_merge(...array_column($this->admin('GET', "/api/v2/albums/$album2")->data()['tracks'], 'artists')), 'id')));
        foreach ($this->admin('GET', "/api/v2/admin/corrections?entityType=artist&entityId=$groupe&status=active")->data()['items'] as $c) {
            $this->admin('POST', '/api/v2/admin/corrections/' . $c['id'] . '/revert');
        }
        $this->assertSame(['Groupe', null], [$this->db->value('SELECT name FROM artists WHERE id = ?', [$groupe]), $this->db->value('SELECT sort_name FROM artists WHERE id = ?', [$groupe])]);
        $this->assertError($this->as($this->token('agent'), 'POST', "/api/v2/admin/artists/$groupe/merge", ['intoArtistId' => $bey]), 403, 'forbidden', 'adminMergeArtist');
    }

    public function testLotProposeParLAgent(): void
    {
        $agent = $this->token('agent');
        $lemonade = $this->albumId('Lemonade');
        $dix = $this->trackId('Dix');
        $rock = (int) $this->db->value("SELECT id FROM genres WHERE name = 'Rock'");
        $changes = [
            ['entityType' => 'album', 'entityId' => $lemonade, 'field' => 'year', 'value' => 2017, 'reason' => 'Réédition de 2017'],
            ['entityType' => 'track', 'entityId' => $dix, 'field' => 'title', 'value' => 'Dix (version longue)'],
            ['entityType' => 'genreAlias', 'alias' => 'Pop', 'field' => 'genreId', 'value' => $rock],
        ];
        $bad = $changes;
        $bad[1]['value'] = '';
        $r = $this->as($agent, 'POST', '/api/v2/admin/change-sets', ['title' => 'Essai', 'changes' => $bad]);
        $this->assertError($r, 400, 'bad_request', 'adminCreateChangeSet');
        $this->assertSame('changes[1].value', $r->data()['error']['details']['field']);
        $r = $this->as($agent, 'POST', '/api/v2/admin/change-sets', ['title' => 'Essai', 'changes' => [['entityType' => 'track', 'entityId' => $dix, 'field' => 'albumArtist', 'value' => 'x']]]);
        $this->assertSame('changes[0].field', $r->data()['error']['details']['field']);

        $r = $this->as($agent, 'POST', '/api/v2/admin/change-sets', ['title' => 'Corrections de Lemonade', 'description' => 'Trois changements', 'changes' => $changes]);
        $this->assertContract($r, 'adminCreateChangeSet', 201);
        $set = $r->data();
        $this->assertSame(['pending', 'Claude', 3, 'Réédition de 2017', 2016], [$set['status'], $set['createdBy']['displayName'], $set['changeCount'], $set['changes'][0]['reason'], $set['changes'][0]['currentValue']]);
        $this->assertSame(2016, $this->admin('GET', "/api/v2/albums/$lemonade")->data()['year'], 'rien ne change avant validation');
        // Nom lisible de chaque élément, pour l'admin (2.27.0) ; rien pour une valeur de tag
        $this->assertSame(['Lemonade — Beyoncé', 'Dix — Groupe', null], array_map(static fn ($c) => $c['label'] ?? null, $set['changes']));
        $this->assertContract($this->as($agent, 'GET', '/api/v2/admin/change-sets?status=pending'), 'adminListChangeSets', 200);
        $this->assertContract($this->as($agent, 'GET', '/api/v2/admin/change-sets/' . $set['id']), 'adminGetChangeSet', 200);
        $this->assertError($this->as($agent, 'POST', '/api/v2/admin/change-sets/' . $set['id'] . '/apply'), 403, 'forbidden', 'adminDecideChangeSet');

        $r = $this->admin('POST', '/api/v2/admin/change-sets/' . $set['id'] . '/apply');
        $this->assertContract($r, 'adminDecideChangeSet', 200);
        $this->assertSame(['applied', 'Admin', ['active', 'active', 'active']], [$r->data()['status'], $r->data()['decidedBy']['displayName'], array_column($r->data()['changes'], 'status')]);
        $a = $this->admin('GET', "/api/v2/albums/$lemonade")->data();
        $this->assertSame([2017, ['Rock']], [$a['year'], array_column($a['genres'], 'name')], 'année et tag Pop → Rock');
        $this->assertSame('Dix (version longue)', $this->admin('GET', "/api/v2/tracks/$dix")->data()['title']);
        $c = $this->admin('GET', '/api/v2/admin/corrections?entityType=genreAlias');
        $this->assertContract($c, 'adminListCorrections', 200);
        $this->assertSame(['Pop', 'Claude'], [$c->data()['items'][0]['alias'], $c->data()['items'][0]['createdBy']['displayName']]);
        $this->assertError($this->admin('POST', '/api/v2/admin/change-sets/' . $set['id'] . '/apply'), 409, 'conflict', 'adminDecideChangeSet');

        $this->assertContract($this->admin('POST', '/api/v2/admin/change-sets/' . $set['id'] . '/revert'), 'adminDecideChangeSet', 200);
        $a = $this->admin('GET', "/api/v2/albums/$lemonade")->data();
        $this->assertSame([2016, ['Pop'], 'Dix'], [$a['year'], array_column($a['genres'], 'name'), $this->admin('GET', "/api/v2/tracks/$dix")->data()['title']]);

        // Refus, et lot devenu impossible (genre supprimé entre-temps)
        $r = $this->as($agent, 'POST', '/api/v2/admin/change-sets', ['title' => 'À refuser', 'changes' => [$changes[0]]]);
        $r = $this->admin('POST', '/api/v2/admin/change-sets/' . $r->data()['id'] . '/reject');
        $this->assertSame(['rejected', ['rejected']], [$r->data()['status'], array_column($r->data()['changes'], 'status')]);
        $tmp = $this->admin('POST', '/api/v2/admin/genres', ['name' => 'Éphémère'])->data()['id'];
        $r = $this->as($agent, 'POST', '/api/v2/admin/change-sets', ['title' => 'Périmé', 'changes' => [['entityType' => 'album', 'entityId' => $lemonade, 'field' => 'genreIds', 'value' => [$tmp]]]]);
        $this->admin('DELETE', "/api/v2/admin/genres/$tmp");
        $this->assertError($this->admin('POST', '/api/v2/admin/change-sets/' . $r->data()['id'] . '/apply'), 409, 'conflict', 'adminDecideChangeSet');
        $this->admin('POST', '/api/v2/admin/change-sets/' . $r->data()['id'] . '/reject');
        $this->assertSame([2016, ['Pop']], [$this->admin('GET', "/api/v2/albums/$lemonade")->data()['year'], array_column($this->admin('GET', "/api/v2/albums/$lemonade")->data()['genres'], 'name')]);
        $this->assertError($this->admin('POST', '/api/v2/admin/change-sets/999999/apply'), 404, 'not_found', 'adminDecideChangeSet');
    }

    // ---------------------------------------------------------------- tâches et journaux

    public function testTacheDemandeeParLAdminPuisExecutee(): void
    {
        $r = $this->admin('POST', '/api/v2/admin/jobs', ['type' => 'index', 'params' => ['prefix' => 'Groupe - Album 2/']]);
        $this->assertContract($r, 'adminCreateJob', 202);
        $id = $r->data()['id'];
        $this->assertSame('queued', $r->data()['status']);
        $this->assertError($this->admin('POST', '/api/v2/admin/jobs', ['type' => 'index']), 409, 'conflict', 'adminCreateJob');
        $this->assertError($this->admin('POST', '/api/v2/admin/jobs', ['type' => 'index', 'params' => ['prefix' => '../etc']]), 400, 'bad_request', 'adminCreateJob');

        // Ce que fait rengaine-jobs.service chaque minute
        $n = (new Runner($this->testConfig(), $this->db, new Logger(self::$env['base'] . '/journaux/test.log', 'debug')))->runQueued();
        $this->assertSame(1, $n);
        $r = $this->admin('GET', "/api/v2/admin/jobs/$id");
        $this->assertContract($r, 'adminGetJob', 200);
        $this->assertSame(['done', 'Admin'], [$r->data()['status'], $r->data()['requestedBy']['displayName']]);
        $this->assertSame(2, $r->data()['stats']['seen'], 'seulement le dossier demandé');

        $this->assertContract($this->admin('GET', '/api/v2/admin/jobs?type=index'), 'adminListJobs', 200);
        $this->assertContract($this->admin('GET', '/api/v2/admin/job-errors?open=false'), 'adminListJobErrors', 200);
        $this->assertContract($this->as($this->token('agent'), 'GET', '/api/v2/admin/job-errors'), 'adminListJobErrors', 200);

        // Marquer comme résolues : une par une, ou toutes
        foreach (['a.mp3', 'b.mp3', 'c.mp3'] as $k) {
            $this->db->run("INSERT INTO job_errors (job_id, storage_key, code, message) VALUES (?, ?, 'probe_failed', 'illisible')", [$id, "Essai/$k"]);
        }
        $ids = array_map('intval', array_column($this->db->all("SELECT id FROM job_errors WHERE storage_key LIKE 'Essai/%' ORDER BY id"), 'id'));
        $r = $this->admin('POST', '/api/v2/admin/job-errors/resolve', ['ids' => [$ids[0]]]);
        $this->assertContract($r, 'adminResolveJobErrors', 200);
        $this->assertSame(1, $r->data()['resolved']);
        $this->assertSame(0, $this->admin('POST', '/api/v2/admin/job-errors/resolve', ['ids' => [$ids[0]]])->data()['resolved'], 'déjà résolue');
        $this->assertError($this->admin('POST', '/api/v2/admin/job-errors/resolve', []), 400, 'bad_request', 'adminResolveJobErrors');
        $this->assertError($this->admin('POST', '/api/v2/admin/job-errors/resolve', ['ids' => [$ids[1]], 'all' => true]), 400, 'bad_request', 'adminResolveJobErrors');
        $this->assertError($this->as($this->token('agent'), 'POST', '/api/v2/admin/job-errors/resolve', ['all' => true]), 403, 'forbidden', 'adminResolveJobErrors');
        $this->assertTrue($this->admin('POST', '/api/v2/admin/job-errors/resolve', ['all' => true])->data()['resolved'] >= 2);
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM job_errors WHERE resolved_at IS NULL'));
        $this->assertSame('job_error.resolve', $this->db->value('SELECT action FROM audit_log ORDER BY id DESC LIMIT 1'));
    }

    public function testDepotDemandeDepuisLAdmin(): void
    {
        // Un album vient d'être déposé : trop récent pour être rangé (10 min sans changement)
        $root = self::$env['root'];
        @mkdir("$root/inbox/Dépôt", 0755, true);
        copy("$root/Groupe - Album 2/01 - Deux.flac", "$root/inbox/Dépôt/01.flac");
        try {
            $r = $this->admin('POST', '/api/v2/admin/jobs', ['type' => 'inbox']);
            $this->assertContract($r, 'adminCreateJob', 202);
            (new Runner($this->testConfig(), $this->db, new Logger(self::$env['base'] . '/journaux/test.log', 'debug')))->runQueued();
            $this->assertSame('done', $this->admin('GET', '/api/v2/admin/jobs/' . $r->data()['id'])->data()['status']);

            $r = $this->admin('GET', '/api/v2/admin/inbox');
            $this->assertContract($r, 'adminListInbox', 200);
            $this->assertSame([['inbox/Dépôt', 'waiting', 1]], array_map(static fn ($i) => [$i['sourceDir'], $i['status'], $i['fileCount']], $r->data()['items']));
            $this->assertTrue(is_file("$root/inbox/Dépôt/01.flac"), 'rien déplacé');
            $this->assertError($this->as($this->token('user'), 'GET', '/api/v2/admin/inbox'), 403, 'forbidden', 'adminListInbox');
        } finally {
            exec('rm -rf ' . escapeshellarg("$root/inbox"));
            $this->db->run('DELETE FROM inbox_items');
        }
    }

    public function testMp3EnDoubleRetiresDansLaCorbeille(): void
    {
        // Un MP3 jumeau du FLAC « Deux » (mêmes tags, même durée), indexé avec lui
        $root = self::$env['root'];
        $mp3 = "$root/Groupe - Album 2/01 - Deux.mp3";
        exec('ffmpeg -v error -y -i ' . escapeshellarg("$root/Groupe - Album 2/01 - Deux.flac") . ' -c:a libmp3lame ' . escapeshellarg($mp3));
        $logger = new Logger(self::$env['base'] . '/journaux/test.log', 'debug');
        $index = function () use ($logger): void {
            $storage = new DiskStorage(self::$env['root']);
            $genres = new Genres($this->db);
            (new Indexer($this->db, $storage, $logger, new Probe(2), new AlbumBuilder($this->db, $genres, new Images($this->db, $storage, self::$env['base'] . '/cache')), $genres))
                ->run('Groupe - Album 2/');
        };
        try {
            $index();
            $albumId = $this->albumId('Album 2');
            $r = $this->admin('GET', '/api/v2/admin/duplicates');
            $this->assertContract($r, 'adminListDuplicates', 200);
            $this->assertSame([[$albumId, 1, 0]], array_map(static fn ($a) => [$a['albumId'], $a['removable'], $a['kept']], $r->data()['items']));
            $this->assertSame(filesize($mp3), $r->data()['removableBytes']);
            $r = $this->admin('GET', "/api/v2/admin/duplicates/$albumId");
            $this->assertContract($r, 'adminGetDuplicates', 200);
            $this->assertSame([['Groupe - Album 2/01 - Deux.mp3', 'flac', true]],
                array_map(static fn ($f) => [$f['mp3']['storageKey'], $f['lossless']['format'], $f['removable']], $r->data()['files']));
            $this->assertError($this->as($this->token('user'), 'GET', '/api/v2/admin/duplicates'), 403, 'forbidden', 'adminListDuplicates');
            $this->assertError($this->admin('GET', '/api/v2/admin/duplicates/999999'), 404, 'not_found', 'adminGetDuplicates');

            // Retrait : tâche par album, une seule à la fois pour un même album
            $this->assertError($this->admin('POST', '/api/v2/admin/jobs', ['type' => 'dedup']), 400, 'bad_request', 'adminCreateJob');
            $this->assertSame(400, $this->admin('POST', '/api/v2/admin/jobs', ['type' => 'dedup', 'params' => ['albumId' => 999999]])->status);
            $r = $this->admin('POST', '/api/v2/admin/jobs', ['type' => 'dedup', 'params' => ['albumId' => $albumId]]);
            $this->assertContract($r, 'adminCreateJob', 202);
            $this->assertError($this->admin('POST', '/api/v2/admin/jobs', ['type' => 'dedup', 'params' => ['albumId' => $albumId]]), 409, 'conflict', 'adminCreateJob');
            (new Runner($this->testConfig(), $this->db, $logger))->runQueued();
            $job = $this->admin('GET', '/api/v2/admin/jobs/' . $r->data()['id'])->data();
            $this->assertSame('done', $job['status']);
            $this->assertFalse(is_file($mp3), 'retiré');
            $this->assertTrue(is_file("$root/corbeille/" . gmdate('Y-m-d') . '/Groupe - Album 2/01 - Deux.mp3'), 'dans la corbeille');
            $this->assertSame([], $this->admin('GET', '/api/v2/admin/duplicates')->data()['items']);
            $this->assertError($this->admin('POST', '/api/v2/admin/jobs', ['type' => 'dedup', 'params' => ['albumId' => $albumId]]), 409, 'conflict', 'adminCreateJob');
            $this->assertSame('audio/flac', $this->admin('GET', '/api/v2/tracks/' . $this->db->value("SELECT id FROM tracks WHERE title = 'Deux'") . '/stream')->headers['content-type'] ?? null);
        } finally {
            @unlink($mp3);
            exec('rm -rf ' . escapeshellarg("$root/corbeille"));
            $index();
        }
    }

    public function testPhotoDArtiste(): void
    {
        $jpg = self::$env['base'] . '/travail/artiste.jpg';
        exec('ffmpeg -v error -y -f lavfi -i color=c=purple:s=80x80 -frames:v 1 ' . escapeshellarg($jpg));
        $bytes = (string) file_get_contents($jpg);
        $id = (int) $this->db->value("SELECT id FROM artists WHERE name = 'Groupe'");
        $type = ['Content-Type' => 'image/jpeg'];
        try {
            $r = $this->admin('PUT', "/api/v2/admin/artists/$id/image", $bytes, $type);
            $this->assertContract($r, 'adminSetArtistImage', 200);
            $url = (string) $r->data()['imageUrl'];
            $this->assertStringContainsString(hash('sha256', $bytes), $url);
            // L'image se sert depuis la base (aucune copie dans le cache ni le stockage), vignette comprise
            $img = $this->as($this->token('user'), 'GET', $url . (str_contains($url, '?') ? '&' : '?') . 'size=original');
            $this->assertSame([200, $bytes], [$img->status, $img->raw]);
            $this->assertSame(200, $this->as($this->token('user'), 'GET', $url)->status, 'vignette');
            $this->assertSame($url, $this->as($this->token('user'), 'GET', "/api/v2/artists/$id")->data()['imageUrl']);
            $this->assertSame('artist.image', $this->db->value("SELECT action FROM audit_log WHERE target_type = 'artist' AND target_id = ? ORDER BY id DESC LIMIT 1", [$id]));

            $this->assertError($this->admin('PUT', "/api/v2/admin/artists/$id/image", 'pas une image', $type), 400, 'bad_request', 'adminSetArtistImage');
            $this->assertError($this->admin('PUT', '/api/v2/admin/artists/999999/image', $bytes, $type), 404, 'not_found', 'adminSetArtistImage');
            $this->assertError($this->as($this->token('user'), 'PUT', "/api/v2/admin/artists/$id/image", $bytes, $type), 403, 'forbidden', 'adminSetArtistImage');

            $this->assertContract($this->admin('DELETE', "/api/v2/admin/artists/$id/image"), 'adminDeleteArtistImage', 204);
            $this->assertNull($this->as($this->token('user'), 'GET', "/api/v2/artists/$id")->data()['imageUrl']);
        } finally {
            $this->db->run('UPDATE artists SET image_id = NULL WHERE id = ?', [$id]);
            $this->db->run("DELETE FROM images WHERE source = 'upload'");
            @unlink($jpg);
        }
    }

    public function testImagesDeFond(): void
    {
        $png = self::$env['base'] . '/travail/fond.png';
        exec('ffmpeg -v error -y -f lavfi -i color=c=orange:s=64x48 -frames:v 1 ' . escapeshellarg($png));
        $bytes = (string) file_get_contents($png);
        $type = ['Content-Type' => 'image/png'];
        try {
            $r = $this->admin('POST', '/api/v2/admin/backgrounds?name=' . rawurlencode('Coucher de soleil'), $bytes, $type);
            $this->assertContract($r, 'adminCreateBackground', 201);
            $bg = $r->data();
            $this->assertSame(['Coucher de soleil', true, 64, 48, 'image/png'], [$bg['name'], $bg['active'], $bg['width'], $bg['height'], $bg['mimeType']]);
            $this->assertError($this->admin('POST', '/api/v2/admin/backgrounds', $bytes, $type), 409, 'conflict', 'adminCreateBackground');
            $this->assertError($this->admin('POST', '/api/v2/admin/backgrounds', 'pas une image', $type), 400, 'bad_request', 'adminCreateBackground');
            $this->assertError($this->as($this->token('user'), 'POST', '/api/v2/admin/backgrounds', $bytes, $type), 403, 'forbidden', 'adminCreateBackground');

            // Tout compte voit les fonds actifs et télécharge l'image (cache permanent)
            $r = $this->as($this->token('user'), 'GET', '/api/v2/backgrounds');
            $this->assertContract($r, 'listBackgrounds', 200);
            $this->assertSame([['id' => $bg['id'], 'imageUrl' => $bg['imageUrl']]], $r->data()['items']);
            $img = $this->as($this->token('user'), 'GET', $bg['imageUrl']);
            $this->assertSame([200, 'image/png', $bytes], [$img->status, $img->headers['content-type'] ?? null, $img->raw]);
            $this->assertTrue(str_contains($img->headers['cache-control'] ?? '', 'immutable'));
            $this->assertSame(304, $this->as($this->token('user'), 'GET', $bg['imageUrl'], null, ['If-None-Match' => $img->headers['etag']])->status);
            $this->assertSame(401, $this->call('GET', $bg['imageUrl'])->status, 'connexion exigée');

            // Désactivé : plus tiré au hasard, toujours listé pour l'admin
            $r = $this->admin('PATCH', "/api/v2/admin/backgrounds/{$bg['id']}", ['active' => false]);
            $this->assertContract($r, 'adminUpdateBackground', 200);
            $this->assertSame([], $this->as($this->token('user'), 'GET', '/api/v2/backgrounds')->data()['items']);
            $r = $this->admin('GET', '/api/v2/admin/backgrounds');
            $this->assertContract($r, 'adminListBackgrounds', 200);
            $this->assertSame([false], array_column($r->data()['items'], 'active'));
            $this->assertError($this->admin('PATCH', "/api/v2/admin/backgrounds/{$bg['id']}", []), 400, 'bad_request', 'adminUpdateBackground');

            $this->assertSame(204, $this->admin('DELETE', "/api/v2/admin/backgrounds/{$bg['id']}")->status);
            $this->assertError($this->admin('DELETE', "/api/v2/admin/backgrounds/{$bg['id']}"), 404, 'not_found', 'adminDeleteBackground');
            $this->assertSame(['background.create', 'background.update', 'background.delete'],
                array_column($this->db->all("SELECT action FROM audit_log WHERE action LIKE 'background.%' ORDER BY id"), 'action'));
        } finally {
            $this->db->run('DELETE FROM backgrounds');
        }
    }

    public function testJournalDAudit(): void
    {
        $this->admin('PATCH', '/api/v2/admin/server-settings', ['serverName' => 'Rengaine']);
        $r = $this->admin('GET', '/api/v2/admin/audit?action=server.&actor=' . $this->userId('admin'));
        $this->assertContract($r, 'adminListAudit', 200);
        $e = $r->data()['items'][0];
        $this->assertSame(['server.settings', 'Admin', '127.0.0.1'], [$e['action'], $e['actor']['displayName'], $e['ip']]);
        $this->assertTrue(strlen((string) $e['requestId']) === 26, 'requestId');
        $this->assertSame(0, $this->admin('GET', '/api/v2/admin/audit?from=2099-01-01T00:00:00Z')->data()['total']);
        $this->assertError($this->admin('GET', '/api/v2/admin/audit?from=hier'), 400, 'bad_request', 'adminListAudit');
        $this->assertError($this->as($this->token('agent'), 'GET', '/api/v2/admin/audit'), 403, 'forbidden', 'adminListAudit');
    }

    public function testMemeEnregistrementSignale(): void
    {
        $ids = array_map('intval', array_column($this->db->all('SELECT id FROM tracks ORDER BY id LIMIT 3'), 'id'));
        [$a, $b, $c] = $ids;
        $this->db->run('DELETE FROM same_recordings');
        try {
            $this->db->run('INSERT INTO same_recordings (track_a, track_b, score, offset_ms) VALUES (?, ?, 0.999, 0), (?, ?, 0.870, 1500)', [$a, $b, $b, $c]);
            $r = $this->admin('GET', '/api/v2/admin/same-recordings');
            $this->assertContract($r, 'adminListSameRecordings', 200);
            $g = $r->data();
            // Pistes reliées en chaîne : un seul groupe, au niveau de la paire la moins sûre
            $this->assertSame(1, $g['total']);
            $this->assertSame([$a, $b, $c], array_column($g['items'][0]['tracks'], 'id'));
            $this->assertSame(['close', 0.87], [$g['items'][0]['verdict'], $g['items'][0]['score']]);
            $high = $this->admin('GET', '/api/v2/admin/same-recordings?minScore=0.95')->data();
            $this->assertSame(['same', [$a, $b]], [$high['items'][0]['verdict'], array_column($high['items'][0]['tracks'], 'id')]);
            $this->assertError($this->admin('GET', '/api/v2/admin/same-recordings?minScore=2'), 400, 'bad_request', 'adminListSameRecordings');
            $this->assertError($this->as($this->token('user'), 'GET', '/api/v2/admin/same-recordings'), 403, 'forbidden', 'adminListSameRecordings');
        } finally {
            $this->db->run('DELETE FROM same_recordings');
        }
    }

    public function testPistesAbimees(): void
    {
        $id = (int) $this->db->value('SELECT track_id FROM track_analysis WHERE file_id IS NOT NULL ORDER BY track_id LIMIT 1');
        $saved = $this->db->one('SELECT decode_errors, decode_detail FROM track_analysis WHERE track_id = ?', [$id]);
        try {
            $this->db->run("UPDATE track_analysis SET decode_errors = 2, decode_detail = 'à 0:35 et 2:08 : flac : invalid subframe padding' WHERE track_id = ?", [$id]);
            $r = $this->admin('GET', '/api/v2/admin/damaged-tracks');
            $this->assertContract($r, 'adminListDamagedTracks', 200);
            $d = $r->data();
            $this->assertSame(1, $d['total']);
            $this->assertSame([$id, 2, 'à 0:35 et 2:08 : flac : invalid subframe padding'], [$d['items'][0]['track']['id'], $d['items'][0]['errors'], $d['items'][0]['detail']]);
            $this->assertTrue($d['checked'] >= 1 && $d['items'][0]['storageKey'] !== '');
            $this->assertError($this->as($this->token('user'), 'GET', '/api/v2/admin/damaged-tracks'), 403, 'forbidden', 'adminListDamagedTracks');
        } finally {
            $this->db->run('UPDATE track_analysis SET decode_errors = ?, decode_detail = ? WHERE track_id = ?', [$saved['decode_errors'], $saved['decode_detail'], $id]);
        }
    }
}

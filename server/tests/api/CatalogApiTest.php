<?php
// Catalogue (API-03, API-06), flux (API-04) et images (API-05) par HTTP.

declare(strict_types=1);

namespace Musique\Tests;

require_once __DIR__ . '/ApiCase.php';

final class CatalogApiTest extends ApiCase
{
    private function get(string $path, string $who = 'user', array $headers = []): ApiResponse
    {
        return $this->as($this->token($who), 'GET', $path, null, $headers);
    }

    private function albumId(string $title): int
    {
        return (int) $this->db->value('SELECT id FROM albums WHERE title = ?', [$title]);
    }

    public function testAlbumsTriNaturelEtFiltres(): void
    {
        $r = $this->get('/api/v2/albums');
        $this->assertContract($r, 'listAlbums', 200);
        $this->assertSame(['Album 2', 'Album 10', 'Lemonade'], array_column($r->data()['items'], 'title'), 'tri naturel');
        $this->assertSame(3, $r->data()['total']);

        $r = $this->get('/api/v2/albums?sort=name&order=desc&limit=1&offset=1');
        $this->assertSame(['Album 10'], array_column($r->data()['items'], 'title'), 'page');
        $this->assertSame(3, $r->data()['total']);

        $r = $this->get('/api/v2/albums?q=beyonce');
        $this->assertSame(['Lemonade'], array_column($r->data()['items'], 'title'), 'recherche sans accent');
        $r = $this->get('/api/v2/albums?lossless=true');
        $this->assertSame(['Album 2'], array_column($r->data()['items'], 'title'));
        $pop = (int) $this->db->value("SELECT id FROM genres WHERE name = 'Pop'");
        $rock = (int) $this->db->value("SELECT id FROM genres WHERE name = 'Rock'");
        $this->assertSame(['Lemonade'], array_column($this->get("/api/v2/albums?genre=$pop")->data()['items'], 'title'));
        $this->assertSame(3, $this->get("/api/v2/albums?genre=$pop,$rock")->data()['total']);
        $this->assertSame(['Album 10'], array_column($this->get('/api/v2/albums?yearFrom=2005&yearTo=2015')->data()['items'], 'title'));
        $this->assertSame(['Lemonade', 'Album 10', 'Album 2'], array_column($this->get('/api/v2/albums?sort=year&order=desc')->data()['items'], 'title'));

        $a = $this->get('/api/v2/albums?sort=random&seed=42')->data()['items'];
        $b = $this->get('/api/v2/albums?sort=random&seed=42')->data()['items'];
        $this->assertSame(array_column($a, 'id'), array_column($b, 'id'), 'même graine, même ordre');
    }

    public function testDetailAlbumEtPistes(): void
    {
        $r = $this->get('/api/v2/albums/' . $this->albumId('Lemonade'));
        $this->assertContract($r, 'getAlbum', 200);
        $album = $r->data();
        $this->assertSame('Beyoncé', $album['artistName']);
        $this->assertSame(['Formation', 'Forward'], array_column($album['tracks'], 'title'));
        $this->assertTrue($album['coverUrl'] !== null && str_starts_with($album['coverUrl'], '/api/v2/images/'), 'jaquette');
        $this->assertTrue($album['loudness']['lufs'] !== null, 'volume d’album mesuré');

        $forward = $album['tracks'][1];
        $this->assertSame('/api/v2/tracks/' . $forward['id'] . '/stream', $forward['streamUrl']);
        $this->assertSame(['Beyoncé', 'James Blake'], array_column($forward['artists'], 'name'));
        $this->assertSame([['Pop', true]], array_map(static fn ($g) => [$g['name'], $g['inherited']], $forward['genres']));
        $this->assertTrue($forward['playback']['track']['lufs'] !== null && $forward['playback']['audioEndMs'] !== null, 'infos de lecture');
        $this->assertSame('mp3', $forward['formats'][0]['format']);
        $this->assertSame(true, $forward['available']);

        $flac = $this->get('/api/v2/albums/' . $this->albumId('Album 2'))->data();
        $this->assertSame(true, $flac['hasLossless']);
        $this->assertSame('flac', $flac['tracks'][0]['formats'][0]['format']);
    }

    public function testArtistesPistesGenresRecherche(): void
    {
        $r = $this->get('/api/v2/artists');
        $this->assertContract($r, 'listArtists', 200);
        $this->assertSame(['Beyoncé', 'Groupe', 'James Blake'], array_column($r->data()['items'], 'name'));

        $blake = (int) $this->db->value("SELECT id FROM artists WHERE name = 'James Blake'");
        $r = $this->get("/api/v2/artists/$blake");
        $this->assertContract($r, 'getArtist', 200);
        $this->assertSame([], $r->data()['albums']);
        $this->assertSame(['Lemonade'], array_column($r->data()['appearsOn'], 'title'), 'invité : apparaît sur');

        $r = $this->get('/api/v2/tracks?album=' . $this->albumId('Album 2'));
        $this->assertContract($r, 'listTracks', 200);
        $this->assertSame(['Deux', 'Deux bis'], array_column($r->data()['items'], 'title'));
        $this->assertSame(['Forward'], array_column($this->get("/api/v2/tracks?artist=$blake")->data()['items'], 'title'));
        $this->assertSame(5, $this->get('/api/v2/tracks?sort=duration&order=desc')->data()['total']);
        $this->assertError($this->get('/api/v2/tracks?camelot=13Z'), 400, 'bad_request', 'listTracks');
        $id = $this->db->value("SELECT id FROM tracks WHERE title = 'Dix'");
        $this->assertContract($this->get("/api/v2/tracks/$id"), 'getTrack', 200);

        $r = $this->get('/api/v2/genres');
        $this->assertContract($r, 'listGenres', 200);
        $pop = array_values(array_filter($r->data()['items'], static fn ($g) => $g['name'] === 'Pop'))[0];
        $this->assertSame([1, 2, ['Pop']], [$pop['albumCount'], $pop['trackCount'], $pop['aliases']]);
        $this->assertContract($this->get('/api/v2/collections'), 'listCollections', 200);

        $r = $this->get('/api/v2/search?q=deux');
        $this->assertContract($r, 'search', 200);
        $this->assertSame(['Deux', 'Deux bis'], array_column($r->data()['tracks'], 'title'));
        $r = $this->get('/api/v2/search?q=BEYONCE');
        $this->assertSame(['Beyoncé'], array_column($r->data()['artists'], 'name'));
        $this->assertError($this->get('/api/v2/search'), 400, 'bad_request', 'search');

        $r = $this->get('/api/v2/random/tracks?count=10&exclude=' . $id);
        $this->assertContract($r, 'randomTracks', 200);
        $this->assertSame(4, count($r->data()['items']));
        $this->assertTrue(!in_array((int) $id, array_column($r->data()['items'], 'id'), true), 'exclusion');

        // Page Aléatoire : genre Rock sans l'album « Album 2 » → seulement « Dix »
        $rock = (int) $this->db->value("SELECT id FROM genres WHERE name = 'Rock'");
        $album2 = (int) $this->db->value("SELECT id FROM albums WHERE title = 'Album 2'");
        $r = $this->get("/api/v2/random/tracks?count=200&genre=$rock&excludeAlbum=$album2");
        $this->assertContract($r, 'randomTracks', 200);
        $this->assertSame(['Dix'], array_column($r->data()['items'], 'title'));
        $this->assertError($this->get('/api/v2/random/tracks?excludeAlbum=x'), 400, 'bad_request', 'randomTracks');
    }

    public function testFormeDOndeEtGrilleDesTemps(): void
    {
        $id = (int) $this->db->value("SELECT id FROM tracks WHERE title = 'Deux'");
        // Relevée par la mesure (les pistes de l'essai sont analysées) ; retirée, la piste n'en a plus
        $measured = $this->get("/api/v2/tracks/$id/waveform");
        $this->assertContract($measured, 'getTrackWaveform', 200);
        $this->assertTrue(count($measured->data()['levels']) > 10, 'niveaux relevés');
        $saved = $this->db->one('SELECT waveform, waveform_step_ms FROM track_analysis WHERE track_id = ?', [$id]);
        $this->db->run('UPDATE track_analysis SET waveform = NULL WHERE track_id = ?', [$id]);
        $this->assertError($this->get("/api/v2/tracks/$id/waveform"), 404, 'not_found', 'getTrackWaveform');
        $this->db->run("UPDATE track_analysis SET waveform = ?, waveform_step_ms = 250, beat_grid = ? WHERE track_id = ?",
            [chr(0) . chr(199) . chr(255), '{"intro":{"bpm":120.002,"offsetMs":130.4},"outro":null}', $id]);

        $r = $this->get("/api/v2/tracks/$id/waveform");
        $this->assertContract($r, 'getTrackWaveform', 200);
        $this->assertSame([null, -14.0, 0.0], $r->data()['levels']);
        $this->assertSame(250.0, $r->data()['stepMs']);
        $etag = (string) ($r->headers['etag'] ?? '');
        $this->assertSame(304, $this->get("/api/v2/tracks/$id/waveform", 'user', ['If-None-Match' => $etag])->status);
        $this->assertError($this->get('/api/v2/tracks/999999/waveform'), 404, 'not_found', 'getTrackWaveform');

        $t = $this->get("/api/v2/tracks/$id");
        $this->assertContract($t, 'getTrack', 200);
        $this->assertSame(['intro' => ['bpm' => 120.002, 'offsetMs' => 130.4], 'outro' => null], $t->data()['playback']['beatGrid']);
        $this->db->run('UPDATE track_analysis SET waveform = ?, waveform_step_ms = ?, beat_grid = NULL WHERE track_id = ?', [$saved['waveform'], $saved['waveform_step_ms'], $id]);
    }

    public function testFluxAvecRange(): void
    {
        $id = (int) $this->db->value("SELECT id FROM tracks WHERE title = 'Deux'");
        $file = self::$env['root'] . '/Groupe - Album 2/01 - Deux.flac';
        $bytes = (string) file_get_contents($file);
        $url = "/api/v2/tracks/$id/stream";

        $r = $this->get($url);
        $this->assertSame(200, $r->status);
        $this->assertSame('audio/flac', $r->headers['content-type'] ?? null);
        $this->assertSame('bytes', $r->headers['accept-ranges'] ?? null);
        $this->assertSame((string) strlen($bytes), $r->headers['content-length'] ?? null);
        $this->assertTrue($r->raw === $bytes, 'fichier complet identique');
        $etag = (string) ($r->headers['etag'] ?? '');

        $r = $this->get($url, 'user', ['Range' => 'bytes=100-199']);
        $this->assertSame(206, $r->status);
        $this->assertSame('bytes 100-199/' . strlen($bytes), $r->headers['content-range'] ?? null);
        $this->assertTrue($r->raw === substr($bytes, 100, 100), 'plage identique');
        $r = $this->get($url, 'user', ['Range' => 'bytes=-50']);
        $this->assertTrue($r->status === 206 && $r->raw === substr($bytes, -50), 'fin du fichier');
        $r = $this->get($url, 'user', ['Range' => 'bytes=' . strlen($bytes) . '-']);
        $this->assertError($r, 416, 'range_not_satisfiable', 'streamTrack');
        $this->assertSame('bytes */' . strlen($bytes), $r->headers['content-range'] ?? null);
        $this->assertSame(416, $this->get($url, 'user', ['Range' => 'bytes=0-1,5-9'])->status, 'plusieurs plages refusées');

        $this->assertSame(304, $this->get($url, 'user', ['If-None-Match' => $etag])->status);
        $this->assertError($this->get('/api/v2/tracks/999999/stream'), 404, 'not_found', 'streamTrack');

        // Le lecteur web s'authentifie par son cookie (l'élément <audio> n'envoie pas d'en-tête)
        $login = $this->call('POST', '/api/v2/auth/login', ['email' => 'user@test.fr', 'password' => self::PASSWORD, 'client' => 'web']);
        $r = $this->call('GET', $url, null, ['Cookie' => 'musique_session=' . $login->sessionCookie(), 'Range' => 'bytes=0-9']);
        $this->assertSame(206, $r->status);
        $r = $this->call('HEAD', $url, null, ['Authorization' => 'Bearer ' . $this->token('user')]);
        $this->assertTrue($r->status === 200 && $r->raw === '', 'HEAD sans corps');
    }

    public function testConversionALaVolee(): void
    {
        $this->assertTrue($this->call('GET', '/api/v2/server')->data()['features']['transcoding'], 'conversion annoncée');
        $flac = (int) $this->db->value("SELECT id FROM tracks WHERE title = 'Deux bis'");
        $url = "/api/v2/tracks/$flac/stream?format=opus&bitrate=96";

        // Première demande : le flux part pendant la conversion (taille inconnue, pas de Range)
        $live = $this->get($url, 'user', ['Range' => 'bytes=0-']);
        $this->assertSame(200, $live->status);
        $this->assertSame('audio/ogg', $live->headers['content-type'] ?? null);
        $this->assertSame('none', $live->headers['accept-ranges'] ?? null);
        $this->assertFalse(isset($live->headers['content-length']), 'pas de taille pendant la conversion');
        $this->assertSame('OggS', substr($live->raw, 0, 4));
        $probe = shell_exec('printf %s ' . escapeshellarg(base64_encode($live->raw)) . ' | base64 -d | ffprobe -v error -show_entries stream=codec_name:format=duration -of csv=p=0 -');
        $this->assertStringContainsString('opus', (string) $probe, 'Opus lisible');

        // Ensuite : servie depuis le cache, comme un fichier
        $cached = $this->get($url);
        $this->assertSame(200, $cached->status);
        $this->assertSame('bytes', $cached->headers['accept-ranges'] ?? null);
        $this->assertSame((string) strlen($live->raw), $cached->headers['content-length'] ?? null);
        $this->assertTrue($cached->raw === $live->raw, 'même conversion');
        $r = $this->get($url, 'user', ['Range' => 'bytes=10-19']);
        $this->assertTrue($r->status === 206 && $r->raw === substr($live->raw, 10, 10), 'Range sur la conversion en cache');
        $this->assertSame(304, $this->get($url, 'user', ['If-None-Match' => (string) $cached->headers['etag']])->status);

        // Avancer : startMs donne une conversion qui commence plus loin
        $later = $this->get("$url&startMs=1500");
        $this->assertSame(200, $later->status);
        $this->assertSame('none', $later->headers['accept-ranges'] ?? null);
        $this->assertTrue(strlen($later->raw) < strlen($live->raw), 'plus court que la piste entière');

        // Une plage au milieu d'une conversion pas encore faite : impossible
        $r = $this->get("/api/v2/tracks/$flac/stream?format=mp3", 'user', ['Range' => 'bytes=100-']);
        $this->assertError($r, 416, 'range_not_satisfiable', 'streamTrack');
        $mp3 = $this->get("/api/v2/tracks/$flac/stream?format=mp3");
        $this->assertSame('audio/mpeg', $mp3->headers['content-type'] ?? null);

        // Rien d'utile à convertir : le fichier d'origine
        $orig = (int) $this->db->value("SELECT id FROM tracks WHERE title = 'Formation'");
        $bytes = (string) file_get_contents(self::$env['root'] . '/Beyoncé - Lemonade/01 - Formation.mp3');
        $r = $this->get("/api/v2/tracks/$orig/stream?format=mp3&bitrate=320");
        $this->assertTrue($r->status === 200 && $r->raw === $bytes && ($r->headers['accept-ranges'] ?? '') === 'bytes', 'jamais de MP3 en MP3');
        $r = $this->get("/api/v2/tracks/$orig/stream?format=flac");
        $this->assertTrue($r->raw === $bytes, 'jamais de FLAC tiré d’un MP3');
        $r = $this->get("/api/v2/tracks/$flac/stream?format=flac");
        $this->assertSame('bytes', $r->headers['accept-ranges'] ?? null, 'FLAC déjà en FLAC : le fichier');

        $this->assertError($this->get("/api/v2/tracks/$flac/stream?format=opus&bitrate=100"), 400, 'bad_request', 'streamTrack');
    }

    public function testImagesEtVignettes(): void
    {
        $cover = $this->get('/api/v2/albums/' . $this->albumId('Lemonade'))->data()['coverUrl'];
        $r = $this->get($cover . '?size=s');
        $this->assertSame(200, $r->status);
        $this->assertSame('image/jpeg', $r->headers['content-type'] ?? null);
        $this->assertSame('private, max-age=31536000, immutable', $r->headers['cache-control'] ?? null);
        $size = getimagesizefromstring($r->raw);
        $this->assertSame([96, 96], [$size[0], $size[1]], 'vignette s = 96 px');
        $hex = substr($cover, strrpos($cover, '/') + 1);
        $this->assertTrue(is_file(self::$env['base'] . "/cache/thumbs/96/" . substr($hex, 0, 2) . "/$hex.jpg"), 'vignette gardée en cache');
        $this->assertSame(304, $this->get($cover . '?size=s', 'user', ['If-None-Match' => $r->headers['etag']])->status);

        $r = $this->get($cover . '?size=original');
        $this->assertTrue($r->raw === file_get_contents(self::$env['root'] . '/Beyoncé - Lemonade/cover.jpg'), 'original identique');
        $r = $this->get($cover);
        $this->assertSame([300, 300], array_slice(getimagesizefromstring($r->raw), 0, 2), 'taille m par défaut');

        // Pochette intégrée au MP3 (copie dans le cache), plus petite que « l » : pas agrandie
        $embedded = $this->get('/api/v2/albums/' . $this->albumId('Album 10'))->data()['coverUrl'];
        $this->assertTrue($embedded !== null, 'pochette intégrée');
        $this->assertSame([400, 400], array_slice(getimagesizefromstring($this->get($embedded . '?size=l')->raw), 0, 2));

        $this->assertError($this->get('/api/v2/images/' . str_repeat('ab', 32)), 404, 'not_found', 'getImage');
        $this->assertSame(404, $this->get('/api/v2/images/pas-une-empreinte')->status);
    }
}

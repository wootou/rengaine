<?php
// Réglages et file d'attente versionnés (CPT-04, WEB-04), favoris, historique, par HTTP.

declare(strict_types=1);

namespace Musique\Tests;

require_once __DIR__ . '/ApiCase.php';

final class MeApiTest extends ApiCase
{
    public function testReglagesParDefautPuisVersions(): void
    {
        $t = $this->token('user');
        $r = $this->as($t, 'GET', '/api/v2/me/settings');
        $this->assertContract($r, 'getMySettings', 200);
        $this->assertSame('"0"', $r->headers['etag'] ?? null);
        $doc = $r->data();
        $this->assertSame(-16, $doc['settings']['playback']['targetLufs'], 'cible par défaut -16 LUFS');
        $this->assertSame([true, ['genreIds' => [], 'excludedAlbumIds' => [], 'count' => 100]],
            [$doc['settings']['infinite']['enabled'], $doc['settings']['random']], 'mode infini activé, tirage de 100 pistes');

        $settings = $doc['settings'];
        $settings['playback']['targetLufs'] = -14;
        $settings['futur'] = ['champ' => 'd’un client plus récent'];
        $this->assertError($this->as($t, 'PUT', '/api/v2/me/settings', $settings), 428, 'precondition_required', 'putMySettings');

        $r = $this->as($t, 'PUT', '/api/v2/me/settings', $settings, ['If-Match' => '"0"']);
        $this->assertContract($r, 'putMySettings', 200);
        $this->assertSame('"1"', $r->headers['etag'] ?? null);
        $this->assertSame(-14, $r->data()['settings']['playback']['targetLufs']);
        $this->assertSame('d’un client plus récent', $r->data()['settings']['futur']['champ'], 'champ inconnu gardé');

        // Un autre appareil enregistre avec une version périmée : 412 et la version actuelle
        $settings['playback']['targetLufs'] = -20;
        $r = $this->as($t, 'PUT', '/api/v2/me/settings', $settings, ['If-Match' => '"0"']);
        $this->assertError($r, 412, 'version_conflict', 'putMySettings');
        $this->assertSame('"1"', $r->headers['etag'] ?? null);
        $this->assertSame(-14, $r->data()['error']['details']['current']['settings']['playback']['targetLufs']);

        $settings['playback']['targetLufs'] = -30;
        $r = $this->as($t, 'PUT', '/api/v2/me/settings', $settings, ['If-Match' => '"1"']);
        $this->assertError($r, 400, 'bad_request', 'putMySettings');
        $this->assertSame('playback.targetLufs', $r->data()['error']['details']['field']);
        $settings['playback']['targetLufs'] = -14;
        unset($settings['interface']);
        $this->assertSame('interface', $this->as($t, 'PUT', '/api/v2/me/settings', $settings, ['If-Match' => '"1"'])->data()['error']['details']['field']);

        // Client antérieur aux réglages « infinite » et « random » : accepté, ils gardent leur valeur
        $settings['interface'] = ['theme' => 'auto'];
        unset($settings['infinite'], $settings['random']);
        $r = $this->as($t, 'PUT', '/api/v2/me/settings', $settings, ['If-Match' => '"1"']);
        $this->assertContract($r, 'putMySettings', 200);
        $this->assertSame([true, 100], [$r->data()['settings']['infinite']['enabled'], $r->data()['settings']['random']['count']]);
        $this->assertTrue($r->data()['settings']['interface']['background'], 'image de fond : affichée par défaut');
        $settings = $r->data()['settings'];
        $settings['random'] = ['genreIds' => [1], 'excludedAlbumIds' => [2], 'count' => 0];
        $r = $this->as($t, 'PUT', '/api/v2/me/settings', $settings, ['If-Match' => '"2"']);
        $this->assertSame('random.count', $r->data()['error']['details']['field']);
        $settings['random']['count'] = 50;
        $settings['infinite']['enabled'] = false;
        $settings['interface']['background'] = 'oui';
        $this->assertSame('interface.background', $this->as($t, 'PUT', '/api/v2/me/settings', $settings, ['If-Match' => '"2"'])->data()['error']['details']['field']);
        $settings['interface']['background'] = false;
        $r = $this->as($t, 'PUT', '/api/v2/me/settings', $settings, ['If-Match' => '"2"']);
        $this->assertContract($r, 'putMySettings', 200);
        $this->assertSame([false, ['genreIds' => [1], 'excludedAlbumIds' => [2], 'count' => 50]], [$r->data()['settings']['infinite']['enabled'], $r->data()['settings']['random']]);
        $this->assertSame(false, $r->data()['settings']['interface']['background'], 'image de fond masquée');

        // Chaque compte a ses propres réglages
        $this->assertSame(-16, $this->as($this->token('admin'), 'GET', '/api/v2/me/settings')->data()['settings']['playback']['targetLufs']);
    }

    public function testFileAttenteRetrouveeSurUnAutreAppareil(): void
    {
        $t = $this->token('admin');
        $ids = array_map('intval', array_column($this->db->all('SELECT id FROM tracks ORDER BY id DESC'), 'id'));
        $r = $this->as($t, 'GET', '/api/v2/me/queue');
        $this->assertContract($r, 'getMyQueue', 200);
        $this->assertSame(0, $r->data()['version']);

        $queue = ['trackIds' => [$ids[0], $ids[1], $ids[0]], 'currentIndex' => 1, 'positionMs' => 12345, 'mode' => 'repeat_all'];
        $r = $this->as($t, 'PUT', '/api/v2/me/queue', $queue, ['If-Match' => '"0"']);
        $this->assertContract($r, 'putMyQueue', 200);

        $other = $this->call('POST', '/api/v2/auth/login', ['email' => 'admin@test.fr', 'password' => self::PASSWORD, 'client' => 'app', 'deviceName' => 'Autre appareil'])->data()['token'];
        $r = $this->as($other, 'GET', '/api/v2/me/queue');
        $this->assertContract($r, 'getMyQueue', 200);
        $q = $r->data();
        $this->assertSame([$ids[0], $ids[1], $ids[0]], array_column($q['tracks'], 'id'), 'pistes dans l’ordre, doublon compris');
        $this->assertSame([1, 12345, 'repeat_all', 'Test admin'], [$q['currentIndex'], $q['positionMs'], $q['mode'], $q['updatedBy']]);

        $this->assertError($this->as($other, 'PUT', '/api/v2/me/queue', $queue, ['If-Match' => '"0"']), 412, 'version_conflict', 'putMyQueue');
        $bad = ['trackIds' => [999999], 'currentIndex' => 0, 'positionMs' => 0, 'mode' => 'normal'];
        $this->assertError($this->as($other, 'PUT', '/api/v2/me/queue', $bad, ['If-Match' => '"1"']), 400, 'bad_request', 'putMyQueue');
        $bad = ['trackIds' => [$ids[0]], 'currentIndex' => 3, 'positionMs' => 0, 'mode' => 'normal'];
        $this->assertSame('currentIndex', $this->as($other, 'PUT', '/api/v2/me/queue', $bad, ['If-Match' => '"1"'])->data()['error']['details']['field']);
    }

    public function testFavoris(): void
    {
        $t = $this->token('user');
        $track = (int) $this->db->value("SELECT id FROM tracks WHERE title = 'Dix'");
        $album = (int) $this->db->value("SELECT id FROM albums WHERE title = 'Lemonade'");
        $this->assertContract($this->as($t, 'PUT', "/api/v2/me/favorites/track/$track"), 'addFavorite', 204);
        $this->assertContract($this->as($t, 'PUT', "/api/v2/me/favorites/track/$track"), 'addFavorite', 204);
        $this->assertContract($this->as($t, 'PUT', "/api/v2/me/favorites/album/$album"), 'addFavorite', 204);
        $this->assertError($this->as($t, 'PUT', '/api/v2/me/favorites/album/999999'), 404, 'not_found', 'addFavorite');

        $r = $this->as($t, 'GET', '/api/v2/me/favorites?type=track');
        $this->assertContract($r, 'listMyFavorites', 200);
        $this->assertSame([$track], array_column(array_column($r->data()['items'], 'track'), 'id'));
        $this->assertSame(true, $this->as($t, 'GET', "/api/v2/tracks/$track")->data()['favorite']);
        $this->assertSame(false, $this->as($this->token('admin'), 'GET', "/api/v2/tracks/$track")->data()['favorite'], 'favori propre au compte');
        $this->assertSame(['Lemonade'], array_column($this->as($t, 'GET', '/api/v2/albums?favorite=true')->data()['items'], 'title'));
        $this->assertSame(['Dix'], array_column($this->as($t, 'GET', '/api/v2/tracks?favorite=true')->data()['items'], 'title'));

        $this->assertContract($this->as($t, 'DELETE', "/api/v2/me/favorites/track/$track"), 'removeFavorite', 204);
        $this->assertSame(0, $this->as($t, 'GET', '/api/v2/me/favorites?type=track')->data()['total']);
        $this->assertError($this->as($t, 'GET', '/api/v2/me/favorites'), 400, 'bad_request', 'listMyFavorites');
    }

    public function testHistorique(): void
    {
        $t = $this->token('user');
        $track = (int) $this->db->value("SELECT id FROM tracks WHERE title = 'Deux'");
        $r = $this->as($t, 'POST', '/api/v2/me/history', ['plays' => [
            ['trackId' => $track, 'startedAt' => '2026-10-19T08:30:00.000Z', 'playedMs' => 2000, 'completed' => true, 'context' => 'album'],
            ['trackId' => $track, 'startedAt' => '2026-10-19T09:00:00Z', 'playedMs' => 500, 'skipped' => true],
            ['trackId' => 999999, 'startedAt' => '2026-10-19T09:10:00Z', 'playedMs' => 1],
        ]]);
        $this->assertContract($r, 'recordPlays', 204);
        $r = $this->as($t, 'GET', '/api/v2/me/history');
        $this->assertContract($r, 'listMyHistory', 200);
        $items = $r->data()['items'];
        $this->assertSame(2, count($items), 'piste inconnue ignorée');
        $this->assertSame(['2026-10-19T09:00:00.000Z', true, 'other'], [$items[0]['startedAt'], $items[0]['skipped'], $items[0]['context']]);
        $this->assertSame('Test user', $items[0]['device']);
        $r = $this->as($t, 'POST', '/api/v2/me/history', ['plays' => [['trackId' => $track, 'startedAt' => 'hier', 'playedMs' => 1]]]);
        $this->assertSame('plays[0].startedAt', $r->data()['error']['details']['field'] ?? null);
    }

    public function testEffacerSonHistorique(): void
    {
        $t = $this->token('user2');
        $other = $this->token('user');
        $track = (int) $this->db->value("SELECT id FROM tracks WHERE title = 'Deux'");
        $play = static fn (string $at) => ['trackId' => $track, 'startedAt' => $at, 'playedMs' => 9000];
        $this->as($t, 'POST', '/api/v2/me/history', ['plays' => [$play('2026-10-20T08:00:00Z'), $play('2026-10-20T09:00:00Z'), $play('2026-10-20T10:00:00Z')]]);
        $this->as($other, 'POST', '/api/v2/me/history', ['plays' => [$play('2026-10-20T11:00:00Z')]]);
        $otherCount = $this->as($other, 'GET', '/api/v2/me/history')->data()['total'];
        $items = $this->as($t, 'GET', '/api/v2/me/history')->data()['items'];
        $this->assertSame(3, count($items));

        // Une écoute : la sienne seulement
        $this->assertContract($this->as($t, 'DELETE', '/api/v2/me/history/' . $items[1]['id']), 'deleteMyPlay', 204);
        $this->assertError($this->as($t, 'DELETE', '/api/v2/me/history/' . $items[1]['id']), 404, 'not_found', 'deleteMyPlay');
        $theirs = $this->as($other, 'GET', '/api/v2/me/history')->data()['items'][0]['id'];
        $this->assertError($this->as($t, 'DELETE', "/api/v2/me/history/$theirs"), 404, 'not_found', 'deleteMyPlay');
        $this->assertSame(['2026-10-20T10:00:00.000Z', '2026-10-20T08:00:00.000Z'], array_column($this->as($t, 'GET', '/api/v2/me/history')->data()['items'], 'startedAt'));

        // Tout : les autres comptes gardent le leur ; trace au journal d'audit
        $r = $this->as($t, 'DELETE', '/api/v2/me/history');
        $this->assertContract($r, 'clearMyHistory', 200);
        $this->assertSame(['deleted' => 2], $r->data());
        $this->assertSame(0, $this->as($t, 'GET', '/api/v2/me/history')->data()['total']);
        $this->assertSame($otherCount, $this->as($other, 'GET', '/api/v2/me/history')->data()['total'], 'les autres comptes gardent leur historique');
        $this->assertSame('history.clear', $this->db->value("SELECT action FROM audit_log WHERE action = 'history.clear' ORDER BY id DESC LIMIT 1"));
        // L'agent n'a pas d'historique
        $this->assertSame(403, $this->as($this->token('agent'), 'DELETE', '/api/v2/me/history')->status);
    }
}

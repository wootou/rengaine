<?php
// Lecture continue intelligente (LEC-03) : POST /playback/next.
// Bibliothèque de test : Formation et Forward (Beyoncé), Dix, Deux, Deux bis (Groupe).

declare(strict_types=1);

namespace Musique\Tests;

use Musique\Playback\Continuous;

final class PlaybackApiTest extends ApiCase
{
    private function track(string $title): int
    {
        return (int) $this->db->value('SELECT id FROM tracks WHERE title = ?', [$title]);
    }

    /** @param array<string, array{0: ?string, 1: ?float}> $values titre → [camelot, bpm] */
    private function dj(array $values): void
    {
        foreach ($values as $title => [$camelot, $bpm]) {
            $this->db->run('UPDATE track_analysis SET camelot = ?, bpm = ? WHERE track_id = ?', [$camelot, $bpm, $this->track($title)]);
        }
    }

    /** @return list<string> titres proposés */
    private function next(array $body, string $who = 'user'): array
    {
        $r = $this->as($this->token($who), 'POST', '/api/v2/playback/next', $body);
        $this->assertContract($r, 'nextTracks', 200);
        return array_map(static fn ($t) => $t['title'], $r->data()['items']);
    }

    public function tearDown(): void
    {
        $this->db->run('UPDATE track_analysis SET camelot = NULL, bpm = NULL');
        $uid = $this->userId('user');
        $this->db->run('DELETE FROM play_history WHERE user_id = ?', [$uid]);
        $this->db->run('DELETE FROM favorites WHERE user_id = ?', [$uid]);
        $this->db->run('DELETE FROM user_settings WHERE user_id = ?', [$uid]);
    }

    public function testToutesLesAutresPistesEtArtistesEspaces(): void
    {
        $formation = $this->track('Formation');
        for ($run = 0; $run < 8; $run++) {
            $titles = $this->next(['count' => 10, 'currentTrackId' => $formation, 'recentTrackIds' => [$formation]]);
            $this->assertSame(['Deux', 'Deux bis', 'Dix', 'Forward'], (static function (array $t): array { sort($t); return $t; })($titles),
                'les quatre autres pistes, pas celle en cours');
            $this->assertTrue($titles[0] !== 'Forward', 'Beyoncé vient de passer : pas Forward tout de suite');
        }
        $this->assertSame(2, count($this->next(['count' => 2])), 'sans piste en cours');
        $this->assertSame(['Formation'], $this->next(['count' => 5, 'recentTrackIds' => [$this->track('Forward'), $this->track('Dix'), $this->track('Deux'), $this->track('Deux bis')]]));
    }

    public function testCleCompatibleEtBpmProche(): void
    {
        $this->dj(['Formation' => ['8A', 120], 'Dix' => ['9A', 60], 'Deux' => ['3B', 126], 'Deux bis' => ['8B', 100], 'Forward' => ['2A', 90]]);
        $formation = $this->track('Formation');
        for ($run = 0; $run < 8; $run++) {
            $key = $this->next(['count' => 1, 'currentTrackId' => $formation, 'overrides' => ['harmonicMixing' => true]]);
            $this->assertTrue(in_array($key[0], ['Dix', 'Deux bis'], true), "clé compatible avec 8A : {$key[0]}");
            $bpm = $this->next(['count' => 1, 'currentTrackId' => $formation, 'overrides' => ['bpmTolerancePercent' => 5]]);
            $this->assertTrue(in_array($bpm[0], ['Dix', 'Deux'], true), "BPM à 5 % de 120 (ou la moitié) : {$bpm[0]}");
        }
        // Plus rien de compatible : on relâche plutôt que de s'arrêter
        $this->assertSame(4, count($this->next(['count' => 4, 'currentTrackId' => $formation, 'overrides' => ['harmonicMixing' => true, 'bpmTolerancePercent' => 0]])));
    }

    public function testGenresRepetitionsEtFavoris(): void
    {
        $rock = (int) $this->db->value("SELECT id FROM genres WHERE name = 'Rock'");
        $titles = $this->next(['count' => 10, 'overrides' => ['genres' => [$rock]]]);
        sort($titles);
        $this->assertSame(['Deux', 'Deux bis', 'Dix'], $titles, 'seulement du rock');

        // Écoutée il y a une heure : pas reproposée (24 h par défaut)
        $this->db->run('INSERT INTO play_history (user_id, track_id, started_at) VALUES (?, ?, CURRENT_TIMESTAMP(3) - INTERVAL 1 HOUR)', [$this->userId('user'), $this->track('Dix')]);
        $this->assertFalse(in_array('Dix', $this->next(['count' => 10]), true));
        $this->assertTrue(in_array('Dix', $this->next(['count' => 10], 'user2'), true), 'l’historique est celui du compte');

        // Favoris seulement (poids 1) : l'album favori passe en premier
        $this->db->run("INSERT INTO favorites (user_id, item_type, item_id) SELECT ?, 'album', album_id FROM tracks WHERE title = 'Deux bis'", [$this->userId('user')]);
        $settings = ['continuous' => ['favoritesWeight' => 1, 'artistSpacing' => 0]];
        $this->db->run('INSERT INTO user_settings (user_id, settings, version) VALUES (?, ?, 1)', [$this->userId('user'), json_encode($settings)]);
        for ($run = 0; $run < 5; $run++) {
            $two = $this->next(['count' => 2]);
            sort($two);
            $this->assertSame(['Deux', 'Deux bis'], $two, 'les deux pistes de l’album favori');
        }
    }

    public function testErreurs(): void
    {
        $t = $this->token('user');
        $this->assertError($this->as($t, 'POST', '/api/v2/playback/next', ['count' => 0]), 400, 'bad_request', 'nextTracks');
        $this->assertError($this->as($t, 'POST', '/api/v2/playback/next', ['count' => 5, 'overrides' => ['energyTrend' => 'vite']]), 400, 'bad_request', 'nextTracks');
        $this->assertError($this->call('POST', '/api/v2/playback/next', ['count' => 5]), 401, 'unauthorized', 'nextTracks');
    }

    public function testRoueCamelotEtTempo(): void
    {
        $this->assertSame(['8A', '7A', '9A', '8B'], Continuous::compatible('8A'));
        $this->assertSame(['12B', '11B', '1B', '12A'], Continuous::compatible('12B'));
        $this->assertSame(['1A', '12A', '2A', '1B'], Continuous::compatible('1A'));
        $this->assertTrue(Continuous::bpmClose(120, 126, 5));
        $this->assertFalse(Continuous::bpmClose(120, 127, 5));
        $this->assertTrue(Continuous::bpmClose(120, 60, 0), 'moitié du tempo');
        $this->assertTrue(Continuous::bpmClose(90, 182, 2), 'double du tempo');
    }
}

<?php
// Tests de l'analyseur sur de vrais fichiers (fabriqués par ffmpeg avec des silences connus),
// dans la base jetable rengaine_test et un stockage disque temporaire.

declare(strict_types=1);

namespace Musique\Tests;

use Musique\Audio\Analyzer;
use Musique\Audio\Fingerprint;
use Musique\Audio\Measure;
use Musique\Audio\Rhythm;
use Musique\Config;
use Musique\Db\Database;
use Musique\Db\Migrator;
use Musique\Library\AlbumBuilder;
use Musique\Library\Genres;
use Musique\Library\Images;
use Musique\Library\Indexer;
use Musique\Jobs\JobLog;
use Musique\Library\Probe;
use Musique\Library\SameRecordings;
use Musique\Log\Logger;
use Musique\Storage\DiskStorage;

final class AnalyzerTest extends TestCase
{
    private Database $db;
    private DiskStorage $storage;
    private Logger $logger;
    private string $root;
    private string $base;

    public function setUp(): void
    {
        if (!is_readable(Config::defaultPath()) && getenv('RENGAINE_CONFIG') === false) {
            $this->skip('configuration illisible (lancer en root)');
        }
        $config = Config::load()->with('base', 'nom', getenv('RENGAINE_TEST_DB') ?: 'rengaine_test');
        $this->db = Database::fromConfig($config);
        try {
            $this->db->pdo();
        } catch (\Throwable $e) {
            $this->skip('base de test inaccessible : ' . $e->getMessage());
        }
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->db->all('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()') as $r) {
            $this->db->exec('DROP TABLE `' . $r['t'] . '`');
        }
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');
        (new Migrator($this->db, \Musique\REPO_DIR . '/db'))->migrate();

        $this->base = sys_get_temp_dir() . '/musique-ana-' . bin2hex(random_bytes(4));
        $this->root = "$this->base/stockage";
        mkdir($this->root, 0755, true);
        mkdir("$this->base/cache", 0755, true);
        $this->storage = new DiskStorage($this->root);
        $this->logger = new Logger("$this->base/test.log", 'debug');
    }

    public function tearDown(): void
    {
        if (isset($this->base)) {
            exec('rm -rf ' . escapeshellarg($this->base));
        }
    }

    /** 1 s de silence, $sound s de son (sinus 440 Hz à -6 dBFS), $tail s de silence. */
    private function audio(string $key, array $tags, float $sound = 2.0, float $tail = 1.5): void
    {
        $path = "$this->root/$key";
        @mkdir(dirname($path), 0755, true);
        $end = 1 + $sound;
        $expr = sprintf("if(between(t\\,1\\,%s)\\,0.5*sin(2*PI*440*t)\\,0)", $end);
        $cmd = ['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', "aevalsrc='$expr':d=" . ($end + $tail) . ':s=44100'];
        foreach ($tags as $k => $v) {
            array_push($cmd, '-metadata', "$k=$v");
        }
        array_push($cmd, '-c:a', str_ends_with($key, '.flac') ? 'flac' : 'libmp3lame', $path);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $err = stream_get_contents($pipes[2]);
        if (proc_close($proc) !== 0) {
            $this->fail("ffmpeg n'a pas pu créer $key : $err");
        }
    }

    private function index(): void
    {
        $genres = new Genres($this->db);
        (new Indexer($this->db, $this->storage, $this->logger, new Probe(2),
            new AlbumBuilder($this->db, $genres, new Images($this->db, $this->storage, "$this->base/cache")), $genres))->run();
    }

    private function analyze(): array
    {
        return (new Analyzer($this->db, $this->storage, $this->logger, "$this->base/cache/tmp", 2))->run();
    }

    private function analysis(string $title): array
    {
        return $this->db->one(
            'SELECT ta.*, t.duration_ms FROM track_analysis ta JOIN tracks t ON t.id = ta.track_id WHERE t.title = ?', [$title]
        ) ?? $this->fail("pas d'analyse pour « $title »");
    }

    public function testVolumeSilencesEmpreinteEtAlbum(): void
    {
        $tags = ['album' => 'Mesures', 'artist' => 'Test', 'album_artist' => 'Test'];
        $this->audio('Test - Mesures/01 - Court.flac', $tags + ['title' => 'Court', 'track' => '1']);
        $this->audio('Test - Mesures/02 - Long.flac', $tags + ['title' => 'Long', 'track' => '2'], 6.0, 0.5);
        $this->index();
        $this->assertSame(2, (int) $this->db->value('SELECT COUNT(*) FROM analysis_queue'));

        $stats = $this->analyze();
        $this->assertSame(2, $stats['analyzed'] ?? 0, 'pistes analysées');
        $this->assertSame(0, $stats['errors'] ?? 0, 'erreurs');

        $a = $this->analysis('Court');
        $this->assertTrue(abs((int) $a['audio_start_ms'] - 1000) <= 30, 'début du son vers 1 s : ' . $a['audio_start_ms']);
        $this->assertTrue(abs((int) $a['audio_end_ms'] - 3000) <= 30, 'fin du son vers 3 s : ' . $a['audio_end_ms']);
        $this->assertTrue((float) $a['loudness_lufs'] > -13 && (float) $a['loudness_lufs'] < -8, 'volume : ' . $a['loudness_lufs']);
        $this->assertTrue(abs((float) $a['true_peak_dbtp'] + 6) <= 0.5, 'crête vers -6 dBTP : ' . $a['true_peak_dbtp']);
        $this->assertTrue(str_contains((string) $a['analyzer_version'], 'ffmpeg'), 'version de l’analyseur');

        // Forme d'onde : 1 s muette, 2 s de son, 1,5 s muette ; des tranches de 50 ms
        $levels = Measure::waveLevels((string) $a['waveform']);
        $this->assertTrue(count($levels) >= 85 && count($levels) <= 95, 'tranches : ' . count($levels));
        $this->assertSame('50.000', (string) $a['waveform_step_ms']);
        $this->assertTrue($levels[5] === null || $levels[5] < -60, 'silence au début : ' . json_encode($levels[5]));
        $this->assertTrue($levels[40] !== null && $levels[40] > -30, 'du son au milieu : ' . json_encode($levels[40]));

        $b = $this->analysis('Long');
        $this->assertTrue(abs((int) $b['audio_end_ms'] - 7000) <= 30, 'fin du son vers 7 s : ' . $b['audio_end_ms']);

        // Empreinte du fichier
        $sha = $this->db->value("SELECT HEX(content_sha256) FROM storage_objects WHERE storage_key = 'Test - Mesures/01 - Court.flac'");
        $this->assertSame(strtoupper(hash_file('sha256', "$this->root/Test - Mesures/01 - Court.flac")), $sha);

        // Les étapes non faites ici (BPM, clé, empreinte acoustique) restent en file ; plus rien à mesurer
        $this->assertSame(2, (int) $this->db->value('SELECT COUNT(*) FROM analysis_queue'));
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM analysis_queue WHERE (steps & ?) <> 0', [Analyzer::STEPS_DONE]));
        $this->assertSame('bpm,key,energy,fingerprint', $this->db->value('SELECT steps FROM analysis_queue LIMIT 1'));

        // Volume de l'album : entre les deux, plus près de la piste longue
        $album = $this->db->one("SELECT loudness_lufs, true_peak_dbtp FROM albums WHERE title = 'Mesures'");
        $this->assertTrue($album['loudness_lufs'] !== null, 'volume d’album calculé');
        $this->assertTrue(abs((float) $album['true_peak_dbtp'] - max((float) $a['true_peak_dbtp'], (float) $b['true_peak_dbtp'])) < 0.01, 'crête d’album = la plus haute');

        // Un second passage n'a rien à faire
        $this->assertSame(0, $this->analyze()['analyzed'] ?? 0);
    }

    public function testPeuDeMemoireUneAnalyseALaFois(): void
    {
        $tags = ['album' => 'Mémoire', 'artist' => 'Test', 'album_artist' => 'Test'];
        foreach ([1, 2, 3] as $n) {
            $this->audio("Test - Mémoire/0$n - Piste $n.flac", $tags + ['title' => "Piste $n", 'track' => (string) $n]);
        }
        $this->index();
        $calls = 0;
        $analyzer = new Analyzer($this->db, $this->storage, $this->logger, "$this->base/cache/tmp", 3, 900, null, null,
            function () use (&$calls): int {
                $calls++;
                return 100_000_000; // 100 Mo : sous le seuil
            });
        $stats = $analyzer->run();
        // Les pistes passent quand même, une par une
        $this->assertSame(3, $stats['analyzed'] ?? 0, 'pistes analysées');
        $this->assertSame(1, $stats['low_memory'] ?? 0, 'manque de mémoire noté une fois');
        $this->assertTrue($calls > 0, 'mémoire consultée');
        // Assez de mémoire : rien de noté
        $this->db->run('UPDATE analysis_queue SET steps = 1, attempts = 0');
        $stats = (new Analyzer($this->db, $this->storage, $this->logger, "$this->base/cache/tmp", 3, 900, null, null,
            static fn (): int => 8_000_000_000))->run();
        $this->assertFalse(isset($stats['low_memory']), 'pas de manque de mémoire');
    }

    public function testMeilleurFichierMesureEtLesDeuxHaches(): void
    {
        $tags = ['album' => 'Double', 'artist' => 'Test', 'album_artist' => 'Test', 'title' => 'Un', 'track' => '1'];
        $this->audio('Test - Double/01 - Un.mp3', $tags);
        $this->audio('Test - Double/flac/01 - Un.flac', $tags);
        $this->index();
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM tracks'));
        $this->analyze();
        $format = $this->db->value('SELECT tf.format FROM track_analysis ta JOIN track_files tf ON tf.id = ta.file_id');
        $this->assertSame('flac', $format, 'le FLAC est mesuré plutôt que le MP3');
        $this->assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM storage_objects WHERE kind = 'audio' AND content_sha256 IS NULL"));
    }

    public function testFichierIllisibleEnEchecPuisAbandonne(): void
    {
        $this->audio('Test - Casse/01 - Casse.mp3', ['album' => 'Casse', 'artist' => 'Test', 'title' => 'Casse']);
        $this->index();
        // Le fichier est abîmé après l'indexation (même taille : l'indexeur ne le verrait qu'à la date)
        $path = "$this->root/Test - Casse/01 - Casse.mp3";
        file_put_contents($path, str_repeat("\0", filesize($path)));

        for ($i = 1; $i <= Analyzer::MAX_ATTEMPTS; $i++) {
            $stats = $this->analyze();
            $this->assertSame(1, $stats['errors'] ?? 0, "essai $i en erreur");
        }
        $q = $this->db->one('SELECT attempts, last_error, locked_by FROM analysis_queue');
        $this->assertSame(Analyzer::MAX_ATTEMPTS, (int) $q['attempts']);
        $this->assertTrue($q['last_error'] !== null && $q['locked_by'] === null, 'erreur notée, verrou rendu');
        $this->assertSame(0, $this->analyze()['errors'] ?? 0, 'abandonnée : plus reprise');
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM job_errors WHERE code = 'analysis_failed'"), 'erreur ouverte : pas de doublon');

        // --retry la remet en file
        $analyzer = new Analyzer($this->db, $this->storage, $this->logger, "$this->base/cache/tmp", 2);
        $this->assertSame(1, $analyzer->retryFailed());
    }

    public function testVerrouRespecteEtRepriseApresArret(): void
    {
        $this->audio('Test - Verrou/01 - Un.flac', ['album' => 'Verrou', 'artist' => 'Test', 'title' => 'Un']);
        $this->index();
        // Piste prise par un autre analyseur : on n'y touche pas
        $this->db->run("UPDATE analysis_queue SET locked_by = 'autre:1', locked_until = CURRENT_TIMESTAMP(3) + INTERVAL 5 MINUTE");
        $this->assertSame(0, $this->analyze()['analyzed'] ?? 0, 'piste verrouillée ailleurs : pas reprise');
        // Verrou expiré (analyseur arrêté brutalement) : la piste est reprise
        $this->db->run('UPDATE analysis_queue SET locked_until = CURRENT_TIMESTAMP(3) - INTERVAL 1 SECOND');
        $this->assertSame(1, $this->analyze()['analyzed'] ?? 0, 'verrou expiré : reprise');
    }

    /** Processus de cette machine qui n'existe pas (au-delà du pid_max de Linux). */
    private static function deadWorker(): string
    {
        return gethostname() . ':4194999';
    }

    public function testPassageTueRepris(): void
    {
        foreach (['Un', 'Deux', 'Trois'] as $i => $t) {
            $this->audio("Test - Tue/0$i - $t.flac", ['album' => 'Tue', 'artist' => 'Test', 'title' => $t]);
        }
        $this->index();
        // Passage tué (manque de mémoire) pendant l'analyse de Un et Deux : verrou encore valide, tâche « en cours »
        $this->db->run("UPDATE analysis_queue q JOIN tracks t ON t.id = q.track_id SET q.locked_by = ?, q.attempts = 1,
                               q.locked_until = CURRENT_TIMESTAMP(3) + INTERVAL 30 MINUTE WHERE t.title IN ('Un', 'Deux')", [self::deadWorker()]);
        $this->db->run("INSERT INTO jobs (type, status, started_at, worker) VALUES ('analyze', 'running', CURRENT_TIMESTAMP(3), ?)", [self::deadWorker()]);
        $ghost = (int) $this->db->pdo()->lastInsertId();
        $this->db->run("INSERT INTO jobs (type, status, started_at, worker) VALUES ('index', 'running', CURRENT_TIMESTAMP(3), 'autre-machine:1')");
        $elsewhere = (int) $this->db->pdo()->lastInsertId();

        $stats = $this->analyze();
        $this->assertSame([3, 0], [$stats['analyzed'] ?? 0, $stats['errors'] ?? 0], 'pistes rendues tout de suite et analysées');
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM analysis_queue WHERE (steps & ?) <> 0', [Analyzer::STEPS_DONE]));
        $this->assertSame('failed', $this->db->value('SELECT status FROM jobs WHERE id = ?', [$ghost]), 'tâche fantôme clôturée');
        $this->assertSame('running', $this->db->value('SELECT status FROM jobs WHERE id = ?', [$elsewhere]), 'autre machine : pas vérifiable, pas touchée');
        $this->assertTrue(str_contains((string) file_get_contents("$this->base/test.log"), 'Analyse interrompue'));
    }

    public function testProcessusTueSignale(): void
    {
        // Un « Essentia » qui se fait tuer comme par le manque de mémoire : seule sa piste est en erreur
        $this->audio('Test - Signal/01 - Un.flac', ['album' => 'Signal', 'artist' => 'Test', 'title' => 'Un']);
        $this->index();
        file_put_contents("$this->base/tue.sh", "kill -9 \$\$\n");
        $rhythm = new Rhythm('/bin/sh', "$this->base/tue.sh");
        $stats = (new Analyzer($this->db, $this->storage, $this->logger, "$this->base/cache/tmp", 2, 900, $rhythm))->run();
        $this->assertSame(1, $stats['errors'] ?? 0);
        $msg = (string) $this->db->value('SELECT last_error FROM analysis_queue');
        $this->assertTrue(str_contains($msg, 'signal 9') && str_contains($msg, 'mémoire'), $msg);
    }

    public function testErreursSansDoublonEtCloturees(): void
    {
        // Un fichier illisible n'est signalé qu'une fois, quel que soit le nombre de scans
        @mkdir("$this->root/Test - Abime", 0755, true);
        file_put_contents("$this->root/Test - Abime/01 - Abime.mp3", random_bytes(4096));
        $this->index();
        $this->index();
        $open = fn (): int => (int) $this->db->value("SELECT COUNT(*) FROM job_errors WHERE code = 'probe_failed' AND resolved_at IS NULL");
        $this->assertSame([1, 1], [$open(), (int) $this->db->value('SELECT COUNT(*) FROM job_errors')]);
        // Le fichier disparaît : l'erreur se clôt
        unlink("$this->root/Test - Abime/01 - Abime.mp3");
        $this->index();
        $this->assertSame(0, $open());
        // Fichier remplacé par un bon : relu, erreur close
        file_put_contents("$this->root/Test - Abime/01 - Abime.mp3", random_bytes(4096));
        $this->index();
        $this->assertSame(1, $open(), 'nouvelle erreur après résolution');
        unlink("$this->root/Test - Abime/01 - Abime.mp3");
        $this->audio('Test - Abime/01 - Abime.mp3', ['album' => 'Abime', 'artist' => 'Test', 'title' => 'Abime']);
        $this->index();
        $this->assertSame(0, $open(), 'relu sans erreur : close');
        $this->assertSame(0, JobLog::resolve($this->db, null, null));
    }

    public function testPisteLongueAnalyseeParExtraits(): void
    {
        $rhythm = Rhythm::fromConfig(Config::load());
        if ($rhythm === null) {
            $this->skip('Essentia absent (/opt/rengaine-analyse)');
        }
        // 21 min 10 s (au-delà de 20 min) : un battement toutes les 0,5 s sur une note tenue
        // Aussi en MP3 à débit variable sans en-tête Xing : le fichier annonce une durée fausse (ici 3 min 30),
        // il ne faut pas s'y fier et charger tout le morceau (8 Go pour un mix de 3 h en production)
        $expr = '0.3*sin(2*PI*220*t)+0.8*exp(-30*mod(t\\,0.5))*sin(2*PI*60*t)';
        foreach (['Set' => '-c:a flac', 'Mix' => '-ac 2 -ar 44100 -c:a libmp3lame -q:a 9 -write_xing 0'] as $title => $codec) {
            $path = "$this->root/Test - $title/01 - $title." . ($title === 'Set' ? 'flac' : 'mp3');
            mkdir(dirname($path), 0755, true);
            exec('ffmpeg -v error -y -f lavfi -i ' . escapeshellarg("aevalsrc='$expr':s=22050:d=1270") . " -metadata album=$title -metadata artist=Test -metadata title=$title $codec " . escapeshellarg($path), $out, $code);
            $this->assertSame(0, $code, 'ffmpeg');
        }
        $this->index();
        $stats = (new Analyzer($this->db, $this->storage, $this->logger, "$this->base/cache/tmp", 1, 900, $rhythm))->run();
        $this->assertSame([2, 0], [$stats['analyzed'] ?? 0, $stats['errors'] ?? 0]);
        foreach (['Set', 'Mix'] as $title) {
            $a = $this->analysis($title);
            $this->assertTrue(abs((float) $a['bpm'] - 120) < 1, "$title, BPM : {$a['bpm']}");
            $grid = json_decode((string) $a['beat_grid'], true);
            foreach (['intro', 'outro'] as $end) {
                $this->assertTrue(is_array($grid[$end] ?? null) && abs($grid[$end]['bpm'] - 120) < 0.5, "$title, grille $end : " . json_encode($grid));
            }
        }
    }

    public function testFileEcouteeEtFavorisAnalysesDAbord(): void
    {
        foreach (['Un', 'Deux', 'Trois', 'Quatre', 'Cinq'] as $n => $title) {
            $this->audio('Test - Ordre/0' . ($n + 1) . " - $title.mp3", ['album' => 'Ordre', 'artist' => 'Test', 'album_artist' => 'Test', 'title' => $title, 'track' => (string) ($n + 1)], 0.5, 0.2);
        }
        $this->audio('Autre - Disque/01 - Six.mp3', ['album' => 'Disque', 'artist' => 'Autre', 'album_artist' => 'Autre', 'title' => 'Six'], 0.5, 0.2);
        $this->index();
        $id = fn (string $t): int => (int) $this->db->value('SELECT id FROM tracks WHERE title = ?', [$t]);
        $this->db->run("INSERT INTO users (email, display_name, status) VALUES ('a@exemple.fr', 'A', 'active')");
        $user = (int) $this->db->pdo()->lastInsertId();

        // Cinq : dans la file de lecture ; Deux : favori ; Trois : dans une playlist ; Quatre : écoutée ;
        // Six : son artiste est en favori ; Un : rien.
        $this->db->run('INSERT INTO play_queues (user_id, track_ids) VALUES (?, ?)', [$user, json_encode([$id('Cinq')])]);
        $this->db->run("INSERT INTO favorites (user_id, item_type, item_id) VALUES (?, 'track', ?)", [$user, $id('Deux')]);
        $this->db->run("INSERT INTO playlists (user_id, name) VALUES (?, 'P')", [$user]);
        $this->db->run('INSERT INTO playlist_items (playlist_id, track_id, position) VALUES (?, ?, 0)', [(int) $this->db->pdo()->lastInsertId(), $id('Trois')]);
        $this->db->run('INSERT INTO play_history (user_id, track_id, started_at) VALUES (?, ?, CURRENT_TIMESTAMP(3))', [$user, $id('Quatre')]);
        $artist = (int) $this->db->value("SELECT album_artist_id FROM albums WHERE title = 'Disque'");
        $this->db->run("INSERT INTO favorites (user_id, item_type, item_id) VALUES (?, 'artist', ?)", [$user, $artist]);

        $analyzer = new Analyzer($this->db, $this->storage, $this->logger, "$this->base/cache/tmp", 1);
        $this->assertSame(5, $analyzer->prioritize(), 'cinq pistes avancées');
        $order = fn (): array => array_column($this->db->all(
            'SELECT t.title, q.priority FROM analysis_queue q JOIN tracks t ON t.id = q.track_id ORDER BY q.priority, t.title'
        ), 'priority', 'title');
        $this->assertSame(['Cinq' => 5, 'Deux' => 10, 'Six' => 10, 'Trois' => 10, 'Quatre' => 20, 'Un' => 100], $order());
        $this->assertSame(0, $analyzer->prioritize(), 'rien ne bouge au passage suivant');

        // Plus de favori : la piste reprend son rang ; une priorité posée à la main est gardée
        $this->db->run("DELETE FROM favorites WHERE item_type = 'track'");
        $this->db->run('UPDATE analysis_queue SET priority = 1 WHERE track_id = ?', [$id('Un')]);
        $analyzer->prioritize();
        $this->assertSame(['Un' => 1, 'Cinq' => 5, 'Six' => 10, 'Trois' => 10, 'Quatre' => 20, 'Deux' => 100], $order());

        // L'analyse suit cet ordre : avec une seule piste par passage, la première faite est « Un », puis « Cinq »
        $this->assertSame(1, $analyzer->run(1)['analyzed'] ?? 0);
        $this->assertSame(1, $analyzer->run(1)['analyzed'] ?? 0);
        $this->assertSame(['Cinq', 'Un'], array_column($this->db->all(
            'SELECT t.title FROM track_analysis ta JOIN tracks t ON t.id = ta.track_id ORDER BY t.title'
        ), 'title'));
    }

    /** 20 s d'un accord de la majeur avec un clic toutes les 0,5 s (120 BPM). */
    private function groove(string $key, array $tags): void
    {
        $path = "$this->root/$key";
        @mkdir(dirname($path), 0755, true);
        $expr = '0.12*(sin(2*PI*110*t)+sin(2*PI*220*t)+sin(2*PI*277.18*t)+sin(2*PI*329.63*t))+0.6*sin(2*PI*1500*t)*lt(mod(t\,0.5)\,0.015)';
        $cmd = ['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', "aevalsrc='$expr':d=20:s=44100"];
        foreach ($tags as $k => $v) {
            array_push($cmd, '-metadata', "$k=$v");
        }
        array_push($cmd, '-c:a', str_ends_with($key, '.flac') ? 'flac' : 'libmp3lame', $path);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $err = stream_get_contents($pipes[2]);
        if (proc_close($proc) !== 0) {
            $this->fail("ffmpeg n'a pas pu créer $key : $err");
        }
    }

    public function testBpmEtTonaliteTagDAbordPuisEssentia(): void
    {
        $rhythm = Rhythm::fromConfig(Config::load());
        if ($rhythm === null) {
            $this->skip('Essentia absent (/opt/rengaine-analyse)');
        }
        $this->groove('Test - Groove/01 - Mesure.flac', ['album' => 'Groove', 'artist' => 'Test', 'title' => 'Mesure', 'track' => '1']);
        $this->groove('Test - Groove/02 - Tag.mp3', ['album' => 'Groove', 'artist' => 'Test', 'title' => 'Tag', 'track' => '2', 'TBPM' => '128', 'TKEY' => 'Fm']);
        $this->index();
        $dj = fn (string $t): array => $this->db->one(
            'SELECT ta.bpm, ta.bpm_source, ta.musical_key, ta.camelot, ta.key_source FROM track_analysis ta JOIN tracks t ON t.id = ta.track_id WHERE t.title = ?', [$t]
        ) ?? [];

        // Sans Essentia : volume mesuré, tags repris, BPM et tonalité laissés en file
        $plain = new Analyzer($this->db, $this->storage, $this->logger, "$this->base/cache/tmp", 2);
        $this->assertSame(2, $plain->run()['analyzed'] ?? 0);
        $this->assertSame(['128.00', 'tag', 'Fm', '4A', 'tag'], array_values($dj('Tag')));
        $this->assertSame([null, null], [$dj('Mesure')['bpm'], $dj('Mesure')['musical_key']]);
        $this->assertSame(2, (int) $this->db->value('SELECT COUNT(*) FROM analysis_queue WHERE (steps & ?) <> 0', [Analyzer::STEPS_RHYTHM]));

        // Avec Essentia : seules les étapes restantes ; le tag garde la priorité sur l'analyse
        $loud = $this->db->value("SELECT loudness_lufs FROM track_analysis ta JOIN tracks t ON t.id = ta.track_id WHERE t.title = 'Mesure'");
        $this->db->run("UPDATE track_analysis SET loudness_lufs = -99 WHERE track_id = (SELECT id FROM tracks WHERE title = 'Mesure')");
        $full = new Analyzer($this->db, $this->storage, $this->logger, "$this->base/cache/tmp", 2, 900, $rhythm);
        $stats = $full->run();
        $this->assertSame([2, 2], [$stats['analyzed'] ?? 0, $stats['bpm_key'] ?? 0]);
        $m = $dj('Mesure');
        $this->assertTrue((float) $m['bpm'] > 115 && (float) $m['bpm'] < 125, "BPM mesuré : {$m['bpm']} (attendu ≈ 120)");
        $this->assertSame(['analysis', 'A', '11B', 'analysis'], [$m['bpm_source'], $m['musical_key'], $m['camelot'], $m['key_source']]);
        $e = $this->db->one("SELECT energy, danceability FROM track_analysis ta JOIN tracks t ON t.id = ta.track_id WHERE t.title = 'Mesure'");
        $this->assertTrue($e['energy'] !== null && (float) $e['energy'] > 0 && (float) $e['energy'] <= 1, "énergie : {$e['energy']}");
        $this->assertTrue($e['danceability'] !== null && (float) $e['danceability'] >= 0 && (float) $e['danceability'] <= 1, "dansabilité : {$e['danceability']}");
        $this->assertSame(['128.00', 'tag', 'Fm', '4A', 'tag'], array_values($dj('Tag')));
        // Grilles des temps : un clic toutes les 0,5 s, soit 120 BPM du début à la fin
        $grid = json_decode((string) $this->db->value("SELECT beat_grid FROM track_analysis ta JOIN tracks t ON t.id = ta.track_id WHERE t.title = 'Mesure'"), true);
        foreach (['intro', 'outro'] as $end) {
            $this->assertTrue(is_array($grid[$end] ?? null) && abs($grid[$end]['bpm'] - 120) < 0.5, "grille $end : " . json_encode($grid));
            $this->assertTrue($grid[$end]['offsetMs'] >= 0 && $grid[$end]['offsetMs'] < 500, "décalage $end dans le premier temps");
        }
        $this->assertSame('-99.00', (string) $this->db->value("SELECT loudness_lufs FROM track_analysis ta JOIN tracks t ON t.id = ta.track_id WHERE t.title = 'Mesure'"),
            'volume pas remesuré (déjà fait)');
        $this->assertNotSame('-99.00', (string) $loud);
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM analysis_queue WHERE (steps & ?) <> 0', [Analyzer::STEPS_DONE | Analyzer::STEPS_RHYTHM]),
            'plus rien à faire ici (reste l’empreinte acoustique, BAS-03)');

        // Une correction à la main n'est jamais remplacée par une nouvelle analyse
        $this->db->run("UPDATE track_analysis SET bpm = 60, bpm_source = 'manual' WHERE track_id = (SELECT id FROM tracks WHERE title = 'Mesure')");
        $this->db->run("UPDATE analysis_queue SET steps = steps | 12 WHERE track_id = (SELECT id FROM tracks WHERE title = 'Mesure')");
        $full->run();
        $this->assertSame(['60.00', 'manual', 'A'], [$dj('Mesure')['bpm'], $dj('Mesure')['bpm_source'], $dj('Mesure')['musical_key']]);
    }

    /** Son synthétique varié (Chromaprint a besoin de mouvement), 40 s. @param string $kind 'a' ou 'b' */
    private function music(string $key, array $tags, string $kind, float $skip = 0.0): void
    {
        $expr = $kind === 'a'
            ? '0.3*sin(2*PI*(220+80*sin(0.9*t))*t)+0.25*sin(2*PI*(660+300*sin(0.37*t))*t)*gt(sin(2*PI*2*t)\,0)+0.2*sin(2*PI*(1500+700*sin(0.13*t))*t)*lt(mod(t\,0.5)\,0.1)'
            : '0.3*sin(2*PI*(330+120*sin(0.55*t))*t)+0.25*sin(2*PI*(440+250*sin(0.21*t))*t)*gt(sin(2*PI*3*t)\,0)+0.2*sin(2*PI*(2500+500*sin(0.27*t))*t)*lt(mod(t\,0.375)\,0.08)';
        $path = "$this->root/$key";
        @mkdir(dirname($path), 0755, true);
        $cmd = ['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', "aevalsrc='$expr':d=40:s=44100", '-ss', (string) $skip];
        foreach ($tags as $k => $v) {
            array_push($cmd, '-metadata', "$k=$v");
        }
        array_push($cmd, ...(str_ends_with($key, '.flac') ? ['-c:a', 'flac'] : ['-af', 'volume=2dB', '-c:a', 'libmp3lame', '-b:a', '128k']));
        $cmd[] = $path;
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $err = stream_get_contents($pipes[2]);
        if (proc_close($proc) !== 0) {
            $this->fail("ffmpeg n'a pas pu créer $key : $err");
        }
    }

    public function testFichierAbimeSignale(): void
    {
        // Les messages lus (« [dec:flac …] Decoding error ») sont ceux de ffmpeg 7 et suivants
        if (preg_match('/^ffmpeg version n?(\d+)/', (string) shell_exec('ffmpeg -version 2>/dev/null'), $v) && (int) $v[1] < 7) {
            $this->skip("ffmpeg $v[1] : la détection des fichiers abîmés demande ffmpeg 7 ou plus récent");
        }
        // Deux copies du même FLAC de 40 s ; l'une abîmée au milieu (octets écrasés), comme les FLAC de « Tonight »
        $this->music('Groupe - Abime/01 - Sain.flac', ['album' => 'Abime', 'artist' => 'Groupe', 'title' => 'Sain', 'track' => '1'], 'a');
        $this->music('Groupe - Abime/02 - Abime.flac', ['album' => 'Abime', 'artist' => 'Groupe', 'title' => 'Abime', 'track' => '2'], 'a');
        $path = "$this->root/Groupe - Abime/02 - Abime.flac";
        $bytes = (string) file_get_contents($path);
        mt_srand(7);
        $at = intdiv(strlen($bytes), 2);
        for ($i = 0; $i < 3000; $i++) {
            $bytes[$at + $i] = chr(mt_rand(0, 255));
        }
        file_put_contents($path, $bytes);
        $this->index();
        $stats = $this->analyze();
        $this->assertSame([2, 1], [$stats['analyzed'] ?? 0, $stats['damaged'] ?? 0]);
        $this->assertSame('0', (string) $this->analysis('Sain')['decode_errors']);
        $bad = $this->analysis('Abime');
        $this->assertTrue((int) $bad['decode_errors'] >= 1, 'trames illisibles : ' . $bad['decode_errors']);
        // Vers le milieu du morceau (0:20), à quelques secondes près
        $this->assertTrue((bool) preg_match('/^à 0:(1[5-9]|2[0-5])\b.* : flac : /', (string) $bad['decode_detail']), (string) $bad['decode_detail']);
        $this->assertTrue($bad['loudness_lufs'] !== null, 'mesuré quand même');
    }

    public function testMemeEnregistrementDansDeuxAlbums(): void
    {
        $fingerprint = Fingerprint::fromConfig(Config::load());
        if ($fingerprint === null) {
            $this->skip('fpcalc absent (paquet libchromaprint-tools)');
        }
        // Le même morceau sous deux titres, dans deux albums ; l'un en MP3, plus fort, commencé 1,5 s plus loin
        $this->music('Groupe - Original/01 - Chanson.flac', ['album' => 'Original', 'artist' => 'Groupe', 'title' => 'Chanson', 'track' => '1'], 'a');
        $this->music('Divers - Compilation/07 - Chanson (version album).mp3', ['album' => 'Compilation', 'artist' => 'Le Groupe', 'title' => 'Chanson (version album)', 'track' => '7'], 'a', 1.5);
        $this->music('Groupe - Original/02 - Autre.flac', ['album' => 'Original', 'artist' => 'Groupe', 'title' => 'Autre', 'track' => '2'], 'b');
        $this->index();
        $stats = (new Analyzer($this->db, $this->storage, $this->logger, "$this->base/cache/tmp", 2, 900, null, $fingerprint))->run();
        $this->assertSame([3, 3], [$stats['analyzed'] ?? 0, $stats['fingerprints'] ?? 0]);

        $pairs = $this->db->all('SELECT ta.title AS a, tb.title AS b, s.score, s.offset_ms FROM same_recordings s
            JOIN tracks ta ON ta.id = s.track_a JOIN tracks tb ON tb.id = s.track_b');
        $this->assertSame(1, count($pairs), json_encode($pairs));
        $titles = [$pairs[0]['a'], $pairs[0]['b']];
        sort($titles);
        $this->assertSame(['Chanson', 'Chanson (version album)'], $titles);
        $this->assertTrue((float) $pairs[0]['score'] >= Fingerprint::SAME, 'ressemblance ' . $pairs[0]['score']);
        // offset_ms : début de b par rapport à a ; la copie commence 1,5 s plus loin dans le morceau
        $expected = $pairs[0]['b'] === 'Chanson' ? -1500 : 1500;
        $this->assertTrue(abs((int) $pairs[0]['offset_ms'] - $expected) <= 150, "décalage {$pairs[0]['offset_ms']} ms, attendu $expected");

        $same = new SameRecordings($this->db);
        $groups = $same->groups();
        $this->assertSame(1, count($groups));
        $this->assertSame(2, count($groups[0]['trackIds']));
        $this->assertSame(0, count($same->groups(0.999)), 'seuil plus haut que la ressemblance');
        // Recalcul complet : même résultat ; plus rien en file pour l'empreinte
        $this->assertSame(['tracks' => 3, 'pairs' => 1], $same->rebuild());
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM analysis_queue WHERE (steps & 32) <> 0'));
        // Une piste supprimée emporte ses paires et ses valeurs indexées
        $this->db->run("DELETE FROM tracks WHERE title = 'Chanson (version album)'");
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM same_recordings'));
    }

    /** MP3 court avec une pochette intégrée d'une couleur unie. */
    private function withPicture(string $key, array $tags, string $color): void
    {
        $pic = "$this->base/$color.jpg";
        if (!is_file($pic)) {
            exec('ffmpeg -v error -y -f lavfi -i ' . escapeshellarg("color=c=$color:s=64x64") . ' -frames:v 1 ' . escapeshellarg($pic), $o, $code);
        }
        $path = "$this->root/$key";
        @mkdir(dirname($path), 0755, true);
        $cmd = ['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=1', '-i', $pic, '-map', '0:a', '-map', '1:v',
            '-c:a', 'libmp3lame', '-c:v', 'copy', '-id3v2_version', '3', '-metadata:s:v', 'comment=Cover (front)'];
        foreach ($tags as $k => $v) {
            array_push($cmd, '-metadata', "$k=$v");
        }
        $cmd[] = $path;
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $err = stream_get_contents($pipes[2]);
        if (proc_close($proc) !== 0) {
            $this->fail("ffmpeg n'a pas pu créer $key : $err");
        }
    }

    public function testImagesPropresAuxPistes(): void
    {
        // Album : deux pistes avec la même pochette, une avec une autre
        foreach ([['Un', 'red'], ['Deux', 'red'], ['Trois', 'blue']] as $n => [$title, $color]) {
            $this->withPicture('Test - Pochettes/0' . ($n + 1) . " - $title.mp3", ['album' => 'Pochettes', 'artist' => 'Test', 'album_artist' => 'Test', 'title' => $title, 'track' => (string) ($n + 1)], $color);
        }
        // Compilation : toutes différentes
        foreach ([['Rouge', 'red'], ['Vert', 'green'], ['Bleu', 'blue']] as $n => [$title, $color]) {
            $this->withPicture('Divers - Compil/0' . ($n + 1) . " - $title.mp3", ['album' => 'Compil', 'artist' => "Artiste $n", 'album_artist' => 'Divers', 'title' => $title, 'track' => (string) ($n + 1)], $color);
        }
        $this->index();
        $stats = $this->analyze();
        $this->assertSame(4, $stats['track_images'] ?? 0, '1 + 3 pistes avec leur image');
        $own = fn (string $t): bool => $this->db->value('SELECT image_id FROM tracks WHERE title = ?', [$t]) !== null;
        $this->assertSame([false, false, true], [$own('Un'), $own('Deux'), $own('Trois')], 'seule la pochette différente');
        $this->assertSame([true, true, true], [$own('Rouge'), $own('Vert'), $own('Bleu')], 'compilation : chacune la sienne');
        $this->assertSame((int) $this->db->value("SELECT image_id FROM tracks WHERE title = 'Trois'"), (int) $this->db->value("SELECT image_id FROM tracks WHERE title = 'Bleu'"),
            'même image bleue : une seule ligne');
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM track_files WHERE has_picture AND picture_sha256 IS NULL'));

        // Copie du cache perdue : la pochette est extraite à nouveau du fichier audio
        $img = $this->db->one("SELECT i.source, so.storage_key, so.missing_since, LOWER(HEX(i.sha256)) AS hex FROM tracks t JOIN images i ON i.id = t.image_id
                                 JOIN storage_objects so ON so.id = i.object_id WHERE t.title = 'Vert'");
        foreach (glob("$this->base/cache/images/*/{$img['hex']}.*") ?: [] as $f) {
            unlink($f);
        }
        $bytes = (new Images($this->db, $this->storage, "$this->base/cache"))->original($img, $img['hex']);
        $this->assertSame($img['hex'], hash('sha256', (string) $bytes), 'mêmes octets');
        $this->assertSame(1, count(glob("$this->base/cache/images/*/{$img['hex']}.*") ?: []), 'copie remise en cache');
    }
}

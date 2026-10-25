<?php
// MP3 en double (LEC-05) sur de vrais fichiers audio (ffmpeg), une base jetable (rengaine_test) et un
// stockage disque temporaire : verdict piste par piste, retrait dans la corbeille, restauration.

declare(strict_types=1);

namespace Musique\Tests;

use Musique\Config;
use Musique\Db\Database;
use Musique\Db\Migrator;
use Musique\Library\AlbumBuilder;
use Musique\Library\Duplicates;
use Musique\Library\Genres;
use Musique\Library\Images;
use Musique\Library\Indexer;
use Musique\Library\Probe;
use Musique\Log\Logger;
use Musique\Storage\DiskStorage;

final class DuplicatesTest extends TestCase
{
    private const ALBUM = 'Groupe - Disque';

    private Database $db;
    private DiskStorage $storage;
    private string $root;
    private string $work;
    private Logger $logger;

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

        $base = sys_get_temp_dir() . '/musique-doublons-' . bin2hex(random_bytes(4));
        $this->root = "$base/stockage";
        $this->work = "$base/travail";
        mkdir($this->root, 0755, true);
        mkdir($this->work, 0755, true);
        $this->storage = new DiskStorage($this->root);
        $this->logger = new Logger($this->work . '/test.log', 'debug');

        // Même rangement que l'ancienne bibliothèque : MP3 à la racine de l'album, FLAC dans flac/
        $t = ['artist' => 'Groupe', 'album_artist' => 'Groupe', 'album' => 'Disque', 'date' => '2009'];
        $this->audio(self::ALBUM . '/01 - Un.mp3', 2, $t + ['title' => 'Un', 'track' => '1']);
        $this->audio(self::ALBUM . '/flac/01 - Un.flac', 2, $t + ['title' => 'Un', 'track' => '1']);
        $this->audio(self::ALBUM . '/02 - Deux.mp3', 6, $t + ['title' => 'Deux', 'track' => '2']); // autre version : 4 s d'écart
        $this->audio(self::ALBUM . '/flac/02 - Deux.flac', 2, $t + ['title' => 'Deux', 'track' => '2']);
        $this->audio(self::ALBUM . '/03 - Trois.mp3', 2, $t + ['title' => 'Trois', 'track' => '3']); // MP3 seul
        $this->index();
    }

    public function tearDown(): void
    {
        if (isset($this->root)) {
            exec('rm -rf ' . escapeshellarg(dirname($this->root)));
        }
    }

    /** @param array<string, string> $tags */
    private function audio(string $key, int $seconds, array $tags): void
    {
        $path = $this->root . '/' . $key;
        @mkdir(dirname($path), 0755, true);
        $cmd = ['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', "sine=frequency=440:duration=$seconds"];
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
        (new Indexer($this->db, $this->storage, $this->logger, new Probe(4),
            new AlbumBuilder($this->db, $genres, new Images($this->db, $this->storage, $this->work)), $genres))->run();
    }

    private function dup(): Duplicates
    {
        return new Duplicates($this->db, $this->storage, $this->logger);
    }

    /** @return list<string> */
    private function formats(string $title): array
    {
        $list = array_column($this->db->all(
            'SELECT tf.format FROM track_files tf JOIN tracks t ON t.id = tf.track_id WHERE t.title = ?', [$title]), 'format');
        sort($list);
        return $list;
    }

    public function testVerdictPisteParPiste(): void
    {
        $this->assertSame(['flac', 'mp3'], $this->formats('Un'), 'MP3 et FLAC rattachés à la même piste');
        $files = $this->dup()->files();
        $this->assertSame(['Un', 'Deux'], array_column($files, 'title'), 'la piste en MP3 seul n’est pas concernée');
        $this->assertTrue($files[0]['removable']);
        $this->assertFalse($files[1]['removable'], 'durées trop différentes : gardé');
        $this->assertStringContainsString('durées différentes (4,0 s)', (string) $files[1]['reason']);

        $albums = $this->dup()->albums();
        $this->assertSame(1, count($albums));
        $this->assertSame(['title' => 'Disque', 'artist' => 'Groupe', 'removable' => 1, 'kept' => 1],
            array_intersect_key($albums[0], array_flip(['title', 'artist', 'removable', 'kept'])));
        $this->assertSame(filesize($this->root . '/' . self::ALBUM . '/01 - Un.mp3'), $albums[0]['removableBytes']);

        // Fichier sans perte disparu du stockage : le MP3 redevient le seul, on le garde
        $this->db->run("UPDATE storage_objects SET missing_since = NOW() WHERE storage_key LIKE '%/flac/01 - Un.flac'");
        $this->assertSame('fichier sans perte absent du stockage', $this->dup()->files()[0]['reason']);
    }

    public function testRetraitDansLaCorbeillePuisRestauration(): void
    {
        $albumId = (int) $this->db->value('SELECT id FROM albums WHERE title = ?', ['Disque']);
        $res = $this->dup()->remove($albumId, null);
        $day = gmdate('Y-m-d');
        $this->assertSame(['moved' => 1, 'kept' => 1, 'failed' => 0, 'trash' => "corbeille/$day/"], array_diff_key($res, ['bytes' => 0]));
        $this->assertFalse(is_file($this->root . '/' . self::ALBUM . '/01 - Un.mp3'), 'plus à sa place');
        $this->assertTrue(is_file($this->root . "/corbeille/$day/" . self::ALBUM . '/01 - Un.mp3'), 'dans la corbeille, même chemin');
        $this->assertTrue(is_file($this->root . '/' . self::ALBUM . '/02 - Deux.mp3'), 'le MP3 gardé ne bouge pas');
        $this->assertSame(['flac'], $this->formats('Un'));
        $this->assertSame([], array_values(array_filter($this->dup()->files($albumId), static fn ($f) => $f['removable'])));
        $job = $this->db->one("SELECT status, stats FROM jobs WHERE type = 'dedup'");
        $this->assertSame('done', $job['status']);
        $audit = $this->db->one("SELECT after_value FROM audit_log WHERE action = 'library.dedup'") ?? $this->fail('pas de trace dans le journal');
        $this->assertSame([self::ALBUM . '/01 - Un.mp3'], json_decode((string) $audit['after_value'], true)['keys']);

        // La corbeille n'est pas indexée
        $this->index();
        $this->assertSame(['flac'], $this->formats('Un'));
        $this->assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM storage_objects WHERE storage_key LIKE 'corbeille/%'"));
        $this->assertSame([$day => ['files' => 1, 'bytes' => $res['bytes']]], $this->dup()->trash());

        // Vider : rien sans confirmation, rien de trop récent
        $this->assertSame(1, $this->dup()->emptyTrash(-1, false)['files']);
        $this->assertTrue(is_file($this->root . "/corbeille/$day/" . self::ALBUM . '/01 - Un.mp3'), 'compté, pas supprimé');
        $this->assertSame([], $this->dup()->emptyTrash(7, true)['days'], 'trop récent');

        // Restauration puis indexation : comme avant
        $back = $this->dup()->restore($day, self::ALBUM . '/');
        $this->assertSame(['restored' => 1, 'skipped' => []], $back);
        $this->index();
        $this->assertSame(['flac', 'mp3'], $this->formats('Un'));
        $this->assertSame([], $this->dup()->trash());
    }
}

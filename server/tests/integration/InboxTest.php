<?php
// Rangement du dépôt (CAT-05) sur de vrais fichiers audio (ffmpeg), une base jetable (rengaine_test)
// et un stockage disque temporaire. Le déplacement en mode S3 est couvert par StorageContract.

declare(strict_types=1);

namespace Musique\Tests;

use Musique\Config;
use Musique\Db\Database;
use Musique\Db\Migrator;
use Musique\Library\AlbumBuilder;
use Musique\Library\Genres;
use Musique\Library\Images;
use Musique\Library\Inbox;
use Musique\Library\Indexer;
use Musique\Library\Probe;
use Musique\Log\Logger;
use Musique\Storage\DiskStorage;

final class InboxTest extends TestCase
{
    private Database $db;
    private DiskStorage $storage;
    private string $root;
    private string $work;

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

        $base = sys_get_temp_dir() . '/musique-depot-' . bin2hex(random_bytes(4));
        $this->root = "$base/stockage";
        $this->work = "$base/travail";
        mkdir($this->root . '/inbox', 0755, true);
        mkdir($this->work, 0755, true);
        $this->storage = new DiskStorage($this->root);
    }

    public function tearDown(): void
    {
        if (isset($this->root)) {
            exec('rm -rf ' . escapeshellarg(dirname($this->root)));
        }
    }

    // ------------------------------------------------------------------ outils

    /** @param array<string, string> $tags */
    private function audio(string $key, array $tags = []): void
    {
        $path = $this->root . '/' . $key;
        @mkdir(dirname($path), 0755, true);
        $ext = pathinfo($key, PATHINFO_EXTENSION);
        $cmd = ['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=1'];
        foreach ($tags as $k => $v) {
            array_push($cmd, '-metadata', "$k=$v");
        }
        array_push($cmd, '-c:a', $ext === 'flac' ? 'flac' : 'libmp3lame', $path);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $err = stream_get_contents($pipes[2]);
        if (proc_close($proc) !== 0) {
            $this->fail("ffmpeg n'a pas pu créer $key : $err");
        }
    }

    private function file(string $key, string $content = 'x'): void
    {
        @mkdir(dirname($this->root . '/' . $key), 0755, true);
        file_put_contents($this->root . '/' . $key, $content);
    }

    /** @return array{stats: array<string, int>, filed: list<string>, plans: list<array<string, mixed>>} */
    private function run(int $wait = 0, int $incompleteHours = 24, bool $dry = false): array
    {
        $inbox = new Inbox($this->db, $this->storage, new Logger($this->work . '/test.log', 'debug'), new Probe(4), $wait, $incompleteHours);
        return $inbox->run(null, null, null, $dry);
    }

    /** Clés du stockage, hors dépôt. @return list<string> */
    private function library(): array
    {
        $keys = [];
        foreach ($this->storage->list('') as $o) {
            if (!Inbox::contains($o->key)) {
                $keys[] = $o->key;
            }
        }
        return $keys;
    }

    /** @return list<string> */
    private function inbox(): array
    {
        return array_map(static fn ($o) => $o->key, iterator_to_array($this->storage->list('inbox/'), false));
    }

    private function item(string $dir): array
    {
        return $this->db->one('SELECT * FROM inbox_items WHERE source_dir = ?', [$dir]) ?? $this->fail("pas d'état pour $dir");
    }

    private function jobs(): int
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM jobs WHERE type = 'inbox'");
    }

    // ------------------------------------------------------------------ tests

    public function testAlbumRangeSousUnNomLisiblePuisIndexe(): void
    {
        $t = ['album' => 'Tonight', 'artist' => 'Franz Ferdinand', 'album_artist' => 'Franz Ferdinand', 'date' => '2009-01-26'];
        $this->audio('inbox/téléchargé/piste1.mp3', $t + ['title' => 'Ulysses', 'track' => '1/3']);
        $this->audio('inbox/téléchargé/piste2.mp3', $t + ['title' => 'Turn It On', 'track' => '2/3']);
        $this->audio('inbox/téléchargé/piste3.mp3', $t + ['title' => 'No You Girls: live?', 'track' => '3/3']);
        $this->file('inbox/téléchargé/cover.jpg');
        $this->file('inbox/téléchargé/Scans/livret.pdf');

        $plan = $this->run(dry: true);
        $this->assertSame('ready', $plan['plans'][0]['status'], 'essai : prêt à ranger');
        $this->assertSame(5, count($this->inbox()), 'essai : rien déplacé');
        $this->assertSame(0, $this->jobs(), 'essai : aucun passage enregistré');

        $res = $this->run();
        $dir = 'Franz Ferdinand/Tonight (2009)';
        $this->assertSame([$dir], $res['filed']);
        $this->assertSame([
            "$dir/01 - Ulysses.mp3",
            "$dir/02 - Turn It On.mp3",
            "$dir/03 - No You Girls- live-.mp3",
            "$dir/Scans/livret.pdf",
            "$dir/cover.jpg",
        ], $this->library(), json_encode($this->library(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->assertSame([], $this->inbox(), 'dépôt vidé');
        $this->assertTrue(is_dir($this->root . '/inbox'), 'le dossier de dépôt reste');
        $item = $this->item('inbox/téléchargé');
        $this->assertSame('filed', $item['status']);
        $this->assertSame($dir, $item['target_dir']);
        $this->assertTrue($item['filed_at'] !== null);
        $this->assertSame(1, $this->jobs(), 'un passage enregistré');
        $this->assertSame(5, (int) json_decode((string) $this->db->value("SELECT stats FROM jobs WHERE type = 'inbox'"), true)['files_moved']);

        // L'indexeur range l'album dans le catalogue et ignore le dépôt
        $this->audio('inbox/en cours/a.mp3', $t + ['title' => 'Pas encore']);
        $genres = new Genres($this->db);
        (new Indexer($this->db, $this->storage, new Logger($this->work . '/test.log', 'debug'), new Probe(4),
            new AlbumBuilder($this->db, $genres, new Images($this->db, $this->storage, $this->work)), $genres))->run();
        $album = $this->db->one('SELECT * FROM albums WHERE source_dir = ?', [$dir]) ?? $this->fail('album non indexé');
        $this->assertSame('Tonight', $album['title']);
        $this->assertSame(3, $album['track_count']);
        $this->assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM storage_objects WHERE storage_key LIKE 'inbox/%'"), 'dépôt non indexé');

        // Dépôt de nouveau vide : le passage suivant n'enregistre rien
        exec('rm -rf ' . escapeshellarg($this->root . '/inbox/en cours'));
        $this->assertSame([], $this->run()['plans']);
        $this->assertSame(1, $this->jobs());
    }

    public function testAttenteTantQueLeDossierBouge(): void
    {
        $this->audio('inbox/Artiste - Album/01.mp3', ['album' => 'Album', 'artist' => 'Artiste', 'title' => 'Un']);
        $res = $this->run(wait: 10);
        $this->assertSame('waiting', $res['plans'][0]['status'], 'modifié il y a moins de 10 min');
        $this->assertSame([], $this->library());
        $this->assertSame('waiting', $this->item('inbox/Artiste - Album')['status']);

        // Copie en cours (fichier .part de Nextcloud ou rclone) : on attend même passé le délai
        $this->file('inbox/Artiste - Album/02.flac.part');
        $this->assertSame('Copie en cours', $this->run()['plans'][0]['message']);
        unlink($this->root . '/inbox/Artiste - Album/02.flac.part');
        $this->assertSame(['Artiste/Album'], $this->run()['filed'], 'rangé une fois la copie finie');
        $this->assertSame(['Artiste/Album/Un.mp3'], $this->library(), 'sans numéro de piste : le titre seul');
    }

    public function testAlbumIncompletAttendPuisEstRange(): void
    {
        $t = ['album' => 'Long', 'artist' => 'Groupe', 'date' => '2001'];
        $this->audio('inbox/Long/01.mp3', $t + ['title' => 'Un', 'track' => '1/12']);
        $this->audio('inbox/Long/02.mp3', $t + ['title' => 'Deux', 'track' => '2/12']);
        $res = $this->run();
        $this->assertSame('incomplete', $res['plans'][0]['status']);
        $this->assertSame('Pistes présentes : 2 sur 12', $res['plans'][0]['message']);
        $this->assertSame([], $this->library());

        $res = $this->run(incompleteHours: 0);
        $this->assertSame('filed', $res['plans'][0]['status'], 'rangé quand même après le délai');
        $this->assertTrue(str_contains((string) $this->item('inbox/Long')['message'], '2 sur 12'), 'avertissement gardé');
        $this->assertSame(['Groupe/Long (2001)/01 - Un.mp3', 'Groupe/Long (2001)/02 - Deux.mp3'], $this->library());
    }

    public function testCompilationSurDeuxDisques(): void
    {
        $t = ['album' => 'Hits', 'album_artist' => 'Various Artists', 'date' => '2020'];
        $this->audio('inbox/Hits/CD1/01.mp3', $t + ['artist' => 'Abba', 'title' => 'Waterloo', 'track' => '1', 'disc' => '1/2']);
        $this->audio('inbox/Hits/CD1/02.mp3', $t + ['artist' => 'Blur', 'title' => 'Song 2', 'track' => '2', 'disc' => '1/2']);
        $this->audio('inbox/Hits/CD2/01.mp3', $t + ['artist' => 'Cher', 'title' => 'Believe', 'track' => '1', 'disc' => '2/2']);
        $this->run();
        $this->assertSame([
            'Compilations/Hits (2020)/CD1/01 - Abba - Waterloo.mp3',
            'Compilations/Hits (2020)/CD1/02 - Blur - Song 2.mp3',
            'Compilations/Hits (2020)/CD2/01 - Cher - Believe.mp3',
        ], $this->library());
    }

    public function testCompilationSansArtisteMajoritaire(): void
    {
        // Un artiste par piste, aucun tag « Various » : compilation, pas l'artiste du nom de dossier
        $t = ['album' => 'NRJ Hits', 'date' => '2026'];
        $this->audio('inbox/Louane - NRJ Hits/01.mp3', $t + ['artist' => 'Louane', 'album_artist' => 'Louane', 'title' => 'Maman', 'track' => '1']);
        $this->audio('inbox/Louane - NRJ Hits/02.mp3', $t + ['artist' => 'Muse', 'album_artist' => 'Muse', 'title' => 'Uprising', 'track' => '2']);
        $this->audio('inbox/Louane - NRJ Hits/03.mp3', $t + ['artist' => 'Tayc', 'album_artist' => 'Tayc', 'title' => 'Le Temps', 'track' => '3']);
        $this->run();
        $this->assertSame([
            'Compilations/NRJ Hits (2026)/01 - Louane - Maman.mp3',
            'Compilations/NRJ Hits (2026)/02 - Muse - Uprising.mp3',
            'Compilations/NRJ Hits (2026)/03 - Tayc - Le Temps.mp3',
        ], $this->library());
    }

    public function testSelectionVaRangeeSousLeNomDuDossier(): void
    {
        // « VA - Nom » dont les pistes viennent d'albums différents : rangé tel quel en compilation
        $this->audio('inbox/VA - Pop Hits 18.09.2026/01 a.mp3', ['album' => 'Un', 'artist' => 'A', 'title' => 'a', 'date' => '2024', 'track' => '7', 'disc' => '2']);
        $this->audio('inbox/VA - Pop Hits 18.09.2026/02 b.mp3', ['album' => 'Deux', 'artist' => 'B', 'title' => 'b', 'date' => '2025']);
        $this->audio('inbox/VA - Pop Hits 18.09.2026/03 c.mp3', ['album' => 'Trois', 'artist' => 'C', 'title' => 'c', 'date' => '2025']);
        $this->run();
        $this->assertSame([
            'Compilations/Pop Hits 18.09.2026/01 - A - a.mp3',
            'Compilations/Pop Hits 18.09.2026/02 - B - b.mp3',
            'Compilations/Pop Hits 18.09.2026/03 - C - c.mp3',
        ], $this->library());
    }

    public function testCasDouteuxLaissesDansLeDepotEtSignalesUneFois(): void
    {
        // Pistes de plusieurs albums dans un même dossier
        $this->audio('inbox/vrac/a.mp3', ['album' => 'Un', 'artist' => 'A', 'title' => 'a']);
        $this->audio('inbox/vrac/b.mp3', ['album' => 'Deux', 'artist' => 'B', 'title' => 'b']);
        $this->audio('inbox/vrac/c.mp3', ['album' => 'Trois', 'artist' => 'C', 'title' => 'c']);
        $res = $this->run();
        $this->assertSame('blocked', $res['plans'][0]['status']);
        $this->assertTrue(str_contains((string) $res['plans'][0]['message'], 'plusieurs albums'));
        $this->assertSame(3, count($this->inbox()), 'rien déplacé');
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM job_errors WHERE code = 'inbox_blocked'"));

        // Rien n'a changé : pas de nouveau signalement ni de passage enregistré
        $jobs = $this->jobs();
        $this->assertSame('blocked', $this->run()['plans'][0]['status']);
        $this->assertSame($jobs, $this->jobs());
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM job_errors WHERE code = 'inbox_blocked'"));

        // Retiré du dépôt : plus suivi
        exec('rm -rf ' . escapeshellarg($this->root . '/inbox/vrac'));
        $this->run();
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM inbox_items'));
    }

    public function testJamaisDEcrasementMaisUnAlbumPeutEtreComplete(): void
    {
        $t = ['album' => 'Album', 'artist' => 'Artiste', 'title' => 'Un', 'track' => '1'];
        $this->audio('Artiste/Album/01 - Un.mp3', $t);
        $this->audio('inbox/encore/01.mp3', $t);
        $res = $this->run();
        $this->assertSame('blocked', $res['plans'][0]['status']);
        $this->assertTrue(str_contains((string) $res['plans'][0]['message'], 'Artiste/Album/01 - Un.mp3'));
        $this->assertSame(['inbox/encore/01.mp3'], $this->inbox(), 'original gardé dans le dépôt');

        // La piste qui manquait arrive : l'album est complété
        $this->audio('inbox/suite/02.mp3', ['title' => 'Deux', 'track' => '2'] + $t);
        $this->run();
        $this->assertSame(['Artiste/Album/01 - Un.mp3', 'Artiste/Album/02 - Deux.mp3'], $this->library());
    }

    public function testNomsSurs(): void
    {
        $this->assertSame('AC-DC', Inbox::segment('AC/DC'));
        $this->assertSame('Quoi- Vraiment-', Inbox::segment('Quoi? Vraiment?'));
        $this->assertSame('cache', Inbox::segment('..cache.'));
        $this->assertSame('Sans titre.mp3', Inbox::segment('  ', 'mp3'));
        $long = Inbox::segment(str_repeat('é', 200), 'flac');
        $this->assertTrue(strlen($long) <= 150 && mb_check_encoding($long, 'UTF-8') && str_ends_with($long, '.flac'), 'nom coupé proprement');
    }
}

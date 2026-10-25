<?php
// Tests de l'indexeur sur de vrais fichiers audio (fabriqués par ffmpeg), une base jetable
// (rengaine_test) et un stockage disque temporaire. Ne touche ni à la base `musique` ni à file/.

declare(strict_types=1);

namespace Musique\Tests;

use Musique\Config;
use Musique\Db\Database;
use Musique\Db\Migrator;
use Musique\Library\AlbumBuilder;
use Musique\Library\Genres;
use Musique\Library\Images;
use Musique\Library\Indexer;
use Musique\Library\Probe;
use Musique\Log\Logger;
use Musique\Storage\DiskStorage;

final class IndexerTest extends TestCase
{
    private Database $db;
    private DiskStorage $storage;
    private string $root;
    private string $work;
    private string $cache;

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
        // Base vide puis schéma complet, migrations comprises
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->db->all('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()') as $r) {
            $this->db->exec('DROP TABLE `' . $r['t'] . '`');
        }
        $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');
        (new Migrator($this->db, \Musique\REPO_DIR . '/db'))->migrate();
        $this->db->run("INSERT INTO users (id, email, display_name, role, status) VALUES (1, 'admin@test', 'Admin', 'admin', 'active')");

        $base = sys_get_temp_dir() . '/musique-idx-' . bin2hex(random_bytes(4));
        $this->root = "$base/stockage";
        $this->work = "$base/travail";
        $this->cache = "$base/cache";
        mkdir($this->root, 0755, true);
        mkdir($this->work, 0755, true);
        mkdir($this->cache, 0755, true);
        $this->storage = new DiskStorage($this->root);
    }

    public function tearDown(): void
    {
        if (isset($this->root)) {
            exec('rm -rf ' . escapeshellarg(dirname($this->root)));
        }
    }

    // ------------------------------------------------------------------ outils

    /** Fabrique un fichier audio d'une seconde avec ses tags (et une pochette intégrée si demandé). */
    private function audio(string $key, array $tags = [], bool $picture = false): void
    {
        $path = $this->root . '/' . $key;
        @mkdir(dirname($path), 0755, true);
        $ext = pathinfo($key, PATHINFO_EXTENSION);
        $cmd = ['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=1'];
        if ($picture) {
            $pic = $this->work . '/pic.jpg';
            if (!is_file($pic)) {
                exec('ffmpeg -v error -y -f lavfi -i color=c=red:s=64x64 -frames:v 1 ' . escapeshellarg($pic));
            }
            array_push($cmd, '-i', $pic, '-map', '0:a', '-map', '1:v', '-c:v', 'copy', '-disposition:v', 'attached_pic', '-id3v2_version', '3');
        }
        foreach ($tags as $k => $v) {
            array_push($cmd, '-metadata', "$k=$v");
        }
        array_push($cmd, '-c:a', $ext === 'flac' ? 'flac' : 'libmp3lame', $path);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $err = stream_get_contents($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0) {
            $this->fail("ffmpeg n'a pas pu créer $key : $err");
        }
    }

    private function image(string $key, int $size = 32): void
    {
        $path = $this->root . '/' . $key;
        @mkdir(dirname($path), 0755, true);
        exec('ffmpeg -v error -y -f lavfi -i color=c=blue:s=' . $size . 'x' . $size . ' -frames:v 1 -f image2 ' . escapeshellarg($path));
    }

    private function index(bool $force = false): array
    {
        $genres = new Genres($this->db);
        $logger = new Logger($this->work . '/test.log', 'debug');
        $indexer = new Indexer($this->db, $this->storage, $logger, new Probe(4),
            new AlbumBuilder($this->db, $genres, new Images($this->db, $this->storage, $this->cache)), $genres);
        return $indexer->run('', $force);
    }

    private function album(string $dir): array
    {
        return $this->db->one('SELECT * FROM albums WHERE source_dir = ?', [$dir]) ?? $this->fail("album absent : $dir");
    }

    // ------------------------------------------------------------------ tests

    public function testRegroupementMp3FlacEtVoteMajoritaire(): void
    {
        $base = ['album' => 'Album X', 'artist' => 'Artiste', 'album_artist' => 'Artiste', 'date' => '2001'];
        $this->audio('Artiste - Album X/01 - Un.mp3', $base + ['title' => 'Un', 'track' => '1']);
        $this->audio('Artiste - Album X/02 - Deux.mp3', $base + ['title' => 'Deux', 'track' => '2']);
        $this->audio('Artiste - Album X/03 - Trois.mp3', ['album' => 'Trois - Single', 'title' => 'Trois', 'track' => '1', 'artist' => 'Artiste', 'date' => '2003']);
        $this->audio('Artiste - Album X/flac/01 - Un.flac', $base + ['title' => 'Un', 'track' => '1']);
        $this->audio('Artiste - Album X/flac/02 - Deux.flac', $base + ['title' => 'Deux', 'track' => '2']);
        $this->image('Artiste - Album X/Cover.jpg.jpg', 48);
        $this->image('Artiste - Album X/scan-livret.jpg', 40);

        $stats = $this->index();
        $this->assertSame(0, $stats['errors'] ?? 0, 'aucune erreur');
        $a = $this->album('Artiste - Album X');
        $this->assertSame('Album X', $a['title'], 'titre majoritaire malgré la piste « Single »');
        $this->assertSame('album', $a['kind']);
        $this->assertSame(2001, $a['year']);
        $this->assertSame(3, $a['track_count'], 'MP3 et FLAC du même morceau = une piste');
        $this->assertSame(1, $a['has_lossless']);
        $this->assertSame('Artiste', $this->db->value('SELECT name FROM artists WHERE id = ?', [$a['album_artist_id']]));
        $this->assertSame(2, (int) $this->db->value("SELECT COUNT(*) FROM track_files tf JOIN tracks t ON t.id = tf.track_id WHERE t.album_id = ? AND t.title = 'Un'", [$a['id']]));
        $cover = $this->db->one('SELECT i.source, i.width, so.storage_key FROM images i JOIN storage_objects so ON so.id = i.object_id WHERE i.id = ?', [$a['cover_image_id']]);
        $this->assertSame('Artiste - Album X/Cover.jpg.jpg', $cover['storage_key'] ?? null, 'jaquette au nom conventionnel');
        $this->assertSame(3, (int) $this->db->value('SELECT COUNT(*) FROM analysis_queue'), 'analyse mise en file');
    }

    public function testDossierEnVracEtDossierSansTags(): void
    {
        $this->audio('vrac/a.mp3', ['album' => 'Premier', 'title' => 'A']);
        $this->audio('vrac/b.mp3', ['album' => 'Second', 'title' => 'B']);
        $this->audio('vrac/c.mp3', ['title' => 'C']);
        $this->audio('Groupe - Disque/01 - Le titre.mp3');
        $this->audio('Groupe - Disque/02 - Groupe - Autre titre.mp3');
        $this->index();

        $vrac = $this->album('vrac');
        $this->assertSame('loose', $vrac['kind'], 'pas d’album commun : en vrac');
        $this->assertSame('vrac', $vrac['title']);
        $this->assertSame(3, $vrac['track_count']);

        $d = $this->album('Groupe - Disque');
        $this->assertSame('album', $d['kind'], 'aucun tag : album tiré du nom du dossier');
        $this->assertSame('Disque', $d['title']);
        $this->assertSame('Groupe', $this->db->value('SELECT name FROM artists WHERE id = ?', [$d['album_artist_id']]));
        $titles = array_column($this->db->all('SELECT track_number, title FROM tracks WHERE album_id = ? ORDER BY track_number', [$d['id']]), 'title', 'track_number');
        $this->assertSame([1 => 'Le titre', 2 => 'Autre titre'], $titles, 'titres et numéros tirés des noms de fichiers');
    }

    public function testMultiDisques(): void
    {
        $this->audio('A - B/CD1/01 - Un.mp3', ['album' => 'B', 'title' => 'Un', 'track' => '1']);
        $this->audio('A - B/CD2/01 - Autre.mp3', ['album' => 'B', 'title' => 'Autre', 'track' => '1']);
        $this->index();
        $a = $this->album('A - B');
        $this->assertSame(2, $a['disc_count']);
        $this->assertSame(2, $a['track_count']);
        $this->assertSame([1, 2], array_map('intval', array_column($this->db->all('SELECT disc_number FROM tracks ORDER BY disc_number'), 'disc_number')));
    }

    public function testRenommageDisparitionEtRetour(): void
    {
        $tags = ['album' => 'R', 'artist' => 'X', 'title' => 'Chanson', 'track' => '4'];
        $this->audio('X - R/04 - Chanson.mp3', $tags);
        $this->audio('X - R/05 - Autre.mp3', ['album' => 'R', 'artist' => 'X', 'title' => 'Autre', 'track' => '5']);
        $this->index();
        $id = (int) $this->db->value("SELECT id FROM tracks WHERE title = 'Chanson'");
        $this->db->run("INSERT INTO favorites (user_id, item_type, item_id) VALUES (1, 'track', ?)", [$id]);

        // Renommage : nouvelle clé, ancienne disparue
        rename("{$this->root}/X - R/04 - Chanson.mp3", "{$this->root}/X - R/04 - Chanson (remaster).mp3");
        $this->index();
        $this->assertSame($id, (int) $this->db->value("SELECT id FROM tracks WHERE title = 'Chanson'"), 'même identifiant après renommage');
        $this->assertNull($this->db->value('SELECT missing_since FROM tracks WHERE id = ?', [$id]));
        $this->assertSame(2, (int) $this->db->value('SELECT COUNT(*) FROM tracks'));

        // Suppression : piste masquée, jamais supprimée (le favori reste)
        unlink("{$this->root}/X - R/04 - Chanson (remaster).mp3");
        $this->index();
        $this->assertTrue($this->db->value('SELECT missing_since FROM tracks WHERE id = ?', [$id]) !== null, 'piste marquée disparue');
        $this->assertSame(1, $this->album('X - R')['track_count']);
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM favorites'));

        // Retour du fichier : même piste, de nouveau visible
        $this->audio('X - R/04 - Chanson.mp3', $tags);
        $this->index();
        $this->assertSame($id, (int) $this->db->value("SELECT id FROM tracks WHERE title = 'Chanson'"));
        $this->assertNull($this->db->value('SELECT missing_since FROM tracks WHERE id = ?', [$id]));
        $this->assertSame(2, $this->album('X - R')['track_count']);
    }

    public function testCorrectionSurvitAuxScans(): void
    {
        $this->audio('Y - Z/01.mp3', ['album' => 'Z mal écrit', 'artist' => 'Y', 'title' => 'T', 'date' => '1999']);
        $this->index();
        $a = $this->album('Y - Z');
        $this->db->run("INSERT INTO corrections (entity_type, entity_id, field, value, previous_value, created_by) VALUES ('album', ?, 'title', '\"Z bien écrit\"', '\"Z mal écrit\"', 1)", [$a['id']]);
        $this->db->run("INSERT INTO corrections (entity_type, entity_id, field, value, previous_value, created_by) VALUES ('album', ?, 'year', '2000', '1999', 1)", [$a['id']]);
        $this->index(force: true);
        $a = $this->album('Y - Z');
        $this->assertSame('Z bien écrit', $a['title'], 'la correction survit à un scan complet');
        $this->assertSame(2000, $a['year']);

        $this->db->run("UPDATE corrections SET status = 'reverted', reverted_at = CURRENT_TIMESTAMP(3) WHERE field = 'title'");
        $this->index(force: true);
        $this->assertSame('Z mal écrit', $this->album('Y - Z')['title'], 'correction annulée : la valeur des tags revient');
    }

    public function testDossierDesTestsDuStockageIgnore(): void
    {
        $this->audio('_tests/0123456789/Artiste - Album/01 - Essai.mp3', ['title' => 'Essai', 'artist' => 'Artiste', 'album' => 'Album']);
        $this->audio('Vrai - Disque/01 - Un.mp3', ['title' => 'Un', 'artist' => 'Vrai', 'album' => 'Disque']);
        $this->index();
        $this->assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM storage_objects WHERE storage_key LIKE '\\_tests/%'"));
        $this->assertSame(['Un'], array_column($this->db->all('SELECT title FROM tracks'), 'title'));
    }

    public function testPassageSansChangement(): void
    {
        $this->audio('P - Q/01.mp3', ['album' => 'Q', 'title' => 'T']);
        $this->index();
        $stats = $this->index();
        $this->assertSame(0, $stats['probed'] ?? 0, 'rien à relire');
        $this->assertSame(0, $stats['albums_rebuilt'] ?? 0, 'aucun album à reconstruire');
        $this->assertSame(1, $stats['unchanged'] ?? 0);
    }

    public function testPochetteIntegree(): void
    {
        $this->audio('E - F/01.mp3', ['album' => 'F', 'title' => 'T'], picture: true);
        $this->index();
        $a = $this->album('E - F');
        $img = $this->db->one('SELECT source, width, LOWER(HEX(sha256)) AS sha, mime_type FROM images WHERE id = ?', [$a['cover_image_id']]);
        $this->assertSame('embedded', $img['source'] ?? null, 'pochette intégrée utilisée faute de fichier image');
        $this->assertSame(64, $img['width']);
        $this->assertTrue(is_file($this->cache . '/images/' . substr($img['sha'], 0, 2) . '/' . $img['sha'] . '.jpeg'), 'copie dans le cache');
    }

    public function testGenres(): void
    {
        // Album avec un meta.json en Windows-1252 (« Indé »), comme sur l'ancien site
        $this->audio('G - Indé/01.mp3', ['album' => 'Indé', 'title' => 'T', 'genre' => 'Pop']);
        file_put_contents($this->root . '/G - Indé/meta.json', "{\"genre\": \"Pop Fran\xE7aise\"}");
        // meta.json abîmé comme celui de Franz Ferdinand : le « é » remplacé par deux octets illisibles
        $this->audio('K - Abime/01.mp3', ['album' => 'Abime', 'title' => 'T', 'genre' => 'Alternatif et Indé']);
        file_put_contents($this->root . '/K - Abime/meta.json', "{\"genre\": \"Alternatif Et Ind\xFF\xFF\"}");
        // meta.json abîmé sans tag correspondant : signalé, aucun genre faux
        $this->audio('L - Perdu/01.mp3', ['album' => 'Perdu', 'title' => 'T']);
        file_put_contents($this->root . '/L - Perdu/meta.json', "{\"genre\": \"Vari\xFF\xFFt\xFF\"}");
        // Compilation : chaque piste garde son propre genre
        $this->audio('Compil/01.mp3', ['album' => 'Compil', 'album_artist' => 'Various Artists', 'artist' => 'A', 'title' => 'Un', 'genre' => 'Rock']);
        $this->audio('Compil/02.mp3', ['album' => 'Compil', 'album_artist' => 'Various Artists', 'artist' => 'B', 'title' => 'Deux', 'genre' => 'Pop']);
        // Valeur de tag inconnue : ne crée pas de genre
        $this->audio('H - Rap/01.mp3', ['album' => 'Rap', 'title' => 'T', 'genre' => 'Hip-Hop/Rap']);
        $this->index();
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM genres'), 'aucun genre créé par les tags');
        $this->assertSame(4, (int) $this->db->value('SELECT COUNT(*) FROM genre_aliases WHERE genre_id IS NULL'), 'valeurs notées, non rattachées');

        $genresOf = fn (string $dir) => array_column($this->db->all(
            'SELECT g.name FROM album_genres ag JOIN genres g ON g.id = ag.genre_id JOIN albums a ON a.id = ag.album_id WHERE a.source_dir = ? ORDER BY g.name', [$dir]), 'name');
        // Genres existants (comme après l'import) : les tags qui portent leur nom sont rattachés
        $this->db->run("INSERT INTO genres (name) VALUES ('Rock'), ('pop')");
        $errors = [];
        [$n, $nErr] = (new Genres($this->db))->importLegacy($this->storage, null, function (string $k, string $m) use (&$errors) { $errors[] = $k; });
        $this->assertSame(2, $n, 'deux meta.json importés');
        $this->assertSame(['L - Perdu/meta.json'], $errors, 'genre irréparable signalé');
        $this->assertSame(['Alternatif et Indé'], $genresOf('K - Abime'), 'genre abîmé réparé grâce au tag des pistes');
        $this->assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM genres WHERE name LIKE '%ÿ%' OR name LIKE 'Vari%'"), 'aucun genre faux créé');

        $this->assertSame(['Pop Française'], $genresOf('G - Indé'), 'meta.json prioritaire sur le tag Pop, Windows-1252 converti');
        $this->assertSame([], $genresOf('H - Rap'), 'valeur non rattachée : pas de genre');

        $trackGenres = array_column($this->db->all(
            "SELECT t.title, g.name FROM track_genres tg JOIN tracks t ON t.id = tg.track_id JOIN genres g ON g.id = tg.genre_id
              JOIN albums a ON a.id = t.album_id WHERE a.source_dir = 'Compil' ORDER BY t.title"), 'name', 'title');
        $this->assertSame(['Deux' => 'pop', 'Un' => 'Rock'], $trackGenres, 'compilation : genre propre à chaque piste');
        $this->assertSame('album', $this->db->value(
            "SELECT tg.source FROM track_genres tg JOIN tracks t ON t.id = tg.track_id JOIN albums a ON a.id = t.album_id WHERE a.source_dir = 'G - Indé'"),
            'album ordinaire : la piste hérite du genre de l’album');

        // L'admin rattache « Hip-Hop/Rap » à un genre : l'album le reçoit
        $this->db->run("INSERT INTO genres (name) VALUES ('Rap')");
        $this->db->run("UPDATE genre_aliases SET genre_id = (SELECT id FROM genres WHERE name = 'Rap') WHERE alias = 'Hip-Hop/Rap'");
        $g = new Genres($this->db);
        $g->refreshAlbum((int) $this->album('H - Rap')['id']);
        $this->assertSame(['Rap'], $genresOf('H - Rap'));
    }
}

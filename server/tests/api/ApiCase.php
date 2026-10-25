<?php
// Base des tests HTTP de l'API (API-08). Préparé une seule fois par lancement :
//   - base rengaine_test vidée, schéma, genres, comptes de chaque sorte ;
//   - petite bibliothèque fabriquée par ffmpeg, indexée et analysée (stockage disque temporaire) ;
//   - API lancée par le serveur intégré de PHP sur un port libre, avec la vraie config
//     surchargée par RENGAINE_CONFIG_OVERRIDES (autre base, autre stockage, journaux à part).
// Ne touche ni à la base `musique`, ni à file/, ni aux journaux de /var/log/rengaine.

declare(strict_types=1);

namespace Musique\Tests;

use Musique\Audio\Analyzer;
use Musique\Auth\Passwords;
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

require_once __DIR__ . '/Contract.php';

abstract class ApiCase extends TestCase
{
    public const PASSWORD = 'mot-de-passe-de-test';

    /** @var array<string, mixed>|null */
    protected static ?array $env = null;
    private static ?string $skipReason = null;
    /** @var array<string, array{proc: resource, url: string}> */
    private static array $servers = [];

    protected Database $db;

    public function setUp(): void
    {
        if (self::$skipReason !== null) {
            $this->skip(self::$skipReason);
        }
        if (self::$env !== null && !self::fixturesIntact()) {
            // Un autre test (indexeur, analyseur) a vidé rengaine_test entre-temps : on recommence
            foreach (self::$servers as $srv) {
                proc_terminate($srv['proc']);
                proc_close($srv['proc']);
            }
            self::$servers = [];
            self::$env = null;
        }
        if (self::$env === null) {
            try {
                self::prepare();
            } catch (Skipped $e) {
                self::$skipReason = $e->getMessage();
                throw $e;
            }
        }
        $this->db = self::$env['db'];
    }

    // ------------------------------------------------------------------ préparation

    private static function fixturesIntact(): bool
    {
        try {
            return (int) self::$env['db']->value('SELECT id FROM users WHERE email = ?', ['admin@test.fr']) === self::$env['users']['admin']['id'];
        } catch (\Throwable) {
            return false;
        }
    }

    private static function prepare(): void
    {
        if (!is_readable(Config::defaultPath()) && getenv('RENGAINE_CONFIG') === false) {
            throw new Skipped('configuration illisible (lancer en root)');
        }
        $dbName = getenv('RENGAINE_TEST_DB') ?: 'rengaine_test';
        $db = Database::fromConfig(Config::load()->with('base', 'nom', $dbName));
        try {
            $db->pdo();
        } catch (\Throwable $e) {
            throw new Skipped('base de test inaccessible : ' . $e->getMessage());
        }
        $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($db->all('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()') as $r) {
            $db->exec('DROP TABLE `' . $r['t'] . '`');
        }
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');
        (new Migrator($db, \Musique\REPO_DIR . '/db'))->migrate();

        $base = sys_get_temp_dir() . '/musique-api-' . bin2hex(random_bytes(4));
        foreach (['stockage', 'cache', 'journaux', 'travail', 'mails'] as $d) {
            mkdir("$base/$d", 0755, true);
        }
        register_shutdown_function(static function () use ($base): void {
            foreach (self::$servers as $s) {
                proc_terminate($s['proc']);
                proc_close($s['proc']);
            }
            self::$servers = [];
            exec('rm -rf ' . escapeshellarg($base));
        });

        // Bibliothèque : tri naturel (Album 2 avant Album 10), accents, jaquette en fichier et intégrée, FLAC
        $root = "$base/stockage";
        self::audio($root, 'Beyoncé - Lemonade/01 - Formation.mp3', ['title' => 'Formation', 'artist' => 'Beyoncé', 'album' => 'Lemonade', 'album_artist' => 'Beyoncé', 'track' => '1', 'date' => '2016', 'genre' => 'Pop']);
        self::audio($root, 'Beyoncé - Lemonade/02 - Forward.mp3', ['title' => 'Forward', 'artist' => 'Beyoncé; James Blake', 'album' => 'Lemonade', 'album_artist' => 'Beyoncé', 'track' => '2', 'date' => '2016', 'genre' => 'Pop']);
        self::image("$root/Beyoncé - Lemonade/cover.jpg", 'red', 800);
        self::audio($root, 'Groupe - Album 10/01 - Dix.mp3', ['title' => 'Dix', 'artist' => 'Groupe', 'album' => 'Album 10', 'track' => '1', 'date' => '2010', 'genre' => 'Rock'], "$base/travail/pochette.jpg");
        self::audio($root, 'Groupe - Album 2/01 - Deux.flac', ['title' => 'Deux', 'artist' => 'Groupe', 'album' => 'Album 2', 'track' => '1', 'date' => '2002', 'genre' => 'Rock']);
        self::audio($root, 'Groupe - Album 2/02 - Deux bis.flac', ['title' => 'Deux bis', 'artist' => 'Groupe', 'album' => 'Album 2', 'track' => '2', 'date' => '2002', 'genre' => 'Rock']);

        $db->run("INSERT INTO genres (name) VALUES ('Pop'), ('Rock')");
        $logger = new Logger("$base/journaux/test.log", 'debug');
        $storage = new DiskStorage($root);
        $genres = new Genres($db);
        (new Indexer($db, $storage, $logger, new Probe(4), new AlbumBuilder($db, $genres, new Images($db, $storage, "$base/cache")), $genres))->run();
        (new Analyzer($db, $storage, $logger, "$base/cache/tmp", 2))->run();

        // Comptes
        $hash = Passwords::hash(self::PASSWORD);
        $users = [];
        foreach ([
            ['admin', 'admin@test.fr', 'Admin', 'admin', 'active'],
            ['user', 'user@test.fr', 'Utilisateur', 'user', 'active'],
            ['user2', 'user2@test.fr', 'Second', 'user', 'active'],
            ['locked', 'verrou@test.fr', 'Verrou', 'user', 'active'],
            ['pending', 'attente@test.fr', 'Attente', 'user', 'pending'],
            ['suspended', 'suspendu@test.fr', 'Suspendu', 'user', 'suspended'],
            ['agent', 'agent@test.fr', 'Claude', 'agent', 'active'],
        ] as [$k, $email, $name, $role, $status]) {
            $db->run('INSERT INTO users (email, display_name, password_hash, role, status) VALUES (?, ?, ?, ?, ?)',
                [$email, $name, $role === 'agent' ? null : $hash, $role, $status]);
            $users[$k] = ['id' => (int) $db->pdo()->lastInsertId(), 'email' => $email];
        }
        $agentToken = (new \Musique\Auth\Tokens($db))->create($users['agent']['id'], 'agent', 'Claude', null, null);

        // Faux serveur SMTP : les mails des tests arrivent dans $base/mails, jamais dans MailCatcher
        $smtpPort = self::freePort();
        self::$servers['smtp'] = ['proc' => proc_open(
            [PHP_BINARY, __DIR__ . '/../smtp-capture.php', (string) $smtpPort, "$base/mails"],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', "$base/journaux/smtp.txt", 'a'], 2 => ['file', "$base/journaux/smtp.txt", 'a']], $pipes
        ), 'url' => ''];
        self::$env = [
            'db' => $db, 'base' => $base, 'root' => $root, 'dbName' => $dbName, 'users' => $users,
            'agentToken' => $agentToken['secret'], 'tokens' => [], 'smtpPort' => $smtpPort,
        ];
        self::waitPort($smtpPort);
        self::$env['url'] = self::server();
    }

    private static function audio(string $root, string $key, array $tags, ?string $picture = null): void
    {
        $path = "$root/$key";
        @mkdir(dirname($path), 0755, true);
        $cmd = ['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=2'];
        if ($picture !== null) {
            self::image($picture, 'blue', 400);
            array_push($cmd, '-i', $picture, '-map', '0:a', '-map', '1:v', '-c:v', 'copy', '-disposition:v', 'attached_pic', '-id3v2_version', '3');
        }
        foreach ($tags as $k => $v) {
            array_push($cmd, '-metadata', "$k=$v");
        }
        array_push($cmd, '-c:a', str_ends_with($key, '.flac') ? 'flac' : 'libmp3lame', $path);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $err = stream_get_contents($pipes[2]);
        if (proc_close($proc) !== 0) {
            throw new \RuntimeException("ffmpeg : $key : $err");
        }
    }

    private static function image(string $path, string $color, int $size): void
    {
        @mkdir(dirname($path), 0755, true);
        exec('ffmpeg -v error -y -f lavfi -i color=c=' . $color . ':s=' . $size . 'x' . $size . ' -frames:v 1 -f image2 ' . escapeshellarg($path));
    }

    /**
     * Lance (une fois par jeu de réglages) l'API sur un port libre ; renvoie son adresse.
     * @param array<string, array<string, mixed>> $limits réglages de [limites]
     */
    protected static function server(array $limits = []): string
    {
        $key = json_encode($limits);
        if (isset(self::$servers[$key])) {
            return self::$servers[$key]['url'];
        }
        $env = self::$env;
        $overrides = [
            'base' => ['nom' => $env['dbName']],
            'stockage' => ['mode' => 'disque', 'chemin' => $env['root']],
            'cache' => ['dossier' => $env['base'] . '/cache'],
            'journaux' => ['dossier' => $env['base'] . '/journaux', 'niveau' => 'debug'],
            'site' => ['url' => 'https://musique.test'],
            'smtp' => self::smtpConfig(),
            // Marche/arrêt du hub demandé par l'admin : un fichier à part (le vrai hub du dev reste en marche)
            'mercure' => ['fichier_etat' => $env['base'] . '/mercure-etat'],
            'limites' => $limits + ['requetes_par_minute' => 100000, 'requetes_par_minute_compte' => 100000, 'connexions_par_10min' => 1000,
                'demandes_compte_par_heure' => 1000, 'appairages_par_10min' => 1000, 'codes_appairage_par_heure' => 1000,
                'changements_mdp_par_10min' => 1000, 'oublis_mdp_par_heure' => 1000, 'recherches_par_minute' => 100000, 'jetons_invalides_par_10min' => 100000],
        ];
        $port = self::freePort();
        $proc = proc_open(
            [PHP_BINARY, '-d', 'apc.enable_cli=1', '-S', "127.0.0.1:$port", \Musique\SERVER_DIR . '/public/index.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $env['base'] . "/journaux/serveur-$port.txt", 'a'], 2 => ['file', $env['base'] . "/journaux/serveur-$port.txt", 'a']],
            $pipes, null,
            ['RENGAINE_CONFIG' => getenv('RENGAINE_CONFIG') ?: Config::defaultPath(), 'RENGAINE_CONFIG_OVERRIDES' => json_encode($overrides), 'PATH' => getenv('PATH')]
        );
        $url = "http://127.0.0.1:$port";
        self::$servers[$key] = ['proc' => $proc, 'url' => $url];
        self::waitPort($port);
        return $url;
    }

    protected static function freePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $name = (string) stream_socket_get_name($sock, false);
        fclose($sock);
        return (int) substr($name, strrpos($name, ':') + 1);
    }

    protected static function waitPort(int $port): void
    {
        for ($i = 0; $i < 50; $i++) {
            if (($s = @fsockopen('127.0.0.1', $port)) !== false) {
                fclose($s);
                return;
            }
            usleep(100_000);
        }
        throw new \RuntimeException("Le serveur de test (port $port) ne démarre pas");
    }

    /** Section [smtp] des tests : le faux serveur, sans chiffrement ni identifiants. */
    protected static function smtpConfig(): array
    {
        return ['hote' => '127.0.0.1', 'port' => self::$env['smtpPort'], 'chiffrement' => 'aucun', 'utilisateur' => '', 'mot_de_passe' => '',
            'expediteur' => 'musique@musique.test', 'admin' => ''];
    }

    /**
     * Mails reçus par le faux serveur SMTP, du plus ancien au plus récent (attend un peu ceux en route).
     * @return list<array{from: string, to: list<string>, auth: ?string, data: string}>
     */
    protected function mails(int $atLeast = 0): array
    {
        for ($i = 0; $i < 40; $i++) {
            $files = glob(self::$env['base'] . '/mails/*.json') ?: [];
            if (count($files) >= $atLeast) {
                break;
            }
            usleep(100_000);
        }
        sort($files);
        return array_map(static fn ($f) => json_decode((string) file_get_contents($f), true), $files);
    }

    protected function clearMails(): void
    {
        array_map('unlink', glob(self::$env['base'] . '/mails/*.json') ?: []);
    }

    /** Texte d'un mail reçu (quoted-printable décodé). */
    protected static function mailBody(array $mail): string
    {
        return quoted_printable_decode(substr($mail['data'], strpos($mail['data'], "\r\n\r\n") + 4));
    }

    /** Relance l'indexeur sur la bibliothèque de test (pour vérifier qu'une correction survit au scan). */
    protected function reindex(bool $force = true): array
    {
        $env = self::$env;
        $storage = new DiskStorage($env['root']);
        $genres = new Genres($env['db']);
        return (new Indexer($env['db'], $storage, new Logger($env['base'] . '/journaux/test.log', 'debug'), new Probe(4),
            new AlbumBuilder($env['db'], $genres, new Images($env['db'], $storage, $env['base'] . '/cache')), $genres))->run('', $force);
    }

    /** Config équivalente à celle du serveur de test (pour lancer une tâche dans le processus du test). */
    protected function testConfig(): Config
    {
        $env = self::$env;
        return Config::load()->with('base', 'nom', $env['dbName'])->with('stockage', 'mode', 'disque')->with('stockage', 'chemin', $env['root'])
            ->with('cache', 'dossier', $env['base'] . '/cache')->with('journaux', 'dossier', $env['base'] . '/journaux')
            ->with('smtp', 'hote', '127.0.0.1')->with('smtp', 'port', $env['smtpPort'])->with('smtp', 'chiffrement', 'aucun')
            ->with('smtp', 'utilisateur', '')->with('smtp', 'mot_de_passe', '')->with('smtp', 'expediteur', 'musique@musique.test')->with('smtp', 'admin', '')
            ->with('site', 'url', 'https://musique.test');
    }

    // ------------------------------------------------------------------ requêtes

    /**
     * @param array<string, string> $headers
     */
    protected function call(string $method, string $path, mixed $body = null, array $headers = [], ?string $base = null): ApiResponse
    {
        $ch = curl_init(($base ?? self::$env['url']) . $path);
        $h = [];
        foreach ($headers as $k => $v) {
            $h[] = "$k: $v";
        }
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
            if (!array_key_exists('Content-Type', $headers)) {
                $h[] = 'Content-Type: application/json';
            }
        }
        $respHeaders = [];
        $cookies = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders, &$cookies): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $name = strtolower(trim($parts[0]));
                    if ($name === 'set-cookie') {
                        $cookies[] = trim($parts[1]);
                    }
                    $respHeaders[$name] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($status === 0) {
            $this->fail('Pas de réponse du serveur : ' . curl_error($ch));
        }
        return new ApiResponse($status, $respHeaders, $cookies, $raw);
    }

    /** Appel avec un jeton Bearer. */
    protected function as(string $token, string $method, string $path, mixed $body = null, array $headers = []): ApiResponse
    {
        return $this->call($method, $path, $body, $headers + ['Authorization' => "Bearer $token"]);
    }

    /** Jeton d'appli (mis en cache par compte). */
    protected function token(string $who): string
    {
        if ($who === 'agent') {
            return self::$env['agentToken'];
        }
        if (!isset(self::$env['tokens'][$who])) {
            $r = $this->call('POST', '/api/v2/auth/login', ['email' => self::$env['users'][$who]['email'], 'password' => self::PASSWORD, 'client' => 'app', 'deviceName' => "Test $who"]);
            $this->assertSame(200, $r->status, "connexion de $who : " . $r->raw);
            self::$env['tokens'][$who] = $r->data()['token'];
        }
        return self::$env['tokens'][$who];
    }

    protected function userId(string $who): int
    {
        return self::$env['users'][$who]['id'];
    }

    /** La réponse suit le contrat (statut prévu, corps conforme au schéma, requestId cohérent). */
    protected function assertContract(ApiResponse $r, string $operationId, int $status): void
    {
        $this->assertSame($status, $r->status, "$operationId : statut (corps : " . mb_substr($r->raw, 0, 300) . ')');
        $this->assertTrue(isset($r->headers['x-request-id']), "$operationId : en-tête X-Request-Id");
        $schema = Contract::responseSchema($operationId, $status);
        if ($schema === null) {
            return;
        }
        $errors = Contract::validate($r->json(), $schema);
        $this->assertSame([], $errors, "$operationId $status : réponse hors contrat");
        if ($status >= 400) {
            $this->assertSame($r->headers['x-request-id'], $r->data()['error']['requestId'], 'requestId du corps = en-tête');
        }
    }

    protected function assertError(ApiResponse $r, int $status, string $code, string $operationId): void
    {
        $this->assertContract($r, $operationId, $status);
        $this->assertSame($code, $r->data()['error']['code'] ?? null, "code d'erreur (corps : " . mb_substr($r->raw, 0, 200) . ')');
    }
}

final class ApiResponse
{
    /**
     * @param array<string, string> $headers
     * @param list<string> $cookies
     */
    public function __construct(public readonly int $status, public readonly array $headers, public readonly array $cookies, public readonly string $raw)
    {
    }

    /** Corps décodé avec des objets (pour le validateur). */
    public function json(): mixed
    {
        return json_decode($this->raw, false, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return (array) json_decode($this->raw, true, 512, JSON_THROW_ON_ERROR);
    }

    /** Valeur du cookie de session posé par la réponse, ou null. */
    public function sessionCookie(): ?string
    {
        foreach ($this->cookies as $c) {
            if (preg_match('/^musique_session=([^;]*)/', $c, $m)) {
                return $m[1];
            }
        }
        return null;
    }
}

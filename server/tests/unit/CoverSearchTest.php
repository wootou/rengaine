<?php
// Jaquettes en ligne (IA-06) : le serveur va chercher une adresse donnée par un client, donc tout ce
// qui pourrait l'envoyer ailleurs est refusé — sites hors liste, http, identifiants, ports, adresses
// IP, réseau local, redirections vers un autre site, réponses qui ne sont pas des images.
// Sans réseau : le transport est remplacé.

declare(strict_types=1);

namespace Musique\Tests;

use Musique\Http\ApiError;
use Musique\Library\CoverSearch;

final class CoverSearchTest extends TestCase
{
    public function setUp(): void
    {
        // Pas de réseau : tout nom se résout vers une adresse publique de documentation, sauf indication
        CoverSearch::$resolver = static fn (string $host): array => ['93.184.216.34'];
    }

    public function tearDown(): void
    {
        CoverSearch::$transport = null;
        CoverSearch::$resolver = null;
    }

    public function testSeulementLesSitesDeLaListe(): void
    {
        foreach (['https://is1-ssl.mzstatic.com/image/thumb/a/1200x1200bb.jpg', 'https://e-cdns-images.dzcdn.net/images/cover/x/1000x1000.jpg',
            'https://coverartarchive.org/release-group/0b3c3b1e-0000-4000-8000-000000000000/front-1200',
            'https://itunes.apple.com/search?term=a'] as $ok) {
            $this->assertSame(parse_url($ok, PHP_URL_HOST), CoverSearch::allowed($ok));
        }
        foreach (['http://is1-ssl.mzstatic.com/a.jpg', 'https://evil.example/a.jpg', 'https://mzstatic.com.evil.example/a.jpg',
            'https://evilmzstatic.com/a.jpg', 'https://user:pw@itunes.apple.com/', 'https://itunes.apple.com:8443/', 'https://172.16.4.10/a.jpg',
            'https://[::1]/a.jpg', 'https://localhost/a.jpg', 'file:///etc/passwd', 'gopher://itunes.apple.com/', 'https:///a.jpg', 'pas une adresse',
            // Analyseurs d'URL en désaccord (PHP et curl) : refusés avant d'aller plus loin
            'https://itunes.apple.com\\@127.0.0.1/', 'https://evil.example#@itunes.apple.com/', 'https://itunes.apple.com%2e.evil.example/',
            'https://itunes.apple.com @evil.example/', "https://itunes.apple.com\t/", 'https://itunes.apple.com/#x',
            // archive.org seulement derrière coverartarchive.org
            'https://archive.org/download/mbid-0b3c3b1e-0000-4000-8000-000000000000/a.jpg', 'https://ia800300.us.archive.org/1/items/mbid-0b3c3b1e-0000-4000-8000-000000000000/a.jpg'] as $bad) {
            $this->assertThrows(ApiError::class, static fn () => CoverSearch::allowed($bad), null);
        }
    }

    public function testLienRefaitPourCurl(): void
    {
        $this->assertSame('https://itunes.apple.com/search?term=Daft%20Punk&entity=album', CoverSearch::normalize('https://ITUNES.apple.com/search?term=Daft+Punk&entity=album'));
        $this->assertSame('https://is1-ssl.mzstatic.com/a%20b/c.jpg', CoverSearch::normalize('https://is1-ssl.mzstatic.com/a%20b/c.jpg'));
        $this->assertSame('https://api.deezer.com/search/album?q=artist%3A%22Queen%22', CoverSearch::normalize('https://api.deezer.com/search/album?q=artist:"Queen"'));
    }

    public function testArchiveOrgDerriereCoverArtArchive(): void
    {
        $mbid = '0b3c3b1e-0000-4000-8000-000000000000';
        $this->assertSame('archive.org', CoverSearch::allowed("https://archive.org/download/mbid-$mbid/mbid-$mbid-1_thumb1200.jpg", 'coverartarchive.org'));
        $this->assertSame('ia800300.us.archive.org', CoverSearch::allowed("https://ia800300.us.archive.org/12/items/mbid-$mbid/a.jpg", 'archive.org'));
        $this->assertThrows(ApiError::class, static fn () => CoverSearch::allowed('https://archive.org/download/autre/a.jpg', 'coverartarchive.org'));
        $this->assertThrows(ApiError::class, static fn () => CoverSearch::allowed("https://evil.archive.org/download/mbid-$mbid/a.jpg", 'coverartarchive.org'));
        $this->assertThrows(ApiError::class, static fn () => CoverSearch::allowed("https://archive.org/download/mbid-$mbid/a.jpg", 'itunes.apple.com'));
        // « .. » (en clair ou encodé) et « / » encodé : sortiraient du dossier mbid- une fois le chemin normalisé par curl
        foreach (["https://ia800.us.archive.org/download/mbid-$mbid/%2e%2e/%2e%2e/x/y.jpg", "https://ia800.us.archive.org/download/mbid-$mbid/../../x/y.jpg",
            "https://archive.org/download/mbid-$mbid/./a.jpg", "https://archive.org/download/mbid-$mbid%2F..%2F..%2Fx/a.jpg"] as $bad) {
            $this->assertThrows(ApiError::class, static fn () => CoverSearch::allowed($bad, 'coverartarchive.org'), null);
        }
        $this->assertThrows(ApiError::class, static fn () => CoverSearch::normalize('https://itunes.apple.com/a/../b'));
    }

    /** « N'importe quel site » (cover.anyhost) : seule la liste des sites saute, le reste est toujours vérifié. */
    public function testTousLesSitesGardeLesAutresProtections(): void
    {
        $this->assertSame('https://images.example.org/covers/a%20b.jpg', CoverSearch::normalize('https://images.example.org/covers/a%20b.jpg', null, true));
        $this->assertThrows(ApiError::class, static fn () => CoverSearch::normalize('https://images.example.org/a.jpg'), null);
        foreach (['http://images.example.org/a.jpg', 'https://10.0.0.1/a.jpg', 'https://localhost/a.jpg', 'https://user:pw@images.example.org/a.jpg',
            'https://images.example.org:8443/a.jpg', 'https://images.example.org/x/../../a.jpg', 'https://images.example.org\\@127.0.0.1/', 'file:///etc/passwd',
            'https://images.example.org/a.jpg#f'] as $bad) {
            $this->assertThrows(ApiError::class, static fn () => CoverSearch::normalize($bad, null, true), null);
        }
        // Redirection revérifiée : vers http ou vers une IP, refusée
        foreach (['http://images.example.org/b.jpg', 'https://169.254.169.254/latest/'] as $to) {
            $calls = 0;
            CoverSearch::$transport = static function (string $url) use (&$calls, $to): array {
                $calls++;
                return ['status' => 302, 'body' => '', 'location' => $to, 'type' => null];
            };
            $this->assertThrows(ApiError::class, static fn () => (new CoverSearch(true))->fetch('https://images.example.org/a.jpg', 1000));
            $this->assertSame(1, $calls, $to);
        }
    }

    public function testAdressesNonPubliquesRefusees(): void
    {
        $called = false;
        CoverSearch::$transport = static function () use (&$called): array {
            $called = true;
            return ['status' => 200, 'body' => '', 'location' => null, 'type' => null];
        };
        foreach ([['10.0.0.5'], ['93.184.216.34', '172.16.0.1'], ['100.64.0.1'], ['127.0.0.1'], []] as $ips) {
            CoverSearch::$resolver = static fn (string $h): array => $ips;
            $s = new CoverSearch();
            $this->assertThrows(ApiError::class, static fn () => $s->fetch('https://coverartarchive.org/x', 1000),
                fn (ApiError $e) => $this->assertSame(CoverSearch::NOT_FOUND, $e->getMessage()));
            $this->assertFalse($called, json_encode($ips) . ' : aucun appel');
        }
    }

    /** Propres services de l'installation (hotes_interdits, ip_interdites, et le site lui-même) : jamais joints. */
    public function testSitesEtAdressesInterdits(): void
    {
        $calls = [];
        CoverSearch::$transport = static function (string $url, int $max, int $t, string $ip, bool $imageOnly) use (&$calls): array {
            $calls[] = [$url, $imageOnly];
            return ['status' => 302, 'body' => '', 'location' => 'https://admin.example.net/x', 'type' => null];
        };
        $s = new CoverSearch(true, ['example.net'], ['203.0.113.0/24']);
        // Nom interdit (et ses sous-domaines) : refusé sans appel
        $this->assertThrows(ApiError::class, static fn () => $s->fetch('https://tools.example.net/a.jpg', 1000));
        $this->assertSame([], $calls);
        // Redirection vers un nom interdit : refusée après le premier saut ; mode « tous les sites » = Accept image/* seulement
        $this->assertThrows(ApiError::class, static fn () => $s->fetch('https://images.example.org/a.jpg', 1000));
        $this->assertSame([['https://images.example.org/a.jpg', true]], $calls);
        // Nom autorisé mais résolu dans un réseau interdit : refusé, même message qu'une image introuvable
        $calls = [];
        CoverSearch::$resolver = static fn (string $h): array => ['203.0.113.7'];
        $this->assertThrows(ApiError::class, static fn () => $s->fetch('https://images.example.org/a.jpg', 1000),
            fn (ApiError $e) => $this->assertSame(CoverSearch::NOT_FOUND, $e->getMessage()));
        $this->assertSame([], $calls);
        $this->assertTrue(str_contains((string) $s->lastError, '203.0.113.7'), 'détail gardé pour le journal');
    }

    /** Depuis la configuration : listes d'interdits, et le site lui-même ([site] url) toujours interdit. */
    public function testInterditsDeLaConfiguration(): void
    {
        $called = false;
        CoverSearch::$transport = static function () use (&$called): array {
            $called = true;
            return ['status' => 200, 'body' => '', 'location' => null, 'type' => null];
        };
        CoverSearch::$resolver = static fn (string $h): array => [$h === 'music.example.com' ? '198.51.100.7' : '93.184.216.34'];
        $c = \Musique\Config::fromArray(['site' => ['url' => 'https://music.example.com/'], 'pochettes' => ['hotes_interdits' => '.example.net, nas.example.org', 'ip_interdites' => '203.0.113.0/24']]);
        $s = CoverSearch::fromConfig($c, true);
        foreach (['https://music.example.com/a.jpg', 'https://box.example.net/a.jpg', 'https://nas.example.org/a.jpg'] as $url) {
            $this->assertThrows(ApiError::class, static fn () => $s->fetch($url, 1000), null);
        }
        // Adresse du site lui-même, sous un autre nom : refusée aussi
        CoverSearch::$resolver = static fn (string $h): array => ['198.51.100.7'];
        $this->assertThrows(ApiError::class, static fn () => $s->fetch('https://autre-nom.example.org/a.jpg', 1000));
        $this->assertFalse($called, 'aucun appel');
    }

    /** Un 403, un 500 ou une page qui n'est pas une image : le même message, sans code HTTP (pas d'oracle). */
    public function testMemeMessageQuelQueSoitLEchec(): void
    {
        foreach ([[403, 'interdit'], [500, 'erreur'], [404, ''], [200, '<html>tableau de bord</html>']] as [$status, $body]) {
            CoverSearch::$transport = static fn (): array => ['status' => $status, 'body' => $body, 'location' => null, 'type' => 'text/html'];
            $this->assertThrows(ApiError::class, static fn () => (new CoverSearch(true))->image('https://images.example.org/a.jpg'),
                fn (ApiError $e) => $this->assertSame(CoverSearch::NOT_FOUND, $e->getMessage(), "HTTP $status"));
        }
    }

    public function testRedirectionsReverifiees(): void
    {
        $calls = [];
        // Le site permis renvoie vers l'adresse des métadonnées d'un hébergeur : refusé avant tout appel
        CoverSearch::$transport = static function (string $url) use (&$calls): array {
            $calls[] = $url;
            return ['status' => 302, 'body' => '', 'location' => 'http://169.254.169.254/latest/meta-data/', 'type' => null];
        };
        $this->assertThrows(ApiError::class, static fn () => (new CoverSearch())->fetch('https://coverartarchive.org/x', 1000));
        $this->assertSame(['https://coverartarchive.org/x'], $calls);

        // « //hôte » : même schéma, autre hôte, revérifié
        $calls = [];
        CoverSearch::$transport = static function (string $url) use (&$calls): array {
            $calls[] = $url;
            return ['status' => 302, 'body' => '', 'location' => '//169.254.169.254/x', 'type' => null];
        };
        $this->assertThrows(ApiError::class, static fn () => (new CoverSearch())->fetch('https://coverartarchive.org/x', 1000));
        $this->assertSame(1, count($calls));

        // Boucle de redirections : au plus 3
        $calls = [];
        CoverSearch::$transport = static function (string $url) use (&$calls): array {
            $calls[] = $url;
            return ['status' => 301, 'body' => '', 'location' => '/encore', 'type' => null];
        };
        $this->assertThrows(ApiError::class, static fn () => (new CoverSearch())->fetch('https://coverartarchive.org/x', 1000));
        $this->assertSame(4, count($calls));

        // Redirection relative vers le même site permis : suivie
        CoverSearch::$transport = static fn (string $url): array => str_ends_with($url, '/final')
            ? ['status' => 200, 'body' => 'ok', 'location' => null, 'type' => 'text/plain']
            : ['status' => 307, 'body' => '', 'location' => '/final', 'type' => null];
        $this->assertSame('ok', (new CoverSearch())->fetch('https://coverartarchive.org/x', 1000)['body']);
    }

    public function testSeulementUneVraieImage(): void
    {
        $png = static function (int $w, int $h): string {
            $p = proc_open(['convert', '-size', "{$w}x{$h}", 'xc:#ff7a2e', 'png:-'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $bytes = (string) stream_get_contents($pipes[1]);
            proc_close($p);
            return $bytes;
        };
        $serve = static fn (string $body) => static fn (string $url): array => ['status' => 200, 'body' => $body, 'location' => null, 'type' => 'image/png'];
        $url = 'https://is1-ssl.mzstatic.com/a.png';

        CoverSearch::$transport = $serve($png(300, 300));
        $this->assertSame(300, getimagesizefromstring((new CoverSearch())->image($url))[0]);
        foreach (['<html>pas une image</html>', '<svg xmlns="http://www.w3.org/2000/svg"/>', $png(100, 100)] as $bad) {
            CoverSearch::$transport = $serve($bad);
            $this->assertThrows(ApiError::class, static fn () => (new CoverSearch())->image($url));
        }
        CoverSearch::$transport = static fn (string $u): array => ['status' => 404, 'body' => '', 'location' => null, 'type' => null];
        $this->assertThrows(ApiError::class, static fn () => (new CoverSearch())->image($url));
    }
}

<?php
// Suite commune aux deux pilotes (STO-05) : disque et S3 doivent se comporter exactement pareil.
// Chaque test travaille sous un préfixe à lui (_tests/<aléatoire>/) et le vide en partant.

declare(strict_types=1);

namespace Musique\Tests;

use Musique\Storage\Storage;
use Musique\Storage\StorageException;

abstract class StorageContract extends TestCase
{
    protected Storage $storage;
    protected string $prefix;
    /** @var list<string> */
    private array $created = [];
    private string $tmpDir;

    /** Pilote à tester ; appelle skip() s'il n'est pas disponible. */
    abstract protected function makeStorage(): Storage;

    public function setUp(): void
    {
        $this->storage = $this->makeStorage();
        $this->prefix = '_tests/' . bin2hex(random_bytes(5)) . '/';
        $this->tmpDir = sys_get_temp_dir() . '/musique-tests-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir);
    }

    public function tearDown(): void
    {
        foreach ($this->created as $key) {
            try {
                $this->storage->delete($key);
            } catch (\Throwable) {
            }
        }
        array_map('unlink', glob($this->tmpDir . '/*') ?: []);
        @rmdir($this->tmpDir);
    }

    protected function putString(string $relKey, string $content): string
    {
        $key = $this->prefix . $relKey;
        $file = $this->tmpDir . '/' . bin2hex(random_bytes(4));
        file_put_contents($file, $content);
        $this->storage->put($key, $file, 'audio/mpeg');
        $this->created[] = $key;
        return $key;
    }

    public function testEcritureEtInfos(): void
    {
        $key = $this->putString('Album/01 - Titre.mp3', 'abcdefghij');
        $obj = $this->storage->stat($key);
        $this->assertTrue($obj !== null, 'stat après écriture');
        $this->assertSame($key, $obj->key);
        $this->assertSame(10, $obj->size);
        $this->assertTrue($obj->modifiedAt !== null && abs($obj->modifiedAt->getTimestamp() - time()) < 300, 'date proche de maintenant');
    }

    public function testLectureComplete(): void
    {
        $key = $this->putString('a.bin', "\x00\x01binaire\xff");
        $this->assertSame("\x00\x01binaire\xff", $this->storage->get($key));
    }

    public function testPlagesDOctets(): void
    {
        $content = str_repeat('0123456789', 100000); // 1 Mo : plusieurs morceaux
        $key = $this->putString('gros.flac', $content);
        $read = function (int $offset, ?int $length) use ($key): string {
            $out = '';
            $this->storage->stream($key, $offset, $length, function (string $chunk) use (&$out) { $out .= $chunk; });
            return $out;
        };
        $this->assertSame(substr($content, 3, 5), $read(3, 5), 'milieu');
        $this->assertSame(substr($content, 999990), $read(999990, null), 'jusqu’à la fin');
        $this->assertSame(substr($content, 999995), $read(999995, 100), 'longueur au-delà de la fin');
        $this->assertSame(md5($content), md5($read(0, null)), 'fichier entier');
        $this->assertThrows(StorageException::class, fn () => $read(1000000, 10),
            fn (StorageException $e) => $this->assertSame(StorageException::INVALID_RANGE, $e->reason));
    }

    public function testCaracteresSpeciaux(): void
    {
        $rel = "Été & co (2024)/01 - L'été #1 [100%] + plus.mp3";
        $key = $this->putString($rel, 'x');
        $this->assertSame('x', $this->storage->get($key));
        $keys = array_map(fn ($o) => $o->key, iterator_to_array($this->storage->list($this->prefix), false));
        $this->assertSame([$key], $keys);
    }

    public function testListeTrieeEtPrefixePartiel(): void
    {
        $k1 = $this->putString('b/2.mp3', '22');
        $k2 = $this->putString('a b/1.mp3', '1');
        $k3 = $this->putString('a/1.mp3', '1');
        $k4 = $this->putString('ab.mp3', '1');
        $all = array_map(fn ($o) => $o->key, iterator_to_array($this->storage->list($this->prefix), false));
        $expected = [$k1, $k2, $k3, $k4];
        sort($expected, SORT_STRING); // ordre des octets, comme S3
        $this->assertSame($expected, $all, 'liste complète triée');

        $partial = array_map(fn ($o) => $o->key, iterator_to_array($this->storage->list($this->prefix . 'a'), false));
        $this->assertSame([$k2, $k3, $k4], $partial, 'préfixe partiel « a »');

        $dir = array_map(fn ($o) => $o->key, iterator_to_array($this->storage->list($this->prefix . 'a/'), false));
        $this->assertSame([$k3], $dir, 'préfixe dossier « a/ »');

        $none = iterator_to_array($this->storage->list($this->prefix . 'zzz'), false);
        $this->assertSame([], $none, 'préfixe sans résultat');
    }

    public function testSuppression(): void
    {
        $key = $this->putString('x/y.mp3', 'z');
        $this->storage->delete($key);
        $this->assertNull($this->storage->stat($key), 'stat après suppression');
        $this->storage->delete($key); // deuxième fois : sans effet, sans erreur
        $this->assertSame([], iterator_to_array($this->storage->list($this->prefix), false));
    }

    public function testFichierAbsent(): void
    {
        $key = $this->prefix . 'absent.mp3';
        $this->assertNull($this->storage->stat($key));
        $this->assertThrows(StorageException::class, fn () => $this->storage->get($key),
            fn (StorageException $e) => $this->assertTrue($e->isNotFound(), 'raison not_found'));
        $this->assertThrows(StorageException::class, fn () => $this->storage->stream($key, 0, null, fn () => null),
            fn (StorageException $e) => $this->assertTrue($e->isNotFound(), 'raison not_found (stream)'));
    }

    public function testClesRefusees(): void
    {
        foreach (['', '/abs.mp3', '../x.mp3', 'a/../b.mp3', 'a//b.mp3', 'a/', '.cache/x', 'a/.DS_Store', "a\x01b"] as $bad) {
            $this->assertThrows(StorageException::class, fn () => $this->storage->stat($bad),
                fn (StorageException $e) => $this->assertSame(StorageException::INVALID_KEY, $e->reason, "clé « $bad »"));
        }
    }

    public function testRemplacement(): void
    {
        $key = $this->putString('r.mp3', 'ancien contenu');
        $this->putString('r.mp3', 'neuf');
        $this->assertSame('neuf', $this->storage->get($key));
        $this->assertSame(4, $this->storage->stat($key)?->size);
    }

    public function testSourcePourFfprobe(): void
    {
        $key = $this->putString('m.mp3', 'contenu');
        $src = $this->storage->mediaSource($key, 60);
        if (str_starts_with($src, 'http')) {
            $this->assertSame('contenu', (string) @file_get_contents($src), 'adresse signée lisible sans en-tête');
        } else {
            $this->assertSame('contenu', (string) file_get_contents($src), 'chemin local lisible');
        }
    }

    public function testDeplacement(): void
    {
        $from = $this->putString('dépôt/Mon album/01 - Été & co.mp3', 'audio');
        $to = $this->prefix . 'Artiste/Album (2020)/01 - Été & co.mp3';
        $obj = $this->storage->move($from, $to);
        $this->assertSame(5, $obj->size);
        $this->assertSame('audio', $this->storage->get($to));
        $this->assertNull($this->storage->stat($from), 'original retiré');

        // Jamais d'écrasement : l'original reste en place
        $other = $this->putString('dépôt/autre.mp3', 'autre');
        try {
            $this->storage->move($other, $to);
            $this->fail('déplacement sur un fichier existant accepté');
        } catch (StorageException $e) {
            $this->assertSame(StorageException::FAILED, $e->reason);
        }
        $this->assertSame('autre', $this->storage->get($other));
        $this->assertSame('audio', $this->storage->get($to));

        try {
            $this->storage->move($this->prefix . 'absent.mp3', $this->prefix . 'x.mp3');
            $this->fail('déplacement d’un fichier absent accepté');
        } catch (StorageException $e) {
            $this->assertTrue($e->isNotFound());
        }
    }
}

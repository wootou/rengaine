<?php
// Conversion à la volée : quand convertir, et plafond du cache.

declare(strict_types=1);

namespace Musique\Tests;

use Musique\Audio\Transcoder;

final class TranscoderTest extends TestCase
{
    public function testConvertirSeulementSiUtile(): void
    {
        $this->assertSame(['format' => 'opus', 'bitrate' => 160], Transcoder::plan('flac', true, 900, 'opus', null), 'Opus 160 par défaut');
        $this->assertSame(['format' => 'mp3', 'bitrate' => 320], Transcoder::plan('flac', true, 900, 'mp3', 320));
        $this->assertNull(Transcoder::plan('mp3', false, 320, 'mp3', 128), 'jamais de MP3 en MP3');
        $this->assertNull(Transcoder::plan('mp3', false, 192, 'opus', 256), 'pas vers un débit supérieur à la source');
        $this->assertSame(['format' => 'opus', 'bitrate' => 96], Transcoder::plan('mp3', false, 320, 'opus', 96), 'MP3 320 → Opus 96 : gain réel');
        $this->assertNull(Transcoder::plan('flac', true, 900, 'flac', null), 'FLAC déjà en FLAC');
        $this->assertNull(Transcoder::plan('mp3', false, 320, 'flac', null), 'jamais de FLAC tiré d’un MP3');
        $this->assertSame(['format' => 'flac', 'bitrate' => null], Transcoder::plan('wav', true, 1411, 'flac', null));
        $this->assertNull(Transcoder::plan('flac', true, 900, 'original', null));
    }

    public function testCommandeAvecPosition(): void
    {
        $cmd = Transcoder::command('/x.flac', ['format' => 'opus', 'bitrate' => 96], 61500);
        $this->assertSame('61.500', $cmd[array_search('-ss', $cmd, true) + 1], 'avance avant -i (rapide)');
        $this->assertTrue(array_search('-ss', $cmd, true) < array_search('-i', $cmd, true));
        $this->assertSame('pipe:1', end($cmd));
        $this->assertFalse(in_array('-ss', Transcoder::command('/x.flac', ['format' => 'mp3', 'bitrate' => 192], 0), true));
    }

    public function testCachePlafonne(): void
    {
        $dir = sys_get_temp_dir() . '/transcode-' . bin2hex(random_bytes(4));
        try {
            $t = new Transcoder("$dir/transcode", 1024 * 1024); // 1 Mio
            $plan = ['format' => 'opus', 'bitrate' => 96];
            $paths = [];
            foreach (['a', 'b', 'c'] as $i => $name) {
                $p = $t->cachePath($name, $plan);
                @mkdir(dirname($p), 0750, true);
                file_put_contents($p, str_repeat('x', 400 * 1024));
                touch($p, time() - 100 + $i * 10); // a est le plus ancien
                $paths[$name] = $p;
            }
            $orphan = dirname($paths['a']) . '/.abandon.tmp';
            file_put_contents($orphan, 'x');
            touch($orphan, time() - 7200);
            $this->assertNotSame($paths['a'], $t->cachePath('a', ['format' => 'opus', 'bitrate' => 160]), 'débit dans la clé');

            $t->cached($paths['a']); // relu : redevient le plus récent
            $res = $t->purge();
            $this->assertFalse(is_file($paths['b']), 'le moins récemment lu part');
            $this->assertTrue(is_file($paths['a']) && is_file($paths['c']));
            $this->assertFalse(is_file($orphan), 'temporaire abandonné retiré');
            $this->assertSame(2, $res['files']);
            $this->assertNull($t->cached("$dir/absent.opus"));
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }
}

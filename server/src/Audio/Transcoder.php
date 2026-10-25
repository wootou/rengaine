<?php
// Conversion à la volée (LEC-04) : FLAC → Opus ou MP3, pour le téléphone ou une connexion lente.
//   - On ne convertit que si c'est utile (voir plan()) : sinon le fichier d'origine est servi.
//   - Une conversion complète (depuis le début) est gardée dans <cache>/transcode/, plafonnée en
//     taille ([conversion] cache_mo) : les morceaux les moins récemment écoutés partent en premier.
//   - Coût mesuré le 2026-10-20 sur ce CT : ~1,5 % d'un cœur par écoute en temps réel, premier
//     octet en 0,3 s depuis le S3.

declare(strict_types=1);

namespace Musique\Audio;

use Musique\Config;

final class Transcoder
{
    /** Format demandé → extension, type MIME, arguments d'encodage, débit par défaut. */
    public const PROFILES = [
        'opus' => ['ext' => 'opus', 'mime' => 'audio/ogg', 'default' => 160],
        'mp3' => ['ext' => 'mp3', 'mime' => 'audio/mpeg', 'default' => 192],
        'flac' => ['ext' => 'flac', 'mime' => 'audio/flac', 'default' => null],
    ];

    private const TMP_MAX_AGE = 3600;

    public function __construct(
        private readonly string $dir,
        private readonly int $maxBytes,
    ) {
    }

    public static function enabled(Config $config): bool
    {
        return $config->int('conversion', 'actif', 1) === 1;
    }

    public static function fromConfig(Config $config, string $cacheDir): self
    {
        return new self($cacheDir . '/transcode', max(0, $config->int('conversion', 'cache_mo', 2048)) * 1024 * 1024);
    }

    /**
     * Conversion à faire, ou null s'il vaut mieux servir le fichier d'origine.
     * @return array{format: string, bitrate: ?int}|null
     */
    public static function plan(string $sourceFormat, bool $lossless, ?int $sourceKbps, string $format, ?int $bitrate): ?array
    {
        if (!isset(self::PROFILES[$format])) {
            return null;
        }
        if ($format === 'flac') {
            // Seulement d'un autre format sans perte (WAV, ALAC) : du FLAC tiré d'un MP3 serait trompeur
            return $lossless && $sourceFormat !== 'flac' ? ['format' => 'flac', 'bitrate' => null] : null;
        }
        $bitrate ??= self::PROFILES[$format]['default'];
        if (!$lossless && ($sourceFormat === $format || ($sourceKbps !== null && $sourceKbps > 0 && $bitrate >= $sourceKbps))) {
            return null; // jamais de MP3 en MP3, ni d'une source avec perte vers un débit supérieur
        }
        return ['format' => $format, 'bitrate' => $bitrate];
    }

    /** Emplacement en cache d'une conversion (empreinte du fichier source + réglages). */
    public function cachePath(string $sourceFingerprint, array $plan): string
    {
        $h = hash('sha256', $sourceFingerprint . '|' . $plan['format'] . '|' . ($plan['bitrate'] ?? ''));
        return $this->dir . '/' . substr($h, 0, 2) . '/' . $h . '.' . self::PROFILES[$plan['format']]['ext'];
    }

    /** Conversion en cache, ou null. Une lecture la remet en tête (date de modification). */
    public function cached(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        @touch($path);
        return $path;
    }

    /** @return list<string> */
    public static function command(string $source, array $plan, int $startMs): array
    {
        $cmd = ['ffmpeg', '-nostdin', '-hide_banner', '-v', 'error'];
        if ($startMs > 0) {
            array_push($cmd, '-ss', sprintf('%.3f', $startMs / 1000));
        }
        // Sans la jaquette ni les tags : le lecteur a déjà tout par l'API
        array_push($cmd, '-i', $source, '-map', '0:a:0', '-vn', '-map_metadata', '-1');
        $codec = match ($plan['format']) {
            'opus' => ['-c:a', 'libopus', '-b:a', $plan['bitrate'] . 'k', '-vbr', 'on', '-f', 'ogg'],
            'mp3' => ['-c:a', 'libmp3lame', '-b:a', $plan['bitrate'] . 'k', '-f', 'mp3'],
            'flac' => ['-c:a', 'flac', '-f', 'flac'],
        };
        return [...$cmd, ...$codec, 'pipe:1'];
    }

    /**
     * Convertit et passe chaque morceau à $sink. Si $cacheTarget est donné, la conversion complète y est
     * gardée (écriture à côté puis renommage : jamais de fichier à moitié écrit dans le cache).
     * $sink lève une exception pour arrêter (client parti) : rien n'est alors gardé.
     * @param callable(string): void $sink
     * @return array{ok: bool, bytes: int, error: string} ok = ffmpeg a fini sans erreur
     */
    public function run(string $source, array $plan, int $startMs, callable $sink, ?string $cacheTarget = null): array
    {
        $tmp = null;
        $out = null;
        if ($cacheTarget !== null && $this->maxBytes > 0) {
            $dir = dirname($cacheTarget);
            if (is_dir($dir) || @mkdir($dir, 0750, true) || is_dir($dir)) {
                $tmp = $dir . '/.' . bin2hex(random_bytes(6)) . '.tmp';
                $out = @fopen($tmp, 'wb') ?: null;
                if ($out === null) {
                    $tmp = null;
                }
            }
        }
        $errFile = tempnam(sys_get_temp_dir(), 'conv');
        $proc = proc_open(self::command($source, $plan, $startMs),
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']], $pipes);
        if (!is_resource($proc)) {
            $this->discard($out, $tmp);
            @unlink($errFile);
            return ['ok' => false, 'bytes' => 0, 'error' => 'ffmpeg ne démarre pas'];
        }
        $bytes = 0;
        $stopped = false;
        try {
            while (!feof($pipes[1])) {
                $chunk = fread($pipes[1], 65536);
                if ($chunk === false || $chunk === '') {
                    continue;
                }
                $bytes += strlen($chunk);
                if ($out !== null && fwrite($out, $chunk) !== strlen($chunk)) {
                    $this->discard($out, $tmp); // disque plein : on continue sans garder
                    $out = $tmp = null;
                }
                $sink($chunk);
            }
        } catch (\Throwable $e) {
            $stopped = true;
            throw $e;
        } finally {
            if ($stopped) {
                proc_terminate($proc);
            }
            fclose($pipes[1]);
            $code = proc_close($proc);
            $error = trim(mb_substr((string) @file_get_contents($errFile), 0, 500));
            @unlink($errFile);
            $ok = !$stopped && $code === 0 && $bytes > 0;
            if ($ok && $out !== null && $tmp !== null && $cacheTarget !== null) {
                fclose($out);
                if (@rename($tmp, $cacheTarget)) {
                    $this->purge();
                } else {
                    @unlink($tmp);
                }
            } else {
                $this->discard($out, $tmp);
            }
        }
        return ['ok' => $ok, 'bytes' => $bytes, 'error' => $error];
    }

    /**
     * Ramène le cache sous son plafond (90 %), en retirant les conversions les moins récemment lues,
     * et retire les fichiers temporaires abandonnés.
     * @return array{files: int, bytes: int, removed: int}
     */
    public function purge(): array
    {
        $files = [];
        $total = 0;
        $removed = 0;
        $kept = 0;
        foreach (glob($this->dir . '/*/{,.}*', GLOB_BRACE | GLOB_NOSORT) ?: [] as $path) {
            $name = basename($path);
            if ($name === '.' || $name === '..' || !is_file($path)) {
                continue;
            }
            $st = @stat($path);
            if ($st === false) {
                continue;
            }
            if (str_ends_with($name, '.tmp')) {
                if ($st['mtime'] < time() - self::TMP_MAX_AGE && @unlink($path)) {
                    $removed++;
                }
                continue;
            }
            $files[] = [$path, (int) $st['size'], (int) $st['mtime']];
            $total += (int) $st['size'];
        }
        $kept = count($files);
        if ($total > $this->maxBytes) {
            usort($files, static fn(array $a, array $b): int => $a[2] <=> $b[2]);
            $goal = (int) ($this->maxBytes * 0.9);
            foreach ($files as [$path, $size]) {
                if ($total <= $goal) {
                    break;
                }
                if (@unlink($path)) {
                    $total -= $size;
                    $removed++;
                    $kept--;
                }
            }
        }
        return ['files' => $kept, 'bytes' => $total, 'removed' => $removed];
    }

    /** Vide le cache (réglages d'encodage changés, essais). @return int conversions retirées */
    public function clear(): int
    {
        $n = 0;
        foreach (glob($this->dir . '/*/*.{opus,mp3,flac}', GLOB_BRACE | GLOB_NOSORT) ?: [] as $path) {
            if (@unlink($path)) {
                $n++;
            }
        }
        return $n;
    }

    /** @param resource|null $out */
    private function discard($out, ?string $tmp): void
    {
        if (is_resource($out)) {
            fclose($out);
        }
        if ($tmp !== null) {
            @unlink($tmp);
        }
    }
}

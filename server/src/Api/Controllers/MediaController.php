<?php
// Flux audio (API-04) et images (API-05).
//   - Flux : le meilleur fichier de la piste, tel quel, avec Range (une seule plage → 206),
//     ou converti à la volée (format=opus|mp3|flac, voir Audio\Transcoder).
//   - Images : adresse = empreinte, donc cache permanent côté client. Les vignettes sont faites
//     par ffmpeg à la première demande, puis gardées dans <cache>/thumbs/.

declare(strict_types=1);

namespace Musique\Api\Controllers;

use Musique\Api\Context;
use Musique\Audio\Transcoder;
use Musique\Http\ApiError;
use Musique\Http\Response;
use Musique\Library\Images;
use Musique\Storage\DiskStorage;

final class MediaController
{
    private const MIME = [
        'mp3' => 'audio/mpeg', 'flac' => 'audio/flac', 'opus' => 'audio/ogg', 'vorbis' => 'audio/ogg',
        'aac' => 'audio/mp4', 'alac' => 'audio/mp4', 'wav' => 'audio/wav',
    ];
    private const SIZES = ['s' => 96, 'm' => 300, 'l' => 600];
    private const IMMUTABLE = 'private, max-age=31536000, immutable';

    public function __construct(private readonly Context $ctx)
    {
    }

    // ---------------------------------------------------------------- flux audio

    public function streamTrack(array $params): Response
    {
        $r = $this->ctx->request;
        $format = $r->queryEnum('format', ['original', 'mp3', 'opus', 'flac'], 'original');
        $bitrate = $r->queryInt('bitrate', null, 96, 320);
        if ($bitrate !== null && !in_array($bitrate, [96, 128, 160, 192, 256, 320], true)) {
            throw ApiError::invalid('bitrate', 'Débit possible : 96, 128, 160, 192, 256 ou 320');
        }
        $startMs = (int) ($r->queryInt('startMs', null, 0) ?? 0);
        if ($format !== 'original' && !Transcoder::enabled($this->ctx->config)) {
            throw ApiError::notImplemented('Conversion à la volée désactivée sur ce serveur : demandez format=original');
        }
        $file = $this->ctx->db->one(
            'SELECT so.id, so.storage_key, so.size_bytes, so.etag, so.modified_at, tf.format, tf.lossless, tf.bitrate_kbps
               FROM tracks t JOIN track_files tf ON tf.track_id = t.id JOIN storage_objects so ON so.id = tf.object_id
              WHERE t.id = ? AND so.missing_since IS NULL
              ORDER BY tf.lossless DESC, tf.bitrate_kbps DESC, tf.id LIMIT 1',
            [(int) $params['id']]
        ) ?? throw ApiError::notFound('Piste introuvable ou fichier absent du stockage');

        $size = (int) $file['size_bytes'];
        $fingerprint = $file['id'] . '|' . $size . '|' . $file['modified_at'] . '|' . $file['etag'];
        $etag = '"' . substr(hash('sha256', $fingerprint), 0, 24) . '"';
        $mime = self::MIME[$file['format']] ?? 'application/octet-stream';
        $local = null;

        $plan = $format === 'original' ? null
            : Transcoder::plan((string) $file['format'], (bool) $file['lossless'], $file['bitrate_kbps'] === null ? null : (int) $file['bitrate_kbps'], $format, $bitrate);
        if ($plan !== null) {
            $transcoder = Transcoder::fromConfig($this->ctx->config, $this->ctx->cacheDir());
            $cachePath = $transcoder->cachePath($fingerprint, $plan);
            $local = $startMs === 0 ? $transcoder->cached($cachePath) : null;
            if ($local === null) {
                return $this->liveConversion($transcoder, $file, $plan, $startMs, $startMs === 0 ? $cachePath : null, (int) $params['id']);
            }
            // Conversion déjà faite : servie comme un fichier (taille connue, Range possible)
            $size = (int) filesize($local);
            $etag = '"' . substr(hash('sha256', $fingerprint . '|' . $plan['format'] . '|' . ($plan['bitrate'] ?? '')), 0, 24) . '"';
            $mime = Transcoder::PROFILES[$plan['format']]['mime'];
        }

        $headers = [
            'Accept-Ranges' => 'bytes',
            'ETag' => $etag,
            'Cache-Control' => 'private, max-age=3600',
            'Content-Type' => $mime,
        ];
        if (self::etagMatches($r->header('if-none-match'), $etag)) {
            return self::with(new Response(304), $headers);
        }

        $offset = 0;
        $length = $size;
        $status = 200;
        $range = $r->header('range');
        $ifRange = $r->header('if-range');
        if ($range !== null && ($ifRange === null || $ifRange === $etag)) {
            $parsed = self::parseRange($range, $size);
            if ($parsed === null) {
                $e = new ApiError(416, 'range_not_satisfiable', 'Plage hors du fichier, ou plusieurs plages', ['size' => $size], ['Content-Range' => "bytes */$size"]);
                throw $e;
            }
            [$offset, $length] = $parsed;
            $status = 206;
            $headers['Content-Range'] = 'bytes ' . $offset . '-' . ($offset + $length - 1) . '/' . $size;
        }
        $headers['Content-Length'] = (string) $length;

        $storage = $local === null ? $this->ctx->storage() : new DiskStorage(dirname($local));
        $key = $local === null ? (string) $file['storage_key'] : basename($local);
        $logger = $this->ctx->logger;
        $response = self::with(new Response($status), $headers);
        $response->stream = static function () use ($storage, $key, $offset, $length, $logger): void {
            if ($length === 0) {
                return;
            }
            self::prepareStream();
            try {
                $storage->stream($key, $offset, $length, self::sink(...));
            } catch (ClientGone) {
                // L'auditeur a avancé ou changé de piste : normal
            } catch (\Throwable $e) {
                $logger->warning('Flux interrompu', ['key' => $key, 'message' => $e->getMessage()]);
            }
        };
        return $response;
    }

    /**
     * Conversion pas encore en cache : le flux part pendant que ffmpeg convertit. Taille inconnue,
     * donc pas de Range (sauf « bytes=0- », que les navigateurs envoient toujours) : on avance avec startMs.
     * @param array{format: string, bitrate: ?int} $plan
     */
    private function liveConversion(Transcoder $transcoder, array $file, array $plan, int $startMs, ?string $cacheTarget, int $trackId): Response
    {
        $range = $this->ctx->request->header('range');
        if ($range !== null && !preg_match('/^\s*bytes\s*=\s*0\s*-\s*$/i', $range)) {
            throw new ApiError(416, 'range_not_satisfiable', 'Conversion en cours : avancez avec startMs', [], ['Content-Range' => 'bytes */*']);
        }
        $source = $this->ctx->storage()->mediaSource((string) $file['storage_key'], 6 * 3600);
        $logger = $this->ctx->logger;
        $response = self::with(new Response(200), [
            'Accept-Ranges' => 'none',
            'Cache-Control' => 'no-store',
            'Content-Type' => Transcoder::PROFILES[$plan['format']]['mime'],
            'X-Accel-Buffering' => 'no',
        ]);
        $response->stream = static function () use ($transcoder, $source, $plan, $startMs, $cacheTarget, $logger, $trackId): void {
            self::prepareStream();
            $t0 = microtime(true);
            try {
                $res = $transcoder->run($source, $plan, $startMs, self::sink(...), $cacheTarget);
                $ctx = ['track' => $trackId, 'format' => $plan['format'], 'bitrate' => $plan['bitrate'], 'startMs' => $startMs, 'octets' => $res['bytes'], 'secondes' => round(microtime(true) - $t0, 1)];
                if ($res['ok']) {
                    $logger->info('Conversion', $ctx + ['cache' => $cacheTarget !== null]);
                } else {
                    $logger->warning('Conversion échouée', $ctx + ['erreur' => $res['error']]);
                }
            } catch (ClientGone) {
                // L'auditeur a avancé ou changé de piste : normal, rien n'est gardé en cache
            } catch (\Throwable $e) {
                $logger->warning('Conversion interrompue', ['track' => $trackId, 'message' => $e->getMessage()]);
            }
        };
        return $response;
    }

    /** Avant d'écrire un flux : pas de limite de temps, pas de tampon, et on finit proprement si le client part. */
    private static function prepareStream(): void
    {
        @set_time_limit(0);
        ignore_user_abort(true);
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
    }

    private static function sink(string $chunk): void
    {
        echo $chunk;
        flush();
        if (connection_aborted()) {
            throw new ClientGone();
        }
    }

    /**
     * « bytes=0-99 », « bytes=100- », « bytes=-500 ». Une seule plage.
     * @return array{0: int, 1: int}|null [début, longueur] ; null = impossible à servir
     */
    public static function parseRange(string $header, int $size): ?array
    {
        if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $m) || ($m[1] === '' && $m[2] === '') || $size === 0) {
            return null;
        }
        if ($m[1] === '') {
            $suffix = (int) $m[2];
            if ($suffix === 0) {
                return null;
            }
            $start = max(0, $size - $suffix);
            return [$start, $size - $start];
        }
        $start = (int) $m[1];
        $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
        if ($start >= $size || $end < $start) {
            return null;
        }
        return [$start, $end - $start + 1];
    }

    private static function etagMatches(?string $ifNoneMatch, string $etag): bool
    {
        if ($ifNoneMatch === null) {
            return false;
        }
        foreach (explode(',', $ifNoneMatch) as $candidate) {
            $c = trim($candidate);
            if ($c === '*' || $c === $etag || $c === 'W/' . $etag) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, string> $headers */
    private static function with(Response $r, array $headers): Response
    {
        foreach ($headers as $k => $v) {
            $r->header($k, $v);
        }
        return $r;
    }

    // ---------------------------------------------------------------- images

    public function getImage(array $params): Response
    {
        $hex = (string) $params['hash'];
        $size = $this->ctx->request->queryEnum('size', ['s', 'm', 'l', 'original'], 'm');
        $img = $this->ctx->db->one(
            'SELECT i.source, i.mime_type, so.storage_key, so.missing_since
               FROM images i LEFT JOIN storage_objects so ON so.id = i.object_id WHERE i.sha256 = UNHEX(?)',
            [$hex]
        ) ?? throw ApiError::notFound('Image introuvable');

        $etag = '"' . $hex . '-' . $size . '"';
        if (self::etagMatches($this->ctx->request->header('if-none-match'), $etag)) {
            return self::with(new Response(304), ['ETag' => $etag, 'Cache-Control' => self::IMMUTABLE]);
        }

        $cache = $this->ctx->cacheDir();
        if ($size !== 'original') {
            $thumb = "$cache/thumbs/" . self::SIZES[$size] . '/' . substr($hex, 0, 2) . "/$hex.jpg";
            if (!is_file($thumb)) {
                $this->makeThumbnail($this->original($img, $hex, $cache), $thumb, self::SIZES[$size]);
            }
            if (is_file($thumb)) {
                return $this->imageResponse((string) file_get_contents($thumb), 'image/jpeg', $etag);
            }
            // Vignette impossible (format exotique) : on sert l'original
        }
        return $this->imageResponse($this->original($img, $hex, $cache), (string) $img['mime_type'], $etag);
    }

    /** Octets de l'image d'origine (voir Images::original). */
    private function original(array $img, string $hex, string $cache): string
    {
        return (new Images($this->ctx->db, $this->ctx->storage(), $cache))->original($img, $hex)
            ?? throw ApiError::notFound('Image absente du stockage');
    }

    private function makeThumbnail(string $bytes, string $target, int $px): void
    {
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            $this->ctx->logger->warning('Cache des vignettes inaccessible', ['dir' => $dir]);
            return;
        }
        $src = "$dir/." . bin2hex(random_bytes(6)) . '.src';
        $tmp = "$dir/." . bin2hex(random_bytes(6)) . '.jpg';
        try {
            file_put_contents($src, $bytes);
            $proc = proc_open(['ffmpeg', '-nostdin', '-v', 'error', '-y', '-i', $src, '-frames:v', '1',
                '-vf', "scale=w='min($px,iw)':h='min($px,ih)':force_original_aspect_ratio=decrease", '-q:v', '3', '-f', 'image2', $tmp],
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']], $pipes);
            $err = is_resource($proc) ? stream_get_contents($pipes[2]) : 'ffmpeg ne démarre pas';
            $code = is_resource($proc) ? proc_close($proc) : -1;
            if ($code === 0 && is_file($tmp) && filesize($tmp) > 0) {
                rename($tmp, $target); // atomique : deux requêtes simultanées ne voient jamais un fichier à moitié écrit
            } else {
                $this->ctx->logger->warning('Vignette impossible', ['target' => basename($target), 'error' => mb_substr((string) $err, 0, 300)]);
            }
        } finally {
            @unlink($src);
            @unlink($tmp);
        }
    }

    private function imageResponse(string $bytes, string $mime, string $etag): Response
    {
        $r = new Response(200, $bytes);
        return self::with($r, ['Content-Type' => $mime, 'Content-Length' => (string) strlen($bytes), 'ETag' => $etag, 'Cache-Control' => self::IMMUTABLE]);
    }
}

/** Le client a fermé la connexion pendant le flux. */
final class ClientGone extends \RuntimeException
{
}

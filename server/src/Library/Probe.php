<?php
// Lecture des fichiers audio par ffprobe (CAT-03), plusieurs à la fois.
// En mode S3, ffprobe lit une adresse signée et ne télécharge que l'en-tête du fichier.

declare(strict_types=1);

namespace Musique\Library;

final class Probe
{
    private const LOSSLESS = ['flac', 'alac', 'wav', 'aiff', 'ape', 'wavpack'];

    /** Correspondance des noms de tags (minuscules) vers un nom unique. */
    private const TAG_ALIASES = [
        'albumartist' => 'album_artist', 'album artist' => 'album_artist', 'tpe2' => 'album_artist',
        'tracknumber' => 'track', 'trck' => 'track',
        'discnumber' => 'disc', 'tpos' => 'disc',
        'year' => 'year', 'tyer' => 'year', 'tdrc' => 'date', 'originaldate' => 'originaldate',
        'tbpm' => 'bpm',
        'initialkey' => 'key', 'tkey' => 'key', 'initial key' => 'key',
        'tcmp' => 'compilation', 'itunescompilation' => 'compilation',
        'musicbrainz_albumid' => 'musicbrainz_album_id', 'musicbrainz album id' => 'musicbrainz_album_id',
    ];

    public function __construct(private readonly int $parallel = 8, private readonly int $timeoutSeconds = 60)
    {
    }

    /**
     * @param array<int|string, string> $sources identifiant → chemin ou adresse
     * @return array<int|string, array{ok: bool, error?: string, info?: array<string, mixed>}>
     */
    public function probeMany(array $sources): array
    {
        $results = [];
        $queue = $sources;
        /** @var array<int|string, array{proc: resource, out: resource, err: resource, stdout: string, stderr: string, start: float}> $running */
        $running = [];

        while ($queue !== [] || $running !== []) {
            while ($queue !== [] && count($running) < $this->parallel) {
                $id = array_key_first($queue);
                $src = $queue[$id];
                unset($queue[$id]);
                $cmd = ['ffprobe', '-v', 'error', '-show_entries',
                    'format=format_name,duration,bit_rate:format_tags:stream=codec_type,codec_name,sample_rate,channels,bits_per_raw_sample,bits_per_sample,bit_rate:stream_tags:stream_disposition=attached_pic',
                    '-of', 'json', $src];
                $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                if (!is_resource($proc)) {
                    $results[$id] = ['ok' => false, 'error' => 'ffprobe ne démarre pas'];
                    continue;
                }
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                $running[$id] = ['proc' => $proc, 'out' => $pipes[1], 'err' => $pipes[2], 'stdout' => '', 'stderr' => '', 'start' => microtime(true)];
            }

            $read = [];
            foreach ($running as $r) {
                $read[] = $r['out'];
                $read[] = $r['err'];
            }
            $w = $e = null;
            if ($read !== []) {
                @stream_select($read, $w, $e, 0, 200000);
            }
            foreach ($running as $id => &$r) {
                $r['stdout'] .= (string) stream_get_contents($r['out']);
                $r['stderr'] .= (string) stream_get_contents($r['err']);
                $status = proc_get_status($r['proc']);
                $timedOut = microtime(true) - $r['start'] > $this->timeoutSeconds;
                if ($status['running'] && !$timedOut) {
                    continue;
                }
                if ($timedOut && $status['running']) {
                    proc_terminate($r['proc'], 9);
                }
                $r['stdout'] .= (string) stream_get_contents($r['out']);
                $r['stderr'] .= (string) stream_get_contents($r['err']);
                fclose($r['out']);
                fclose($r['err']);
                proc_close($r['proc']);
                $exit = $status['running'] ? -1 : $status['exitcode'];
                $results[$id] = $timedOut
                    ? ['ok' => false, 'error' => "ffprobe : délai dépassé ({$this->timeoutSeconds} s)"]
                    : $this->parse($r['stdout'], $r['stderr'], $exit);
                unset($running[$id]);
            }
            unset($r);
        }
        return $results;
    }

    /** @return array{ok: bool, error?: string, info?: array<string, mixed>} */
    private function parse(string $stdout, string $stderr, int $exit): array
    {
        $json = json_decode(Text::utf8($stdout), true);
        if ($exit !== 0 || !is_array($json)) {
            $msg = trim(Text::utf8($stderr)) ?: "code de sortie $exit";
            // Une adresse signée ne doit jamais finir dans un message d'erreur
            $msg = preg_replace('#https?://\S+#', '<adresse>', $msg) ?? $msg;
            return ['ok' => false, 'error' => 'ffprobe : ' . mb_substr($msg, 0, 400)];
        }
        $audio = null;
        $hasPicture = false;
        foreach ($json['streams'] ?? [] as $s) {
            if (($s['codec_type'] ?? '') === 'audio' && $audio === null) {
                $audio = $s;
            }
            if (($s['codec_type'] ?? '') === 'video' && (int) ($s['disposition']['attached_pic'] ?? 0) === 1) {
                $hasPicture = true;
            }
        }
        if ($audio === null) {
            return ['ok' => false, 'error' => 'aucun flux audio'];
        }
        // Les tags peuvent être au niveau du fichier (MP3, FLAC) ou du flux (Ogg, Opus)
        $tags = self::normalizeTags(array_merge($audio['tags'] ?? [], $json['format']['tags'] ?? []));

        $codec = strtolower((string) ($audio['codec_name'] ?? ''));
        $format = match (true) {
            $codec === 'mp3' => 'mp3',
            $codec === 'flac' => 'flac',
            $codec === 'opus' => 'opus',
            $codec === 'vorbis' => 'vorbis',
            $codec === 'aac' => 'aac',
            $codec === 'alac' => 'alac',
            str_starts_with($codec, 'pcm_') => 'wav',
            default => 'other',
        };
        $bitDepth = (int) ($audio['bits_per_raw_sample'] ?? 0) ?: (int) ($audio['bits_per_sample'] ?? 0);
        $bitrate = (int) ($audio['bit_rate'] ?? 0) ?: (int) ($json['format']['bit_rate'] ?? 0);

        return ['ok' => true, 'info' => [
            'format' => $format,
            'lossless' => in_array($format, self::LOSSLESS, true) || str_starts_with($codec, 'pcm_'),
            'bitrate_kbps' => $bitrate > 0 ? min(65535, (int) round($bitrate / 1000)) : null,
            'sample_rate_hz' => (int) ($audio['sample_rate'] ?? 0) ?: null,
            'bit_depth' => $bitDepth > 0 ? $bitDepth : null,
            'channels' => (int) ($audio['channels'] ?? 0) ?: null,
            'duration_ms' => (int) round(((float) ($json['format']['duration'] ?? 0)) * 1000),
            'has_picture' => $hasPicture,
            'tags' => $tags,
        ]];
    }

    /**
     * Noms en minuscules, synonymes ramenés à un seul nom, valeurs en UTF-8 valide.
     * @param array<string, mixed> $raw
     * @return array<string, string>
     */
    public static function normalizeTags(array $raw): array
    {
        $out = [];
        foreach ($raw as $name => $value) {
            $k = strtolower(trim((string) $name));
            $k = self::TAG_ALIASES[$k] ?? $k;
            $v = Text::clean(is_scalar($value) ? (string) $value : null);
            if ($v === null || isset($out[$k])) {
                continue;
            }
            $out[$k] = mb_substr($v, 0, 2000);
        }
        if (!isset($out['date']) && isset($out['year'])) {
            $out['date'] = $out['year'];
        }
        return $out;
    }
}

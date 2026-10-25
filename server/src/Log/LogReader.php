<?php
// Lecture des journaux techniques pour l'admin (JRN-07) : api.log, cli.log (une ligne JSON par
// événement, voir Logger) et securite.log (format fail2ban, voir SecurityLog), y compris les fichiers
// tournés par logrotate (.1, .2.gz…). Du plus récent au plus ancien.
//
// Pagination par curseur « fichier.lignes » : numéro du fichier (0 = le courant) et nombre de lignes
// déjà parcourues depuis sa fin. Une page s'arrête à `limit` entrées ou après SCAN_MAX lignes lues.

declare(strict_types=1);

namespace Musique\Log;

use Musique\Config;

final class LogReader
{
    public const SOURCES = ['api', 'cli', 'securite'];
    public const SCAN_MAX = 200_000;

    public function __construct(private readonly string $dir)
    {
    }

    public static function fromConfig(Config $config): self
    {
        return new self(rtrim($config->string('journaux', 'dossier', '/var/log/rengaine'), '/'));
    }

    /** Fichiers d'une source, du plus récent au plus ancien. @return list<string> */
    public function files(string $source): array
    {
        $base = "{$this->dir}/$source.log";
        $out = [];
        if (is_file($base)) {
            $out[0] = $base;
        }
        foreach (glob($base . '.*') ?: [] as $f) {
            if (preg_match('/\.log\.(\d+)(\.gz)?$/', $f, $m)) {
                $out[(int) $m[1]] = $f;
            }
        }
        ksort($out);
        return array_values($out);
    }

    public static function validCursor(?string $cursor): bool
    {
        return $cursor === null || preg_match('/^\d{1,3}\.\d{1,9}$/', $cursor) === 1;
    }

    /**
     * @param array{level?: ?string, q?: ?string, requestId?: ?string} $filters
     * @return array{items: list<array<string, mixed>>, next: ?string}
     */
    public function read(string $source, array $filters, ?string $cursor, int $limit): array
    {
        $files = $this->files($source);
        [$fileIndex, $skip] = $cursor === null ? [0, 0] : array_map('intval', explode('.', $cursor));
        $minRank = Logger::LEVELS[$filters['level'] ?? 'debug'] ?? 0;
        $q = ($filters['q'] ?? '') === '' ? null : (string) $filters['q'];
        $rid = $filters['requestId'] ?? null;

        $items = [];
        $scanned = 0;
        for ($f = $fileIndex; $f < count($files); $f++, $skip = 0) {
            $lines = self::lines($files[$f]);
            $n = count($lines);
            for ($i = $n - 1 - $skip; $i >= 0; $i--) {
                $scanned++;
                $line = $lines[$i];
                if (($q === null || stripos($line, $q) !== false || stripos(str_replace('\\/', '/', $line), $q) !== false) && ($entry = self::parse($line, $source)) !== null
                    && Logger::LEVELS[$entry['level']] >= $minRank && ($rid === null || $entry['requestId'] === $rid)) {
                    $items[] = $entry;
                }
                $consumed = $n - $i;
                if (count($items) >= $limit || $scanned >= self::SCAN_MAX) {
                    // Suite : la ligne d'après dans ce fichier, ou le fichier suivant
                    $next = $consumed < $n ? "$f.$consumed" : ($f + 1 < count($files) ? ($f + 1) . '.0' : null);
                    return ['items' => $items, 'next' => $next];
                }
            }
        }
        return ['items' => $items, 'next' => null];
    }

    /** @return list<string> lignes non vides */
    private static function lines(string $file): array
    {
        $raw = str_ends_with($file, '.gz') ? @gzfile($file) : @file($file);
        if ($raw === false) {
            return [];
        }
        return array_values(array_filter(array_map(static fn ($l) => rtrim($l, "\r\n"), $raw), static fn ($l) => $l !== ''));
    }

    /** Une ligne vers une entrée de l'API ; null si illisible. @return ?array<string, mixed> */
    public static function parse(string $line, string $source): ?array
    {
        if ($source === 'securite') {
            if (!preg_match('/^(\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d)Z musique (.*)$/', $line, $m)) {
                return null;
            }
            $ctx = [];
            foreach (preg_split('/\s+/', trim($m[2])) ?: [] as $pair) {
                [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
                $ctx[$k] = $v;
            }
            $event = $ctx['event'] ?? '?';
            $rid = $ctx['rid'] ?? null;
            unset($ctx['event'], $ctx['rid']);
            return ['ts' => $m[1] . '.000Z', 'level' => 'warning', 'source' => 'securite', 'requestId' => $rid,
                'userId' => isset($ctx['user']) && ctype_digit($ctx['user']) ? (int) $ctx['user'] : null,
                'message' => $event, 'context' => $ctx === [] ? null : $ctx];
        }
        $d = json_decode($line, true);
        if (!is_array($d) || !is_string($d['ts'] ?? null) || !isset(Logger::LEVELS[$d['level'] ?? ''])) {
            return null;
        }
        return [
            'ts' => $d['ts'],
            'level' => $d['level'],
            'source' => $source,
            'requestId' => is_string($d['requestId'] ?? null) ? $d['requestId'] : null,
            'userId' => is_int($d['userId'] ?? null) ? $d['userId'] : null,
            'message' => (string) ($d['msg'] ?? ''),
            'context' => is_array($d['ctx'] ?? null) && $d['ctx'] !== [] ? $d['ctx'] : null,
        ];
    }
}

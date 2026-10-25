<?php
// BPM et tonalité (AUD-03, AUD-04), énergie et dansabilité (AUD-06, analyse seulement : pas de tag),
// grilles des temps du début et de la fin (LEC-06, analyse seulement).
//
// D'où vient la valeur, du plus fort au plus faible :
//   1. une correction à la main (source « manual », jamais écrasée ici) ;
//   2. le tag du fichier (TBPM, TKEY / initialkey), s'il est lisible ;
//   3. l'analyse par Essentia (server/tools/rythme_tonalite.py, environnement /opt/rengaine-analyse).
// Sans Essentia installé, seuls les tags servent : l'analyse se fera quand il le sera.

declare(strict_types=1);

namespace Musique\Audio;

use Musique\Config;

final class Rhythm
{
    public const DEFAULT_PYTHON = '/opt/rengaine-analyse/bin/python';
    public const BPM_MIN = 20;
    public const BPM_MAX = 300;

    /** Notation anglaise → Camelot (les deux écritures des altérations). */
    private const CAMELOT = [
        'G#m' => '1A', 'Abm' => '1A', 'D#m' => '2A', 'Ebm' => '2A', 'A#m' => '3A', 'Bbm' => '3A', 'Fm' => '4A', 'Cm' => '5A',
        'Gm' => '6A', 'Dm' => '7A', 'Am' => '8A', 'Em' => '9A', 'Bm' => '10A', 'F#m' => '11A', 'Gbm' => '11A', 'C#m' => '12A', 'Dbm' => '12A',
        'B' => '1B', 'Cb' => '1B', 'F#' => '2B', 'Gb' => '2B', 'Db' => '3B', 'C#' => '3B', 'Ab' => '4B', 'G#' => '4B', 'Eb' => '5B', 'D#' => '5B',
        'Bb' => '6B', 'A#' => '6B', 'F' => '7B', 'C' => '8B', 'G' => '9B', 'D' => '10B', 'A' => '11B', 'E' => '12B',
    ];
    /** Camelot → écriture retenue (celle des DJ : bémols pour Ab, Eb, Bb, Db ; dièses pour F#, C#m…). */
    private const FROM_CAMELOT = [
        '1A' => 'Abm', '2A' => 'Ebm', '3A' => 'Bbm', '4A' => 'Fm', '5A' => 'Cm', '6A' => 'Gm', '7A' => 'Dm', '8A' => 'Am',
        '9A' => 'Em', '10A' => 'Bm', '11A' => 'F#m', '12A' => 'C#m',
        '1B' => 'B', '2B' => 'F#', '3B' => 'Db', '4B' => 'Ab', '5B' => 'Eb', '6B' => 'Bb', '7B' => 'F', '8B' => 'C',
        '9B' => 'G', '10B' => 'D', '11B' => 'A', '12B' => 'E',
    ];

    private ?string $version = null;

    public function __construct(private readonly string $python, private readonly string $script)
    {
    }

    /** null si Essentia n'est pas installé (ou désactivé : `[analyse] python = ""`). */
    public static function fromConfig(Config $config): ?self
    {
        $python = $config->string('analyse', 'python', self::DEFAULT_PYTHON);
        $script = \Musique\REPO_DIR . '/server/tools/rythme_tonalite.py';
        if ($python === '' || !is_executable($python) || !is_file($script)) {
            return null;
        }
        return new self($python, $script);
    }

    /** @return list<string> */
    public function command(string $file): array
    {
        return [$this->python, $this->script, $file];
    }

    /** Version d'Essentia et de la méthode, ex. « essentia 2.1-beta6-dev;rx2013-multifeature+key-bgate ». */
    public function version(): string
    {
        if ($this->version === null) {
            $out = trim((string) @shell_exec(escapeshellarg($this->python) . ' ' . escapeshellarg($this->script) . ' --version 2>/dev/null'));
            $this->version = $out !== '' ? $out : 'essentia ?';
        }
        return $this->version;
    }

    /**
     * Lit la ligne JSON du script.
     * @return array{bpm: ?float, confidence: float, key: ?string, strength: float, energy: ?float, danceability: ?float, grid: ?array{intro: ?array{bpm: float, offsetMs: float}, outro: ?array{bpm: float, offsetMs: float}}}
     */
    public static function parse(string $stdout): array
    {
        $line = trim((string) strrchr("\n" . trim($stdout), "\n"));
        $d = json_decode($line, true);
        if (!is_array($d) || !array_key_exists('bpm', $d)) {
            throw new \RuntimeException('réponse illisible : ' . mb_substr($line, 0, 200));
        }
        $bpm = is_numeric($d['bpm']) ? (float) $d['bpm'] : null;
        $key = is_string($d['key'] ?? null) ? self::normalizeKey($d['key'] . (($d['scale'] ?? '') === 'minor' ? 'm' : '')) : null;
        return [
            'bpm' => $bpm !== null && $bpm >= self::BPM_MIN && $bpm <= self::BPM_MAX ? round($bpm, 2) : null,
            'confidence' => (float) ($d['bpmConfidence'] ?? 0),
            'key' => $key,
            'strength' => (float) ($d['keyStrength'] ?? 0),
            'energy' => self::unit($d['energy'] ?? null),
            'danceability' => self::unit($d['danceability'] ?? null),
            'grid' => self::grid($d['beatGrid'] ?? null),
        ];
    }

    /**
     * Grilles des temps : chaque bout est gardé s'il est plausible ; null si aucun ne l'est.
     * @return ?array{intro: ?array{bpm: float, offsetMs: float}, outro: ?array{bpm: float, offsetMs: float}}
     */
    public static function grid(mixed $g): ?array
    {
        if (!is_array($g)) {
            return null;
        }
        $one = static function (mixed $e): ?array {
            if (!is_array($e) || !is_numeric($e['bpm'] ?? null) || !is_numeric($e['offsetMs'] ?? null)) {
                return null;
            }
            $bpm = (float) $e['bpm'];
            $offset = (float) $e['offsetMs'];
            if ($bpm < self::BPM_MIN || $bpm > self::BPM_MAX || $offset < 0 || $offset >= 60000 / $bpm + 1) {
                return null;
            }
            return ['bpm' => round($bpm, 3), 'offsetMs' => round($offset, 1)];
        };
        $out = ['intro' => $one($g['intro'] ?? null), 'outro' => $one($g['outro'] ?? null)];
        return $out['intro'] === null && $out['outro'] === null ? null : $out;
    }

    /** Nombre entre 0 et 1 (borné), ou null. */
    private static function unit(mixed $v): ?float
    {
        return is_numeric($v) ? round(min(1.0, max(0.0, (float) $v)), 3) : null;
    }

    /** BPM d'un tag : un nombre plausible, sinon null (« 0 » et le vide sont fréquents). */
    public static function tagBpm(mixed $raw): ?float
    {
        if (!is_scalar($raw)) {
            return null;
        }
        $v = str_replace(',', '.', trim((string) $raw));
        if (!is_numeric($v)) {
            return null;
        }
        $bpm = (float) $v;
        return $bpm >= self::BPM_MIN && $bpm <= self::BPM_MAX ? round($bpm, 2) : null;
    }

    /**
     * Tonalité d'un tag ou de l'analyse, ramenée à la notation anglaise courte : « Am », « F# », « Ebm ».
     * Accepte « A minor », « a min », « Amin », « A♭m », « 8A », « 08B »… ; null si illisible.
     */
    public static function normalizeKey(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $s = trim(str_replace(['♯', '♭'], ['#', 'b'], $raw));
        if (preg_match('/^0?(1[0-2]|[1-9])\s*([AaBb])$/', $s, $m)) {
            return self::FROM_CAMELOT[$m[1] . strtoupper($m[2])];
        }
        if (!preg_match('/^([A-Ga-g])\s*([#b]?)\s*(m|min|minor|moll|maj|major|dur)?$/i', $s, $m)) {
            return null;
        }
        $minor = isset($m[3]) && in_array(strtolower($m[3]), ['m', 'min', 'minor', 'moll'], true);
        $key = strtoupper($m[1]) . $m[2] . ($minor ? 'm' : '');
        if (!isset(self::CAMELOT[$key])) {
            return null; // E#, Fb… : pas une écriture utilisée
        }
        // Une seule écriture par tonalité (celle de FROM_CAMELOT)
        return self::FROM_CAMELOT[self::CAMELOT[$key]];
    }

    public static function camelot(?string $key): ?string
    {
        return $key === null ? null : (self::CAMELOT[$key] ?? null);
    }
}

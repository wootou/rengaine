<?php
// Mesure d'un fichier audio par ffmpeg, en un seul décodage (AUD-01, AUD-02) :
//   - ebur128   : volume intégré (LUFS), plage de volume (LU), crête vraie (dBTP) ;
//   - silencedetect : silences de début et de fin, qui servent de points de fondu ;
//   - astats par tranches : la forme d'onde (AUD-07), un niveau RMS par tranche, environ WAVE_POINTS par piste ;
//   - au passage, les trames que le décodeur n'a pas pu lire (fichier abîmé) : ffmpeg les saute à la
//     lecture, mais on entend un accroc à cet endroit.
// Le serveur donne la mesure ; le gain est calculé par le client selon la cible choisie.

declare(strict_types=1);

namespace Musique\Audio;

final class Measure
{
    /** En dessous de ce niveau, c'est du silence (souffle d'un vinyle compris). */
    public const SILENCE_DB = -50;
    /** Durée minimale d'un silence. */
    public const SILENCE_SECONDS = 0.2;
    /** Change quand la méthode change : les pistes mesurées avant seront remesurées. */
    public const METHOD = 'r128-tp+sd50-0.2+wf800/2';
    /** Forme d'onde : nombre de tranches visé, durée minimale d'une tranche, nombre de niveaux au plus. */
    public const WAVE_POINTS = 800;
    public const WAVE_MIN_MS = 50;
    public const WAVE_MAX_POINTS = 2000;
    /** Un niveau tient sur un octet : 0 = -63,75 dBFS ou moins, 255 = 0 dBFS (pas de 0,25 dB). */
    public const WAVE_FLOOR_DB = -63.75;

    /**
     * @param int $durationMs durée connue (index), pour régler la taille des tranches
     * @param ?int $sampleRate fréquence d'échantillonnage connue, 44 100 Hz sinon
     * @return list<string>
     */
    public static function command(string $source, int $durationMs = 0, ?int $sampleRate = null): array
    {
        $rate = $sampleRate !== null && $sampleRate > 0 ? $sampleRate : 44100;
        $window = self::waveWindow($durationMs, $rate);
        return ['ffmpeg', '-nostdin', '-hide_banner', '-nostats', '-loglevel', 'info', '-i', $source, '-vn',
            '-af', sprintf('ebur128=peak=true:framelog=quiet,silencedetect=noise=%ddB:duration=%s,', self::SILENCE_DB, self::SILENCE_SECONDS)
                . "asetnsamples=n=$window:p=1,astats=metadata=1:reset=1:measure_perchannel=none:measure_overall=RMS_level,"
                . 'ametadata=mode=print:key=lavfi.astats.Overall.RMS_level',
            '-f', 'null', '-'];
    }

    /** Taille d'une tranche, en échantillons. */
    public static function waveWindow(int $durationMs, int $sampleRate): int
    {
        $byDuration = (int) ceil($sampleRate * max(0, $durationMs) / 1000 / self::WAVE_POINTS);
        return max((int) ceil($sampleRate * self::WAVE_MIN_MS / 1000), $byDuration);
    }

    /**
     * Niveaux de la forme d'onde lus dans la sortie de ffmpeg, un octet par tranche (voir WAVE_FLOOR_DB).
     * null s'il n'y en a pas, ou trop (durée de l'index fausse : on ne garde pas une forme tronquée).
     */
    public static function parseWave(string $output): ?string
    {
        preg_match_all('/lavfi\.astats\.Overall\.RMS_level=(\S+)/', $output, $m);
        $n = count($m[1]);
        if ($n === 0 || $n > self::WAVE_MAX_POINTS) {
            return null;
        }
        $bytes = '';
        foreach ($m[1] as $v) {
            $db = is_numeric($v) ? (float) $v : -INF; // « -inf » : tranche silencieuse
            $bytes .= chr((int) max(0, min(255, round(($db - self::WAVE_FLOOR_DB) * 4))));
        }
        return $bytes;
    }

    /** Octets → niveaux en dBFS (null = silence). @return list<?float> */
    public static function waveLevels(string $bytes): array
    {
        $out = [];
        foreach (unpack('C*', $bytes) ?: [] as $b) {
            $out[] = $b === 0 ? null : self::WAVE_FLOOR_DB + $b / 4;
        }
        return $out;
    }

    /** Durée d'une tranche, en ms. */
    public static function waveStepMs(int $durationMs, ?int $sampleRate): float
    {
        $rate = $sampleRate !== null && $sampleRate > 0 ? $sampleRate : 44100;
        return round(self::waveWindow($durationMs, $rate) * 1000 / $rate, 3);
    }

    /**
     * Lit la sortie (stderr) de ffmpeg.
     * @return array{loudness_lufs: ?float, loudness_range_lu: ?float, true_peak_dbtp: ?float, audio_start_ms: int, audio_end_ms: int, silent: bool, waveform: ?string, decode_errors: int, decode_detail: ?string}
     */
    public static function parse(string $output, int $durationMs): array
    {
        $pos = strrpos($output, 'Summary:');
        if ($pos === false) {
            throw new \RuntimeException('sortie de ffmpeg sans résumé EBU R128');
        }
        $summary = substr($output, $pos);
        $num = static function (string $pattern) use ($summary): ?float {
            if (!preg_match($pattern, $summary, $m) || !is_numeric($m[1])) {
                return null; // -inf, nan
            }
            return round((float) $m[1], 2);
        };
        $loudness = $num('/\bI:\s+(\S+)\s+LUFS/');
        $range = $num('/\bLRA:\s+(\S+)\s+LU\b/');
        $peak = $num('/\bPeak:\s+(\S+)\s+dBFS/');

        // Intervalles de silence, dans l'ordre (le dernier peut rester ouvert jusqu'à la fin)
        $silences = [];
        preg_match_all('/silence_(start|end):\s*(-?[\d.]+)/', $output, $mm, PREG_SET_ORDER);
        foreach ($mm as $m) {
            $ms = (int) round((float) $m[2] * 1000);
            if ($m[1] === 'start') {
                $silences[] = [$ms, null];
            } elseif ($silences !== [] && $silences[count($silences) - 1][1] === null) {
                $silences[count($silences) - 1][1] = $ms;
            }
        }

        $duration = max(0, $durationMs);
        $start = 0;
        $end = $duration;
        $silent = false;
        if ($silences !== []) {
            [$s0, $e0] = $silences[0];
            if ($s0 <= 50) {
                if ($e0 === null || $e0 >= $duration - 100) {
                    $silent = true; // tout le fichier est silencieux
                } else {
                    $start = $e0;
                }
            }
            [$sN, $eN] = $silences[count($silences) - 1];
            if (!$silent && ($eN === null || $eN >= $duration - 100) && $sN > $start) {
                $end = $sN;
            }
        }
        if ($duration > 0) {
            $start = min($start, $duration);
            $end = max($start, min($end, $duration));
        }
        if ($loudness !== null && $loudness <= -70) {
            $silent = true;
        }

        return [
            'loudness_lufs' => $silent ? null : $loudness,
            'loudness_range_lu' => $silent ? null : $range,
            'true_peak_dbtp' => $peak,
            'audio_start_ms' => $silent ? 0 : $start,
            'audio_end_ms' => $silent ? $duration : $end,
            'silent' => $silent,
            'waveform' => self::parseWave($output),
        ] + self::decodeErrors($output, $durationMs);
    }

    /** Une trame illisible dans cette fin de fichier est une fin coupée à l'encodage : inaudible, ignorée. */
    public const DECODE_TAIL_MS = 1500;

    /**
     * Trames illisibles : ffmpeg écrit « Decoding error » (FLAC…) ou « Error submitting packet to
     * decoder » (MP3…) pour chacune, précédée de la raison donnée par le décodeur. La position vient
     * des lignes de la forme d'onde intercalées (pts_time), à une tranche près.
     * @return array{decode_errors: int, decode_detail: ?string} detail : « à 0:35 et 2:08 : flac : invalid subframe padding »
     */
    public static function decodeErrors(string $output, int $durationMs = 0): array
    {
        $positions = [];
        $reasons = [];
        /** Premier message d'une série, par composant : [texte, ligne]. @var array<string, array{string, int}> */
        $said = [];
        $lastMs = 0;
        $i = 0;
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (str_starts_with($line, '[Parsed_')) {
                // Niveaux de la forme d'onde : donnent la position, ne comptent pas dans la distance
                if (preg_match('/pts_time:([\d.]+)/', $line, $m)) {
                    $lastMs = (int) round((float) $m[1] * 1000);
                }
                continue;
            }
            $i++;
            if (preg_match('/\[dec:([^ \]]+)[^\]]*\] (?:Decoding error|Error submitting packet to decoder)/', $line, $d)) {
                // La raison : ce que ce décodeur-là vient de dire (pas le lecteur du fichier, ni un message ancien)
                $own = $said[$d[1]] ?? null;
                if ($durationMs <= 0 || $lastMs < $durationMs - self::DECODE_TAIL_MS) {
                    $positions[] = $lastMs;
                    $reasons[] = $own !== null && $i - $own[1] <= 5 ? $own[0] : trim((string) preg_replace('/^.*\[dec:([^ \]]+)[^\]]*\] /', '$1 : ', $line));
                }
                unset($said[$d[1]]);
            } elseif (preg_match('/^\[([a-z0-9_]+) @ 0x[0-9a-f]+\] (.+)$/', $line, $m) && $m[1] !== 'silencedetect') {
                // Le premier message d'une série dit pourquoi (« invalid subframe padding ») ; les suivants, qu'il a échoué
                if (!isset($said[$m[1]]) || $i - $said[$m[1]][1] > 5) {
                    $said[$m[1]] = ["$m[1] : " . trim($m[2]), $i];
                }
            }
        }
        if ($positions === []) {
            return ['decode_errors' => 0, 'decode_detail' => null];
        }
        $all = array_values(array_unique(array_map(static fn (int $ms): string => sprintf('%d:%02d', intdiv($ms, 60000), intdiv($ms, 1000) % 60), $positions)));
        $at = array_slice($all, 0, 5);
        $where = count($at) === 1 ? $at[0] : implode(', ', array_slice($at, 0, -1)) . ' et ' . end($at);
        $more = count($all) > 5 ? ' (…)' : '';
        return ['decode_errors' => count($positions), 'decode_detail' => mb_substr("à $where$more : {$reasons[0]}", 0, 255)];
    }

    /**
     * Volume d'un album à partir de ses pistes : moyenne de l'énergie pondérée par la durée
     * (une piste très douce ne tire pas tout l'album vers le bas comme le ferait une moyenne des LUFS).
     * @param list<array{lufs: float, ms: int}> $tracks
     */
    public static function albumLoudness(array $tracks): ?float
    {
        $energy = $total = 0.0;
        foreach ($tracks as $t) {
            if ($t['ms'] <= 0) {
                continue;
            }
            $energy += $t['ms'] * 10 ** ($t['lufs'] / 10);
            $total += $t['ms'];
        }
        return $total > 0 ? round(10 * log10($energy / $total), 2) : null;
    }
}

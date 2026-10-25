<?php
// Empreinte acoustique (BAS-03) : reconnaître le même enregistrement dans plusieurs fichiers, même
// quand les tags, le format, le volume ou le début diffèrent (autre extraction, autre source).
//
// fpcalc (Chromaprint) donne une suite d'entiers de 32 bits, environ 8 par seconde, sur les
// MAX_SECONDS premières secondes. Deux étapes :
//   1. repérage : quelques valeurs de chaque empreinte (une sur KEY_MODULO, choisie par un hachage de
//      la valeur : les bits faibles de Chromaprint ne sont pas répartis au hasard, certaines pistes
//      n'ont aucune valeur divisible par 16) sont
//      indexées en base ; deux pistes qui en partagent au moins MIN_SHARED_KEYS sont candidates.
//      Le même enregistrement en partage des dizaines, deux morceaux différents aucune ;
//   2. vérification : proportion de bits identiques au meilleur décalage (± MAX_OFFSET valeurs,
//      soit une quinzaine de secondes).
// Mesuré sur le dev : FLAC et MP3 d'une piste 0,999 ; extraction décalée de 3 s, +2 dB, MP3 128
// 0,967 ; égalisation et compression changées 0,887 ; morceaux différents 0,51 à 0,54.

declare(strict_types=1);

namespace Musique\Audio;

use Musique\Config;

final class Fingerprint
{
    public const MAX_SECONDS = 120;
    public const KEY_MODULO = 8;
    public const MIN_SHARED_KEYS = 3;
    public const MAX_OFFSET = 120;
    /** Moins de valeurs en commun (après décalage) : comparaison non significative. */
    public const MIN_OVERLAP = 80;
    /** Seuils : même enregistrement (autre encodage), très proche (autre extraction, remasterisé). */
    public const SAME = 0.95;
    public const CLOSE = 0.80;
    /** Une valeur présente dans plus de pistes que ça ne départage rien (silence, bruit) : ignorée. */
    public const COMMON_KEY_TRACKS = 50;

    /** @var array<int, int> nombre de bits à 1 de chaque valeur sur 16 bits */
    private static array $bits = [];

    public function __construct(private readonly string $fpcalc)
    {
    }

    /** null si fpcalc n'est pas installé (paquet libchromaprint-tools) ou désactivé (`[analyse] fpcalc = ""`). */
    public static function fromConfig(Config $config): ?self
    {
        $bin = $config->string('analyse', 'fpcalc', 'fpcalc');
        if ($bin === '') {
            return null;
        }
        if (!str_contains($bin, '/')) {
            $found = trim((string) @shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null'));
            if ($found === '') {
                return null;
            }
            $bin = $found;
        }
        return is_executable($bin) ? new self($bin) : null;
    }

    /**
     * ffmpeg décode, fpcalc calcule : fpcalc seul s'arrête à la première trame abîmée (des FLAC du
     * dev en ont), ffmpeg la saute. Même empreinte que fpcalc seul pour un fichier sain.
     * @return list<string>
     */
    public function command(string $file): array
    {
        $seconds = (string) self::MAX_SECONDS;
        return ['sh', '-c',
            'ffmpeg -nostdin -v error -i "$1" -t ' . $seconds . ' -vn -ac 2 -ar 44100 -f s16le - | '
            . '"$2" -format s16le -rate 44100 -channels 2 -raw -json -length ' . $seconds . ' -',
            'empreinte', $file, $this->fpcalc];
    }

    /**
     * Lit la sortie de fpcalc.
     * @return list<int> valeurs (entiers positifs de 32 bits) ; vide si le fichier est trop court
     */
    public static function parse(string $stdout): array
    {
        $line = trim((string) strrchr("\n" . trim($stdout), "\n"));
        $d = json_decode($line, true);
        if (!is_array($d) || !isset($d['fingerprint']) || !is_array($d['fingerprint'])) {
            throw new \RuntimeException('réponse illisible : ' . mb_substr($line, 0, 200));
        }
        $out = [];
        foreach ($d['fingerprint'] as $v) {
            if (!is_int($v)) {
                throw new \RuntimeException('valeur d’empreinte invalide');
            }
            $out[] = $v & 0xFFFFFFFF; // fpcalc écrit parfois des entiers signés
        }
        return $out;
    }

    /** Stockage compact : 4 octets par valeur, en base64. @param list<int> $fp */
    public static function encode(array $fp): string
    {
        return base64_encode($fp === [] ? '' : pack('V*', ...$fp));
    }

    /** @return list<int> */
    public static function decode(?string $stored): array
    {
        $raw = $stored === null ? false : base64_decode($stored, true);
        if ($raw === false || $raw === '' || strlen($raw) % 4 !== 0) {
            return [];
        }
        return array_values(unpack('V*', $raw) ?: []);
    }

    /** Valeurs indexées pour le repérage. @param list<int> $fp @return list<int> */
    public static function keys(array $fp): array
    {
        $keys = [];
        foreach ($fp as $v) {
            if ($v !== 0 && crc32(pack('V', $v)) % self::KEY_MODULO === 0) {
                $keys[$v] = true;
            }
        }
        return array_keys($keys);
    }

    /**
     * Ressemblance de deux empreintes : proportion de bits identiques au meilleur décalage.
     * @param list<int> $a @param list<int> $b
     * @return array{score: float, offset: int} offset : décalage de b par rapport à a, en valeurs
     */
    public static function compare(array $a, array $b): array
    {
        if (self::$bits === []) {
            for ($i = 0; $i < 65536; $i++) {
                self::$bits[$i] = substr_count(decbin($i), '1');
            }
        }
        $bits = self::$bits;
        $na = count($a);
        $nb = count($b);
        $best = ['score' => 0.0, 'offset' => 0];
        // Repérage grossier (une valeur sur quatre), puis calcul complet autour des meilleurs décalages
        $coarse = [];
        for ($o = -self::MAX_OFFSET; $o <= self::MAX_OFFSET; $o++) {
            $from = max(0, -$o);
            $to = min($na, $nb - $o);
            if ($to - $from < self::MIN_OVERLAP) {
                continue;
            }
            $err = 0;
            $n = 0;
            for ($i = $from; $i < $to; $i += 4) {
                $x = $a[$i] ^ $b[$i + $o];
                $err += $bits[$x & 0xFFFF] + $bits[($x >> 16) & 0xFFFF];
                $n++;
            }
            $coarse[$o] = $err / ($n * 32);
        }
        asort($coarse);
        foreach (array_slice($coarse, 0, 3, true) as $o => $_) {
            $from = max(0, -$o);
            $to = min($na, $nb - $o);
            $err = 0;
            for ($i = $from; $i < $to; $i++) {
                $x = $a[$i] ^ $b[$i + $o];
                $err += $bits[$x & 0xFFFF] + $bits[($x >> 16) & 0xFFFF];
            }
            $score = 1 - $err / (($to - $from) * 32);
            if ($score > $best['score']) {
                $best = ['score' => round($score, 3), 'offset' => (int) $o];
            }
        }
        return $best;
    }

    /** Durée d'une valeur d'empreinte (Chromaprint : 4096 échantillons à 11 025 Hz, recouvrement 2/3). */
    public static function offsetMs(int $offset): int
    {
        return (int) round($offset * 4096 / 3 / 11025 * 1000);
    }
}

<?php
// BPM et tonalité (AUD-03, AUD-04) : lecture des tags et de la réponse d'Essentia, notation Camelot.

declare(strict_types=1);

namespace Musique\Tests;

use Musique\Audio\Rhythm;

final class RhythmTest extends TestCase
{
    public function testTonalitesDesTags(): void
    {
        $cases = [
            'Am' => 'Am', 'A minor' => 'Am', 'a min' => 'Am', 'Amin' => 'Am', 'A' => 'A', 'A major' => 'A', 'Amaj' => 'A',
            'F#m' => 'F#m', 'Gbm' => 'F#m', 'F♯m' => 'F#m', 'Ab' => 'Ab', 'G#' => 'Ab', 'A♭' => 'Ab', 'bbm' => 'Bbm', 'A#m' => 'Bbm',
            'C#' => 'Db', 'Db' => 'Db', 'Ebm' => 'Ebm', 'D#m' => 'Ebm', 'B' => 'B', 'Bm' => 'Bm',
            '8A' => 'Am', '08A' => 'Am', '8a' => 'Am', '12B' => 'E', '1A' => 'Abm', '3B' => 'Db',
            '' => null, 'H' => null, 'E#' => null, '13A' => null, 'Am7' => null, 'o' => null,
        ];
        foreach ($cases as $raw => $expected) {
            $this->assertSame($expected, Rhythm::normalizeKey((string) $raw), "« $raw »");
        }
        $this->assertNull(Rhythm::normalizeKey(null));
    }

    public function testCamelotDeChaqueTonalite(): void
    {
        $this->assertSame('8A', Rhythm::camelot('Am'));
        $this->assertSame('8B', Rhythm::camelot('C'));
        $this->assertSame('11A', Rhythm::camelot('F#m'));
        $this->assertSame('4B', Rhythm::camelot('Ab'));
        // Les 24 tonalités retenues ont chacune leur code, et il revient à la même écriture
        $codes = [];
        foreach (['A', 'B'] as $l) {
            foreach (range(1, 12) as $n) {
                $key = Rhythm::normalizeKey("$n$l");
                $this->assertSame("$n$l", Rhythm::camelot($key), "$n$l");
                $codes[$key] = true;
            }
        }
        $this->assertSame(24, count($codes));
    }

    public function testBpmDesTags(): void
    {
        $this->assertSame(128.0, Rhythm::tagBpm('128'));
        $this->assertSame(99.86, Rhythm::tagBpm('99.86'));
        $this->assertSame(119.5, Rhythm::tagBpm('119,5'));
        $this->assertSame(85.0, Rhythm::tagBpm(85));
        foreach (['0', '', 'abc', '400', '12', null, ['128']] as $bad) {
            $this->assertNull(Rhythm::tagBpm($bad), 'tag ' . json_encode($bad));
        }
    }

    public function testReponseDEssentia(): void
    {
        $r = Rhythm::parse("[   INFO   ] bavard\n" . '{"bpm": 124.06, "bpmConfidence": 3.2, "key": "C#", "scale": "minor", "keyStrength": 0.71}' . "\n");
        $this->assertSame(['bpm' => 124.06, 'confidence' => 3.2, 'key' => 'C#m', 'strength' => 0.71, 'energy' => null, 'danceability' => null, 'grid' => null], $r);
        $full = Rhythm::parse('{"bpm": 98, "bpmConfidence": 2, "key": "A", "scale": "major", "keyStrength": 0.9, "energy": 0.6912, "danceability": 1.7}');
        $this->assertSame([0.691, 1.0], [$full['energy'], $full['danceability']], 'arrondi, et borné à 1');
        $this->assertSame('Ab', Rhythm::parse('{"bpm": 90, "bpmConfidence": 1, "key": "Ab", "scale": "major", "keyStrength": 0.5}')['key']);
        $short = Rhythm::parse('{"bpm": null, "bpmConfidence": 0, "key": null, "scale": null, "keyStrength": 0}');
        $this->assertSame([null, null], [$short['bpm'], $short['key']], 'piste trop courte');
        $this->assertNull(Rhythm::parse('{"bpm": 5, "bpmConfidence": 0, "key": "A", "scale": "major", "keyStrength": 0}')['bpm'], 'BPM aberrant');
        $this->assertThrows(\RuntimeException::class, static fn () => Rhythm::parse('Traceback (most recent call last)'));
    }

    public function testGrillesDesTemps(): void
    {
        $r = Rhythm::parse('{"bpm": 124, "bpmConfidence": 3, "key": "A", "scale": "minor", "keyStrength": 0.7, "energy": 0.5, "danceability": 0.5,'
            . ' "beatGrid": {"intro": {"bpm": 124.0612, "offsetMs": 312.54}, "outro": null}}');
        $this->assertSame(['intro' => ['bpm' => 124.061, 'offsetMs' => 312.5], 'outro' => null], $r['grid']);
        $this->assertNull(Rhythm::parse('{"bpm": 124, "bpmConfidence": 3, "key": null, "scale": null, "keyStrength": 0}')['grid'], 'ancienne version du script');
        $this->assertNull(Rhythm::grid(['intro' => null, 'outro' => null]));
        $this->assertNull(Rhythm::grid('x'));
        // Valeurs aberrantes écartées, bout par bout
        $g = Rhythm::grid(['intro' => ['bpm' => 5, 'offsetMs' => 10], 'outro' => ['bpm' => 120, 'offsetMs' => 499.9]]);
        $this->assertSame(['intro' => null, 'outro' => ['bpm' => 120.0, 'offsetMs' => 499.9]], $g);
        $this->assertNull(Rhythm::grid(['intro' => ['bpm' => 120, 'offsetMs' => 900]]), 'décalage plus long qu’un temps');
        $this->assertNull(Rhythm::grid(['intro' => ['bpm' => 120, 'offsetMs' => -1]]));
    }
}

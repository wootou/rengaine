<?php
// Empreinte acoustique (BAS-03) : lecture de fpcalc, stockage, valeurs indexées, comparaison.

declare(strict_types=1);

namespace Musique\Tests;

use Musique\Audio\Fingerprint;

final class FingerprintTest extends TestCase
{
    /** Empreinte pseudo-aléatoire reproductible. @return list<int> */
    private static function random(int $n, int $seed): array
    {
        mt_srand($seed);
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = mt_rand(0, 0xFFFF) << 16 | mt_rand(0, 0xFFFF);
        }
        return $out;
    }

    public function testLectureEtStockage(): void
    {
        // fpcalc écrit parfois des entiers signés ; une ligne de bavardage peut précéder le JSON
        $fp = Fingerprint::parse("ERROR: Error decoding the audio source\n" . '{"duration": 12.5, "fingerprint": [1934527975, -1, 0, 4294967295]}');
        $this->assertSame([1934527975, 4294967295, 0, 4294967295], $fp);
        $this->assertSame($fp, Fingerprint::decode(Fingerprint::encode($fp)));
        $this->assertSame([], Fingerprint::decode(null));
        $this->assertSame([], Fingerprint::decode('pas du base64 !'));
        $this->assertSame([], Fingerprint::parse('{"duration": 1, "fingerprint": []}'), 'fichier trop court');
        $this->assertThrows(\RuntimeException::class, static fn () => Fingerprint::parse('Could not open the input file'));
    }

    public function testValeursIndexeesReparties(): void
    {
        $fp = self::random(960, 1);
        $keys = Fingerprint::keys($fp);
        // Environ une sur 8, toujours les mêmes pour les mêmes valeurs
        $this->assertTrue(count($keys) > 80 && count($keys) < 160, count($keys) . ' valeurs indexées');
        $this->assertSame($keys, Fingerprint::keys($fp));
        // Des empreintes dont les bits faibles ne valent jamais 0 (vu sur de vraies pistes) en ont aussi
        $this->assertTrue(count(Fingerprint::keys(array_map(static fn ($v) => $v | 0b1111, $fp))) > 60);
        $this->assertSame([], Fingerprint::keys([0, 0, 0]));
    }

    public function testComparaisonAvecDecalage(): void
    {
        $a = self::random(948, 2);
        // b : le même enregistrement commencé 26 valeurs (≈ 3,2 s) plus loin, 5 % des bits changés
        mt_srand(3);
        $b = array_map(static function (int $v): int {
            for ($bit = 0; $bit < 32; $bit++) {
                if (mt_rand(0, 99) < 5) {
                    $v ^= 1 << $bit;
                }
            }
            return $v;
        }, array_slice($a, 26));
        $r = Fingerprint::compare($a, $b);
        $this->assertSame(-26, $r['offset']);
        $this->assertTrue($r['score'] > 0.93 && $r['score'] < 0.97, 'ressemblance ' . $r['score']);
        $this->assertSame(-3220, Fingerprint::offsetMs(-26));
        // Deux empreintes sans rapport : la moitié des bits, au hasard
        $other = Fingerprint::compare($a, self::random(948, 4));
        $this->assertTrue($other['score'] < 0.6, 'sans rapport ' . $other['score']);
        // Trop court pour comparer
        $this->assertSame(0.0, Fingerprint::compare($a, array_slice($a, 0, 40))['score']);
    }
}

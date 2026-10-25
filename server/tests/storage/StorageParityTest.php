<?php
// La musique de test existe en double : un dossier (RENGAINE_TEST_MUSIC_DIR) et le bucket S3 de test.
// Les deux pilotes doivent en donner exactement la même liste (clés, tailles). Lecture seule.

declare(strict_types=1);

namespace Musique\Tests;

use Musique\Storage\DiskStorage;

require_once __DIR__ . '/StorageDrivers.php';

final class StorageParityTest extends TestCase
{
    public function testMemeListeSurDisqueEtS3(): void
    {
        $dir = (string) getenv('RENGAINE_TEST_MUSIC_DIR');
        $s3 = StorageDrivers::s3() ?? $this->skip('identifiants S3 de test illisibles');
        if (!is_dir($dir)) {
            $this->skip("dossier de musique de test absent : $dir");
        }
        $index = static function (iterable $objects): array {
            $out = [];
            foreach ($objects as $o) {
                if (!str_starts_with($o->key, '_tests/')) {
                    $out[$o->key] = $o->size;
                }
            }
            return $out;
        };
        $disk = $index((new DiskStorage($dir))->list());
        // Le bucket reçoit aussi des albums qui ne sont pas sur le disque (dépôt, rangement) :
        // on compare seulement les fichiers de la musique de test.
        $bucket = array_intersect_key($index($s3->list()), $disk);
        $this->assertTrue(count($disk) > 0, 'musique de test présente');
        $this->assertSame(array_keys($disk), array_keys($bucket), 'mêmes clés, même ordre');
        $this->assertSame($disk, $bucket, 'mêmes tailles');
    }
}

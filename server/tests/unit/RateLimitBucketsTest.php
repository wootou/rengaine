<?php
// Limitation du débit (API-07) : images et flux audio ont leur propre compteur.

declare(strict_types=1);

namespace Musique\Tests;

use Musique\Api\Kernel;

final class RateLimitBucketsTest extends TestCase
{
    public function testImagesEtFluxAPart(): void
    {
        foreach ([['GET', '/images/0a1b2c'], ['GET', '/tracks/12/stream'], ['HEAD', '/tracks/12/stream'], ['GET', '/backgrounds/3/image']] as [$m, $p]) {
            $this->assertTrue(Kernel::isMedia($m, $p), "$m $p");
        }
        foreach ([['GET', '/albums'], ['POST', '/images/0a1b2c'], ['GET', '/tracks/12'], ['GET', '/backgrounds'], ['PUT', '/admin/artists/3/image'], ['GET', '/images/a/b']] as [$m, $p]) {
            $this->assertFalse(Kernel::isMedia($m, $p), "$m $p");
        }
    }
}

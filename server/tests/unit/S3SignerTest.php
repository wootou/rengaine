<?php
// Exemples officiels d'AWS (documentation « Signature Version 4 », exemples S3 avec examplebucket).

declare(strict_types=1);

namespace Musique\Tests;

use Musique\Storage\S3Signer;

final class S3SignerTest extends TestCase
{
    private function signer(): S3Signer
    {
        return new S3Signer('AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'us-east-1');
    }

    private function date(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2013-05-24T00:00:00Z');
    }

    public function testGetObjectAvecRange(): void
    {
        $h = $this->signer()->sign('GET', 'https://examplebucket.s3.amazonaws.com/test.txt',
            ['Range' => 'bytes=0-9'], S3Signer::EMPTY_PAYLOAD, $this->date());
        $this->assertSame(
            'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, '
            . 'SignedHeaders=host;range;x-amz-content-sha256;x-amz-date, '
            . 'Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41',
            $h['authorization']
        );
    }

    public function testListeAvecParametres(): void
    {
        $h = $this->signer()->sign('GET', 'https://examplebucket.s3.amazonaws.com/?max-keys=2&prefix=J',
            [], S3Signer::EMPTY_PAYLOAD, $this->date());
        $this->assertSame(
            'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, '
            . 'SignedHeaders=host;x-amz-content-sha256;x-amz-date, '
            . 'Signature=34b48302e7b5fa45bde8084f4b7868a86f0a534bc59db6670ed5711ef69dc6f7',
            $h['authorization']
        );
    }

    public function testAdresseSignee(): void
    {
        $url = $this->signer()->presign('GET', 'https://examplebucket.s3.amazonaws.com/test.txt', 86400, $this->date());
        $this->assertTrue(str_ends_with($url, '&X-Amz-Signature=aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404'),
            "signature de l'adresse : $url");
    }

    public function testEncodageDesCles(): void
    {
        $this->assertSame("L%27%C3%A9t%C3%A9%20%26%20co/01%20-%20%23a%20%28100%25%29.mp3",
            S3Signer::encodeKey("L'été & co/01 - #a (100%).mp3"));
    }
}

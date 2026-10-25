<?php

declare(strict_types=1);

namespace Musique\Tests;

use Musique\Config;
use Musique\Log\Logger;
use Musique\Log\RequestId;

final class LoggerTest extends TestCase
{
    private string $file;

    public function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/musique-test-' . bin2hex(random_bytes(4)) . '.log';
    }

    public function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testLigneJsonSansSecret(): void
    {
        $log = (new Logger($this->file, 'info'))->withRequest('01JADQ8Z6R5Y3W0N2C4V7B9K1M', 7);
        $log->debug('ignoré');
        $log->info('connexion', ['email' => 'a@b.fr', 'password' => 'hunter2', 'token' => 'abc',
            'tokenId' => 12, 'nested' => ['Authorization' => 'Bearer x', 's3_secret' => 'y']]);
        $lines = file($this->file, FILE_IGNORE_NEW_LINES);
        $this->assertSame(1, count($lines), 'une seule ligne (debug filtré)');
        $raw = $lines[0];
        foreach (['hunter2', 'Bearer x', '"abc"', '"y"'] as $secret) {
            $this->assertTrue(!str_contains($raw, $secret), "secret présent dans le journal : $secret");
        }
        $entry = json_decode($raw, true);
        $this->assertSame('info', $entry['level']);
        $this->assertSame(7, $entry['userId']);
        $this->assertSame(12, $entry['ctx']['tokenId'], 'tokenId n\'est pas un secret');
        $this->assertSame('a@b.fr', $entry['ctx']['email']);
    }

    public function testUtf8InvalideNePerdPasLaLigne(): void
    {
        (new Logger($this->file))->warning("meta.json illisible", ['genre' => "Alternatif Et Ind\xE9"]);
        $entry = json_decode((string) file_get_contents($this->file), true);
        $this->assertSame('meta.json illisible', $entry['msg']);
    }

    public function testIdentifiantDeRequete(): void
    {
        $a = RequestId::generate();
        $b = RequestId::generate();
        $this->assertTrue(RequestId::isValid($a), "format : $a");
        $this->assertTrue($a !== $b, 'deux identifiants différents');
        $this->assertTrue(RequestId::generate(1000) < RequestId::generate(2000), 'triables par date');
    }

    public function testConfigMasqueLesSecrets(): void
    {
        $c = Config::fromArray(['base' => ['nom' => 'musique', 'mot_de_passe' => 'secret'], 'stockage' => ['s3_secret' => '']]);
        $d = $c->describe();
        $this->assertSame('(défini, masqué)', $d['base']['mot_de_passe']);
        $this->assertSame('(vide)', $d['stockage']['s3_secret']);
        $this->assertSame('musique', $d['base']['nom']);
    }
}

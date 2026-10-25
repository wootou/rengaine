<?php
// Petit cadre de tests, sans dépendance (pas de Composer, donc pas de PHPUnit).

declare(strict_types=1);

namespace Musique\Tests;

final class AssertionFailed extends \Exception
{
}

final class Skipped extends \Exception
{
}

abstract class TestCase
{
    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
    }

    protected function skip(string $why): never
    {
        throw new Skipped($why);
    }

    protected function fail(string $message): never
    {
        throw new AssertionFailed($message);
    }

    protected function assertTrue(bool $condition, string $message = 'condition fausse'): void
    {
        if (!$condition) {
            $this->fail($message);
        }
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            $this->fail(($message !== '' ? "$message : " : '') . 'attendu ' . self::show($expected) . ', obtenu ' . self::show($actual));
        }
    }

    protected function assertNull(mixed $actual, string $message = ''): void
    {
        $this->assertSame(null, $actual, $message);
    }

    protected function assertFalse(bool $condition, string $message = 'condition vraie'): void
    {
        $this->assertTrue(!$condition, $message);
    }

    protected function assertNotSame(mixed $unexpected, mixed $actual, string $message = ''): void
    {
        if ($unexpected === $actual) {
            $this->fail(($message !== '' ? "$message : " : '') . 'valeur inattendue ' . self::show($actual));
        }
    }

    protected function assertStringContainsString(string $needle, string $haystack, string $message = ''): void
    {
        if (!str_contains($haystack, $needle)) {
            $this->fail(($message !== '' ? "$message : " : '') . self::show($needle) . ' absent de ' . self::show(mb_substr($haystack, 0, 200)));
        }
    }

    /** @param class-string<\Throwable> $class */
    protected function assertThrows(string $class, callable $fn, ?callable $check = null): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            if (!$e instanceof $class) {
                $this->fail("exception $class attendue, obtenu " . $e::class . ' : ' . $e->getMessage());
            }
            if ($check !== null) {
                $check($e);
            }
            return;
        }
        $this->fail("exception $class attendue, aucune levée");
    }

    private static function show(mixed $v): string
    {
        $s = var_export($v, true);
        return strlen($s) > 200 ? substr($s, 0, 200) . '…' : $s;
    }
}

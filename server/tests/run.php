<?php
// Lance les tests du serveur.
//   php server/tests/run.php            tous les tests
//   php server/tests/run.php Storage    seulement les classes dont le nom contient « Storage »
// Code de sortie : 0 si tout passe, 1 sinon. Les tests « sautés » (ex. S3 sans identifiants) ne font pas échouer.

declare(strict_types=1);

// La limitation du débit utilise APCu, désactivé par défaut en ligne de commande : on se relance avec.
if (extension_loaded('apcu') && !ini_get('apc.enable_cli') && getenv('RENGAINE_TESTS_REEXEC') === false) {
    putenv('RENGAINE_TESTS_REEXEC=1');
    passthru(escapeshellarg(PHP_BINARY) . ' -d apc.enable_cli=1 ' . implode(' ', array_map('escapeshellarg', $argv)), $code);
    exit($code);
}

// Valeurs propres à une machine (identifiants S3 d'essai, musique de test…) : server/tests/local.env,
// au format CLE=valeur, non suivi par git. Une variable déjà définie l'emporte.
if (is_readable(__DIR__ . '/local.env')) {
    foreach (file(__DIR__ . '/local.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*?)\s*$/', $line, $m) && getenv($m[1]) === false) {
            putenv($m[1] . '=' . trim($m[2], '"\''));
        }
    }
}

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/TestCase.php';

use Musique\Tests\AssertionFailed;
use Musique\Tests\Skipped;
use Musique\Tests\TestCase;

$filter = $argv[1] ?? '';
$files = array_merge(glob(__DIR__ . '/unit/*Test.php') ?: [], glob(__DIR__ . '/storage/*Test.php') ?: [], glob(__DIR__ . '/integration/*Test.php') ?: [], glob(__DIR__ . '/api/*Test.php') ?: []);
$before = get_declared_classes();
foreach ($files as $file) {
    require_once $file;
}
$classes = array_filter(
    array_diff(get_declared_classes(), $before),
    static fn ($c) => is_subclass_of($c, TestCase::class) && !(new ReflectionClass($c))->isAbstract()
        && ($filter === '' || str_contains($c, $filter)),
);
sort($classes);

$passed = $failed = $skipped = 0;
$start = microtime(true);
foreach ($classes as $class) {
    $short = substr($class, strrpos($class, '\\') + 1);
    foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if (!str_starts_with($method->name, 'test')) {
            continue;
        }
        /** @var TestCase $test */
        $test = new $class();
        $label = "$short::{$method->name}";
        try {
            $test->setUp();
            try {
                $test->{$method->name}();
            } finally {
                $test->tearDown();
            }
            $passed++;
            echo "  ok    $label\n";
        } catch (Skipped $e) {
            $skipped++;
            echo "  sauté $label — {$e->getMessage()}\n";
        } catch (AssertionFailed $e) {
            $failed++;
            echo "  ÉCHEC $label — {$e->getMessage()}\n";
        } catch (Throwable $e) {
            $failed++;
            echo "  ERREUR $label — " . $e::class . ' : ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
        }
    }
}
printf("\n%d réussi(s), %d échec(s), %d sauté(s) en %.1f s\n", $passed, $failed, $skipped, microtime(true) - $start);
exit($failed === 0 ? 0 : 1);

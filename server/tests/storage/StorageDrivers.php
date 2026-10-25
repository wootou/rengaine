<?php
// Fabrique des pilotes pour les tests.
//   - Disque : un dossier temporaire (jamais file/, pour ne pas toucher à la musique).
//   - S3 : identifiants lus dans le fichier RENGAINE_TEST_S3_ENV (voir tests/local.env) ; test sauté sans lui.

declare(strict_types=1);

namespace Musique\Tests;

use Musique\Storage\DiskStorage;
use Musique\Storage\S3Storage;

final class StorageDrivers
{
    public static function tempDisk(): DiskStorage
    {
        $dir = sys_get_temp_dir() . '/musique-disque-' . bin2hex(random_bytes(4));
        mkdir($dir);
        register_shutdown_function(static function () use ($dir): void {
            exec('rm -rf ' . escapeshellarg($dir));
        });
        return new DiskStorage($dir);
    }

    public static function s3(): ?S3Storage
    {
        $file = (string) getenv('RENGAINE_TEST_S3_ENV');
        if (!is_readable($file)) {
            return null;
        }
        $env = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (preg_match('/^\s*([A-Z0-9_]+)\s*=\s*"?([^"]*)"?\s*$/', $line, $m)) {
                $env[$m[1]] = $m[2];
            }
        }
        foreach (['S3_ENDPOINT', 'S3_BUCKET', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY'] as $k) {
            if (($env[$k] ?? '') === '') {
                return null;
            }
        }
        return new S3Storage($env['S3_ENDPOINT'], $env['S3_BUCKET'], $env['AWS_DEFAULT_REGION'] ?? 'us-east-1',
            $env['AWS_ACCESS_KEY_ID'], $env['AWS_SECRET_ACCESS_KEY']);
    }
}

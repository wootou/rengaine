<?php
// Le mode de stockage se choisit dans la config ([stockage] mode) ; aucun autre code n'en dépend (STO-04).

declare(strict_types=1);

namespace Musique\Storage;

use Musique\Config;

final class StorageFactory
{
    public static function fromConfig(Config $config): Storage
    {
        return match ($mode = $config->string('stockage', 'mode')) {
            'disque' => new DiskStorage($config->string('stockage', 'chemin')),
            's3' => S3Storage::fromConfig($config),
            default => throw new \RuntimeException("[stockage] mode inconnu : « $mode » (attendu : disque ou s3)"),
        };
    }
}

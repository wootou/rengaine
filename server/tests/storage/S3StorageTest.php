<?php

declare(strict_types=1);

namespace Musique\Tests;

use Musique\Storage\Storage;

require_once __DIR__ . '/StorageContract.php';
require_once __DIR__ . '/StorageDrivers.php';

final class S3StorageTest extends StorageContract
{
    protected function makeStorage(): Storage
    {
        return StorageDrivers::s3() ?? $this->skip('identifiants S3 de test illisibles');
    }
}

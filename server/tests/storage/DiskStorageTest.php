<?php

declare(strict_types=1);

namespace Musique\Tests;

use Musique\Storage\Storage;

require_once __DIR__ . '/StorageContract.php';
require_once __DIR__ . '/StorageDrivers.php';

final class DiskStorageTest extends StorageContract
{
    protected function makeStorage(): Storage
    {
        return StorageDrivers::tempDisk();
    }
}

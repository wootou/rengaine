<?php

declare(strict_types=1);

namespace Musique\Storage;

/** Un fichier du stockage, tel que le décrit le pilote (disque ou S3). */
final readonly class StorageObject
{
    public function __construct(
        public string $key,
        public int $size,
        public ?\DateTimeImmutable $modifiedAt,
        /** ETag S3 sans guillemets ; null en mode disque. */
        public ?string $etag = null,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Musique\Storage;

/** Validation commune d'une plage d'octets. */
final class Range
{
    /**
     * @return array{0: int, 1: int} [début, nombre d'octets à lire]
     */
    public static function resolve(int $offset, ?int $length, int $size): array
    {
        if ($offset < 0 || ($length !== null && $length < 0)) {
            throw new StorageException(StorageException::INVALID_RANGE, 'Plage négative');
        }
        if ($size === 0 && $offset === 0) {
            return [0, 0];
        }
        if ($offset >= $size) {
            throw new StorageException(StorageException::INVALID_RANGE, "Début de plage ($offset) au-delà du fichier ($size octets)");
        }
        $available = $size - $offset;
        return [$offset, $length === null ? $available : min($length, $available)];
    }
}

<?php

declare(strict_types=1);

namespace Musique\Storage;

final class StorageException extends \RuntimeException
{
    public const NOT_FOUND = 'not_found';
    public const INVALID_KEY = 'invalid_key';
    public const INVALID_RANGE = 'invalid_range';
    public const UNAVAILABLE = 'unavailable';
    public const FAILED = 'failed';

    public function __construct(public readonly string $reason, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function isNotFound(): bool
    {
        return $this->reason === self::NOT_FOUND;
    }
}

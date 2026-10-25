<?php

declare(strict_types=1);

namespace Musique\Storage;

/** Règles communes aux clés, pour que les deux pilotes acceptent et refusent exactement les mêmes. */
final class Keys
{
    public const MAX_BYTES = 1024;

    public static function assertValid(string $key): void
    {
        $problem = match (true) {
            $key === '' => 'clé vide',
            strlen($key) > self::MAX_BYTES => 'clé trop longue',
            !mb_check_encoding($key, 'UTF-8') => 'clé en UTF-8 invalide',
            str_contains($key, "\0") || preg_match('/[\x00-\x1f\x7f]/', $key) === 1 => 'caractère de contrôle',
            str_starts_with($key, '/') || str_ends_with($key, '/') => 'barre oblique au début ou à la fin',
            str_contains($key, '//') => 'segment vide',
            str_contains($key, '\\') => 'barre oblique inverse',
            default => null,
        };
        if ($problem === null) {
            foreach (explode('/', $key) as $segment) {
                if ($segment === '.' || $segment === '..') {
                    $problem = 'segment . ou ..';
                    break;
                }
                if (str_starts_with($segment, '.')) {
                    $problem = 'fichier ou dossier caché';
                    break;
                }
            }
        }
        if ($problem !== null) {
            throw new StorageException(StorageException::INVALID_KEY, "Clé refusée ($problem)");
        }
    }

    /** Vrai si la clé contient un segment caché (ignoré lors d'un parcours). */
    public static function isHidden(string $key): bool
    {
        return str_starts_with($key, '.') || str_contains($key, '/.');
    }
}

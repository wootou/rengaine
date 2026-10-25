<?php
// Mots de passe en argon2id (CPT-02).

declare(strict_types=1);

namespace Musique\Auth;

final class Passwords
{
    /** Empreinte factice : vérifier un compte inconnu prend le même temps qu'un vrai. */
    private const DUMMY = '$argon2id$v=19$m=65536,t=4,p=1$b1lFNmxwOEc2OENSSVpkTQ$j40kqk3VgH/r2eRVzBHI9ljTRRMT82k+OHsEiV3hvY0';

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID);
    }

    public static function verify(string $password, ?string $hash): bool
    {
        if ($hash === null || $hash === '') {
            password_verify($password, self::DUMMY);
            return false;
        }
        return password_verify($password, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID);
    }
}

<?php
// Conversions communes vers le format du contrat.

declare(strict_types=1);

namespace Musique\Api;

final class Format
{
    /** « 2026-10-19 08:30:00.123 » (UTC, base) → « 2026-10-19T08:30:00.123Z » */
    public static function date(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!str_contains($value, '.')) {
            $value .= '.000';
        }
        return str_replace(' ', 'T', $value) . 'Z';
    }

    /** INET6 rend une IPv4 sous la forme ::ffff:1.2.3.4 */
    public static function ip(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        return str_starts_with($value, '::ffff:') && str_contains($value, '.') ? substr($value, 7) : $value;
    }

    public static function imageUrl(?string $sha256Binary): ?string
    {
        return $sha256Binary === null ? null : '/api/v2/images/' . bin2hex($sha256Binary);
    }

    public static function float(mixed $v): ?float
    {
        return $v === null ? null : (float) $v;
    }

    public static function int(mixed $v): ?int
    {
        return $v === null ? null : (int) $v;
    }

    /** Échappe % et _ pour un LIKE. */
    public static function like(string $q): string
    {
        return '%' . addcslashes($q, '%_\\') . '%';
    }

    /** Réseau d'une demande de compte : l'IPv4 elle-même, ou le préfixe /64 d'une IPv6. */
    public static function network(string $ip): string
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return $ip;
        }
        if (strlen($bin) === 16) {
            // IPv4 écrite en IPv6 (::ffff:a.b.c.d) : c'est une IPv4
            if (str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) {
                return (string) inet_ntop(substr($bin, 12));
            }
            return (string) inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8));
        }
        return (string) inet_ntop($bin);
    }
}

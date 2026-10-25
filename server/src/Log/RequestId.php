<?php
// Identifiant de requête au format ULID (26 caractères, triable par date). Il relie la réponse
// HTTP (en-tête X-Request-Id), le journal technique et le journal d'audit.

declare(strict_types=1);

namespace Musique\Log;

final class RequestId
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'; // Crockford

    public static function generate(?int $timeMs = null): string
    {
        $timeMs ??= (int) floor(microtime(true) * 1000);
        $out = '';
        // 48 bits de temps → 10 caractères
        for ($i = 9; $i >= 0; $i--) {
            $out .= self::ALPHABET[($timeMs >> ($i * 5)) & 31];
        }
        // 80 bits aléatoires → 16 caractères
        $bytes = random_bytes(10);
        $bits = '';
        foreach (str_split($bytes) as $b) {
            $bits .= str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
        }
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec($chunk)];
        }
        return $out;
    }

    public static function isValid(string $id): bool
    {
        return (bool) preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id);
    }
}

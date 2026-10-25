<?php
// Sessions web, jetons d'appareil et d'agent (API-02, CPT-02) : une seule table, un seul code.
//   - Le secret n'est montré qu'une fois ; la base n'en garde que l'empreinte SHA-256.
//   - Le jeton anti-CSRF d'une session est dérivé de son secret (HMAC) : on peut le redonner
//     dans GET /me sans le stocker en clair.

declare(strict_types=1);

namespace Musique\Auth;

use Musique\Db\Database;

final class Tokens
{
    public const COOKIE = 'musique_session';
    public const SESSION_DAYS = 30;
    /** Écriture de last_used_at au plus toutes les 5 minutes par jeton (moins d'écritures en base). */
    private const TOUCH_SECONDS = 300;

    public function __construct(private readonly Database $db)
    {
    }

    public static function newSecret(string $prefix = 'mu_'): string
    {
        return $prefix . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function csrfFor(string $sessionSecret): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', 'csrf', $sessionSecret, true)), '+/', '-_'), '=');
    }

    /**
     * @param 'web'|'app'|'agent' $kind
     * @return array{id: int, secret: string}
     */
    public function create(int $userId, string $kind, string $name, ?string $ip, ?string $userAgent): array
    {
        $secret = self::newSecret($kind === 'web' ? 'ms_' : 'mu_');
        $this->db->run(
            'INSERT INTO access_tokens (user_id, kind, token_hash, token_prefix, csrf_hash, name, last_used_at, last_ip, user_agent, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP(3), ?, ?, ' . ($kind === 'web' ? 'CURRENT_TIMESTAMP(3) + INTERVAL ' . self::SESSION_DAYS . ' DAY' : 'NULL') . ')',
            [$userId, $kind, hash('sha256', $secret, true), substr($secret, 0, 8),
                $kind === 'web' ? hash('sha256', self::csrfFor($secret), true) : null,
                mb_substr($name, 0, 100), $ip, $userAgent === null ? null : mb_substr($userAgent, 0, 255)]
        );
        return ['id' => (int) $this->db->pdo()->lastInsertId(), 'secret' => $secret];
    }

    /** Compte actif lié au secret, ou null (inconnu, révoqué, expiré, compte non actif). */
    public function verify(string $secret, string $expectedKind): ?Principal
    {
        if (strlen($secret) < 20 || strlen($secret) > 100) {
            return null;
        }
        $row = $this->db->one(
            "SELECT t.id AS token_id, t.kind, t.last_used_at, u.id AS user_id, u.email, u.display_name, u.role,
                    TIMESTAMPDIFF(SECOND, t.last_used_at, CURRENT_TIMESTAMP(3)) AS idle
               FROM access_tokens t JOIN users u ON u.id = t.user_id
              WHERE t.token_hash = ? AND t.revoked_at IS NULL
                AND (t.expires_at IS NULL OR t.expires_at > CURRENT_TIMESTAMP(3)) AND u.status = 'active'",
            [hash('sha256', $secret, true)]
        );
        if ($row === null) {
            return null;
        }
        // Un cookie ne vaut que pour une session web, un en-tête Bearer que pour un jeton
        $isWeb = $row['kind'] === 'web';
        if (($expectedKind === 'web') !== $isWeb) {
            return null;
        }
        return new Principal((int) $row['user_id'], (string) $row['email'], (string) $row['display_name'], (string) $row['role'],
            (int) $row['token_id'], (string) $row['kind'], $isWeb ? $secret : null);
    }

    /** Note l'utilisation (et prolonge une session web), au plus toutes les 5 minutes. */
    public function touch(Principal $p, string $ip, ?string $userAgent): void
    {
        $this->db->run(
            'UPDATE access_tokens SET last_used_at = CURRENT_TIMESTAMP(3), last_ip = ?, user_agent = COALESCE(?, user_agent)'
            . ($p->isWeb() ? ', expires_at = CURRENT_TIMESTAMP(3) + INTERVAL ' . self::SESSION_DAYS . ' DAY' : '')
            . ' WHERE id = ? AND (last_used_at IS NULL OR last_used_at < CURRENT_TIMESTAMP(3) - INTERVAL ' . self::TOUCH_SECONDS . ' SECOND)',
            [$ip, $userAgent === null ? null : mb_substr($userAgent, 0, 255), $p->tokenId]
        );
    }

    public function revoke(int $tokenId, ?int $byUserId): bool
    {
        return $this->db->run(
            'UPDATE access_tokens SET revoked_at = CURRENT_TIMESTAMP(3), revoked_by = ? WHERE id = ? AND revoked_at IS NULL',
            [$byUserId, $tokenId]
        )->rowCount() > 0;
    }

    /** Révoque tous les jetons d'un compte, sauf éventuellement un. */
    public function revokeAll(int $userId, ?int $exceptTokenId, ?int $byUserId): int
    {
        return $this->db->run(
            'UPDATE access_tokens SET revoked_at = CURRENT_TIMESTAMP(3), revoked_by = ? WHERE user_id = ? AND revoked_at IS NULL AND id <> ?',
            [$byUserId, $userId, $exceptTokenId ?? 0]
        )->rowCount();
    }

    /** Cookie de session : HttpOnly, Secure, SameSite=Strict, limité à l'API. */
    public static function sessionCookie(string $secret): string
    {
        return self::COOKIE . '=' . $secret . '; Path=/api/; Max-Age=' . (self::SESSION_DAYS * 86400) . '; HttpOnly; Secure; SameSite=Strict';
    }

    public static function clearCookie(): string
    {
        return self::COOKIE . '=; Path=/api/; Max-Age=0; HttpOnly; Secure; SameSite=Strict';
    }
}

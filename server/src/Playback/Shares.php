<?php
// Liens de télécommande (FUT-02) : un secret donné à un invité, valable quelques heures, révocable.
// Comme les jetons d'appareil, la base ne garde que l'empreinte SHA-256 du secret. Le jeton
// anti-CSRF de l'invité est dérivé du secret (HMAC), sans être stocké.

declare(strict_types=1);

namespace Musique\Playback;

use Musique\Api\Format;
use Musique\Db\Database;

final class Shares
{
    public const COOKIE = 'musique_remote';
    public const MAX_ACTIVE = 10;
    public const MAX_HOURS = 168;
    /** Écriture de last_used_at au plus toutes les minutes par lien. */
    private const TOUCH_SECONDS = 60;
    private const VALID = 'revoked_at IS NULL AND expires_at > CURRENT_TIMESTAMP(3)';

    public function __construct(private readonly Database $db)
    {
    }

    public static function csrfFor(string $secret): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', 'csrf-remote', $secret, true)), '+/', '-_'), '=');
    }

    /**
     * @param array{add: bool, edit: bool, control: bool} $rights
     * @return array{id: int, secret: string}
     */
    public function create(int $userId, ?int $tokenId, string $label, int $hours, array $rights): array
    {
        $secret = 'mr_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->db->run(
            'INSERT INTO queue_shares (user_id, token_hash, token_prefix, label, can_add, can_edit, can_control, created_by, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP(3) + INTERVAL ? HOUR)',
            [$userId, hash('sha256', $secret, true), substr($secret, 0, 8), $label,
                (int) $rights['add'], (int) $rights['edit'], (int) $rights['control'], $tokenId, $hours]
        );
        return ['id' => (int) $this->db->pdo()->lastInsertId(), 'secret' => $secret];
    }

    public function countActive(int $userId): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM queue_shares WHERE user_id = ? AND ' . self::VALID, [$userId]);
    }

    /** @return list<int> */
    public function activeIds(int $userId): array
    {
        return array_map('intval', array_column($this->db->all('SELECT id FROM queue_shares WHERE user_id = ? AND ' . self::VALID, [$userId]), 'id'));
    }

    /** @return list<array<string, mixed>> QueueShare */
    public function listActive(int $userId): array
    {
        return array_map(self::format(...), $this->db->all(
            'SELECT * FROM queue_shares WHERE user_id = ? AND ' . self::VALID . ' ORDER BY id DESC', [$userId]
        ));
    }

    /** @return array<string, mixed>|null QueueShare */
    public function get(int $id): ?array
    {
        $row = $this->db->one('SELECT * FROM queue_shares WHERE id = ?', [$id]);
        return $row === null ? null : self::format($row);
    }

    public function revoke(int $id, int $userId): bool
    {
        return $this->db->run(
            'UPDATE queue_shares SET revoked_at = CURRENT_TIMESTAMP(3) WHERE id = ? AND user_id = ? AND ' . self::VALID, [$id, $userId]
        )->rowCount() > 0;
    }

    /**
     * Le lien valable de ce secret, avec le nom du compte, ou null (inconnu, expiré, révoqué, compte inactif).
     * @return array{id: int, userId: int, label: string, ownerName: string, rights: array{add: bool, edit: bool, control: bool}, expiresAt: string, idle: int}|null
     */
    public function verify(string $secret): ?array
    {
        if (strlen($secret) < 20 || strlen($secret) > 100) {
            return null;
        }
        $row = $this->db->one(
            "SELECT s.*, u.display_name, TIMESTAMPDIFF(SECOND, COALESCE(s.last_used_at, s.created_at), CURRENT_TIMESTAMP(3)) AS idle
               FROM queue_shares s JOIN users u ON u.id = s.user_id
              WHERE s.token_hash = ? AND s.revoked_at IS NULL AND s.expires_at > CURRENT_TIMESTAMP(3) AND u.status = 'active'",
            [hash('sha256', $secret, true)]
        );
        if ($row === null) {
            return null;
        }
        return [
            'id' => (int) $row['id'], 'userId' => (int) $row['user_id'], 'label' => (string) $row['label'],
            'ownerName' => (string) $row['display_name'], 'rights' => self::rights($row),
            'expiresAt' => (string) $row['expires_at'], 'idle' => (int) $row['idle'],
        ];
    }

    /** @param array{id: int, idle: int} $share */
    public function touch(array $share): void
    {
        if ($share['idle'] >= self::TOUCH_SECONDS || $this->db->value('SELECT last_used_at FROM queue_shares WHERE id = ?', [$share['id']]) === null) {
            $this->db->run('UPDATE queue_shares SET last_used_at = CURRENT_TIMESTAMP(3) WHERE id = ?', [$share['id']]);
        }
    }

    /** Cookie de l'invité, jusqu'à l'expiration du lien. */
    public static function cookie(string $secret, int $maxAge): string
    {
        return self::COOKIE . '=' . $secret . '; Path=/api/v2/; Max-Age=' . max(0, $maxAge) . '; HttpOnly; Secure; SameSite=Strict';
    }

    public static function clearCookie(): string
    {
        return self::COOKIE . '=; Path=/api/v2/; Max-Age=0; HttpOnly; Secure; SameSite=Strict';
    }

    /** @param array<string, mixed> $row @return array{add: bool, edit: bool, control: bool} */
    private static function rights(array $row): array
    {
        return ['add' => (bool) $row['can_add'], 'edit' => (bool) $row['can_edit'], 'control' => (bool) $row['can_control']];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function format(array $row): array
    {
        return [
            'id' => (int) $row['id'], 'label' => (string) $row['label'], 'prefix' => (string) $row['token_prefix'],
            'rights' => self::rights($row), 'createdAt' => Format::date($row['created_at']),
            'expiresAt' => Format::date($row['expires_at']), 'lastUsedAt' => Format::date($row['last_used_at']),
        ];
    }
}

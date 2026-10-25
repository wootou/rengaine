<?php
// Réglages globaux du serveur (table server_settings, valeurs JSON).

declare(strict_types=1);

namespace Musique\Account;

use Musique\Db\Database;

final class ServerSettings
{
    public const DEFAULTS = [
        'server_name' => 'Rengaine',
        'language' => 'fr',
        'registration_open' => true,
        'registration_pending_per_network' => 2,
        'remote_control_enabled' => true,
    ];

    public function __construct(private readonly Database $db)
    {
    }

    public function get(string $name): mixed
    {
        $raw = $this->db->value('SELECT value FROM server_settings WHERE name = ?', [$name]);
        if ($raw === null) {
            return self::DEFAULTS[$name] ?? null;
        }
        return json_decode((string) $raw, true);
    }

    public function set(string $name, mixed $value, ?int $byUserId): void
    {
        $this->db->run(
            'INSERT INTO server_settings (name, value, updated_by) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP(3)',
            [$name, json_encode($value, JSON_UNESCAPED_UNICODE), $byUserId]
        );
    }
}

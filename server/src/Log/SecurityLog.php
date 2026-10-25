<?php
// Journal de sécurité (JRN-04) : une ligne simple par événement, lisible par fail2ban.
//   2026-10-19T08:30:00Z musique event=login_failed ip=203.0.113.7 rid=01J…
// Jamais d'adresse e-mail ni de secret.

declare(strict_types=1);

namespace Musique\Log;

use Musique\Config;

final class SecurityLog
{
    public function __construct(private readonly string $file)
    {
    }

    public static function fromConfig(Config $config): self
    {
        return new self(rtrim($config->string('journaux', 'dossier', '/var/log/rengaine'), '/') . '/securite.log');
    }

    /** @param array<string, string|int|null> $fields */
    public function event(string $event, string $ip, array $fields = []): void
    {
        $line = gmdate('Y-m-d\TH:i:s\Z') . ' musique event=' . self::clean($event) . ' ip=' . self::clean($ip);
        foreach ($fields as $k => $v) {
            if ($v !== null) {
                $line .= ' ' . self::clean((string) $k) . '=' . self::clean((string) $v);
            }
        }
        $isNew = !file_exists($this->file);
        if (@file_put_contents($this->file, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
            error_log('musique securite : ' . $line);
            return;
        }
        if ($isNew) {
            @chmod($this->file, 0640);
        }
    }

    private static function clean(string $v): string
    {
        return preg_replace('/[^A-Za-z0-9_.:\-\/]/', '_', $v) ?? '_';
    }
}

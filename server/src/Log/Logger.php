<?php
// Journal technique (JRN-01) : une ligne JSON par événement dans /var/log/rengaine/<canal>.log.
// Jamais de secret : les champs sensibles du contexte sont masqués avant écriture.
// Écrire dans le journal ne doit jamais faire échouer une requête : en cas d'échec, repli sur error_log.

declare(strict_types=1);

namespace Musique\Log;

use Musique\Config;

final class Logger
{
    public const LEVELS = ['debug' => 10, 'info' => 20, 'warning' => 30, 'error' => 40, 'critical' => 50];

    /** Fragments de noms de champs dont la valeur est masquée. */
    private const SENSITIVE = ['password', 'mot_de_passe', 'secret', 'authorization', 'cookie', 'csrf', 's3_cle'];

    private ?string $requestId = null;
    private ?int $userId = null;

    public function __construct(
        private readonly string $file,
        private readonly string $minLevel = 'info',
        private readonly string $channel = 'app',
    ) {
        if (!isset(self::LEVELS[$minLevel])) {
            throw new \InvalidArgumentException("Niveau de journal inconnu : $minLevel");
        }
    }

    public static function fromConfig(Config $config, string $channel = 'app'): self
    {
        $dir = rtrim($config->string('journaux', 'dossier', '/var/log/rengaine'), '/');
        return new self("$dir/$channel.log", $config->string('journaux', 'niveau', 'info'), $channel);
    }

    /** Copie du journal liée à une requête et, une fois connu, à un utilisateur. */
    public function withRequest(?string $requestId, ?int $userId = null): self
    {
        $copy = clone $this;
        $copy->requestId = $requestId;
        $copy->userId = $userId;
        return $copy;
    }

    public function debug(string $message, array $context = []): void { $this->log('debug', $message, $context); }
    public function info(string $message, array $context = []): void { $this->log('info', $message, $context); }
    public function warning(string $message, array $context = []): void { $this->log('warning', $message, $context); }
    public function error(string $message, array $context = []): void { $this->log('error', $message, $context); }
    public function critical(string $message, array $context = []): void { $this->log('critical', $message, $context); }

    public function log(string $level, string $message, array $context = []): void
    {
        if ((self::LEVELS[$level] ?? 0) < self::LEVELS[$this->minLevel]) {
            return;
        }
        $line = json_encode(array_filter([
            'ts' => gmdate('Y-m-d\TH:i:s') . sprintf('.%03dZ', (int) (fmod(microtime(true), 1) * 1000)),
            'level' => $level,
            'channel' => $this->channel,
            'requestId' => $this->requestId,
            'userId' => $this->userId,
            'msg' => $message,
            'ctx' => $context === [] ? null : self::redact($context),
        ], static fn ($v) => $v !== null), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        $isNew = !file_exists($this->file);
        if (@file_put_contents($this->file, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
            error_log("musique [$level] $message");
            return;
        }
        if ($isNew) {
            // Journal lisible seulement par www-data et le groupe adm (comme le crée logrotate).
            @chmod($this->file, 0640);
            // Lancé en root (commande à la main), le fichier doit rester inscriptible par PHP-FPM.
            if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
                @chown($this->file, 'www-data');
                @chgrp($this->file, 'adm');
            }
        }
    }

    /**
     * Masque les valeurs des champs sensibles, à toute profondeur.
     * @param array<mixed> $context
     * @return array<mixed>
     */
    public static function redact(array $context): array
    {
        foreach ($context as $key => $value) {
            $k = strtolower((string) $key);
            $sensitive = $k === 'token';
            foreach (self::SENSITIVE as $fragment) {
                $sensitive = $sensitive || str_contains($k, $fragment);
            }
            if ($sensitive) {
                $context[$key] = '[masqué]';
            } elseif (is_array($value)) {
                $context[$key] = self::redact($value);
            } elseif ($value instanceof \Throwable) {
                $context[$key] = ['class' => $value::class, 'message' => $value->getMessage(),
                    'file' => basename($value->getFile()) . ':' . $value->getLine()];
            }
        }
        return $context;
    }
}

<?php
// Limitation du débit (API-07) : compteurs par fenêtre fixe dans APCu, mémoire partagée par les
// processus PHP-FPM. Aucune requête en base. Sans APCu (ligne de commande sans apc.enable_cli),
// la limite n'est pas appliquée : on le note une fois au journal.

declare(strict_types=1);

namespace Musique\Http;

final class RateLimiter
{
    private static bool $warned = false;

    public function __construct(private readonly ?\Closure $onUnavailable = null)
    {
    }

    public static function available(): bool
    {
        return function_exists('apcu_enabled') && apcu_enabled();
    }

    /**
     * Compte un appel. Lève 429 si la limite est dépassée.
     * @param string $bucket ex. « login:ip »
     */
    public function hit(string $bucket, string $subject, int $limit, int $windowSeconds, string $message = 'Trop de requêtes, réessayez plus tard', string $code = 'rate_limited'): void
    {
        $retry = $this->consume($bucket, $subject, $limit, $windowSeconds);
        if ($retry !== null) {
            throw ApiError::tooManyRequests($retry, $message, $code);
        }
    }

    /** null si l'appel passe, sinon le nombre de secondes à attendre. */
    public function consume(string $bucket, string $subject, int $limit, int $windowSeconds): ?int
    {
        if (!self::available()) {
            if (!self::$warned && $this->onUnavailable !== null) {
                self::$warned = true;
                ($this->onUnavailable)();
            }
            return null;
        }
        $now = time();
        $window = intdiv($now, $windowSeconds);
        $key = 'musique:rl:' . $bucket . ':' . hash('xxh128', $subject) . ':' . $window;
        apcu_add($key, 0, $windowSeconds + 5);
        $count = apcu_inc($key);
        if ($count === false) {
            return null;
        }
        if ($count > $limit) {
            return max(1, ($window + 1) * $windowSeconds - $now);
        }
        return null;
    }

    /** Remet un compteur à zéro (ex. connexion réussie). */
    public function reset(string $bucket, string $subject, int $windowSeconds): void
    {
        if (self::available()) {
            apcu_delete('musique:rl:' . $bucket . ':' . hash('xxh128', $subject) . ':' . intdiv(time(), $windowSeconds));
        }
    }
}

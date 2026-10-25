<?php
// Erreur renvoyée au client au format `Error` du contrat : { error: { status, code, message, requestId, details } }.
// Le message est écrit en français et traduit à l'envoi dans la langue du serveur (Lang, WEB-13) ;
// le code est stable (le client le teste).

declare(strict_types=1);

namespace Musique\Http;

final class ApiError extends \RuntimeException
{
    /**
     * @param array<string, mixed> $details
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    /** @param array<string, mixed> $details */
    public static function badRequest(string $message, array $details = []): self
    {
        return new self(400, 'bad_request', $message, $details);
    }

    /** Champ invalide : le nom du champ va dans details.field. */
    public static function invalid(string $field, string $message): self
    {
        return new self(400, 'bad_request', $message, ['field' => $field]);
    }

    public static function unauthorized(string $message = 'Authentification requise', string $code = 'unauthorized'): self
    {
        return new self(401, $code, $message);
    }

    public static function forbidden(string $message = 'Accès refusé', string $code = 'forbidden'): self
    {
        return new self(403, $code, $message);
    }

    public static function notFound(string $message = 'Introuvable'): self
    {
        return new self(404, 'not_found', $message);
    }

    public static function notImplemented(string $message = 'Fonction prévue, pas encore disponible sur ce serveur'): self
    {
        return new self(501, 'not_implemented', $message);
    }

    public static function tooManyRequests(int $retryAfter, string $message = 'Trop de requêtes, réessayez plus tard', string $code = 'rate_limited'): self
    {
        return new self(429, $code, $message, ['retryAfter' => $retryAfter], ['Retry-After' => (string) $retryAfter]);
    }
}

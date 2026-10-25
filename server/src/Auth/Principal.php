<?php
// Le compte authentifié d'une requête, et la session ou le jeton utilisé.

declare(strict_types=1);

namespace Musique\Auth;

final class Principal
{
    public function __construct(
        public readonly int $userId,
        public readonly string $email,
        public readonly string $displayName,
        public readonly string $role,
        public readonly int $tokenId,
        public readonly string $tokenKind,
        /** Secret de la session web (cookie), pour recalculer le jeton anti-CSRF ; null pour un Bearer */
        public readonly ?string $sessionSecret = null,
    ) {
    }

    public function isWeb(): bool
    {
        return $this->tokenKind === 'web';
    }

    /** @return array{id: int, email: string, displayName: string, role: string} */
    public function user(): array
    {
        return ['id' => $this->userId, 'email' => $this->email, 'displayName' => $this->displayName, 'role' => $this->role];
    }
}

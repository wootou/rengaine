<?php
// Échec d'envoi d'un mail. Le code est celui de la réponse SMTP (5xx : définitif ; 4xx : réessayer).

declare(strict_types=1);

namespace Musique\Mail;

final class MailException extends \RuntimeException
{
    /** Erreur définitive (adresse refusée…) : inutile de réessayer. */
    public function permanent(): bool
    {
        return $this->getCode() >= 500 && $this->getCode() < 600 && $this->getCode() !== 535;
    }
}

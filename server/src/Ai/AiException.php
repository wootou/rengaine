<?php
// Échec d'un appel au fournisseur d'IA (réseau, clé refusée, quota…). Le message ne contient jamais la clé.

declare(strict_types=1);

namespace Musique\Ai;

final class AiException extends \RuntimeException
{
}

<?php
// Leçon de l'ancien site : un avertissement PHP ne doit JAMAIS devenir une erreur 500.
// Avertissements et notices vont au journal ; seules les exceptions, les erreurs fatales,
// E_RECOVERABLE_ERROR et E_USER_ERROR interrompent le traitement.

declare(strict_types=1);

namespace Musique\Log;

final class ErrorHandler
{
    public static function install(Logger $logger): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line) use ($logger): bool {
            if (!(error_reporting() & $severity)) {
                return true; // supprimé par @
            }
            if ($severity === E_RECOVERABLE_ERROR || $severity === E_USER_ERROR) {
                throw new \ErrorException($message, 0, $severity, $file, $line);
            }
            $logger->warning('Avertissement PHP', [
                'severity' => $severity, 'message' => $message, 'at' => basename($file) . ":$line",
            ]);
            return true;
        });

        register_shutdown_function(static function () use ($logger): void {
            $e = error_get_last();
            if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $logger->critical('Erreur fatale PHP', [
                    'message' => $e['message'], 'at' => basename($e['file']) . ':' . $e['line'],
                ]);
            }
        });
    }
}

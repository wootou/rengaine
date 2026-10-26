<?php
// Identité d'un processus de tâche (« hôte:pid ») : sert à reconnaître une tâche dont le processus a été
// tué (manque de mémoire, SIGKILL) et qui ne se clôturera jamais d'elle-même.

declare(strict_types=1);

namespace Musique\Jobs;

final class Worker
{
    public static function id(): string
    {
        return gethostname() . ':' . getmypid();
    }

    /**
     * true si le processus est de cette machine et n'existe plus. Un processus d'une autre machine n'est
     * jamais déclaré disparu : on ne peut pas le vérifier d'ici.
     */
    public static function isGone(?string $worker): bool
    {
        if ($worker === null || !preg_match('/^(.+):(\d+)$/', $worker, $m) || $m[1] !== gethostname()) {
            return false;
        }
        $pid = (int) $m[2];
        if ($pid === getmypid()) {
            return false;
        }
        if (function_exists('posix_kill')) {
            // EPERM (1) : le processus existe mais appartient à un autre compte
            return !posix_kill($pid, 0) && posix_get_last_error() !== 1;
        }
        return !is_dir("/proc/$pid");
    }
}

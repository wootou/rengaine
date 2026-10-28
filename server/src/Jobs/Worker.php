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

    /**
     * Mémoire encore utilisable par ce processus, en octets : MemAvailable de la machine, réduite par la
     * limite de son groupe systemd (MemoryMax d'un service ou d'une tranche parente) s'il y en a une.
     * null si on ne sait pas la lire (autre système que Linux).
     */
    public static function availableMemory(string $proc = '/proc', string $cgroupRoot = '/sys/fs/cgroup'): ?int
    {
        $meminfo = @file_get_contents("$proc/meminfo");
        $free = $meminfo !== false && preg_match('/^MemAvailable:\s+(\d+) kB/m', $meminfo, $m) ? (int) $m[1] * 1024 : null;
        // cgroup v2 : « 0::/system.slice/rengaine-analyze.service »
        $line = @file_get_contents("$proc/self/cgroup");
        if ($line !== false && preg_match('/^0::(\/\S*)/m', $line, $m)) {
            for ($dir = rtrim($m[1], '/'); ; $dir = dirname($dir)) {
                $max = @file_get_contents("$cgroupRoot$dir/memory.max");
                $current = @file_get_contents("$cgroupRoot$dir/memory.current");
                if ($max !== false && $current !== false && ctype_digit(trim($max))) {
                    $left = max(0, (int) trim($max) - (int) trim($current));
                    $free = $free === null ? $left : min($free, $left);
                }
                if ($dir === '' || $dir === '/') {
                    break;
                }
            }
        }
        return $free;
    }
}

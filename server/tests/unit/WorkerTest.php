<?php

declare(strict_types=1);

namespace Musique\Tests;

use Musique\Jobs\Worker;

final class WorkerTest extends TestCase
{
    private string $dir;

    public function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/musique-worker-' . bin2hex(random_bytes(4));
        mkdir("$this->dir/proc/self", 0700, true);
        file_put_contents("$this->dir/proc/meminfo", "MemTotal:       16000000 kB\nMemFree:          500000 kB\nMemAvailable:    8000000 kB\n");
    }

    public function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function cgroup(string $path, string $max, int $current): void
    {
        mkdir("$this->dir/cg$path", 0700, true);
        file_put_contents("$this->dir/cg$path/memory.max", "$max\n");
        file_put_contents("$this->dir/cg$path/memory.current", "$current\n");
    }

    public function testMemoireDeLaMachineSansLimite(): void
    {
        file_put_contents("$this->dir/proc/self/cgroup", "0::/system.slice/rengaine-analyze.service\n");
        $this->cgroup('/system.slice/rengaine-analyze.service', 'max', 5_000_000_000);
        $this->assertSame(8_000_000 * 1024, Worker::availableMemory("$this->dir/proc", "$this->dir/cg"));
    }

    public function testLimiteDuServiceEtDeLaTranche(): void
    {
        file_put_contents("$this->dir/proc/self/cgroup", "0::/system.slice/rengaine-analyze.service\n");
        $this->cgroup('/system.slice/rengaine-analyze.service', '3000000000', 1_000_000_000);
        $this->assertSame(2_000_000_000, Worker::availableMemory("$this->dir/proc", "$this->dir/cg"), 'MemoryMax du service');
        // Une tranche parente plus serrée l'emporte
        $this->cgroup('/system.slice', '1500000000', 1_200_000_000);
        $this->assertSame(300_000_000, Worker::availableMemory("$this->dir/proc", "$this->dir/cg"), 'limite de la tranche');
    }

    public function testRienDeLisible(): void
    {
        $this->assertNull(Worker::availableMemory("$this->dir/absent", "$this->dir/absent"));
    }
}

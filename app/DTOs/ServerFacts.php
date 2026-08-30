<?php

namespace App\DTOs;

/**
 * Everything the optimizer knows about one server: hardware measured on the box,
 * plus the stack facts Vito already holds in its own database.
 *
 * Deliberately a plain value object with no I/O — App\Support\Optimization\ResourceBudget
 * consumes it and must stay unit-testable without a server.
 */
class ServerFacts
{
    /**
     * @param  array<int, string>  $phpVersions
     */
    public function __construct(
        public readonly int $totalRamMb,
        public readonly int $cores,
        public readonly int $physicalCores = 0,
        public readonly bool $diskRotational = false,
        public readonly ?string $virtualisation = null,
        public readonly bool $dbLocal = false,
        public readonly bool $redisLocal = false,
        public readonly bool $hasWorkers = false,
        public readonly array $phpVersions = [],
        public readonly ?int $avgWorkerRssMb = null,
        public readonly int $swapTotalMb = 0,
        public readonly int $oomKillCount = 0,
    ) {}

    /**
     * The measured per-worker footprint, or the conservative fallback used only
     * when no pool has run yet. Guessing here causes OOM or waste, so the probe
     * measures it whenever a pool exists.
     */
    public function workerRssMb(): int
    {
        return $this->avgWorkerRssMb !== null && $this->avgWorkerRssMb > 0
            ? $this->avgWorkerRssMb
            : 80;
    }

    /**
     * OPcache SHM and the JIT buffer are allocated once per installed PHP version,
     * so the count drives the reserve.
     */
    public function phpVersionCount(): int
    {
        return max(1, count($this->phpVersions));
    }

    /**
     * Many sysctl keys are not settable inside a container; phases that write them
     * must skip rather than fail confusingly.
     */
    public function isContainer(): bool
    {
        return in_array($this->virtualisation, ['lxc', 'lxc-libvirt', 'openvz', 'docker'], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'total_ram_mb' => $this->totalRamMb,
            'cores' => $this->cores,
            'physical_cores' => $this->physicalCores,
            'disk_rotational' => $this->diskRotational,
            'virtualisation' => $this->virtualisation,
            'db_local' => $this->dbLocal,
            'redis_local' => $this->redisLocal,
            'has_workers' => $this->hasWorkers,
            'php_versions' => $this->phpVersions,
            'avg_worker_rss_mb' => $this->avgWorkerRssMb,
            'swap_total_mb' => $this->swapTotalMb,
            'oom_kill_count' => $this->oomKillCount,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            totalRamMb: (int) ($data['total_ram_mb'] ?? 0),
            cores: (int) ($data['cores'] ?? 1),
            physicalCores: (int) ($data['physical_cores'] ?? 0),
            diskRotational: (bool) ($data['disk_rotational'] ?? false),
            virtualisation: $data['virtualisation'] ?? null,
            dbLocal: (bool) ($data['db_local'] ?? false),
            redisLocal: (bool) ($data['redis_local'] ?? false),
            hasWorkers: (bool) ($data['has_workers'] ?? false),
            phpVersions: $data['php_versions'] ?? [],
            avgWorkerRssMb: isset($data['avg_worker_rss_mb']) ? (int) $data['avg_worker_rss_mb'] : null,
            swapTotalMb: (int) ($data['swap_total_mb'] ?? 0),
            oomKillCount: (int) ($data['oom_kill_count'] ?? 0),
        );
    }
}

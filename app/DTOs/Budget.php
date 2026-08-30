<?php

namespace App\DTOs;

/**
 * The RAM pie for one server: every named reserve, and what is left for PHP-FPM.
 *
 * Produced by App\Support\Optimization\ResourceBudget. Values are megabytes.
 */
class Budget
{
    public function __construct(
        public readonly int $totalRamMb,
        public readonly int $osMb,
        public readonly int $databaseMb,
        public readonly int $redisMb,
        public readonly int $workersMb,
        public readonly int $opcacheMb,
        public readonly int $opcacheShmMb,
        public readonly int $opcacheJitMb,
        public readonly int $fpmPoolMb,
        public readonly int $workerRssMb,
        public readonly int $maxWorkers,
    ) {}

    /**
     * Everything reserved before PHP-FPM gets its slice.
     */
    public function reservedMb(): int
    {
        return $this->osMb + $this->databaseMb + $this->redisMb + $this->workersMb + $this->opcacheMb;
    }

    /**
     * Share of total RAM a reserve occupies, for the budget bar in the UI.
     */
    public function percentOf(int $megabytes): float
    {
        if ($this->totalRamMb <= 0) {
            return 0.0;
        }

        return round(($megabytes / $this->totalRamMb) * 100, 1);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'total_ram_mb' => $this->totalRamMb,
            'os_mb' => $this->osMb,
            'database_mb' => $this->databaseMb,
            'redis_mb' => $this->redisMb,
            'workers_mb' => $this->workersMb,
            'opcache_mb' => $this->opcacheMb,
            'opcache_shm_mb' => $this->opcacheShmMb,
            'opcache_jit_mb' => $this->opcacheJitMb,
            'fpm_pool_mb' => $this->fpmPoolMb,
            'worker_rss_mb' => $this->workerRssMb,
            'max_workers' => $this->maxWorkers,
        ];
    }
}

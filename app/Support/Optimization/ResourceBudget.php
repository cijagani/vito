<?php

namespace App\Support\Optimization;

use App\DTOs\Budget;
use App\DTOs\ServerFacts;

/**
 * Divides a server's RAM into named reserves and derives how many PHP-FPM workers
 * actually fit in what is left.
 *
 * Every number the optimizer proposes traces back to this class, so it holds no
 * state, performs no I/O and never touches SSH — a ServerFacts in, a Budget out.
 * That is what makes it exhaustively testable without a server.
 *
 * Ported from the Server 360 toolkit's compute_budget().
 */
class ResourceBudget
{
    /**
     * Kernel, sshd, cron and monitoring. Five percent of RAM is the right shape,
     * but on a small box that is too little to keep the base system healthy.
     */
    private const int OS_RESERVE_PERCENT = 5;

    private const int OS_RESERVE_FLOOR_MB = 512;

    private const int DB_RESERVE_PERCENT = 30;

    private const int REDIS_RESERVE_PERCENT = 20;

    /**
     * Long-lived queue workers and websocket servers hold RAM continuously. Left
     * uncounted they would be funded out of the FPM pool, which is how a box that
     * looks correctly sized still OOMs under load.
     */
    private const int WORKER_RESERVE_PERCENT = 12;

    private const int SMALL_BOX_RAM_MB = 4096;

    private const int FPM_POOL_FLOOR_MB = 256;

    private const int MIN_WORKERS = 2;

    public function compute(ServerFacts $facts): Budget
    {
        $ram = $facts->totalRamMb;

        $os = max(
            (int) ($ram * self::OS_RESERVE_PERCENT / 100),
            self::OS_RESERVE_FLOOR_MB
        );

        // A remote database's RAM is budgeted on the machine that runs it, not here.
        $database = $facts->dbLocal
            ? (int) ($ram * self::DB_RESERVE_PERCENT / 100)
            : 0;

        $redis = $facts->redisLocal
            ? (int) ($ram * self::REDIS_RESERVE_PERCENT / 100)
            : 0;

        $workers = $facts->hasWorkers
            ? (int) ($ram * self::WORKER_RESERVE_PERCENT / 100)
            : 0;

        // The opcode cache and JIT buffer are allocated once per PHP version at FPM
        // start, entirely outside the per-worker RSS this budget divides up. A box
        // running two versions allocates twice; counting it once over-commits RAM
        // by roughly a gigabyte with nothing to signal it.
        $opcacheShm = $ram < self::SMALL_BOX_RAM_MB ? 256 : 512;
        $opcacheJit = $ram < self::SMALL_BOX_RAM_MB ? 64 : 256;
        $opcache = ($opcacheShm + $opcacheJit) * $facts->phpVersionCount();

        // Whatever survives every reserve funds the web request pool. The floor keeps
        // the arithmetic sane on a heavily-reserved small box.
        $fpmPool = max(
            $ram - $os - $database - $redis - $workers - $opcache,
            self::FPM_POOL_FLOOR_MB
        );

        $workerRss = $facts->workerRssMb();
        $maxWorkers = max((int) ($fpmPool / $workerRss), self::MIN_WORKERS);

        return new Budget(
            totalRamMb: $ram,
            osMb: $os,
            databaseMb: $database,
            redisMb: $redis,
            workersMb: $workers,
            opcacheMb: $opcache,
            opcacheShmMb: $opcacheShm,
            opcacheJitMb: $opcacheJit,
            fpmPoolMb: $fpmPool,
            workerRssMb: $workerRss,
            maxWorkers: $maxWorkers,
        );
    }
}

<?php

namespace App\Optimizers\Database;

use App\DTOs\Budget;
use App\DTOs\ServerFacts;
use App\Optimizers\AbstractOptimizer;

/**
 * Proposes postgresql.conf values derived from the machine's own resources.
 *
 * Every number comes from the database slice of the RAM budget or from total
 * memory, so the same rules produce different -- and correct -- values on a 2GB
 * box and a 64GB one.
 */
class PostgresOptimizer extends AbstractOptimizer
{
    /**
     * PostgreSQL sizes work_mem per sort or hash operation, so the worst case
     * scales with how many connections can run one at once. Keeping the ceiling
     * modest is what makes that arithmetic safe; PgBouncer, not this number, is
     * what lets an application open many connections.
     */
    private const int MAX_CONNECTIONS = 100;

    private const int MAX_CONNECTIONS_HIGH_CONCURRENCY = 200;

    public static function component(): string
    {
        return 'postgresql';
    }

    /**
     * @param  array<string, string>  $probe
     */
    public function applies(ServerFacts $facts, array $probe): bool
    {
        // A remote database's memory is budgeted on the machine that runs it, and
        // postgresql.conf here would belong to an instance nothing is using.
        return $facts->dbLocal && isset($probe['pg_shared_buffers']);
    }

    /**
     * @param  array<string, string>  $probe
     */
    protected function serviceVersion(ServerFacts $facts, array $probe): ?string
    {
        return $probe['pg_version'] ?? null;
    }

    /**
     * @param  array<string, string>  $probe
     */
    protected function currentValue(string $configKey, array $probe): ?string
    {
        $value = $probe['pg_'.$configKey] ?? null;

        return $value === null || $value === '' ? null : $value;
    }

    /**
     * @param  array<string, string>  $probe
     * @return array<string, float|int>
     */
    protected function variables(ServerFacts $facts, Budget $budget, array $probe): array
    {
        return [
            'total_ram_mb' => $facts->totalRamMb,
            'db_buffer_mb' => $budget->databaseMb,
            'cores' => $facts->cores,
            'max_connections_target' => $this->maxConnections($facts),
        ];
    }

    /**
     * Derived from the machine rather than asked of the operator: a box with many
     * cores is expected to carry more concurrent work.
     */
    private function maxConnections(ServerFacts $facts): int
    {
        return $facts->cores >= 8
            ? self::MAX_CONNECTIONS_HIGH_CONCURRENCY
            : self::MAX_CONNECTIONS;
    }
}

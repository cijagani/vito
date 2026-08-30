<?php

namespace App\Optimizers\Database;

use App\DTOs\Budget;
use App\DTOs\ServerFacts;
use App\DTOs\TuningProposal;
use App\Optimizers\AbstractOptimizer;
use App\Support\Optimization\Ruleset;

/**
 * Proposes MySQL and MariaDB settings from the machine's own resources.
 *
 * The two engines share almost everything at this level -- InnoDB is InnoDB --
 * so one optimizer covers both and the ruleset marks the handful of settings
 * that only exist on one of them.
 */
class MysqlOptimizer extends AbstractOptimizer
{
    /**
     * Each connection carries its own buffers, so the ceiling multiplies the
     * per-connection memory settings rather than sitting beside them.
     */
    private const int MAX_CONNECTIONS = 150;

    private const int MAX_CONNECTIONS_HIGH_CONCURRENCY = 300;

    public static function component(): string
    {
        return 'mysql';
    }

    /**
     * @param  array<string, string>  $probe
     */
    public function applies(ServerFacts $facts, array $probe): bool
    {
        return $facts->dbLocal && isset($probe['mysql_innodb_buffer_pool_size']);
    }

    /**
     * @param  array<string, string>  $probe
     * @return array<int, TuningProposal>
     */
    public function propose(ServerFacts $facts, Budget $budget, array $probe): array
    {
        if (! $this->applies($facts, $probe)) {
            return [];
        }

        $ruleset = $this->ruleset();

        if (! $ruleset instanceof Ruleset) {
            return [];
        }

        $variables = $this->variables($facts, $budget, $probe);
        $proposals = [];

        $rules = $ruleset->rulesForVariant(
            $ruleset->rulesFor($this->serviceVersion($facts, $probe)),
            $this->variant($probe),
        );

        foreach ($rules as $rule) {
            $proposal = $this->proposalFor($ruleset, $rule, $variables, $probe);

            if ($proposal instanceof TuningProposal) {
                $proposals[] = $proposal;
            }
        }

        return $this->sort($proposals);
    }

    /**
     * Which engine is actually running. MariaDB and Percona both report their
     * name in the version string, so the server is asked rather than assumed --
     * a MariaDB install can sit behind a service Vito recorded as mysql.
     *
     * @param  array<string, string>  $probe
     */
    private function variant(array $probe): string
    {
        $version = strtolower($probe['mysql_version'] ?? '');

        if (str_contains($version, 'mariadb')) {
            return 'mariadb';
        }

        return str_contains($version, 'percona') ? 'percona' : 'mysql';
    }

    /**
     * @param  array<string, string>  $probe
     */
    protected function serviceVersion(ServerFacts $facts, array $probe): ?string
    {
        return $probe['mysql_version'] ?? null;
    }

    /**
     * @param  array<string, string>  $probe
     */
    protected function currentValue(string $configKey, array $probe): ?string
    {
        $value = $probe['mysql_'.$configKey] ?? null;

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
            'max_connections_target' => $facts->cores >= 8
                ? self::MAX_CONNECTIONS_HIGH_CONCURRENCY
                : self::MAX_CONNECTIONS,
        ];
    }
}

<?php

namespace App\Optimizers\Redis;

use App\DTOs\Budget;
use App\DTOs\ServerFacts;
use App\DTOs\TuningProposal;
use App\Optimizers\AbstractOptimizer;

/**
 * Proposes Redis memory and eviction settings.
 *
 * The eviction policy carries the risk here. allkeys-lru is right for a cache and
 * destructive for a queue: under memory pressure Redis discards keys to stay
 * inside its ceiling, and if those keys are queued jobs the work is gone with
 * nothing reporting it. This optimizer will not propose an eviction policy for an
 * instance that backs a queue -- the guardrail is code rather than a note in the
 * ruleset, because a note is something a later change can quietly step around.
 */
class RedisOptimizer extends AbstractOptimizer
{
    private const string EVICTION_KEY = 'maxmemory-policy';

    private const string SAFE_POLICY = 'noeviction';

    public static function component(): string
    {
        return 'redis';
    }

    /**
     * @param  array<string, string>  $probe
     */
    public function applies(ServerFacts $facts, array $probe): bool
    {
        return $facts->redisLocal && isset($probe['redis_maxmemory']);
    }

    /**
     * @param  array<string, string>  $probe
     * @return array<int, TuningProposal>
     */
    public function propose(ServerFacts $facts, Budget $budget, array $probe): array
    {
        $proposals = parent::propose($facts, $budget, $probe);

        if (! $this->backsAQueue($facts, $probe)) {
            return $proposals;
        }

        return array_values(array_map(
            fn (TuningProposal $proposal): TuningProposal => $proposal->configKey === self::EVICTION_KEY
                ? $this->withoutEviction($proposal)
                : $proposal,
            $proposals
        ));
    }

    /**
     * Redis is shared between cache and queue on most single-server setups, so
     * the presence of queue workers is enough to make eviction unsafe. Splitting
     * them into separate instances is the real answer, and is not something the
     * optimizer can do on its own.
     *
     * @param  array<string, string>  $probe
     */
    private function backsAQueue(ServerFacts $facts, array $probe): bool
    {
        return $facts->hasWorkers;
    }

    private function withoutEviction(TuningProposal $proposal): TuningProposal
    {
        return new TuningProposal(
            component: $proposal->component,
            configKey: $proposal->configKey,
            currentValue: $proposal->currentValue,
            proposedValue: self::SAFE_POLICY,
            severity: $proposal->severity,
            applyMethod: $proposal->applyMethod,
            rationale: 'This server runs queue workers, so Redis is holding queued '
                ."jobs as well as cache. An eviction policy would discard those jobs \n"
                ."under memory pressure with nothing reporting the loss, so eviction \n"
                .'is left off. Running separate cache and queue instances is what '
                .'allows the cache to evict safely.',
            kbRef: $proposal->kbRef,
            clamped: true,
        );
    }

    /**
     * @param  array<string, string>  $probe
     */
    protected function serviceVersion(ServerFacts $facts, array $probe): ?string
    {
        return $probe['redis_version'] ?? null;
    }

    /**
     * @param  array<string, string>  $probe
     */
    protected function currentValue(string $configKey, array $probe): ?string
    {
        $value = $probe['redis_'.str_replace('-', '_', $configKey)] ?? null;

        return $value === null || $value === '' ? null : $value;
    }

    /**
     * @param  array<string, string>  $probe
     * @return array<string, float|int>
     */
    protected function variables(ServerFacts $facts, Budget $budget, array $probe): array
    {
        return [
            'cores' => $facts->cores,
            'total_ram_mb' => $facts->totalRamMb,
            'redis_mb' => $budget->redisMb,
        ];
    }
}

<?php

namespace App\Optimizers\PHP;

use App\DTOs\Budget;
use App\DTOs\ServerFacts;
use App\DTOs\TuningProposal;
use App\Models\Site;
use App\Optimizers\AbstractOptimizer;
use App\Support\Optimization\FormulaEvaluator;
use App\Support\Optimization\PoolAllocator;
use App\Support\Optimization\RulesetLoader;
use Illuminate\Support\Collection;

/**
 * Proposes PHP-FPM pool and OPcache settings.
 *
 * Unlike the database optimizer, this one produces a set of values per site: each
 * pool gets its own ceiling, sized from that site's share of the machine.
 */
class FpmOptimizer extends AbstractOptimizer
{
    /** @var Collection<int, Site> */
    private Collection $sites;

    private ?string $phpVersion = null;

    public function __construct(
        RulesetLoader $rulesets = new RulesetLoader,
        FormulaEvaluator $evaluator = new FormulaEvaluator,
        private readonly PoolAllocator $allocator = new PoolAllocator,
    ) {
        parent::__construct($rulesets, $evaluator);
        $this->sites = collect();
    }

    public static function component(): string
    {
        return 'php-fpm';
    }

    /**
     * @param  Collection<int, Site>  $sites
     */
    public function forSites(Collection $sites, ?string $phpVersion = null): self
    {
        $this->sites = $sites;
        $this->phpVersion = $phpVersion;

        return $this;
    }

    /**
     * @param  array<string, string>  $probe
     */
    public function applies(ServerFacts $facts, array $probe): bool
    {
        return $facts->phpVersions !== [];
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

        $allocations = $this->allocator->allocate($this->sites, $budget->fpmPoolMb);

        $proposals = [];

        // OPcache is allocated once per PHP version rather than per pool, so it is
        // proposed once against the version rather than repeated for every site.
        foreach ($this->opcacheProposals($facts, $budget, $probe) as $proposal) {
            $proposals[] = $proposal;
        }

        foreach ($allocations as $allocation) {
            foreach ($this->poolProposals($facts, $budget, $probe, $allocation) as $proposal) {
                $proposals[] = $proposal;
            }
        }

        return $this->sort($proposals);
    }

    /**
     * @param  array<string, string>  $probe
     * @return array<int, TuningProposal>
     */
    private function opcacheProposals(ServerFacts $facts, Budget $budget, array $probe): array
    {
        return $this->rulesFiltered(
            $facts,
            $probe,
            fn (array $rule): bool => str_starts_with($rule['key'], 'opcache.'),
            $this->variables($facts, $budget, $probe),
        );
    }

    /**
     * @param  array<string, string>  $probe
     * @param  array{site: Site, pool_mb: int, memory_limit_mb: int, max_children: int}  $allocation
     * @return array<int, TuningProposal>
     */
    private function poolProposals(ServerFacts $facts, Budget $budget, array $probe, array $allocation): array
    {
        $variables = [
            ...$this->variables($facts, $budget, $probe),
            'site_pool_mb' => $allocation['pool_mb'],
            'memory_limit_mb' => $allocation['memory_limit_mb'],
        ];

        $site = $allocation['site'];

        return array_map(
            fn (TuningProposal $proposal): TuningProposal => new TuningProposal(
                component: $proposal->component,
                // The pool a value belongs to is part of its identity here: two
                // sites legitimately hold different values for the same key.
                configKey: $site->domain.' · '.$proposal->configKey,
                currentValue: $proposal->currentValue,
                proposedValue: $proposal->proposedValue,
                severity: $proposal->severity,
                applyMethod: $proposal->applyMethod,
                rationale: $proposal->rationale,
                kbRef: $proposal->kbRef,
                clamped: $proposal->clamped,
            ),
            $this->rulesFiltered(
                $facts,
                $probe,
                fn (array $rule): bool => str_starts_with($rule['key'], 'pm.'),
                $variables,
            )
        );
    }

    /**
     * @param  array<string, string>  $probe
     * @param  callable(array<string, mixed>): bool  $filter
     * @param  array<string, float|int>  $variables
     * @return array<int, TuningProposal>
     */
    private function rulesFiltered(ServerFacts $facts, array $probe, callable $filter, array $variables): array
    {
        $ruleset = $this->ruleset();

        if ($ruleset === null) {
            return [];
        }

        $proposals = [];

        foreach ($ruleset->rulesFor($this->serviceVersion($facts, $probe)) as $rule) {
            if (! $filter($rule)) {
                continue;
            }

            $proposal = $this->proposalFor($ruleset, $rule, $variables, $probe);

            if ($proposal instanceof TuningProposal) {
                $proposals[] = $proposal;
            }
        }

        return $proposals;
    }

    /**
     * @param  array<string, string>  $probe
     */
    protected function serviceVersion(ServerFacts $facts, array $probe): ?string
    {
        return $this->phpVersion ?? ($facts->phpVersions[0] ?? null);
    }

    /**
     * @param  array<string, string>  $probe
     */
    protected function currentValue(string $configKey, array $probe): ?string
    {
        // OPcache is read from the ini files under the php_ prefix; pool sizing
        // is not read back at all, since the probe reports one value per PHP
        // version rather than per pool.
        $value = $probe['php_'.preg_replace('/[^a-z0-9]+/i', '_', $configKey)] ?? null;

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
            'fpm_pool_mb' => $budget->fpmPoolMb,
            'cores' => $facts->cores,
            'opcache_shm_mb' => $budget->opcacheShmMb,
            'opcache_jit_mb' => $budget->opcacheJitMb,
            'worker_rss_mb' => $budget->workerRssMb,
        ];
    }
}

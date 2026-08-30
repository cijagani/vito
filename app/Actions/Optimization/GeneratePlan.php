<?php

namespace App\Actions\Optimization;

use App\DTOs\TuningProposal;
use App\Enums\OptimizationPlanStatus;
use App\Exceptions\SSHError;
use App\Models\OptimizationPlan;
use App\Models\Server;
use App\Models\User;
use App\Optimizers\Database\MysqlOptimizer;
use App\Optimizers\Database\PostgresOptimizer;
use App\Optimizers\OptimizerInterface;
use App\Optimizers\OS\KernelOptimizer;
use App\Optimizers\PHP\FpmOptimizer;
use App\Optimizers\Redis\RedisOptimizer;
use App\Optimizers\Webserver\NginxOptimizer;
use App\Support\Optimization\ResourceBudget;
use App\Support\Optimization\RulesetLoader;
use Illuminate\Support\Facades\DB;

/**
 * Analyses a server and records what should change, without changing anything.
 *
 * The plan stores the facts and the budget it was computed from, not just the
 * conclusions, so a proposal can still be explained after the machine has moved
 * on -- and so applying it later can tell whether the ground has shifted.
 */
class GeneratePlan
{
    /**
     * @var array<int, class-string<OptimizerInterface>>
     */
    private const array OPTIMIZERS = [
        PostgresOptimizer::class,
        MysqlOptimizer::class,
        NginxOptimizer::class,
        KernelOptimizer::class,
        RedisOptimizer::class,
    ];

    public function __construct(
        private readonly Probe $probe = new Probe,
        private readonly ResourceBudget $budget = new ResourceBudget,
        private readonly RulesetLoader $rulesets = new RulesetLoader,
    ) {}

    /**
     * @throws SSHError
     */
    public function handle(Server $server, ?User $user = null): OptimizationPlan
    {
        ['facts' => $facts, 'probe' => $probe] = $this->probe->withProbe($server);

        $budget = $this->budget->compute($facts);

        $proposals = [];

        foreach (self::OPTIMIZERS as $optimizer) {
            $proposals = [
                ...$proposals,
                ...app($optimizer)->propose($facts, $budget, $probe),
            ];
        }

        // PHP-FPM is sized per site rather than per server, so it needs the sites
        // the pool is being divided between.
        $proposals = [
            ...$proposals,
            ...app(FpmOptimizer::class)
                ->forSites($server->sites)
                ->propose($facts, $budget, $probe),
        ];

        return DB::transaction(function () use ($server, $user, $facts, $budget, $proposals): OptimizationPlan {
            $plan = OptimizationPlan::query()->create([
                'server_id' => $server->id,
                'user_id' => $user?->id,
                'status' => OptimizationPlanStatus::DRAFT,
                'source' => 'engine',
                'facts' => $facts->toArray(),
                'budget' => $budget->toArray(),
                'ruleset_versions' => $this->rulesetVersions(),
            ]);

            foreach ($proposals as $proposal) {
                $this->store($plan, $proposal);
            }

            return $plan->load('proposals');
        });
    }

    private function store(OptimizationPlan $plan, TuningProposal $proposal): void
    {
        $plan->proposals()->create([
            'component' => $proposal->component,
            'config_key' => $proposal->configKey,
            'current_value' => $proposal->currentValue,
            'proposed_value' => $proposal->proposedValue,
            'severity' => $proposal->severity,
            'apply_method' => $proposal->applyMethod,
            'rationale' => $proposal->rationale,
            'kb_ref' => $proposal->kbRef,
            'clamped' => $proposal->clamped,
            // Settings already at their proposed value are recorded but not
            // selected, so the panel can show they were checked without offering
            // to write a change that would do nothing.
            'accepted' => $proposal->isChange(),
        ]);
    }

    /**
     * Which ruleset produced this plan. Rules evolve, and a plan read a year from
     * now should say which version of the reasoning it came from.
     *
     * @return array<string, int>
     */
    private function rulesetVersions(): array
    {
        $versions = [];

        foreach ($this->rulesets->all() as $ruleset) {
            $versions[$ruleset->component] = $ruleset->rulesetVersion;
        }

        return $versions;
    }
}

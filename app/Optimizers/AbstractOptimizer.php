<?php

namespace App\Optimizers;

use App\DTOs\Budget;
use App\DTOs\ServerFacts;
use App\DTOs\TuningProposal;
use App\Enums\ApplyMethod;
use App\Enums\ProposalSeverity;
use App\Support\Optimization\FormulaEvaluator;
use App\Support\Optimization\Ruleset;
use App\Support\Optimization\RulesetLoader;
use RuntimeException;

/**
 * Turns a component's ruleset into concrete proposals for one server.
 *
 * All the shared work lives here -- evaluating formulas, clamping to declared
 * bounds, reading the value currently in force -- so a component optimizer only
 * has to say which facts its formulas can reference and how to find the running
 * value of a setting.
 */
abstract class AbstractOptimizer implements OptimizerInterface
{
    public function __construct(
        protected readonly RulesetLoader $rulesets = new RulesetLoader,
        protected readonly FormulaEvaluator $evaluator = new FormulaEvaluator,
    ) {}

    /**
     * The version of the service being tuned, used to narrow the ruleset. Null
     * means every rule applies.
     *
     * @param  array<string, string>  $probe
     */
    abstract protected function serviceVersion(ServerFacts $facts, array $probe): ?string;

    /**
     * The value currently in force on the server, or null when it could not be
     * read -- which is treated as "unknown", never as "matches what we propose".
     *
     * @param  array<string, string>  $probe
     */
    abstract protected function currentValue(string $configKey, array $probe): ?string;

    /**
     * The facts a formula in this component's ruleset may reference.
     *
     * @param  array<string, string>  $probe
     * @return array<string, float|int>
     */
    abstract protected function variables(ServerFacts $facts, Budget $budget, array $probe): array;

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

        foreach ($ruleset->rulesFor($this->serviceVersion($facts, $probe)) as $rule) {
            $proposal = $this->proposalFor($ruleset, $rule, $variables, $probe);

            if ($proposal instanceof TuningProposal) {
                $proposals[] = $proposal;
            }
        }

        return $this->sort($proposals);
    }

    protected function ruleset(): ?Ruleset
    {
        return $this->rulesets->component(static::component());
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  array<string, float|int>  $variables
     * @param  array<string, string>  $probe
     */
    protected function proposalFor(Ruleset $ruleset, array $rule, array $variables, array $probe): ?TuningProposal
    {
        [$value, $clamped] = $this->resolveValue($rule, $variables, $probe);

        if ($value === null) {
            return null;
        }

        return new TuningProposal(
            component: $ruleset->component,
            configKey: $rule['key'],
            currentValue: $this->currentValue($rule['key'], $probe),
            proposedValue: $value,
            severity: ProposalSeverity::from($rule['severity_if_default'] ?? 'low'),
            applyMethod: ApplyMethod::from($rule['apply']),
            rationale: trim((string) $rule['why']),
            kbRef: $rule['kb_ref'] ?? null,
            clamped: $clamped,
        );
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  array<string, float|int>  $variables
     * @param  array<string, string>  $probe
     * @return array{0: ?string, 1: bool}
     */
    protected function resolveValue(array $rule, array $variables, array $probe): array
    {
        if (isset($rule['value'])) {
            return [$this->literalValue($rule, $probe), false];
        }

        $computed = $this->evaluator->evaluate($rule['formula'], $variables);

        [$bounded, $clamped] = $this->clamp($computed, $rule['bounds'] ?? [], $variables);

        return [$this->format((int) round($bounded), $rule['unit'] ?? null), $clamped];
    }

    /**
     * A literal may vary with the storage the server actually has, which a
     * formula cannot express -- random_page_cost is 1.1 on flash and 4.0 on a
     * spinning disk, not a calculation.
     *
     * @param  array<string, mixed>  $rule
     * @param  array<string, string>  $probe
     */
    protected function literalValue(array $rule, array $probe): string
    {
        if (isset($rule['value_when_rotational']) && ($probe['disk_rotational'] ?? '0') === '1') {
            return (string) $rule['value_when_rotational'];
        }

        return (string) $rule['value'];
    }

    /**
     * Bounds are what keep a formula honest on hardware its author never saw --
     * and later, what keeps an AI adjustment inside a range it cannot exceed.
     *
     * @param  array<string, mixed>  $bounds
     * @param  array<string, float|int>  $variables
     * @return array{0: float, 1: bool}
     */
    protected function clamp(float $value, array $bounds, array $variables): array
    {
        $original = $value;

        if (isset($bounds['min'])) {
            $value = max($value, (float) $bounds['min']);
        }

        if (isset($bounds['min_formula'])) {
            $value = max($value, $this->evaluator->evaluate($bounds['min_formula'], $variables));
        }

        if (isset($bounds['max'])) {
            $value = min($value, (float) $bounds['max']);
        }

        if (isset($bounds['max_formula'])) {
            $value = min($value, $this->evaluator->evaluate($bounds['max_formula'], $variables));
        }

        return [$value, $value !== $original];
    }

    protected function format(int $value, ?string $unit): string
    {
        return $unit === null ? (string) $value : $value.$unit;
    }

    /**
     * Most severe first, then alphabetically so the order is stable between runs
     * and a reader can find a key again.
     *
     * @param  array<int, TuningProposal>  $proposals
     * @return array<int, TuningProposal>
     */
    protected function sort(array $proposals): array
    {
        usort($proposals, function (TuningProposal $a, TuningProposal $b): int {
            return [$b->severity->rank(), $a->configKey] <=> [$a->severity->rank(), $b->configKey];
        });

        return $proposals;
    }

    public static function component(): string
    {
        throw new RuntimeException('Optimizers must declare their component.');
    }
}

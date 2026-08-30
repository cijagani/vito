<?php

namespace App\Actions\Optimization;

use App\DTOs\TuningProposal;
use App\DTOs\VerificationResult;
use App\Enums\ProposalSeverity;
use App\Exceptions\SSHError;
use App\Models\OptimizationPlan;
use App\Models\OptimizationProposal;

/**
 * Asks a server what it actually believes, after a plan said it changed something.
 *
 * A write that succeeded and a setting that took effect are different claims. A
 * value can be written correctly, pass the config check, and still not be in force
 * -- overridden later in the file, needing a restart where only a reload happened,
 * or clamped by the service to something it considers reasonable. The only way to
 * know is to ask the running service.
 */
class VerifyPlan
{
    public function __construct(private readonly Probe $probe = new Probe) {}

    /**
     * @return array<int, VerificationResult>
     *
     * @throws SSHError
     */
    public function handle(OptimizationPlan $plan): array
    {
        ['probe' => $probe] = $this->probe->withProbe($plan->server);

        $results = [];

        // Only what has actually been written. Reporting a pending proposal as
        // "not applied" is true but useless -- it reads as a failure when nothing
        // was attempted.
        foreach ($plan->proposals()->where('accepted', true)->whereNotNull('applied_at')->get() as $proposal) {
            $results[] = $this->verify($proposal, $probe);
        }

        return $results;
    }

    /**
     * @param  array<string, string>  $probe
     */
    private function verify(OptimizationProposal $proposal, array $probe): VerificationResult
    {
        $actual = $this->actual($proposal, $probe);

        if ($actual === null) {
            // Not every setting the optimizer writes is one the probe reads back.
            // Reporting that honestly is better than reporting a pass nobody
            // checked.
            return new VerificationResult(
                component: $proposal->component,
                configKey: $proposal->config_key,
                expected: $proposal->proposed_value,
                actual: null,
                status: VerificationResult::UNKNOWN,
                note: 'The server was not asked for this value.',
            );
        }

        // Compared through the proposal DTO so "4GB" and "4096MB" are recognised as
        // the same value, exactly as they are when deciding whether to propose a
        // change in the first place.
        $matches = ! (new TuningProposal(
            component: $proposal->component,
            configKey: $proposal->config_key,
            currentValue: $actual,
            proposedValue: $proposal->proposed_value,
            severity: ProposalSeverity::LOW,
            applyMethod: $proposal->apply_method,
            rationale: '',
        ))->isChange();

        return new VerificationResult(
            component: $proposal->component,
            configKey: $proposal->config_key,
            expected: $proposal->proposed_value,
            actual: $actual,
            status: $matches ? VerificationResult::PASS : VerificationResult::FAIL,
            note: $matches
                ? null
                : 'The server reports a different value, so the change did not take effect.',
        );
    }

    /**
     * The probe key holding the running value for a setting.
     *
     * Pool settings arrive as "domain · pm.max_children"; the probe reports one
     * value per PHP version rather than per pool, so those are left unverified
     * rather than compared against the wrong thing.
     *
     * @param  array<string, string>  $probe
     */
    private function actual(OptimizationProposal $proposal, array $probe): ?string
    {
        if (str_contains($proposal->config_key, ' · ')) {
            return null;
        }

        $key = match ($proposal->component) {
            'postgresql' => 'pg_'.$proposal->config_key,
            'mysql' => 'mysql_'.$proposal->config_key,
            'redis' => 'redis_'.str_replace('-', '_', $proposal->config_key),
            'nginx' => 'nginx_'.$proposal->config_key,
            'kernel' => 'sysctl_'.str_replace('.', '_', $proposal->config_key),
            'php-fpm' => 'php_'.str_replace('.', '_', $proposal->config_key),
            default => null,
        };

        if ($key === null) {
            return null;
        }

        $value = $probe[$key] ?? null;

        return $value === null || $value === '' ? null : $value;
    }
}

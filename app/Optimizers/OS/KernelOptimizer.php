<?php

namespace App\Optimizers\OS;

use App\DTOs\Budget;
use App\DTOs\ServerFacts;
use App\Optimizers\AbstractOptimizer;

/**
 * Proposes kernel and network settings.
 *
 * Skipped entirely inside a container. Most of these keys belong to the host
 * kernel and a container either refuses the write or silently ignores it, so
 * proposing them would promise a change that cannot happen.
 */
class KernelOptimizer extends AbstractOptimizer
{
    public static function component(): string
    {
        return 'kernel';
    }

    /**
     * @param  array<string, string>  $probe
     */
    public function applies(ServerFacts $facts, array $probe): bool
    {
        return ! $facts->isContainer();
    }

    /**
     * @param  array<string, string>  $probe
     */
    protected function serviceVersion(ServerFacts $facts, array $probe): ?string
    {
        return null;
    }

    /**
     * The probe does not read current sysctl values, so every proposal is offered
     * against an unknown rather than claimed to be a change. Reading them is a
     * later refinement; proposing the right value is useful without it.
     *
     * @param  array<string, string>  $probe
     */
    protected function currentValue(string $configKey, array $probe): ?string
    {
        // Every separator, not only dots: fs.file-max would otherwise keep its
        // hyphen and never match the key the probe emitted.
        $value = $probe['sysctl_'.preg_replace('/[^a-z0-9]+/i', '_', $configKey)] ?? null;

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
        ];
    }
}

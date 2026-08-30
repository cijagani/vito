<?php

namespace App\Optimizers\Webserver;

use App\DTOs\Budget;
use App\DTOs\ServerFacts;
use App\Optimizers\AbstractOptimizer;

/**
 * Proposes nginx worker and HTTP settings.
 *
 * Capacity comes from the machine: one worker per core, and connections bounded
 * by the file-descriptor limit the kernel will actually allow.
 */
class NginxOptimizer extends AbstractOptimizer
{
    /**
     * A conservative ceiling used when the probe could not read the limit, since
     * proposing more descriptors than the kernel permits produces a config nginx
     * accepts and then fails to honour under load.
     */
    private const int DEFAULT_FD_LIMIT = 65535;

    public static function component(): string
    {
        return 'nginx';
    }

    /**
     * @param  array<string, string>  $probe
     */
    public function applies(ServerFacts $facts, array $probe): bool
    {
        return isset($probe['nginx_worker_processes']);
    }

    /**
     * @param  array<string, string>  $probe
     */
    protected function serviceVersion(ServerFacts $facts, array $probe): ?string
    {
        return null;
    }

    /**
     * @param  array<string, string>  $probe
     */
    protected function currentValue(string $configKey, array $probe): ?string
    {
        $value = $probe['nginx_'.str_replace('.', '_', $configKey)] ?? null;

        return $value === null || $value === '' ? null : $value;
    }

    /**
     * @param  array<string, string>  $probe
     * @return array<string, float|int>
     */
    protected function variables(ServerFacts $facts, Budget $budget, array $probe): array
    {
        $fdLimit = (int) ($probe['nofile_limit'] ?? 0);

        return [
            'cores' => $facts->cores,
            'total_ram_mb' => $facts->totalRamMb,
            'fd_limit' => $fdLimit > 0 ? $fdLimit : self::DEFAULT_FD_LIMIT,
        ];
    }
}

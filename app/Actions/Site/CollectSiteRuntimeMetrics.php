<?php

namespace App\Actions\Site;

use App\Enums\FpmServiceMode;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteRuntimeMetric;
use LogicException;

class CollectSiteRuntimeMetrics
{
    public function collect(Server $server, Site $site): ?SiteRuntimeMetric
    {
        if ($site->server_id !== $server->id) {
            throw new LogicException('Site runtime metrics must be collected from the site server.');
        }

        $profile = $site->relationLoaded('runtimeProfile')
            ? $site->runtimeProfile
            : $site->runtimeProfile()->first();

        if (! $site->isIsolated() || ! $site->php_version || $profile?->fpm_service_mode !== FpmServiceMode::DEDICATED_MASTER) {
            return null;
        }

        $output = $server->ssh()->exec(
            command: view('ssh.services.php.site-runtime-metrics', [
                'serviceUnit' => $site->runtimeArtifacts()->dedicatedFpmServiceUnit($site->php_version),
            ]),
            log: 'collect-site-runtime-metrics',
            siteId: $site->id,
            timeout: 5,
        );

        $metrics = $this->parse($output);
        if ($metrics === []) {
            return null;
        }

        return $site->runtimeMetrics()->create($metrics);
    }

    /**
     * @return array<string, int>
     */
    private function parse(string $output): array
    {
        $allowed = [
            'memory_current_bytes',
            'memory_peak_bytes',
            'cpu_usage_usec',
            'tasks_current',
            'oom_kill_count',
        ];
        $metrics = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match('/^([a-z_]+):([0-9]+)$/', trim($line), $matches) !== 1) {
                continue;
            }

            if (! in_array($matches[1], $allowed, true)) {
                continue;
            }

            $value = filter_var($matches[2], FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0],
            ]);
            if ($value === false) {
                continue;
            }

            $metrics[$matches[1]] = $value;
        }

        return $metrics;
    }
}

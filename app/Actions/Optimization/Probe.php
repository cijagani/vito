<?php

namespace App\Actions\Optimization;

use App\DTOs\ServerFacts;
use App\Exceptions\SSHError;
use App\Models\Server;
use App\Models\Service;
use App\Services\Database\Mysql;
use App\Services\Database\Postgresql;
use App\Services\Redis\Redis;
use App\Services\Valkey\Valkey;
use App\Services\Webserver\Nginx;

/**
 * Reads the live facts the optimizer needs from a server, and combines them with
 * what Vito already knows from its own database.
 *
 * One SSH round trip. Vito talks to servers over the network rather than running
 * on them, so a probe made of twenty small commands would spend most of its time
 * in latency.
 */
class Probe
{
    /**
     * @throws SSHError
     */
    public function handle(Server $server): ServerFacts
    {
        $values = $this->parse($this->run($server));

        return new ServerFacts(
            totalRamMb: (int) ($values['total_ram_mb'] ?? 0),
            cores: max(1, (int) ($values['cores'] ?? 1)),
            physicalCores: (int) ($values['physical_cores'] ?? 0),
            diskRotational: ($values['disk_rotational'] ?? '0') === '1',
            virtualisation: $this->virtualisation($values['virtualisation'] ?? null),
            dbLocal: $this->databaseIsLocal($server),
            redisLocal: $this->hasService($server, [Redis::id(), Valkey::id()]),
            hasWorkers: $server->workers()->exists(),
            phpVersions: $this->phpVersions($values['php_versions'] ?? ''),
            avgWorkerRssMb: $this->intOrNull($values['fpm_avg_rss_mb'] ?? ''),
            swapTotalMb: (int) ($values['swap_total_mb'] ?? 0),
            oomKillCount: (int) ($server->metrics()->latest('id')->first()?->oom_kill_count ?? 0),
        );
    }

    /**
     * @throws SSHError
     */
    private function run(Server $server): string
    {
        $database = $server->database();

        return $server->ssh()->exec(
            view('ssh.optimization.probe', [
                'postgresVersion' => $this->versionOf($database, Postgresql::id()),
                'mysqlVersion' => $this->versionOf($database, Mysql::id()),
                'redisInstalled' => $this->hasService($server, [Redis::id(), Valkey::id()]),
                'nginxInstalled' => $this->hasService($server, [Nginx::id()]),
            ]),
            'optimization-probe',
            timeout: 30,
        );
    }

    /**
     * The probe emits one `key:value` line per fact, matching ssh/os/resource-info.
     *
     * @return array<string, string>
     */
    private function parse(string $output): array
    {
        $values = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match('/^([a-z0-9_]+):(.*)$/', trim($line), $matches) === 1) {
                $values[$matches[1]] = trim($matches[2]);
            }
        }

        return $values;
    }

    /**
     * Vito installed everything on this box, so the absence of a database service
     * means the application talks to one elsewhere -- and a remote database's
     * memory is budgeted on that machine, not this one.
     */
    private function databaseIsLocal(Server $server): bool
    {
        return $server->database() instanceof Service;
    }

    /**
     * @param  array<int, string>  $names
     */
    private function hasService(Server $server, array $names): bool
    {
        return $server->services()->whereIn('name', $names)->exists();
    }

    private function versionOf(?Service $service, string $name): ?string
    {
        return $service?->name === $name ? $service->version : null;
    }

    /**
     * @return array<int, string>
     */
    private function phpVersions(string $raw): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            fn (string $version): bool => $version !== ''
        ));
    }

    private function intOrNull(string $value): ?int
    {
        return $value === '' || ! is_numeric($value) ? null : (int) $value;
    }

    /**
     * systemd-detect-virt reports "none" on bare metal; the DTO expects null.
     */
    private function virtualisation(?string $value): ?string
    {
        return $value === null || $value === '' || $value === 'none' ? null : $value;
    }
}

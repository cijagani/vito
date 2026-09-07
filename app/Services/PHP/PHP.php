<?php

namespace App\Services\PHP;

use App\Actions\PHP\ApplySiteFpmConfig;
use App\DTOs\ServiceLog;
use App\Enums\FpmServiceMode;
use App\Exceptions\SSHCommandError;
use App\Exceptions\SSHError;
use App\Models\Site;
use App\Models\SiteRuntimeProfile;
use App\Support\SiteStorage;
use App\Services\AbstractService;
use App\Services\HasLogs;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PHP extends AbstractService implements HasLogs
{
    public static function id(): string
    {
        return 'php';
    }

    public static function type(): string
    {
        return 'php';
    }

    public function unit(): string
    {
        return 'php'.$this->service->version.'-fpm';
    }

    public function creationRules(array $input): array
    {
        return [
            'version' => [
                'required',
                Rule::in(config('service.services.php.versions')),
                Rule::unique('services', 'version')
                    ->where('type', 'php')
                    ->where('server_id', $this->service->server_id),
            ],
        ];
    }

    public function deletionRules(): array
    {
        return [
            'service' => [
                function (string $attribute, mixed $value, Closure $fail): void {
                    $hasSite = $this->service->server->sites()
                        ->where('php_version', $this->service->version)
                        ->exists();
                    if ($hasSite) {
                        $fail('Some sites are using this PHP version.');
                    }
                },
            ],
        ];
    }

    /**
     * @throws SSHError
     */
    public function install(): void
    {
        $server = $this->service->server;
        $server->ssh()
            ->setLog($this->service->log)
            ->exec(
                view('ssh.services.php.install-php', [
                    'version' => $this->service->version,
                    'user' => $server->getSshUser(),
                ]),
                'install-php-'.$this->service->version
            );
        event('service.installed', $this->service);
        $this->service->server->os()->cleanup();
    }

    /**
     * @throws SSHError
     */
    public function uninstall(): void
    {
        $this->service->server->ssh()->exec(
            view('ssh.services.php.uninstall-php', [
                'version' => $this->service->version,
            ]),
            'uninstall-php-'.$this->service->version
        );
        event('service.uninstalled', $this->service);
        $this->service->server->os()->cleanup();
    }

    /**
     * @throws SSHError
     */
    public function setDefaultCli(): void
    {
        $this->service->server->ssh()->exec(
            view('ssh.services.php.change-default-php', [
                'version' => $this->service->version,
            ]),
            'change-default-php'
        );
    }

    /**
     * @throws SSHError
     */
    public function installExtension(string $name): void
    {
        $result = $this->service->server->ssh()->exec(
            view('ssh.services.php.install-php-extension', [
                'version' => $this->service->version,
                'name' => $name,
            ]),
            'install-php-extension-'.$name
        );
        $pos = strpos($result, '[PHP Modules]');
        if ($pos === false) {
            throw new SSHCommandError('Failed to install extension');
        }
        $result = Str::substr($result, $pos);
        if (! Str::contains($result, $name)) {
            throw new SSHCommandError('Failed to install extension');
        }
    }

    /**
     * @throws SSHError
     */
    public function getPHPIni(string $type): string
    {
        return $this->service->server->os()->readFile(
            sprintf('/etc/php/%s/%s/php.ini', $this->service->version, $type)
        );
    }

    /**
     * @throws SSHError
     */
    public function createFpmPool(string $user, string $version): void
    {
        $this->service->server->ssh()->write(
            "/etc/php/{$version}/fpm/pool.d/{$user}.conf",
            view('ssh.services.php.fpm-pool', [
                'user' => $user,
                'version' => $version,
                'homeDirectory' => SiteStorage::homeDirectory($user),
            ]),
            'root'
        );

        $this->service->server->systemd()->restart($this->unit());
    }

    /**
     * @throws SSHError
     */
    public function createSiteFpmPool(Site $site): void
    {
        app(ApplySiteFpmConfig::class)->apply($site);
    }

    /**
     * @throws SSHError
     */
    public function removeSiteFpmPool(Site $site, ?string $phpVersion = null): void
    {
        $version = $phpVersion ?? $site->php_version;
        if ($version === '') {
            return;
        }

        $artifacts = $site->runtimeArtifacts();
        $removingCurrentRuntime = $phpVersion === null || $version === $site->php_version;
        $dedicated = $site->runtimeProfile()->value('fpm_service_mode') === 'dedicated_master';
        $this->service->server->ssh()->exec(
            view($dedicated
                ? 'ssh.services.php.remove-dedicated-site-fpm-config'
                : 'ssh.services.php.remove-site-fpm-pool', [
                'targetPath' => $artifacts->fpmConfigPath(
                    $version,
                    $dedicated ? FpmServiceMode::DEDICATED_MASTER : FpmServiceMode::SHARED_MASTER,
                ),
                'socketPath' => $artifacts->fpmSocketPath($version),
                'stateDirectory' => $artifacts->fpmStateDirectory($version),
                'cliRuntimeDirectory' => $artifacts->phpCliIniDirectory(),
                'sitePhpLink' => $site->homeDirectory().'/bin/php',
                'removeCliRuntime' => $removingCurrentRuntime,
                'removeSitePhpLink' => $removingCurrentRuntime && ! $site->userSharedWithSiblings(),
                'fpmBinary' => '/usr/sbin/php-fpm'.$version,
                'serviceUnit' => $dedicated
                    ? $artifacts->dedicatedFpmServiceUnit($version)
                    : 'php'.$version.'-fpm',
                'servicePath' => $artifacts->dedicatedFpmServicePath($version),
                'slicePath' => $artifacts->systemdSlicePath(),
            ]),
            $dedicated ? 'remove-dedicated-site-fpm-config' : 'remove-site-fpm-pool',
            $site->id,
        );
    }

    public function retireLegacyFpmPoolIfUnused(Site $site, ?string $phpVersion = null): bool
    {
        $version = $phpVersion ?? $site->php_version;
        $hasUnmigratedSibling = Site::query()
            ->where('server_id', $site->server_id)
            ->where('user', $site->user)
            ->where('php_version', $version)
            ->where(function ($query): void {
                $query->whereDoesntHave('runtimeProfile')
                    ->orWhereHas('runtimeProfile', fn ($profile) => $profile
                        ->whereNull('legacy_fpm_migrated_at'));
            })
            ->exists();

        if ($hasUnmigratedSibling) {
            return false;
        }

        try {
            $this->removeLegacyFpmPool($site, $version);
        } catch (SSHError $exception) {
            Log::warning('Legacy PHP-FPM pool could not be retired after site migration', [
                'site_id' => $site->id,
                'server_id' => $site->server_id,
                'php_version' => $version,
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }

        SiteRuntimeProfile::query()
            ->whereHas('site', fn ($query) => $query
                ->where('server_id', $site->server_id)
                ->where('user', $site->user)
                ->where('php_version', $version))
            ->update(['legacy_fpm_retired_at' => now()]);

        return true;
    }

    /**
     * @throws SSHError
     */
    public function removeLegacyFpmPoolIfLastConsumer(Site $site): void
    {
        $hasLegacyDependentSibling = Site::query()
            ->where('server_id', $site->server_id)
            ->where('user', $site->user)
            ->where('php_version', $site->php_version)
            ->whereKeyNot($site->id)
            ->where(function ($query): void {
                $query->whereDoesntHave('runtimeProfile')
                    ->orWhereHas('runtimeProfile', fn ($profile) => $profile
                        ->whereNull('legacy_fpm_migrated_at'));
            })
            ->exists();

        if (! $hasLegacyDependentSibling) {
            $this->removeLegacyFpmPool($site);
        }
    }

    /**
     * @throws SSHError
     */
    private function removeLegacyFpmPool(Site $site, ?string $phpVersion = null): void
    {
        $version = $phpVersion ?? $site->php_version;
        $this->service->server->ssh()->exec(
            view('ssh.services.php.remove-legacy-site-fpm-pool', [
                'targetPath' => $site->runtimeArtifacts()->fpmLegacyPoolPath($version, (string) $site->user),
                'stateDirectory' => $site->runtimeArtifacts()->fpmStateDirectory($version),
                'fpmBinary' => '/usr/sbin/php-fpm'.$version,
                'serviceUnit' => 'php'.$version.'-fpm',
            ]),
            'remove-legacy-site-fpm-pool',
            $site->id,
        );
    }

    /**
     * @throws SSHError
     */
    public function removeFpmPool(string $user, string $version, ?int $siteId): void
    {
        $this->service->server->ssh()->exec(
            view('ssh.services.php.remove-fpm-pool', [
                'user' => $user,
                'version' => $version,
            ]),
            "remove-{$version}fpm-pool-{$user}",
            $siteId
        );
    }

    public function versionCommand(): ?string
    {
        return sprintf(
            '/usr/bin/php%s -r %s 2>/dev/null',
            escapeshellarg($this->service->version),
            escapeshellarg('echo PHP_VERSION;')
        );
    }

    public function parseVersionOutput(string $output): ?string
    {
        if (preg_match('/(\d+\.\d+\.\d+)/', $output, $matches)) {
            return $matches[1];
        }

        return null;
    }

    public function logs(): array
    {
        $version = $this->service->version;
        $serviceLabel = 'PHP '.$version;

        $logs = [
            new ServiceLog(
                key: 'php:'.$version.':fpm-journal',
                serviceLabel: $serviceLabel,
                label: 'FPM service journal',
                source: ServiceLog::SOURCE_JOURNAL,
                target: 'php'.$version.'-fpm.service',
            ),
        ];

        $sites = $this->service->server->relationLoaded('sites')
            ? $this->service->server->sites
                ->where('php_version', $version)
                ->sortBy('id')
            : $this->service->server->sites()
                ->where('php_version', $version)
                ->orderBy('id')
                ->get(['id', 'domain', 'user', 'isolated_user_id']);

        foreach ($sites as $site) {
            $target = $site->isIsolated()
                ? $site->runtimeArtifacts()->logDirectory().'/php-error.log'
                : '/home/'.$site->user.'/.logs/php_errors.log';
            $logs[] = new ServiceLog(
                key: 'php:'.$version.':site:'.$site->id,
                serviceLabel: $serviceLabel,
                label: 'FPM pool '.$site->domain,
                source: ServiceLog::SOURCE_FILE,
                target: $target,
            );
        }

        return $logs;
    }
}

<?php

namespace App\Actions\PHP;

use App\Actions\Site\SyncSiteRuntimeProfiles;
use App\DTOs\SiteRuntimeConfig;
use App\Enums\FpmProcessManager;
use App\Enums\FpmServiceMode;
use App\Enums\SiteRuntimeConfigType;
use App\Models\Site;
use App\Models\SiteRuntimeProfile;
use App\Services\Webserver\Nginx;
use Illuminate\Support\Facades\DB;
use LogicException;

class RenderSiteFpmConfig
{
    public function __construct(private readonly SyncSiteRuntimeProfiles $profiles) {}

    public function render(Site $site): SiteRuntimeConfig
    {
        if (! $site->isIsolated() || $site->php_version === '') {
            throw new LogicException('A site-specific PHP-FPM pool requires an isolated PHP site.');
        }

        $this->profiles->sync($site);
        $profile = SiteRuntimeProfile::query()->where('site_id', $site->id)->firstOrFail();
        $this->assertValid($site, $profile);

        $webserverUser = $site->webserver()::id() === Nginx::id()
            ? Nginx::WORKER_USER
            : $site->server->getSshUser();
        $artifacts = $site->runtimeArtifacts();
        $contents = view('ssh.services.php.site-fpm-pool', [
            'poolName' => $artifacts->fpmPoolName(),
            'siteUser' => $site->user,
            'socketPath' => $artifacts->fpmSocketPath($site->php_version),
            'webserverUser' => $webserverUser,
            'processManager' => $profile->fpm_process_manager->value,
            'maxChildren' => $profile->fpm_max_children,
            'startServers' => $profile->fpm_start_servers,
            'minSpareServers' => $profile->fpm_min_spare_servers,
            'maxSpareServers' => $profile->fpm_max_spare_servers,
            'idleTimeoutSeconds' => $profile->fpm_idle_timeout_seconds ?? 10,
            'maxRequests' => $profile->fpm_max_requests,
            'requestTimeoutSeconds' => $profile->request_timeout_seconds,
            'slowRequestSeconds' => $profile->slow_request_seconds,
            'memoryLimitMb' => $profile->memory_limit_mb,
            'maxExecutionTimeSeconds' => $profile->max_execution_time_seconds,
            'maxInputTimeSeconds' => $profile->max_input_time_seconds,
            'maxInputVars' => $profile->max_input_vars,
            'postMaxSizeMb' => $profile->post_max_size_mb,
            'uploadMaxFilesizeMb' => $profile->upload_max_filesize_mb,
            'siteHome' => '/home/'.$site->user,
            'temporaryPath' => '/home/'.$site->user.'/tmp/'.$artifacts->key(),
            'errorLogPath' => $artifacts->logDirectory().'/php-error.log',
            'slowLogPath' => $artifacts->logDirectory().'/php-slow.log',
        ])->render();
        $checksum = hash('sha256', $contents);

        $revision = DB::transaction(function () use ($profile, $checksum): int {
            $locked = SiteRuntimeProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();
            if ($locked->desired_checksum !== $checksum) {
                $locked->desired_revision++;
                $locked->desired_checksum = $checksum;
                $locked->save();
            }

            return $locked->desired_revision;
        });

        return new SiteRuntimeConfig(
            SiteRuntimeConfigType::PHP_FPM,
            $artifacts->fpmPoolPath($site->php_version),
            $contents,
            $revision,
        );
    }

    private function assertValid(Site $site, SiteRuntimeProfile $profile): void
    {
        if ($profile->fpm_service_mode !== FpmServiceMode::SHARED_MASTER) {
            throw new LogicException('Dedicated PHP-FPM masters require the hardened runtime stage.');
        }

        if ($profile->php_version !== $site->php_version || ! $site->server->php($site->php_version)) {
            throw new LogicException('The selected PHP version is not installed on the site server.');
        }

        if ($profile->fpm_max_children < 1 || $profile->fpm_max_children > 500 || $profile->fpm_max_requests < 1) {
            throw new LogicException('The PHP-FPM process limits are invalid.');
        }

        if ($profile->request_timeout_seconds < 1 || $profile->request_timeout_seconds > 3600) {
            throw new LogicException('The PHP-FPM request timeout is invalid.');
        }

        if ($profile->fpm_process_manager === FpmProcessManager::DYNAMIC && (
            $profile->fpm_start_servers === null
            || $profile->fpm_min_spare_servers === null
            || $profile->fpm_max_spare_servers === null
            || $profile->fpm_start_servers < 1
            || $profile->fpm_min_spare_servers < 1
            || $profile->fpm_max_spare_servers < $profile->fpm_min_spare_servers
            || $profile->fpm_start_servers > $profile->fpm_max_children
            || $profile->fpm_max_spare_servers > $profile->fpm_max_children
        )) {
            throw new LogicException('The dynamic PHP-FPM process settings are invalid.');
        }
    }
}

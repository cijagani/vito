<?php

namespace App\Actions\PHP;

use App\Contracts\SiteRuntimeConfigApplier;
use App\DTOs\SiteRuntimeConfig;
use App\Enums\SiteRuntimeConfigType;
use App\Models\Site;
use App\Models\SiteRuntimeOperation;
use App\Models\SiteRuntimeProfile;
use LogicException;

class DeploySiteFpmConfig implements SiteRuntimeConfigApplier
{
    public function apply(Site $site, SiteRuntimeConfig $config): void
    {
        if ($config->type !== SiteRuntimeConfigType::PHP_FPM || ! $site->php_version) {
            throw new LogicException('The PHP-FPM applier only accepts PHP-FPM site configuration.');
        }

        $artifacts = $site->runtimeArtifacts();
        $profile = SiteRuntimeProfile::query()->where('site_id', $site->id)->firstOrFail();
        if ($profile->fpm_service_mode->value === 'dedicated_master') {
            $site->server->ssh()->exec(
                view('ssh.services.php.apply-dedicated-site-fpm-config', [
                    'candidatePath' => $artifacts->fpmCandidatePath($site->php_version, $config->checksum),
                    'serviceCandidatePath' => $artifacts->fpmServiceCandidatePath($site->php_version, $config->checksum),
                    'sliceCandidatePath' => $artifacts->systemdSliceCandidatePath($site->php_version, $config->checksum),
                    'targetPath' => $config->targetPath,
                    'servicePath' => $artifacts->dedicatedFpmServicePath($site->php_version),
                    'slicePath' => $artifacts->systemdSlicePath(),
                    'stateDirectory' => $artifacts->fpmStateDirectory($site->php_version),
                    'fpmBinary' => '/usr/sbin/php-fpm'.$site->php_version,
                    'serviceUnit' => $artifacts->dedicatedFpmServiceUnit($site->php_version),
                ]),
                'apply-dedicated-site-fpm-config',
                $site->id,
            );

            return;
        }
        $site->server->ssh()->exec(
            view('ssh.services.php.apply-site-fpm-config', [
                'candidatePath' => $artifacts->fpmCandidatePath($site->php_version, $config->checksum),
                'targetPath' => $config->targetPath,
                'stateDirectory' => $artifacts->fpmStateDirectory($site->php_version),
                'fpmBinary' => '/usr/sbin/php-fpm'.$site->php_version,
                'serviceUnit' => 'php'.$site->php_version.'-fpm',
            ]),
            'apply-site-fpm-config',
            $site->id,
        );
    }

    public function rollback(Site $site, SiteRuntimeOperation $operation): void
    {
        $phpVersion = data_get($operation->metadata, 'php_version');
        if ($operation->type !== SiteRuntimeConfigType::PHP_FPM || ! is_string($phpVersion)) {
            throw new LogicException('The PHP-FPM rollback operation is invalid.');
        }

        $site->server->ssh()->exec(
            view($this->isDedicated($site)
                ? 'ssh.services.php.rollback-dedicated-site-fpm-config'
                : 'ssh.services.php.rollback-site-fpm-config', [
                'targetPath' => $operation->target_path,
                'stateDirectory' => $site->runtimeArtifacts()->fpmStateDirectory($phpVersion),
                'fpmBinary' => '/usr/sbin/php-fpm'.$phpVersion,
                'serviceUnit' => $this->isDedicated($site)
                    ? $site->runtimeArtifacts()->dedicatedFpmServiceUnit($phpVersion)
                    : 'php'.$phpVersion.'-fpm',
                'servicePath' => $site->runtimeArtifacts()->dedicatedFpmServicePath($phpVersion),
                'slicePath' => $site->runtimeArtifacts()->systemdSlicePath(),
            ]),
            $this->isDedicated($site) ? 'rollback-dedicated-site-fpm-config' : 'rollback-site-fpm-config',
            $site->id,
        );
    }

    public function finish(Site $site, SiteRuntimeConfig $config): void
    {
        if (! $site->php_version) {
            throw new LogicException('The PHP-FPM cleanup requires a PHP version.');
        }

        $artifacts = $site->runtimeArtifacts();
        $site->server->ssh()->exec(
            view('ssh.services.php.finish-site-fpm-config', [
                'candidatePath' => $artifacts->fpmCandidatePath($site->php_version, $config->checksum),
                'serviceCandidatePath' => $artifacts->fpmServiceCandidatePath($site->php_version, $config->checksum),
                'sliceCandidatePath' => $artifacts->systemdSliceCandidatePath($site->php_version, $config->checksum),
                'stateDirectory' => $artifacts->fpmStateDirectory($site->php_version),
            ]),
            'finish-site-fpm-config',
            $site->id,
        );
    }

    private function isDedicated(Site $site): bool
    {
        return SiteRuntimeProfile::query()
            ->where('site_id', $site->id)
            ->value('fpm_service_mode') === 'dedicated_master';
    }
}

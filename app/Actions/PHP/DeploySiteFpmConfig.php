<?php

namespace App\Actions\PHP;

use App\Contracts\SiteRuntimeConfigApplier;
use App\DTOs\SiteRuntimeConfig;
use App\Enums\SiteRuntimeConfigType;
use App\Models\Site;
use App\Models\SiteRuntimeOperation;
use LogicException;

class DeploySiteFpmConfig implements SiteRuntimeConfigApplier
{
    public function apply(Site $site, SiteRuntimeConfig $config): void
    {
        if ($config->type !== SiteRuntimeConfigType::PHP_FPM || ! $site->php_version) {
            throw new LogicException('The PHP-FPM applier only accepts PHP-FPM site configuration.');
        }

        $artifacts = $site->runtimeArtifacts();
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
            view('ssh.services.php.rollback-site-fpm-config', [
                'targetPath' => $operation->target_path,
                'stateDirectory' => $site->runtimeArtifacts()->fpmStateDirectory($phpVersion),
                'fpmBinary' => '/usr/sbin/php-fpm'.$phpVersion,
                'serviceUnit' => 'php'.$phpVersion.'-fpm',
            ]),
            'rollback-site-fpm-config',
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
                'stateDirectory' => $artifacts->fpmStateDirectory($site->php_version),
            ]),
            'finish-site-fpm-config',
            $site->id,
        );
    }
}

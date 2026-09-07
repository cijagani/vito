<?php

namespace App\Actions\PHP;

use App\Contracts\SiteRuntimeConfigValidator;
use App\DTOs\SiteRuntimeConfig;
use App\Enums\SiteRuntimeConfigType;
use App\Models\Site;
use App\Models\SiteRuntimeProfile;
use LogicException;

class ValidateSiteFpmConfig implements SiteRuntimeConfigValidator
{
    public function validate(Site $site, SiteRuntimeConfig $config): void
    {
        if ($config->type !== SiteRuntimeConfigType::PHP_FPM || ! $site->php_version) {
            throw new LogicException('The PHP-FPM validator only accepts PHP-FPM site configuration.');
        }

        $artifacts = $site->runtimeArtifacts();
        $candidatePath = $artifacts->fpmCandidatePath($site->php_version, $config->checksum);
        $profile = SiteRuntimeProfile::query()->where('site_id', $site->id)->firstOrFail();
        $site->server->ssh()->write($candidatePath, $config->contents, 'root');
        if ($profile->fpm_service_mode->value === 'dedicated_master') {
            $serviceRenderer = app(RenderSiteFpmService::class);
            $serviceCandidatePath = $artifacts->fpmServiceCandidatePath($site->php_version, $config->checksum);
            $sliceCandidatePath = $artifacts->systemdSliceCandidatePath($site->php_version, $config->checksum);
            $site->server->ssh()->write($serviceCandidatePath, $serviceRenderer->unit($site, $profile), 'root');
            $site->server->ssh()->write($sliceCandidatePath, $serviceRenderer->slice($profile), 'root');
            $site->server->ssh()->exec(
                view('ssh.services.php.validate-dedicated-site-fpm-config', [
                    'candidatePath' => $candidatePath,
                    'serviceCandidatePath' => $serviceCandidatePath,
                    'sliceCandidatePath' => $sliceCandidatePath,
                    'targetPath' => $config->targetPath,
                    'servicePath' => $artifacts->dedicatedFpmServicePath($site->php_version),
                    'slicePath' => $artifacts->systemdSlicePath(),
                    'stateDirectory' => $artifacts->fpmStateDirectory($site->php_version),
                    'fpmBinary' => '/usr/sbin/php-fpm'.$site->php_version,
                ]),
                'validate-dedicated-site-fpm-config',
                $site->id,
            );

            return;
        }
        $site->server->ssh()->exec(
            view('ssh.services.php.validate-site-fpm-config', [
                'candidatePath' => $candidatePath,
                'targetPath' => $config->targetPath,
                'stateDirectory' => $artifacts->fpmStateDirectory($site->php_version),
                'fpmBinary' => '/usr/sbin/php-fpm'.$site->php_version,
            ]),
            'validate-site-fpm-config',
            $site->id,
        );
    }
}

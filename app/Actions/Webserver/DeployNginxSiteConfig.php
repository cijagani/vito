<?php

namespace App\Actions\Webserver;

use App\Contracts\SiteRuntimeConfigApplier;
use App\DTOs\SiteRuntimeConfig;
use App\Enums\SiteRuntimeConfigType;
use App\Models\Site;
use App\Models\SiteRuntimeOperation;
use LogicException;

class DeployNginxSiteConfig implements SiteRuntimeConfigApplier
{
    public function apply(Site $site, SiteRuntimeConfig $config, bool $restart = false): void
    {
        if ($config->type !== SiteRuntimeConfigType::NGINX) {
            throw new LogicException('The Nginx applier only accepts Nginx configuration.');
        }

        $artifacts = $site->runtimeArtifacts();
        $site->server->ssh()->exec(
            view('ssh.services.webserver.nginx.apply-site-config', [
                'candidatePath' => $artifacts->nginxCandidatePath($config->checksum),
                'targetPath' => $artifacts->nginxAvailablePath(),
                'enabledPath' => $artifacts->nginxEnabledPath(),
                'legacyAvailablePath' => $artifacts->nginxLegacyAvailablePath($site->domain),
                'legacyEnabledPath' => $artifacts->nginxLegacyEnabledPath($site->domain),
                'stateDirectory' => $artifacts->nginxStateDirectory(),
                'serviceAction' => $restart ? 'restart' : 'reload',
            ]),
            'apply-nginx-site-config',
            $site->id
        );
    }

    public function rollback(Site $site, SiteRuntimeOperation $operation): void
    {
        if ($operation->type !== SiteRuntimeConfigType::NGINX) {
            throw new LogicException('The Nginx applier cannot roll back another runtime type.');
        }

        $artifacts = $site->runtimeArtifacts();
        $site->server->ssh()->exec(
            view('ssh.services.webserver.nginx.rollback-site-config', [
                'targetPath' => $artifacts->nginxAvailablePath(),
                'enabledPath' => $artifacts->nginxEnabledPath(),
                'legacyAvailablePath' => $artifacts->nginxLegacyAvailablePath($site->domain),
                'legacyEnabledPath' => $artifacts->nginxLegacyEnabledPath($site->domain),
                'stateDirectory' => $artifacts->nginxStateDirectory(),
            ]),
            'rollback-nginx-site-config',
            $site->id
        );
    }

    public function finish(Site $site, SiteRuntimeConfig $config): void
    {
        $artifacts = $site->runtimeArtifacts();
        $site->server->ssh()->exec(
            view('ssh.services.webserver.nginx.finish-site-config', [
                'candidatePath' => $artifacts->nginxCandidatePath($config->checksum),
                'stateDirectory' => $artifacts->nginxStateDirectory(),
            ]),
            'finish-nginx-site-config',
            $site->id
        );
    }
}

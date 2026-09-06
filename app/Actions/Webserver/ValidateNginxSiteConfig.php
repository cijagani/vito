<?php

namespace App\Actions\Webserver;

use App\Contracts\SiteRuntimeConfigValidator;
use App\DTOs\SiteRuntimeConfig;
use App\Enums\SiteRuntimeConfigType;
use App\Models\Site;
use LogicException;

class ValidateNginxSiteConfig implements SiteRuntimeConfigValidator
{
    public function validate(Site $site, SiteRuntimeConfig $config): void
    {
        if ($config->type !== SiteRuntimeConfigType::NGINX) {
            throw new LogicException('The Nginx validator only accepts Nginx configuration.');
        }

        $artifacts = $site->runtimeArtifacts();
        $site->server->ssh()->exec(
            view('ssh.services.webserver.nginx.prepare-config-state', [
                'stateDirectory' => $artifacts->nginxStateDirectory(),
            ]),
            'prepare-nginx-config-state',
            $site->id
        );
        $candidatePath = $artifacts->nginxCandidatePath($config->checksum);
        $site->server->ssh()->write($candidatePath, $config->contents, 'root');

        $site->server->ssh()->exec(
            view('ssh.services.webserver.nginx.validate-site-config', [
                'candidatePath' => $candidatePath,
                'targetPath' => $artifacts->nginxAvailablePath(),
                'enabledPath' => $artifacts->nginxEnabledPath(),
                'legacyEnabledPath' => $artifacts->nginxLegacyEnabledPath($site->domain),
                'stateDirectory' => $artifacts->nginxStateDirectory(),
            ]),
            'validate-nginx-site-config',
            $site->id
        );
    }
}

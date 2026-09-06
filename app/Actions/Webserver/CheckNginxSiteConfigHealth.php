<?php

namespace App\Actions\Webserver;

use App\Contracts\SiteRuntimeConfigHealthChecker;
use App\DTOs\SiteRuntimeConfig;
use App\Enums\SiteRuntimeConfigType;
use App\Models\Site;
use LogicException;

class CheckNginxSiteConfigHealth implements SiteRuntimeConfigHealthChecker
{
    public function check(Site $site, SiteRuntimeConfig $config): void
    {
        if ($config->type !== SiteRuntimeConfigType::NGINX) {
            throw new LogicException('The Nginx health checker only accepts Nginx configuration.');
        }

        $site->server->ssh()->exec(
            view('ssh.services.webserver.nginx.health-check-site-config', [
                'domain' => $site->domain,
                'siteId' => $site->id,
            ]),
            'health-check-nginx-site-config',
            $site->id
        );
    }
}

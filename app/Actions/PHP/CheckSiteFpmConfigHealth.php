<?php

namespace App\Actions\PHP;

use App\Contracts\SiteRuntimeConfigHealthChecker;
use App\DTOs\SiteRuntimeConfig;
use App\Enums\SiteRuntimeConfigType;
use App\Models\Site;
use App\Services\Webserver\Nginx;
use LogicException;

class CheckSiteFpmConfigHealth implements SiteRuntimeConfigHealthChecker
{
    public function check(Site $site, SiteRuntimeConfig $config): void
    {
        if ($config->type !== SiteRuntimeConfigType::PHP_FPM) {
            throw new LogicException('The PHP-FPM health checker only accepts PHP-FPM configuration.');
        }

        $webserverUser = $site->webserver()::id() === Nginx::id()
            ? Nginx::WORKER_USER
            : $site->server->getSshUser();
        $site->server->ssh()->exec(
            view('ssh.services.php.health-check-site-fpm-config', [
                'socketPath' => $site->runtimeArtifacts()->fpmSocketPath((string) $site->php_version),
                'webserverUser' => $webserverUser,
            ]),
            'health-check-site-fpm-config',
            $site->id,
        );
    }
}

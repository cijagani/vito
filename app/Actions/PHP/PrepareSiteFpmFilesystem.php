<?php

namespace App\Actions\PHP;

use App\Models\Site;
use App\Services\Webserver\Nginx;
use LogicException;

class PrepareSiteFpmFilesystem
{
    public function prepare(Site $site, string $phpVersion): void
    {
        if (! $site->exists || ! $site->isIsolated()) {
            throw new LogicException('PHP-FPM site filesystems require a persisted isolated site.');
        }

        $artifacts = $site->runtimeArtifacts();
        $webserverUser = $site->webserver()::id() === Nginx::id()
            ? Nginx::WORKER_USER
            : $site->server->getSshUser();
        $site->server->ssh()->exec(
            view('ssh.services.php.prepare-site-fpm-filesystem', [
                'siteUser' => $site->user,
                'webserverUser' => $webserverUser,
                'temporaryPath' => $site->homeDirectory().'/tmp/'.$artifacts->key(),
                'logDirectory' => $artifacts->logDirectory(),
                'stateDirectory' => $artifacts->fpmStateDirectory($phpVersion),
            ]),
            'prepare-site-fpm-filesystem',
            $site->id,
        );
    }
}

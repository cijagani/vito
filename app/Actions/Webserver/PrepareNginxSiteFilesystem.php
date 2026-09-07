<?php

namespace App\Actions\Webserver;

use App\Models\Site;
use App\Services\Webserver\Nginx;
use LogicException;

class PrepareNginxSiteFilesystem
{
    public function prepare(Site $site): void
    {
        if (! $site->exists || $site->id < 1) {
            throw new LogicException('Nginx site filesystems require a persisted site.');
        }

        $artifacts = $site->runtimeArtifacts();

        $site->server->ssh()->exec(
            view('ssh.services.webserver.nginx.prepare-site-filesystem', [
                'siteUser' => $site->user,
                'workerUser' => Nginx::WORKER_USER,
                'sitePath' => $site->path,
                'webRoot' => $site->getWebDirectoryPath(),
                'createWebRoot' => empty($site->repository),
                'homeDirectory' => $site->homeDirectory(),
                'temporaryPath' => $site->homeDirectory().'/tmp/'.$artifacts->key(),
                'logDirectory' => $artifacts->logDirectory(),
                'stateDirectory' => $artifacts->nginxStateDirectory(),
                'isIsolated' => $site->isIsolated(),
            ]),
            'prepare-nginx-site-filesystem',
            $site->id
        );
    }
}

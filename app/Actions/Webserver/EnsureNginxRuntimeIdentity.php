<?php

namespace App\Actions\Webserver;

use App\Models\Server;
use App\Services\Webserver\Nginx;
use LogicException;

class EnsureNginxRuntimeIdentity
{
    public function ensure(Server $server): void
    {
        if (! $server->exists || $server->id < 1) {
            throw new LogicException('The Nginx runtime identity requires a persisted server.');
        }

        $siteUsers = $server->isolatedUsers()
            ->orderBy('username')
            ->pluck('username')
            ->all();

        $server->ssh()->exec(
            view('ssh.services.webserver.nginx.ensure-runtime-identity', [
                'workerUser' => Nginx::WORKER_USER,
                'controlUser' => $server->getSshUser(),
                'legacySocketGroup' => 'vito',
                'siteUsers' => $siteUsers,
            ]),
            'ensure-nginx-runtime-identity'
        );
    }
}

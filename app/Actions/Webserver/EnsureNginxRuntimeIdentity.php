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
        $needsLegacySocketAccess = $server->sites()
            ->with('runtimeProfile')
            ->whereNotNull('isolated_user_id')
            ->whereNotNull('php_version')
            ->get()
            ->contains(fn ($site): bool => $site->type()->language() === 'php'
                && $site->runtimeProfile?->legacy_fpm_retired_at === null);

        $server->ssh()->exec(
            view('ssh.services.webserver.nginx.ensure-runtime-identity', [
                'workerUser' => Nginx::WORKER_USER,
                'controlUser' => $server->getSshUser(),
                'legacySocketGroup' => 'vito',
                'needsLegacySocketAccess' => $needsLegacySocketAccess,
                'siteUsers' => $siteUsers,
            ]),
            'ensure-nginx-runtime-identity'
        );
    }
}

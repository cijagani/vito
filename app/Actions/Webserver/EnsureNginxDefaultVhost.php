<?php

namespace App\Actions\Webserver;

use App\Models\Server;
use LogicException;

class EnsureNginxDefaultVhost
{
    public function ensure(Server $server): void
    {
        if (! $server->exists || $server->id < 1) {
            throw new LogicException('The Nginx default vhost requires a persisted server.');
        }

        $candidatePath = '/etc/nginx/sites-available/.vito-default-candidate-'.$server->id.'.conf';
        $server->ssh()->write(
            $candidatePath,
            view('ssh.services.webserver.nginx.default-vhost'),
            'root'
        );
        $server->ssh()->exec(
            view('ssh.services.webserver.nginx.ensure-default-vhost', [
                'candidatePath' => $candidatePath,
            ]),
            'ensure-nginx-default-vhost'
        );
    }
}

<?php

namespace App\Actions\Site;

use App\Models\Site;

class ConfigureSiteLogRotation
{
    public function configure(Site $site): void
    {
        if (! $site->isIsolated()) {
            return;
        }

        $artifacts = $site->runtimeArtifacts();
        $site->server->ssh()->write(
            $artifacts->logrotatePath(),
            view('ssh.os.site-logrotate', [
                'logDirectory' => $artifacts->logDirectory(),
            ]),
            'root',
        );
    }

    public function remove(Site $site): void
    {
        if (! $site->isIsolated()) {
            return;
        }

        $site->server->ssh()->exec(
            view('ssh.os.remove-site-logrotate', [
                'logrotatePath' => $site->runtimeArtifacts()->logrotatePath(),
            ]),
            'remove-site-logrotate',
            $site->id,
        );
    }
}

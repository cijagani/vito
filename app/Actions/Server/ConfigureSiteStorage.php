<?php

namespace App\Actions\Server;

use App\Models\Server;
use App\Support\SiteStorage;

class ConfigureSiteStorage
{
    public function configure(Server $server): void
    {
        $server->ssh()->exec(
            view('ssh.os.configure-site-storage', [
                'storageRoot' => SiteStorage::ROOT,
            ]),
            'configure-site-storage',
        );
    }
}

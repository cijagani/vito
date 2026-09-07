<?php

namespace App\Actions\PHP;

use App\Actions\Webserver\EnsureNginxRuntimeIdentity;
use App\Models\Service;
use App\Models\Site;
use App\Models\SiteRuntimeProfile;
use App\Services\PHP\PHP;
use App\Services\Webserver\Nginx;

class FinalizeSitePhpRuntimeMigration
{
    public function finalize(Site $site): void
    {
        if (! $site->isIsolated() || ! $site->php_version || $site->type()->language() !== 'php') {
            return;
        }

        $service = $site->server->php($site->php_version);
        if (! $service instanceof Service) {
            return;
        }

        SiteRuntimeProfile::query()
            ->where('site_id', $site->id)
            ->update(['legacy_fpm_migrated_at' => now()]);

        /** @var PHP $php */
        $php = $service->handler();
        if ($php->retireLegacyFpmPoolIfUnused($site) && $site->webserver()::id() === Nginx::id()) {
            app(EnsureNginxRuntimeIdentity::class)->ensure($site->server);
        }
    }
}

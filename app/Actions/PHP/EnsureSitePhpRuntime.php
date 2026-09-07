<?php

namespace App\Actions\PHP;

use App\Actions\Site\SyncSiteRuntimeProfiles;
use App\Actions\Webserver\EnsureNginxRuntimeIdentity;
use App\Models\Service;
use App\Models\Site;
use App\Models\SiteRuntimeProfile;
use App\Services\PHP\PHP;
use App\Services\Webserver\Nginx;
use RuntimeException;

class EnsureSitePhpRuntime
{
    public function __construct(
        private readonly SyncSiteRuntimeProfiles $profiles,
        private readonly EnsureSitePhpCli $cli,
    ) {}

    public function ensure(Site $site): void
    {
        if (! $site->isIsolated() || ! $site->php_version || $site->type()->language() !== 'php') {
            return;
        }

        if ($site->webserver()::id() === Nginx::id()) {
            app(EnsureNginxRuntimeIdentity::class)->ensure($site->server);
        }

        $service = $site->server->php($site->php_version);
        if (! $service instanceof Service) {
            throw new RuntimeException('PHP service not found');
        }

        $this->profiles->sync($site);
        $profile = SiteRuntimeProfile::query()->where('site_id', $site->id)->firstOrFail();

        if ($profile->applied_checksum === null || $profile->needsApply()) {
            /** @var PHP $php */
            $php = $service->handler();
            $php->createSiteFpmPool($site);
        }

        $this->cli->ensure($site);
    }
}

<?php

namespace App\Actions\Site;

use App\Models\Site;
use Illuminate\Validation\ValidationException;

class RepairSiteRuntime
{
    public function repair(Site $site): void
    {
        if (! $site->isIsolated() || ! $site->php_version || $site->type()->language() !== 'php') {
            throw ValidationException::withMessages([
                'site' => 'Runtime repair requires an isolated PHP site.',
            ]);
        }

        if (! $site->vhost_generation_enabled || $site->vhost_template !== null) {
            throw ValidationException::withMessages([
                'site' => 'Runtime repair requires automatic generation with the managed default vhost.',
            ]);
        }

        $site->webserver()->updateVHost($site);
        app(RefreshSiteRuntimeConsumers::class)->refresh($site);
    }
}

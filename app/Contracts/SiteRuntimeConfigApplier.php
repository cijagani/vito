<?php

namespace App\Contracts;

use App\DTOs\SiteRuntimeConfig;
use App\Models\Site;
use App\Models\SiteRuntimeOperation;

interface SiteRuntimeConfigApplier
{
    public function apply(Site $site, SiteRuntimeConfig $config): void;

    public function rollback(Site $site, SiteRuntimeOperation $operation): void;
}

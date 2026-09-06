<?php

namespace App\Contracts;

use App\DTOs\SiteRuntimeConfig;
use App\Models\Site;

interface SiteRuntimeConfigHealthChecker
{
    public function check(Site $site, SiteRuntimeConfig $config): void;
}

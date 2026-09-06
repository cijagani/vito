<?php

namespace App\Contracts;

use App\DTOs\SiteRuntimeConfig;
use App\Models\Site;

interface SiteRuntimeConfigRenderer
{
    public function render(Site $site): SiteRuntimeConfig;
}

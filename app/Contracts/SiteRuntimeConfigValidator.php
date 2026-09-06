<?php

namespace App\Contracts;

use App\DTOs\SiteRuntimeConfig;
use App\Models\Site;

interface SiteRuntimeConfigValidator
{
    public function validate(Site $site, SiteRuntimeConfig $config): void;
}

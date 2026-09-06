<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\SiteWebProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteWebProfile>
 */
class SiteWebProfileFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'static_cache_policy' => 'default',
            'symlink_policy' => 'if_not_owner',
            'access_log_enabled' => true,
            'desired_revision' => 1,
            'applied_revision' => null,
        ];
    }
}

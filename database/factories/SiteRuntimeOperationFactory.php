<?php

namespace Database\Factories;

use App\Enums\SiteRuntimeConfigType;
use App\Enums\SiteRuntimeOperationStatus;
use App\Models\Site;
use App\Models\SiteRuntimeOperation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteRuntimeOperation>
 */
class SiteRuntimeOperationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'server_id' => 1,
            'site_id' => Site::factory(),
            'actor_id' => null,
            'type' => SiteRuntimeConfigType::NGINX,
            'status' => SiteRuntimeOperationStatus::PENDING,
            'target_path' => '/etc/nginx/sites-available/vito-site-1.conf',
            'desired_revision' => 1,
        ];
    }
}

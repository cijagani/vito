<?php

namespace Database\Factories;

use App\Enums\FpmProcessManager;
use App\Enums\FpmServiceMode;
use App\Enums\SiteIsolationProfile;
use App\Models\Site;
use App\Models\SiteRuntimeProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteRuntimeProfile>
 */
class SiteRuntimeProfileFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'isolation_profile' => SiteIsolationProfile::ISOLATED,
            'php_version' => '8.2',
            'fpm_service_mode' => FpmServiceMode::SHARED_MASTER,
            'fpm_process_manager' => FpmProcessManager::DYNAMIC,
            'fpm_max_children' => 5,
            'fpm_start_servers' => 2,
            'fpm_min_spare_servers' => 1,
            'fpm_max_spare_servers' => 3,
            'fpm_idle_timeout_seconds' => null,
            'fpm_max_requests' => 500,
            'request_timeout_seconds' => 60,
            'desired_revision' => 1,
            'applied_revision' => null,
        ];
    }
}

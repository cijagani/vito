<?php

namespace App\Actions\PHP;

use App\Models\Site;
use App\Models\SiteRuntimeProfile;

class RenderSiteFpmService
{
    public function unit(Site $site, SiteRuntimeProfile $profile): string
    {
        $artifacts = $site->runtimeArtifacts();

        return view('ssh.services.php.site-fpm-service', [
            'description' => 'Vito PHP-FPM site '.$site->id,
            'serviceUnit' => $artifacts->dedicatedFpmServiceUnit((string) $site->php_version),
            'sliceUnit' => $artifacts->systemdSlice(),
            'fpmBinary' => '/usr/sbin/php-fpm'.$site->php_version,
            'configPath' => $artifacts->dedicatedFpmConfigPath((string) $site->php_version),
        ])->render();
    }

    public function slice(SiteRuntimeProfile $profile): string
    {
        return view('ssh.services.php.site-runtime-slice', [
            'cpuQuotaPercent' => $profile->cpu_quota_percent,
            'memoryHighMb' => $profile->memory_high_mb,
            'memoryMaxMb' => $profile->memory_max_mb,
            'tasksMax' => $profile->tasks_max,
        ])->render();
    }
}

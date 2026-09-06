<?php

namespace App\Actions\Site;

use App\Enums\FpmProcessManager;
use App\Enums\FpmServiceMode;
use App\Enums\SiteIsolationProfile;
use App\Models\Site;
use App\Models\SiteRuntimeProfile;
use App\Models\SiteWebProfile;
use Illuminate\Support\Facades\DB;
use LogicException;

class SyncSiteRuntimeProfiles
{
    public function sync(Site $site): void
    {
        if (! $site->exists || $site->id < 1) {
            throw new LogicException('Runtime profiles require a persisted site.');
        }

        DB::transaction(function () use ($site): void {
            $lockedSite = Site::query()->whereKey($site->id)->lockForUpdate()->firstOrFail();
            $php = $this->phpSettings($lockedSite);
            $isolationProfile = $this->isolationProfile($lockedSite);

            $runtime = SiteRuntimeProfile::query()->firstOrNew(['site_id' => $lockedSite->id]);
            if (! $runtime->exists) {
                $runtime->fill([
                    'fpm_service_mode' => FpmServiceMode::SHARED_MASTER,
                    'fpm_process_manager' => FpmProcessManager::DYNAMIC,
                    'fpm_max_children' => 5,
                    'fpm_start_servers' => 2,
                    'fpm_min_spare_servers' => 1,
                    'fpm_max_spare_servers' => 3,
                    'fpm_idle_timeout_seconds' => null,
                    'fpm_max_requests' => 500,
                    'request_timeout_seconds' => $php['max_execution_time'] ?? 60,
                ]);
            }
            $runtime->fill([
                'isolation_profile' => $isolationProfile,
                'php_version' => $lockedSite->php_version,
                'memory_limit_mb' => $php['memory_limit'] ?? null,
                'max_execution_time_seconds' => $php['max_execution_time'] ?? null,
                'max_input_vars' => $php['max_input_vars'] ?? null,
                'post_max_size_mb' => $php['max_upload_size'] ?? null,
                'upload_max_filesize_mb' => $php['max_upload_size'] ?? null,
            ]);
            $this->saveWithRevision($runtime);

            $web = SiteWebProfile::query()->firstOrNew(['site_id' => $lockedSite->id]);
            if (! $web->exists) {
                $web->fill([
                    'static_cache_policy' => 'default',
                    'symlink_policy' => 'legacy',
                    'access_log_enabled' => true,
                ]);
            }
            $web->fill([
                'client_max_body_size_mb' => $php['max_upload_size'] ?? null,
                'fastcgi_read_timeout_seconds' => $php['max_execution_time'] ?? null,
            ]);
            $this->saveWithRevision($web);

            if ($isolationProfile === SiteIsolationProfile::SHARED) {
                SiteRuntimeProfile::query()
                    ->whereHas('site', fn ($query) => $query
                        ->where('isolated_user_id', $lockedSite->isolated_user_id)
                        ->whereKeyNot($lockedSite->id))
                    ->where('isolation_profile', '!=', SiteIsolationProfile::SHARED->value)
                    ->get()
                    ->each(function (SiteRuntimeProfile $profile): void {
                        $profile->isolation_profile = SiteIsolationProfile::SHARED;
                        $profile->desired_revision++;
                        $profile->save();
                    });
            }
        });
    }

    private function isolationProfile(Site $site): SiteIsolationProfile
    {
        if ($site->isolated_user_id === null) {
            return SiteIsolationProfile::LEGACY_UNISOLATED;
        }

        return Site::query()->where('isolated_user_id', $site->isolated_user_id)->count() > 1
            ? SiteIsolationProfile::SHARED
            : SiteIsolationProfile::ISOLATED;
    }

    /** @return array<string, int> */
    private function phpSettings(Site $site): array
    {
        $php = $site->type_data['php'] ?? [];

        if (! is_array($php)) {
            return [];
        }

        $settings = [];
        foreach (['max_upload_size', 'max_execution_time', 'memory_limit', 'max_input_vars'] as $key) {
            if (isset($php[$key]) && is_numeric($php[$key])) {
                $settings[$key] = (int) $php[$key];
            }
        }

        return $settings;
    }

    private function saveWithRevision(SiteRuntimeProfile|SiteWebProfile $profile): void
    {
        if (! $profile->exists) {
            $profile->desired_revision = 1;
            $profile->save();

            return;
        }

        if ($profile->isDirty()) {
            $profile->desired_revision++;
            $profile->save();
        }
    }
}

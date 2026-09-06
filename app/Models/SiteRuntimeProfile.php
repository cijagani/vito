<?php

namespace App\Models;

use App\Enums\FpmProcessManager;
use App\Enums\FpmServiceMode;
use App\Enums\SiteIsolationProfile;
use Database\Factories\SiteRuntimeProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $site_id
 * @property SiteIsolationProfile $isolation_profile
 * @property ?string $php_version
 * @property FpmServiceMode $fpm_service_mode
 * @property FpmProcessManager $fpm_process_manager
 * @property int $fpm_max_children
 * @property ?int $fpm_start_servers
 * @property ?int $fpm_min_spare_servers
 * @property ?int $fpm_max_spare_servers
 * @property ?int $fpm_idle_timeout_seconds
 * @property int $fpm_max_requests
 * @property int $request_timeout_seconds
 * @property ?int $slow_request_seconds
 * @property ?int $memory_limit_mb
 * @property ?int $max_execution_time_seconds
 * @property ?int $max_input_time_seconds
 * @property ?int $max_input_vars
 * @property ?int $post_max_size_mb
 * @property ?int $upload_max_filesize_mb
 * @property ?int $cpu_quota_percent
 * @property ?int $memory_high_mb
 * @property ?int $memory_max_mb
 * @property ?int $tasks_max
 * @property ?int $disk_quota_mb
 * @property int $desired_revision
 * @property ?int $applied_revision
 * @property ?\Carbon\Carbon $last_applied_at
 * @property ?string $last_apply_error
 * @property Site $site
 */
class SiteRuntimeProfile extends AbstractModel
{
    /** @use HasFactory<SiteRuntimeProfileFactory> */
    use HasFactory;

    protected $fillable = [
        'site_id',
        'isolation_profile',
        'php_version',
        'fpm_service_mode',
        'fpm_process_manager',
        'fpm_max_children',
        'fpm_start_servers',
        'fpm_min_spare_servers',
        'fpm_max_spare_servers',
        'fpm_idle_timeout_seconds',
        'fpm_max_requests',
        'request_timeout_seconds',
        'slow_request_seconds',
        'memory_limit_mb',
        'max_execution_time_seconds',
        'max_input_time_seconds',
        'max_input_vars',
        'post_max_size_mb',
        'upload_max_filesize_mb',
        'cpu_quota_percent',
        'memory_high_mb',
        'memory_max_mb',
        'tasks_max',
        'disk_quota_mb',
        'desired_revision',
        'applied_revision',
        'last_applied_at',
        'last_apply_error',
    ];

    protected $casts = [
        'site_id' => 'integer',
        'isolation_profile' => SiteIsolationProfile::class,
        'fpm_service_mode' => FpmServiceMode::class,
        'fpm_process_manager' => FpmProcessManager::class,
        'fpm_max_children' => 'integer',
        'fpm_start_servers' => 'integer',
        'fpm_min_spare_servers' => 'integer',
        'fpm_max_spare_servers' => 'integer',
        'fpm_idle_timeout_seconds' => 'integer',
        'fpm_max_requests' => 'integer',
        'request_timeout_seconds' => 'integer',
        'slow_request_seconds' => 'integer',
        'memory_limit_mb' => 'integer',
        'max_execution_time_seconds' => 'integer',
        'max_input_time_seconds' => 'integer',
        'max_input_vars' => 'integer',
        'post_max_size_mb' => 'integer',
        'upload_max_filesize_mb' => 'integer',
        'cpu_quota_percent' => 'integer',
        'memory_high_mb' => 'integer',
        'memory_max_mb' => 'integer',
        'tasks_max' => 'integer',
        'disk_quota_mb' => 'integer',
        'desired_revision' => 'integer',
        'applied_revision' => 'integer',
        'last_applied_at' => 'datetime',
        'last_apply_error' => 'encrypted',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function needsApply(): bool
    {
        return $this->applied_revision !== $this->desired_revision;
    }
}

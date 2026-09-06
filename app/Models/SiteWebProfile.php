<?php

namespace App\Models;

use Database\Factories\SiteWebProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $site_id
 * @property ?int $client_max_body_size_mb
 * @property ?int $fastcgi_read_timeout_seconds
 * @property ?int $proxy_connect_timeout_seconds
 * @property ?int $proxy_read_timeout_seconds
 * @property string $static_cache_policy
 * @property ?string $rate_limit_profile
 * @property string $symlink_policy
 * @property bool $access_log_enabled
 * @property int $desired_revision
 * @property ?int $applied_revision
 * @property ?\Carbon\Carbon $last_applied_at
 * @property ?string $last_apply_error
 * @property Site $site
 */
class SiteWebProfile extends AbstractModel
{
    /** @use HasFactory<SiteWebProfileFactory> */
    use HasFactory;

    protected $fillable = [
        'site_id',
        'client_max_body_size_mb',
        'fastcgi_read_timeout_seconds',
        'proxy_connect_timeout_seconds',
        'proxy_read_timeout_seconds',
        'static_cache_policy',
        'rate_limit_profile',
        'symlink_policy',
        'access_log_enabled',
        'desired_revision',
        'applied_revision',
        'last_applied_at',
        'last_apply_error',
    ];

    protected $casts = [
        'site_id' => 'integer',
        'client_max_body_size_mb' => 'integer',
        'fastcgi_read_timeout_seconds' => 'integer',
        'proxy_connect_timeout_seconds' => 'integer',
        'proxy_read_timeout_seconds' => 'integer',
        'access_log_enabled' => 'boolean',
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

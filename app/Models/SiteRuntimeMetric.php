<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $site_id
 * @property ?int $memory_current_bytes
 * @property ?int $memory_peak_bytes
 * @property ?int $cpu_usage_usec
 * @property ?int $tasks_current
 * @property ?int $oom_kill_count
 * @property Site $site
 */
class SiteRuntimeMetric extends AbstractModel
{
    protected $fillable = [
        'site_id',
        'memory_current_bytes',
        'memory_peak_bytes',
        'cpu_usage_usec',
        'tasks_current',
        'oom_kill_count',
    ];

    protected $casts = [
        'site_id' => 'integer',
        'memory_current_bytes' => 'integer',
        'memory_peak_bytes' => 'integer',
        'cpu_usage_usec' => 'integer',
        'tasks_current' => 'integer',
        'oom_kill_count' => 'integer',
    ];

    /**
     * @return BelongsTo<Site, covariant $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}

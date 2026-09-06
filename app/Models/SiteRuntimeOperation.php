<?php

namespace App\Models;

use App\Enums\SiteRuntimeConfigType;
use App\Enums\SiteRuntimeOperationStatus;
use Database\Factories\SiteRuntimeOperationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $server_id
 * @property int $site_id
 * @property ?int $actor_id
 * @property SiteRuntimeConfigType $type
 * @property SiteRuntimeOperationStatus $status
 * @property string $target_path
 * @property int $desired_revision
 * @property ?string $desired_checksum
 * @property ?string $previous_checksum
 * @property ?array<string, mixed> $metadata
 * @property ?string $error
 * @property ?\Carbon\Carbon $started_at
 * @property ?\Carbon\Carbon $finished_at
 * @property Server $server
 * @property Site $site
 * @property ?User $actor
 */
class SiteRuntimeOperation extends AbstractModel
{
    /** @use HasFactory<SiteRuntimeOperationFactory> */
    use HasFactory;

    protected $fillable = [
        'server_id',
        'site_id',
        'actor_id',
        'type',
        'status',
        'target_path',
        'desired_revision',
        'desired_checksum',
        'previous_checksum',
        'metadata',
        'error',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'server_id' => 'integer',
        'site_id' => 'integer',
        'actor_id' => 'integer',
        'type' => SiteRuntimeConfigType::class,
        'status' => SiteRuntimeOperationStatus::class,
        'desired_revision' => 'integer',
        'metadata' => 'encrypted:json',
        'error' => 'encrypted',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}

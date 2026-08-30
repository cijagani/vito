<?php

namespace App\Models;

use App\DTOs\ServerFacts;
use App\Enums\ApplyMethod;
use App\Enums\OptimizationPlanStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * One analysis of one server: the facts it was computed from, the budget those
 * facts produced, and the changes proposed as a result.
 *
 * The facts and budget are stored rather than recomputed so a plan can still be
 * read, and audited, long after the machine has changed underneath it.
 *
 * @property int $server_id
 * @property ?int $user_id
 * @property OptimizationPlanStatus $status
 * @property string $source
 * @property ?array<string, mixed> $facts
 * @property ?array<string, mixed> $budget
 * @property ?array<string, int> $ruleset_versions
 * @property ?array<int, array<string, mixed>> $verification
 * @property ?\Carbon\Carbon $applied_at
 * @property ?\Carbon\Carbon $rolled_back_at
 * @property Server $server
 * @property ?User $user
 * @property Collection<int, OptimizationProposal> $proposals
 * @property Collection<int, OptimizationChange> $changes
 */
class OptimizationPlan extends AbstractModel
{
    protected $fillable = [
        'server_id',
        'user_id',
        'status',
        'source',
        'facts',
        'budget',
        'ruleset_versions',
        'verification',
        'applied_at',
        'rolled_back_at',
    ];

    protected $casts = [
        'server_id' => 'integer',
        'user_id' => 'integer',
        'status' => OptimizationPlanStatus::class,
        'facts' => 'json',
        'budget' => 'json',
        'ruleset_versions' => 'json',
        'verification' => 'json',
        'applied_at' => 'datetime',
        'rolled_back_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Server, covariant $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return BelongsTo<User, covariant $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<OptimizationProposal, covariant $this>
     */
    public function proposals(): HasMany
    {
        return $this->hasMany(OptimizationProposal::class);
    }

    /**
     * @return HasMany<OptimizationChange, covariant $this>
     */
    public function changes(): HasMany
    {
        return $this->hasMany(OptimizationChange::class);
    }

    public function serverFacts(): ServerFacts
    {
        return ServerFacts::fromArray($this->facts ?? []);
    }

    /**
     * Whether applying this plan would restart a service rather than reload it,
     * which is what the confirmation has to warn about.
     */
    public function isDisruptive(): bool
    {
        return $this->proposals()
            ->where('accepted', true)
            ->where('apply_method', ApplyMethod::RESTART->value)
            ->exists();
    }
}

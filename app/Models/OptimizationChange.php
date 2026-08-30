<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in the rollback manifest: a file the optimizer touched, and its
 * contents beforehand.
 *
 * A row is written here before anything is changed on the server. Without a
 * recorded original there is no way back, which is the difference between a panel
 * that edits configuration and one that can be trusted with production.
 *
 * The backup is encrypted because a configuration file may carry credentials --
 * a connection string, a replication password -- that must not sit in plain text
 * in Vito's database.
 *
 * @property int $optimization_plan_id
 * @property string $target_path
 * @property string $action
 * @property ?string $backup_content
 * @property ?string $backup_hash
 * @property ?string $applied_hash
 * @property ?\Carbon\Carbon $applied_at
 * @property ?\Carbon\Carbon $reverted_at
 * @property OptimizationPlan $plan
 */
class OptimizationChange extends AbstractModel
{
    public const string ACTION_CREATED = 'created';

    public const string ACTION_MODIFIED = 'modified';

    protected $fillable = [
        'optimization_plan_id',
        'target_path',
        'action',
        'backup_content',
        'backup_hash',
        'applied_hash',
        'applied_at',
        'reverted_at',
    ];

    protected $casts = [
        'optimization_plan_id' => 'integer',
        'backup_content' => 'encrypted',
        'applied_at' => 'datetime',
        'reverted_at' => 'datetime',
    ];

    protected $hidden = [
        'backup_content',
    ];

    /**
     * @return BelongsTo<OptimizationPlan, covariant $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(OptimizationPlan::class, 'optimization_plan_id');
    }

    /**
     * Reverting a file the optimizer created means deleting it, not restoring
     * content that never existed.
     */
    public function wasCreated(): bool
    {
        return $this->action === self::ACTION_CREATED;
    }

    public function isReverted(): bool
    {
        return $this->reverted_at !== null;
    }
}

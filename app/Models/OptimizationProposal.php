<?php

namespace App\Models;

use App\DTOs\TuningProposal;
use App\Enums\ApplyMethod;
use App\Enums\ProposalSeverity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One proposed setting within a plan, stored with the rationale that produced it.
 *
 * @property int $optimization_plan_id
 * @property string $component
 * @property string $config_key
 * @property ?string $current_value
 * @property string $proposed_value
 * @property ProposalSeverity $severity
 * @property ApplyMethod $apply_method
 * @property string $rationale
 * @property ?string $kb_ref
 * @property bool $clamped
 * @property bool $accepted
 * @property ?\Carbon\Carbon $applied_at
 * @property OptimizationPlan $plan
 */
class OptimizationProposal extends AbstractModel
{
    protected $fillable = [
        'optimization_plan_id',
        'component',
        'config_key',
        'current_value',
        'proposed_value',
        'severity',
        'apply_method',
        'rationale',
        'kb_ref',
        'clamped',
        'accepted',
    ];

    protected $casts = [
        'optimization_plan_id' => 'integer',
        'severity' => ProposalSeverity::class,
        'apply_method' => ApplyMethod::class,
        'clamped' => 'boolean',
        'accepted' => 'boolean',
    ];

    /**
     * @return BelongsTo<OptimizationPlan, covariant $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(OptimizationPlan::class, 'optimization_plan_id');
    }

    /**
     * Whether applying this would actually alter the running configuration. A
     * setting already at its proposed value is still reported, so the panel can
     * show it was checked rather than leaving a silent gap.
     *
     * Delegates to the DTO so stored and freshly computed proposals agree on what
     * counts as a change -- engines echo the same value as "4GB" or "4096MB", and
     * comparing the text would report changes that are not changes.
     */
    public function isChange(): bool
    {
        return $this->toDto()->isChange();
    }

    public function toDto(): TuningProposal
    {
        return new TuningProposal(
            component: $this->component,
            configKey: $this->config_key,
            currentValue: $this->current_value,
            proposedValue: $this->proposed_value,
            severity: $this->severity,
            applyMethod: $this->apply_method,
            rationale: $this->rationale,
            kbRef: $this->kb_ref,
            clamped: $this->clamped,
        );
    }
}

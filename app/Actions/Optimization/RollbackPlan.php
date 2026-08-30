<?php

namespace App\Actions\Optimization;

use App\Enums\OptimizationPlanStatus;
use App\Exceptions\SSHError;
use App\Models\OptimizationChange;
use App\Models\OptimizationPlan;
use App\Support\Optimization\ChangeWriter;
use Throwable;

/**
 * Puts a server back the way it was before a plan was applied.
 *
 * Replays the manifest in reverse, because a later change may depend on an
 * earlier one and undoing them in order can leave an intermediate state that
 * never existed.
 */
class RollbackPlan
{
    public function __construct(private readonly ChangeWriter $writer = new ChangeWriter) {}

    /**
     * @throws SSHError
     * @throws Throwable
     */
    public function handle(OptimizationPlan $plan): OptimizationPlan
    {
        $changes = $plan->changes()
            ->whereNull('reverted_at')
            ->orderByDesc('id')
            ->get();

        foreach ($changes as $change) {
            $this->revert($change);
        }

        if ($changes->isNotEmpty()) {
            $this->reload($plan);
        }

        $plan->status = OptimizationPlanStatus::ROLLED_BACK;
        $plan->rolled_back_at = now();
        $plan->save();

        return $plan;
    }

    /**
     * A file that cannot be restored must not stop the rest of the rollback: the
     * remaining changes are the ones still standing between the server and the
     * state the operator asked for.
     *
     * @throws Throwable
     */
    private function revert(OptimizationChange $change): void
    {
        try {
            $this->writer->restore($change);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @throws SSHError
     */
    private function reload(OptimizationPlan $plan): void
    {
        $service = $plan->server->database();

        if ($service === null) {
            return;
        }

        // Restarting rather than reloading: a rollback undoes settings that may
        // have needed a restart to take effect, and the same is true undoing them.
        $plan->server->systemd()->restart($service->unit ?: 'postgresql');
    }
}

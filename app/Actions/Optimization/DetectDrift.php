<?php

namespace App\Actions\Optimization;

use App\Exceptions\SSHError;
use App\Models\OptimizationChange;
use App\Models\OptimizationPlan;
use App\Models\Server;
use App\Support\Optimization\ChangeWriter;
use Throwable;

/**
 * Reports which managed files have been edited outside Vito.
 *
 * Vito is not the only thing that can write to these files, and it should not
 * pretend otherwise. Someone fixing an incident at three in the morning edits
 * postgresql.conf directly; a package upgrade replaces a pool file. Either way the
 * next plan would silently discard that work, and rollback would restore a file
 * that is no longer the one anybody wants back.
 */
class DetectDrift
{
    /**
     * @return array<int, array{path: string, plan_id: int, drifted: bool}>
     *
     * @throws SSHError
     */
    public function handle(Server $server): array
    {
        $changes = OptimizationChange::query()
            ->whereHas('plan', fn ($query) => $query->where('server_id', $server->id))
            ->whereNotNull('applied_at')
            ->whereNull('reverted_at')
            ->with('plan')
            ->get()
            // Only the most recent write to each path matters; earlier ones were
            // superseded by it, not by whoever edited the file afterwards.
            ->groupBy('target_path')
            ->map(fn ($group) => $group->sortByDesc('applied_at')->first());

        $results = [];

        foreach ($changes as $change) {
            $results[] = [
                'path' => $change->target_path,
                'plan_id' => $change->optimization_plan_id,
                'drifted' => $this->hasDrifted($server, $change),
            ];
        }

        return $results;
    }

    public function hasDriftedForPlan(OptimizationPlan $plan): bool
    {
        foreach ($plan->changes()->whereNotNull('applied_at')->whereNull('reverted_at')->get() as $change) {
            if ($this->hasDrifted($plan->server, $change)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compares what is on the server now against what Vito wrote.
     *
     * A file that cannot be read is reported as drifted rather than as unchanged:
     * it may have been deleted, and treating "gone" as "fine" is the wrong way to
     * be wrong here.
     *
     * @throws SSHError
     */
    private function hasDrifted(Server $server, OptimizationChange $change): bool
    {
        try {
            $current = $server->os()->readFile($change->target_path);
        } catch (Throwable) {
            return true;
        }

        if ($change->applied_hash === null) {
            return false;
        }

        return ChangeWriter::hash($current) !== $change->applied_hash;
    }
}

<?php

namespace App\Jobs\Optimization;

use App\Actions\Optimization\RollbackPlan;
use App\DTOs\SocketEventDTO;
use App\Events\SocketEvent;
use App\Http\Resources\OptimizationPlanResource;
use App\Models\OptimizationPlan;
use App\Models\ServerLog;
use App\Traits\UniqueQueue;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Undoes a plan away from the request.
 *
 * Shares the per-server lock with ApplyPlanJob, so a rollback cannot interleave
 * with an apply on the same machine -- the one case where two writers would be
 * fighting over exactly the same files.
 */
class RollbackPlanJob implements ShouldQueue
{
    use Queueable;
    use UniqueQueue;

    public function __construct(protected OptimizationPlan $plan) {}

    public function handle(): void
    {
        $this->run("server-{$this->plan->server_id}", function (): void {
            Log::info("Rolling back optimization plan ID {$this->plan->id} on server ID {$this->plan->server_id}");

            app(RollbackPlan::class)->handle($this->plan);

            $this->broadcast();

            Log::info("Optimization plan ID {$this->plan->id} rolled back");
        });
    }

    public function failed(Exception $e): void
    {
        // Deliberately not marking the plan rolled back: a rollback that failed
        // leaves the server somewhere between the two states, and saying otherwise
        // would hide that from whoever has to sort it out.
        $this->broadcast();

        ServerLog::log(
            $this->plan->server,
            'optimization-rollback-failed',
            $e->getMessage()
        );
    }

    private function broadcast(): void
    {
        $this->plan->refresh()->load('proposals');

        SocketEvent::dispatch(new SocketEventDTO(
            projectId: $this->plan->server->project_id,
            type: 'optimization.updated',
            data: new OptimizationPlanResource($this->plan),
        ));
    }
}

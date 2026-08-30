<?php

namespace App\Jobs\Optimization;

use App\Actions\Optimization\ApplyPlan;
use App\DTOs\SocketEventDTO;
use App\Enums\OptimizationPlanStatus;
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
 * Applies a plan away from the request.
 *
 * Two reasons this cannot run inline. Writing several files over SSH and waiting
 * for a service to restart is slow enough to hold a web worker for the duration.
 * And the lock is keyed per server, so two people applying plans to the same
 * machine queue behind one another instead of interleaving writes to the same
 * configuration.
 */
class ApplyPlanJob implements ShouldQueue
{
    use Queueable;
    use UniqueQueue;

    /**
     * @param  array<string, mixed>  $input
     */
    public function __construct(
        protected OptimizationPlan $plan,
        protected array $input = [],
    ) {}

    public function handle(): void
    {
        $this->run("server-{$this->plan->server_id}", function (): void {
            Log::info("Applying optimization plan ID {$this->plan->id} on server ID {$this->plan->server_id}");

            app(ApplyPlan::class)->handle($this->plan, $this->input);

            $this->broadcast();

            Log::info("Optimization plan ID {$this->plan->id} applied");
        });
    }

    public function failed(Exception $e): void
    {
        // ApplyPlan already rolled back and marked the plan failed; this covers the
        // case where the job died before it could -- a lost connection mid-apply,
        // for instance -- so the plan is never left showing as still applying.
        if ($this->plan->refresh()->status === OptimizationPlanStatus::APPLYING) {
            $this->plan->status = OptimizationPlanStatus::FAILED;
            $this->plan->save();
        }

        $this->broadcast();

        ServerLog::log(
            $this->plan->server,
            'optimization-apply-failed',
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

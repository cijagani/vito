<?php

namespace App\Actions\Optimization;

use App\Enums\ApplyMethod;
use App\Enums\OptimizationPlanStatus;
use App\Exceptions\SSHError;
use App\Models\OptimizationPlan;
use App\Optimizers\Database\PostgresApplier;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Writes an approved plan to the server.
 *
 * The first action in this subsystem that changes anything. Every write goes
 * through ChangeWriter, so the original is recorded before it is replaced and an
 * invalid configuration is put back rather than activated.
 */
class ApplyPlan
{
    public function __construct(
        private readonly PostgresApplier $postgres = new PostgresApplier,
        private readonly RollbackPlan $rollback = new RollbackPlan,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws SSHError
     * @throws Throwable
     */
    public function handle(OptimizationPlan $plan, array $input = []): OptimizationPlan
    {
        $this->validate($plan, $input);

        $plan->status = OptimizationPlanStatus::APPLYING;
        $plan->save();

        try {
            $accepted = $plan->proposals()->where('accepted', true)->get();

            $this->postgres->apply($plan, $accepted->where('component', 'postgresql'));

            $this->reload($plan, $accepted->contains(
                fn ($proposal): bool => $proposal->apply_method === ApplyMethod::RESTART
            ));
        } catch (Throwable $exception) {
            // Anything already written is put back, so a partial apply never
            // leaves the server in a state nobody planned for.
            $this->rollback->handle($plan);

            $plan->status = OptimizationPlanStatus::FAILED;
            $plan->save();

            throw $exception;
        }

        $plan->status = OptimizationPlanStatus::APPLIED;
        $plan->applied_at = now();
        $plan->save();

        return $plan;
    }

    /**
     * Reload where the settings allow it. A restart drops every open connection,
     * so it happens only when a setting genuinely requires one.
     *
     * @throws SSHError
     */
    private function reload(OptimizationPlan $plan, bool $requiresRestart): void
    {
        $service = $plan->server->database();

        if ($service === null) {
            return;
        }

        $unit = $service->unit ?: 'postgresql';

        $requiresRestart
            ? $plan->server->systemd()->restart($unit)
            : $plan->server->systemd()->reload($unit);
    }

    /**
     * Checked before the work is queued as well as before it runs, so a refusal
     * reaches the person who asked rather than surfacing later in a failed job.
     *
     * @param  array<string, mixed>  $input
     */
    public function validate(OptimizationPlan $plan, array $input): void
    {
        Validator::make($input, [
            // Applying a plan that restarts a service drops connections and any
            // in-flight request, so the caller has to say it meant to.
            'confirmed' => [$plan->isDisruptive() ? 'accepted' : 'nullable'],
        ], [
            'confirmed.accepted' => 'This plan restarts a service, which drops open connections.',
        ])->validate();

        if (! $plan->status->isApplicable()) {
            Validator::make([], [])->after(function ($validator) use ($plan): void {
                $validator->errors()->add(
                    'status',
                    "A plan that is {$plan->status->value} cannot be applied again."
                );
            })->validate();
        }
    }
}

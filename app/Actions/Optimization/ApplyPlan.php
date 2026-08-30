<?php

namespace App\Actions\Optimization;

use App\Enums\ApplyMethod;
use App\Enums\OptimizationPlanStatus;
use App\Exceptions\SSHError;
use App\Models\OptimizationPlan;
use App\Models\OptimizationProposal;
use App\Optimizers\Database\MysqlApplier;
use App\Optimizers\Database\PostgresApplier;
use App\Optimizers\OS\KernelApplier;
use App\Optimizers\PHP\FpmApplier;
use App\Optimizers\Redis\RedisApplier;
use App\Optimizers\Webserver\NginxApplier;
use App\Optimizers\Webserver\NginxContextApplier;
use App\Services\Database\Mariadb;
use App\Services\Database\Mysql;
use Illuminate\Support\Collection;
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
        private readonly MysqlApplier $mysql = new MysqlApplier,
        private readonly FpmApplier $fpm = new FpmApplier,
        private readonly NginxApplier $nginx = new NginxApplier,
        private readonly NginxContextApplier $nginxContext = new NginxContextApplier,
        private readonly KernelApplier $kernel = new KernelApplier,
        private readonly RedisApplier $redis = new RedisApplier,
        private readonly VerifyPlan $verify = new VerifyPlan,
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
            $accepted = $plan->proposals()
                ->where('accepted', true)
                // Applying one group at a time is the normal way to use this:
                // change PHP-FPM, watch the box, then decide about the database.
                ->when(
                    $input['components'] ?? null,
                    fn ($query, array $components) => $query->whereIn('component', $components)
                )
                ->get();

            $database = $accepted->where('component', 'postgresql');
            $mysql = $accepted->where('component', 'mysql');
            $fpm = $accepted->where('component', 'php-fpm');
            $nginx = $accepted->where('component', 'nginx');
            $kernel = $accepted->where('component', 'kernel');
            $redis = $accepted->where('component', 'redis');

            $this->postgres->apply($plan, $database);
            $this->mysql->apply($plan, $mysql);
            $this->fpm->apply($plan, $fpm);
            $this->nginx->apply($plan, $nginx);
            $this->nginxContext->apply($plan, $nginx);
            $this->kernel->apply($plan, $kernel);
            $this->redis->apply($plan, $redis);

            // Only the services that were actually written are reloaded. Bouncing
            // PHP-FPM because a database setting changed would drop requests for
            // no reason.
            if ($database->isNotEmpty()) {
                $this->reloadDatabase($plan, $this->requiresRestart($database));
            }

            if ($mysql->isNotEmpty()) {
                $this->reloadDatabase($plan, $this->requiresRestart($mysql));
            }

            if ($fpm->isNotEmpty()) {
                $this->reloadFpm($plan, $this->requiresRestart($fpm));
            }

            if ($nginx->isNotEmpty()) {
                $this->reloadUnit($plan, 'nginx', $this->requiresRestart($nginx));
            }

            // sysctl values are read from the file at boot, so the written file is
            // loaded now rather than restarting anything.
            if ($kernel->isNotEmpty()) {
                $plan->server->ssh()->exec(
                    'sudo sysctl -p /etc/sysctl.d/60-vito-tuning.conf > /dev/null',
                    'optimization-sysctl-load',
                );
            }

            // Redis values were already set on the running server as the validity
            // check, so only a restart-only setting needs the service bounced.
            if ($redis->isNotEmpty() && $this->requiresRestart($redis)) {
                $this->reloadUnit($plan, 'redis-server', true);
            }
        } catch (Throwable $exception) {
            // Anything already written is put back, so a partial apply never
            // leaves the server in a state nobody planned for.
            $this->rollback->handle($plan);

            $plan->status = OptimizationPlanStatus::FAILED;
            $plan->save();

            throw $exception;
        }

        // Mark what was just written, so a later run can tell which groups are
        // still outstanding.
        $plan->proposals()
            ->whereIn('id', $accepted->pluck('id'))
            ->update(['applied_at' => now()]);

        // Applying one group leaves the rest of the plan open, otherwise the first
        // group applied would make every other group unappliable.
        $plan->status = $this->hasRemainingWork($plan)
            ? OptimizationPlanStatus::DRAFT
            : OptimizationPlanStatus::APPLIED;

        $plan->applied_at = now();
        $plan->verification = $this->verifyQuietly($plan);
        $plan->save();

        return $plan;
    }

    /**
     * Whether the groups being applied would restart a service.
     *
     * Asked of the selection rather than the whole plan: applying only nginx
     * should not demand confirmation because a database setting elsewhere in the
     * plan happens to need a restart.
     *
     * @param  array<string, mixed>  $input
     */
    private function isDisruptive(OptimizationPlan $plan, array $input): bool
    {
        return $plan->proposals()
            ->where('accepted', true)
            ->whereNull('applied_at')
            ->when(
                $input['components'] ?? null,
                fn ($query, array $components) => $query->whereIn('component', $components)
            )
            ->where('apply_method', ApplyMethod::RESTART->value)
            ->exists();
    }

    /**
     * Whether any accepted proposal in this plan has still not been written.
     */
    private function hasRemainingWork(OptimizationPlan $plan): bool
    {
        return $plan->proposals()
            ->where('accepted', true)
            ->whereNull('applied_at')
            ->exists();
    }

    /**
     * Asks the server what it now believes, and records the answer.
     *
     * A failure here is a finding, not an error: the plan was applied, and what
     * verification adds is knowing whether it took effect. Throwing would roll back
     * a change that may well be correct, so the result is stored for the operator
     * to read instead.
     *
     * @return array<int, array<string, mixed>>
     */
    private function verifyQuietly(OptimizationPlan $plan): array
    {
        try {
            return array_map(
                fn ($result): array => $result->toArray(),
                $this->verify->handle($plan)
            );
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }

    /**
     * Reload where the settings allow it. A restart drops every open connection,
     * so it happens only when a setting genuinely requires one.
     *
     * @throws SSHError
     */
    private function reloadDatabase(OptimizationPlan $plan, bool $requiresRestart): void
    {
        $service = $plan->server->database();

        if ($service === null) {
            return;
        }

        // Derived from the service rather than defaulted, so a MySQL server whose
        // unit was never recorded is not asked to restart postgresql.
        $unit = $service->unit ?: match ($service->name) {
            Mariadb::id() => 'mariadb',
            Mysql::id() => 'mysql',
            default => 'postgresql',
        };

        $requiresRestart
            ? $plan->server->systemd()->restart($unit)
            : $plan->server->systemd()->reload($unit);
    }

    /**
     * Every installed PHP version, since OPcache is written per version and a pool
     * belongs to one of them.
     *
     * @throws SSHError
     */
    private function reloadFpm(OptimizationPlan $plan, bool $requiresRestart): void
    {
        $services = $plan->server->services()->where('type', 'php')->get();

        foreach ($services as $service) {
            $unit = $service->unit ?: "php{$service->version}-fpm";

            // OPcache memory is allocated once at start, so a reload leaves the old
            // size in place; those settings are marked restart in the ruleset for
            // exactly that reason.
            $requiresRestart
                ? $plan->server->systemd()->restart($unit)
                : $plan->server->systemd()->reload($unit);
        }
    }

    /**
     * @throws SSHError
     */
    private function reloadUnit(OptimizationPlan $plan, string $unit, bool $requiresRestart): void
    {
        $requiresRestart
            ? $plan->server->systemd()->restart($unit)
            : $plan->server->systemd()->reload($unit);
    }

    /**
     * @param  Collection<int, OptimizationProposal>  $proposals
     */
    private function requiresRestart(Collection $proposals): bool
    {
        return $proposals->contains(
            fn (OptimizationProposal $proposal): bool => $proposal->apply_method === ApplyMethod::RESTART
        );
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
            'confirmed' => [$this->isDisruptive($plan, $input) ? 'accepted' : 'nullable'],
            'components' => ['sometimes', 'array'],
            'components.*' => ['string'],
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

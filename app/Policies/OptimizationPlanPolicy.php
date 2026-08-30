<?php

namespace App\Policies;

use App\Models\OptimizationPlan;
use App\Models\Server;
use App\Models\User;
use App\Traits\HasRolePolicies;
use Illuminate\Auth\Access\HandlesAuthorization;

class OptimizationPlanPolicy
{
    use HandlesAuthorization;
    use HasRolePolicies;

    public function viewAny(User $user, Server $server): bool
    {
        return $this->hasReadAccess($user, $server->project) &&
            $server->isReady();
    }

    public function view(User $user, OptimizationPlan $plan): bool
    {
        return $this->hasReadAccess($user, $plan->server->project) &&
            $plan->server->isReady();
    }

    /**
     * Analysing a server reads its configuration over SSH, so it is gated behind
     * write access even though it changes nothing.
     */
    public function create(User $user, Server $server): bool
    {
        return $this->hasWriteAccess($user, $server->project) &&
            $server->isReady();
    }

    public function update(User $user, OptimizationPlan $plan): bool
    {
        return $this->hasWriteAccess($user, $plan->server->project) &&
            $plan->server->isReady();
    }

    public function delete(User $user, OptimizationPlan $plan): bool
    {
        return $this->hasWriteAccess($user, $plan->server->project) &&
            $plan->server->isReady();
    }
}

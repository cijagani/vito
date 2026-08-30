<?php

namespace App\Http\Controllers;

use App\Actions\Optimization\ApplyPlan;
use App\Actions\Optimization\GeneratePlan;
use App\Actions\Optimization\RollbackPlan;
use App\Http\Resources\OptimizationPlanResource;
use App\Models\OptimizationPlan;
use App\Models\Server;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;
use Spatie\RouteAttributes\Attributes\Get;
use Spatie\RouteAttributes\Attributes\Middleware;
use Spatie\RouteAttributes\Attributes\Post;
use Spatie\RouteAttributes\Attributes\Prefix;

#[Prefix('servers/{server}/optimization')]
#[Middleware(['auth', 'has-project'])]
class OptimizationController extends Controller
{
    #[Get('/', name: 'optimization')]
    public function index(Server $server): Response
    {
        $this->authorize('viewAny', [OptimizationPlan::class, $server]);

        $latest = $server->optimizationPlans()
            ->with('proposals')
            ->latest('id')
            ->first();

        return Inertia::render('optimization/index', [
            'plan' => $latest instanceof OptimizationPlan
                ? new OptimizationPlanResource($latest)
                : null,
            'hasDatabase' => $server->database() !== null,
        ]);
    }

    /**
     * Analyse the server. This reads its configuration over SSH and records what
     * should change; it writes nothing to the machine.
     */
    #[Post('/analyze', name: 'optimization.analyze')]
    public function analyze(Server $server, GeneratePlan $generate): RedirectResponse
    {
        $this->authorize('create', [OptimizationPlan::class, $server]);

        $generate->handle($server, auth()->user());

        return back()->with('success', 'Server analysed.');
    }

    /**
     * Write the accepted proposals to the server.
     *
     * @throws Throwable
     */
    #[Post('/{plan}/apply', name: 'optimization.apply')]
    public function apply(Request $request, Server $server, OptimizationPlan $plan, ApplyPlan $apply): RedirectResponse
    {
        $this->authorize('update', $plan);
        $this->ensureBelongsTo($plan, $server);

        $apply->handle($plan, $request->input());

        return back()->with('success', 'Optimization applied.');
    }

    /**
     * Put the server back the way it was before this plan.
     *
     * @throws Throwable
     */
    #[Post('/{plan}/rollback', name: 'optimization.rollback')]
    public function rollback(Server $server, OptimizationPlan $plan, RollbackPlan $rollback): RedirectResponse
    {
        $this->authorize('update', $plan);
        $this->ensureBelongsTo($plan, $server);

        $rollback->handle($plan);

        return back()->with('success', 'Optimization rolled back.');
    }

    #[Get('/{plan}', name: 'optimization.show')]
    public function show(Server $server, OptimizationPlan $plan): Response
    {
        $this->authorize('view', $plan);
        $this->ensureBelongsTo($plan, $server);

        return Inertia::render('optimization/index', [
            'plan' => new OptimizationPlanResource($plan->load('proposals')),
            'hasDatabase' => $server->database() !== null,
        ]);
    }

    /**
     * Route model binding resolves a plan globally, so without this a plan
     * belonging to another server is reachable -- and writable -- through this
     * server's URL.
     */
    private function ensureBelongsTo(OptimizationPlan $plan, Server $server): void
    {
        if ($plan->server_id !== $server->id) {
            abort(404);
        }
    }
}

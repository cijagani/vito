<?php

namespace App\Http\Controllers;

use App\Actions\Optimization\GeneratePlan;
use App\Http\Resources\OptimizationPlanResource;
use App\Models\OptimizationPlan;
use App\Models\Server;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
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

    #[Get('/{plan}', name: 'optimization.show')]
    public function show(Server $server, OptimizationPlan $plan): Response
    {
        // Route model binding resolves the plan globally, so a plan belonging to
        // another server would otherwise be reachable through this server's URL.
        if ($plan->server_id !== $server->id) {
            abort(404);
        }

        $this->authorize('view', $plan);

        return Inertia::render('optimization/index', [
            'plan' => new OptimizationPlanResource($plan->load('proposals')),
            'hasDatabase' => $server->database() !== null,
        ]);
    }
}

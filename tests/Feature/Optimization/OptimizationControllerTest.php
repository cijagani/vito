<?php

use App\Facades\SSH;
use App\Models\OptimizationPlan;
use App\Models\Server;
use App\Services\Database\Postgresql;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function controllerProbeOutput(): string
{
    return collect([
        'total_ram_mb' => '16384',
        'cores' => '4',
        'disk_rotational' => '0',
        'php_versions' => '8.4',
        'fpm_avg_rss_mb' => '80',
        'pg_version' => '17.2',
        'pg_shared_buffers' => '128MB',
        'pg_work_mem' => '4MB',
        'pg_max_connections' => '100',
    ])->map(fn ($value, $key): string => "{$key}:{$value}")->implode("\n");
}

beforeEach(function () {
    $this->actingAs($this->user);

    $this->server->services()->where('type', 'database')->delete();
    $this->server->services()->create([
        'type' => 'database',
        'name' => Postgresql::id(),
        'version' => '17',
        'is_default' => true,
    ]);
});

test('shows the optimization page', function () {
    $this->get(route('optimization', ['server' => $this->server]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('optimization/index')->where('plan', null));
});

test('analyzing a server records a plan and shows it', function () {
    SSH::fake(controllerProbeOutput());

    $this->post(route('optimization.analyze', ['server' => $this->server]))
        ->assertRedirect();

    $this->assertDatabaseHas('optimization_plans', [
        'server_id' => $this->server->id,
        'user_id' => $this->user->id,
        'status' => 'draft',
    ]);

    $this->get(route('optimization', ['server' => $this->server]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('optimization/index')->has('plan'));

    $plan = OptimizationPlan::query()->where('server_id', $this->server->id)->firstOrFail();
    $proposal = $plan->proposals()->where('config_key', 'shared_buffers')->firstOrFail();

    expect($proposal->proposed_value)->toBe('3932MB')
        ->and($proposal->current_value)->toBe('128MB');
});

test('a plan belonging to another server is not reachable', function () {
    SSH::fake(controllerProbeOutput());

    $other = Server::factory()->create([
        'user_id' => $this->user->id,
        'project_id' => $this->user->current_project_id,
    ]);

    $plan = OptimizationPlan::query()->create([
        'server_id' => $other->id,
        'status' => 'draft',
        'source' => 'engine',
    ]);

    $this->get(route('optimization.show', ['server' => $this->server, 'plan' => $plan]))
        ->assertNotFound();
});

test('a user outside the project cannot see the page', function () {
    $this->actingAs(App\Models\User::factory()->create());

    $this->get(route('optimization', ['server' => $this->server]))
        ->assertForbidden();
});

test('a user outside the project cannot analyze the server', function () {
    $this->actingAs(App\Models\User::factory()->create());

    $this->post(route('optimization.analyze', ['server' => $this->server]))
        ->assertForbidden();

    $this->assertDatabaseCount('optimization_plans', 0);
});

test('the plan payload carries no server credentials', function () {
    SSH::fake(controllerProbeOutput());

    $this->post(route('optimization.analyze', ['server' => $this->server]));

    $response = $this->get(route('optimization', ['server' => $this->server]));

    $payload = json_encode($response->viewData('page')['props']['plan']);

    foreach (['authentication', 'private_key', 'password', 'secret'] as $forbidden) {
        expect($payload)->not->toContain($forbidden);
    }
});

test('applying a plan writes it to the server', function () {
    SSH::fake('VITO_CONFIG_OK');

    $plan = OptimizationPlan::query()->create([
        'server_id' => $this->server->id,
        'status' => 'draft',
        'source' => 'engine',
    ]);
    $plan->proposals()->create([
        'component' => 'postgresql',
        'config_key' => 'work_mem',
        'current_value' => '4MB',
        'proposed_value' => '41MB',
        'severity' => 'high',
        'apply_method' => 'reload',
        'rationale' => 'per sort operation',
        'accepted' => true,
    ]);

    $this->post(route('optimization.apply', ['server' => $this->server, 'plan' => $plan]))
        ->assertRedirect();

    expect($plan->refresh()->status->value)->toBe('applied');
});

test('a plan cannot be applied through another server url', function () {
    SSH::fake('VITO_CONFIG_OK');

    $other = Server::factory()->create([
        'user_id' => $this->user->id,
        'project_id' => $this->user->current_project_id,
    ]);

    $plan = OptimizationPlan::query()->create([
        'server_id' => $other->id,
        'status' => 'draft',
        'source' => 'engine',
    ]);

    $this->post(route('optimization.apply', ['server' => $this->server, 'plan' => $plan]))
        ->assertNotFound();

    expect($plan->refresh()->status->value)->toBe('draft');
});

test('a user outside the project cannot apply a plan', function () {
    SSH::fake('VITO_CONFIG_OK');

    $plan = OptimizationPlan::query()->create([
        'server_id' => $this->server->id,
        'status' => 'draft',
        'source' => 'engine',
    ]);

    $this->actingAs(App\Models\User::factory()->create());

    $this->post(route('optimization.apply', ['server' => $this->server, 'plan' => $plan]))
        ->assertForbidden();

    expect($plan->refresh()->status->value)->toBe('draft');
});

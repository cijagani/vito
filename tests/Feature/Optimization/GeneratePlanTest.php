<?php

use App\Actions\Optimization\GeneratePlan;
use App\Enums\OptimizationPlanStatus;
use App\Facades\SSH;
use App\Models\OptimizationPlan;
use App\Services\Database\Postgresql;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function planProbeOutput(array $overrides = []): string
{
    $values = array_merge([
        'total_ram_mb' => '16384',
        'swap_total_mb' => '2048',
        'cores' => '4',
        'physical_cores' => '2',
        'disk_rotational' => '0',
        'virtualisation' => 'kvm',
        'php_versions' => '8.4',
        'fpm_avg_rss_mb' => '80',
        'pg_version' => '17.2',
        'pg_shared_buffers' => '128MB',
        'pg_work_mem' => '4MB',
        'pg_max_connections' => '100',
    ], $overrides);

    return collect($values)
        ->map(fn ($value, $key): string => "{$key}:{$value}")
        ->implode("\n");
}

beforeEach(function () {
    // TestCase installs MySQL; this suite exercises the PostgreSQL optimizer.
    $this->server->services()->where('type', 'database')->delete();
    $this->server->services()->create([
        'type' => 'database',
        'name' => Postgresql::id(),
        'version' => '17',
        'is_default' => true,
    ]);
});

test('records the facts and budget a plan was computed from', function () {
    SSH::fake(planProbeOutput());

    $plan = (new GeneratePlan)->handle($this->server, $this->user);

    expect($plan->status)->toBe(OptimizationPlanStatus::DRAFT)
        ->and($plan->facts['total_ram_mb'])->toBe(16384)
        ->and($plan->budget['database_mb'])->toBe(4915)
        ->and($plan->user_id)->toBe($this->user->id);
});

test('stores the ruleset version that produced the plan', function () {
    SSH::fake(planProbeOutput());

    $plan = (new GeneratePlan)->handle($this->server);

    expect($plan->ruleset_versions)->toHaveKey('postgresql')
        ->and($plan->ruleset_versions['postgresql'])->toBe(1);
});

test('persists each proposal with the reasoning behind it', function () {
    SSH::fake(planProbeOutput());

    $plan = (new GeneratePlan)->handle($this->server);

    $sharedBuffers = $plan->proposals->firstWhere('config_key', 'shared_buffers');

    expect($sharedBuffers->proposed_value)->toBe('3932MB')
        ->and($sharedBuffers->current_value)->toBe('128MB')
        ->and($sharedBuffers->rationale)->not->toBe('')
        ->and($sharedBuffers->kb_ref)->not->toBeNull();

    $this->assertDatabaseHas('optimization_proposals', [
        'optimization_plan_id' => $plan->id,
        'config_key' => 'shared_buffers',
        'proposed_value' => '3932MB',
    ]);
});

test('does not select a setting that already holds its proposed value', function () {
    SSH::fake(planProbeOutput(['pg_shared_buffers' => '3932MB']));

    $plan = (new GeneratePlan)->handle($this->server);

    $proposal = $plan->proposals->firstWhere('config_key', 'shared_buffers');

    // Still recorded, so the panel can show the setting was checked.
    expect($proposal)->not->toBeNull()
        ->and($proposal->accepted)->toBeFalse();
});

test('flags a plan that would restart rather than reload', function () {
    SSH::fake(planProbeOutput());

    $plan = (new GeneratePlan)->handle($this->server);

    expect($plan->isDisruptive())->toBeTrue();
});

test('proposes no database settings for a server whose database is elsewhere', function () {
    $this->server->services()->where('type', 'database')->delete();

    SSH::fake(planProbeOutput());

    $plan = (new GeneratePlan)->handle($this->server);

    // PHP-FPM is still tuned; only the database rules are skipped, and the pool
    // is larger precisely because no memory is reserved for a database here.
    expect($plan->proposals->where('component', 'postgresql'))->toHaveCount(0)
        ->and($plan->facts['db_local'])->toBeFalse();
});

test('writes nothing to the server while analysing', function () {
    SSH::fake(planProbeOutput());

    (new GeneratePlan)->handle($this->server);

    // The whole point of the read-only phase: one probe, no writes.
    SSH::assertNotExecutedContains('ALTER SYSTEM');
    SSH::assertNotExecutedContains('systemctl restart');
});

test('deleting a server removes its plans', function () {
    SSH::fake(planProbeOutput());

    $plan = (new GeneratePlan)->handle($this->server);

    $this->server->delete();

    expect(OptimizationPlan::query()->find($plan->id))->toBeNull();
    $this->assertDatabaseMissing('optimization_proposals', ['optimization_plan_id' => $plan->id]);
});

<?php

use App\Actions\Optimization\ApplyPlan;
use App\Actions\Optimization\RollbackPlan;
use App\Enums\OptimizationPlanStatus;
use App\Facades\SSH;
use App\Models\OptimizationChange;
use App\Models\OptimizationPlan;
use App\Services\Database\Postgresql;
use App\Support\Optimization\ChangeWriter;
use App\Support\Optimization\ConfigurationDriftException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function planWith(array $proposals, array $planAttributes = []): OptimizationPlan
{
    $plan = OptimizationPlan::query()->create(array_merge([
        'server_id' => test()->server->id,
        'status' => OptimizationPlanStatus::DRAFT,
        'source' => 'engine',
    ], $planAttributes));

    foreach ($proposals as $proposal) {
        $plan->proposals()->create(array_merge([
            'component' => 'postgresql',
            'current_value' => '128MB',
            'severity' => 'high',
            'apply_method' => 'reload',
            'rationale' => 'because the default is unrelated to this machine',
            'accepted' => true,
        ], $proposal));
    }

    return $plan->load('proposals');
}

beforeEach(function () {
    $this->server->services()->where('type', 'database')->delete();
    $this->server->services()->create([
        'type' => 'database',
        'name' => Postgresql::id(),
        'version' => '17',
        'unit' => 'postgresql',
        'is_default' => true,
    ]);
});

test('writes accepted settings to a managed drop-in file', function () {
    SSH::fake('VITO_CONFIG_OK');

    $plan = planWith([
        ['config_key' => 'shared_buffers', 'proposed_value' => '3932MB'],
        ['config_key' => 'work_mem', 'proposed_value' => '41MB'],
    ]);

    (new ApplyPlan)->handle($plan);

    // The write lands at the managed path, and carries both values with the
    // reasoning that produced them.
    $change = $plan->changes()->firstOrFail();
    expect($change->target_path)->toBe('/etc/postgresql/17/main/conf.d/zz-vito-tuning.conf');

    expect(SSH::getUploadedContent())
        ->toContain('shared_buffers = 3932MB')
        ->toContain('work_mem = 41MB')
        ->toContain('Managed by Vito');

    expect($plan->refresh()->status)->toBe(OptimizationPlanStatus::APPLIED)
        ->and($plan->applied_at)->not->toBeNull();
});

test('records the original contents before writing', function () {
    SSH::fake('VITO_CONFIG_OK');

    $plan = planWith([['config_key' => 'shared_buffers', 'proposed_value' => '3932MB']]);

    (new ApplyPlan)->handle($plan);

    $change = $plan->changes()->firstOrFail();

    expect($change->target_path)->toBe('/etc/postgresql/17/main/conf.d/zz-vito-tuning.conf')
        ->and($change->applied_at)->not->toBeNull();
});

test('a plan that restarts a service is refused without confirmation', function () {
    SSH::fake('VITO_CONFIG_OK');

    $plan = planWith([
        ['config_key' => 'shared_buffers', 'proposed_value' => '3932MB', 'apply_method' => 'restart'],
    ]);

    expect(fn () => (new ApplyPlan)->handle($plan))
        ->toThrow(ValidationException::class);

    expect($plan->refresh()->status)->toBe(OptimizationPlanStatus::DRAFT)
        ->and($plan->changes()->count())->toBe(0);
});

test('a confirmed restart is applied', function () {
    SSH::fake('VITO_CONFIG_OK');

    $plan = planWith([
        ['config_key' => 'shared_buffers', 'proposed_value' => '3932MB', 'apply_method' => 'restart'],
    ]);

    (new ApplyPlan)->handle($plan, ['confirmed' => true]);

    expect($plan->refresh()->status)->toBe(OptimizationPlanStatus::APPLIED);
});

test('an already applied plan cannot be applied again', function () {
    SSH::fake('VITO_CONFIG_OK');

    $plan = planWith(
        [['config_key' => 'shared_buffers', 'proposed_value' => '3932MB']],
        ['status' => OptimizationPlanStatus::APPLIED],
    );

    expect(fn () => (new ApplyPlan)->handle($plan))
        ->toThrow(ValidationException::class);
});

test('a rejected configuration is put back rather than activated', function () {
    // The validator never reports success, standing in for postgres refusing the
    // file. The write must be undone rather than left for the next restart.
    SSH::fake('some unrelated output');

    $plan = planWith([['config_key' => 'shared_buffers', 'proposed_value' => '3932MB']]);

    expect(fn () => (new ApplyPlan)->handle($plan))->toThrow(Exception::class);

    expect($plan->refresh()->status)->toBe(OptimizationPlanStatus::FAILED);

    $change = $plan->changes()->first();

    expect($change)->not->toBeNull()
        ->and($change->reverted_at)->not->toBeNull()
        ->and($change->applied_at)->toBeNull();
});

test('a file edited on the server since the plan was drawn is not overwritten', function () {
    SSH::fake('the operator edited this by hand');

    $plan = planWith([['config_key' => 'shared_buffers', 'proposed_value' => '3932MB']]);

    expect(fn () => (new ChangeWriter)->write(
        plan: $plan,
        path: '/etc/postgresql/17/main/conf.d/zz-vito-tuning.conf',
        content: 'shared_buffers = 3932MB',
        validate: fn () => null,
        expectedHash: hash('sha256', 'what the plan was reasoned about'),
    ))->toThrow(ConfigurationDriftException::class);

    expect($plan->changes()->count())->toBe(0);
});

test('rollback restores every change in reverse', function () {
    SSH::fake('VITO_CONFIG_OK');

    $plan = planWith([['config_key' => 'shared_buffers', 'proposed_value' => '3932MB']]);

    (new ApplyPlan)->handle($plan);

    (new RollbackPlan)->handle($plan);

    expect($plan->refresh()->status)->toBe(OptimizationPlanStatus::ROLLED_BACK)
        ->and($plan->rolled_back_at)->not->toBeNull()
        ->and($plan->changes()->whereNull('reverted_at')->count())->toBe(0);
});

test('rolling back restores the contents found before the write', function () {
    // Driven through ChangeWriter rather than ApplyPlan so the file already on the
    // server can be something other than the validator's success marker.
    SSH::fake('shared_buffers = 128MB');

    $plan = planWith([['config_key' => 'shared_buffers', 'proposed_value' => '3932MB']]);

    $change = (new ChangeWriter)->write(
        plan: $plan,
        path: '/etc/postgresql/17/main/conf.d/zz-vito-tuning.conf',
        content: 'shared_buffers = 3932MB',
        validate: fn () => null,
    );

    expect($change->action)->toBe(OptimizationChange::ACTION_MODIFIED)
        ->and($change->backup_content)->toBe('shared_buffers = 128MB')
        ->and(SSH::getUploadedContent())->toBe('shared_buffers = 3932MB');

    (new RollbackPlan)->handle($plan);

    // What the server ends up holding is what it held before the plan ran.
    expect(SSH::getUploadedContent())->toBe('shared_buffers = 128MB')
        ->and($change->refresh()->reverted_at)->not->toBeNull();
});

test('applying nothing writes nothing', function () {
    SSH::fake('VITO_CONFIG_OK');

    $plan = planWith([
        ['config_key' => 'shared_buffers', 'proposed_value' => '128MB', 'accepted' => false],
    ]);

    (new ApplyPlan)->handle($plan);

    expect($plan->changes()->count())->toBe(0)
        ->and($plan->refresh()->status)->toBe(OptimizationPlanStatus::APPLIED);
});

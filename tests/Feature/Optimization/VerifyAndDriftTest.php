<?php

use App\Actions\Optimization\ApplyPlan;
use App\Actions\Optimization\DetectDrift;
use App\Actions\Optimization\VerifyPlan;
use App\DTOs\VerificationResult;
use App\Facades\SSH;
use App\Models\OptimizationPlan;
use App\Services\Database\Postgresql;
use App\Support\Optimization\ChangeWriter;
use App\Support\Optimization\ConfigurationDriftException;
// ChangeWriter::hash is used directly so the test asserts the same normalisation
// the writer applies, rather than a raw hash that would drift from it.
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function verifyPlanWith(array $proposals): OptimizationPlan
{
    $plan = OptimizationPlan::query()->create([
        'server_id' => test()->server->id,
        'status' => 'draft',
        'source' => 'engine',
    ]);

    foreach ($proposals as $proposal) {
        $plan->proposals()->create(array_merge([
            'component' => 'postgresql',
            'current_value' => '128MB',
            'severity' => 'high',
            'apply_method' => 'reload',
            'rationale' => 'derived from this machine',
            'accepted' => true,
        ], $proposal));
    }

    return $plan->load('proposals');
}

function verifyProbe(array $overrides = []): string
{
    return collect(array_merge([
        'total_ram_mb' => '16384',
        'cores' => '4',
        'php_versions' => '8.4',
        'pg_version' => '17.2',
        'pg_shared_buffers' => '3932MB',
        'pg_work_mem' => '41MB',
    ], $overrides))->map(fn ($v, $k): string => "{$k}:{$v}")->implode("\n");
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

test('a setting the server now reports passes verification', function () {
    SSH::fake(verifyProbe());

    $plan = verifyPlanWith([['config_key' => 'shared_buffers', 'proposed_value' => '3932MB']]);

    $results = (new VerifyPlan)->handle($plan);

    expect($results[0]->status)->toBe(VerificationResult::PASS)
        ->and($results[0]->actual)->toBe('3932MB');
});

test('a setting the server did not adopt fails verification', function () {
    // The write succeeded and the config parsed, but the running server still
    // reports the old value -- which is exactly what verification exists to catch.
    SSH::fake(verifyProbe(['pg_shared_buffers' => '128MB']));

    $plan = verifyPlanWith([['config_key' => 'shared_buffers', 'proposed_value' => '3932MB']]);

    $results = (new VerifyPlan)->handle($plan);

    expect($results[0]->status)->toBe(VerificationResult::FAIL)
        ->and($results[0]->actual)->toBe('128MB')
        ->and($results[0]->note)->toContain('did not take effect');
});

test('verification compares meaning rather than spelling', function () {
    // 4GB and 4096MB are the same setting written two ways.
    SSH::fake(verifyProbe(['pg_shared_buffers' => '4GB']));

    $plan = verifyPlanWith([['config_key' => 'shared_buffers', 'proposed_value' => '4096MB']]);

    expect((new VerifyPlan)->handle($plan)[0]->status)->toBe(VerificationResult::PASS);
});

test('a setting the probe does not read is reported as unknown', function () {
    SSH::fake(verifyProbe());

    $plan = verifyPlanWith([['config_key' => 'wal_buffers', 'proposed_value' => '64MB']]);

    $result = (new VerifyPlan)->handle($plan)[0];

    // Better than claiming a pass nobody checked.
    expect($result->status)->toBe(VerificationResult::UNKNOWN)
        ->and($result->actual)->toBeNull();
});

test('per-pool settings are not compared against a per-version value', function () {
    SSH::fake(verifyProbe());

    $plan = verifyPlanWith([[
        'component' => 'php-fpm',
        'config_key' => 'vito.test · pm.max_children',
        'proposed_value' => '27',
    ]]);

    expect((new VerifyPlan)->handle($plan)[0]->status)->toBe(VerificationResult::UNKNOWN);
});

test('applying a plan records what the server reports afterwards', function () {
    SSH::fake('VITO_CONFIG_OK');

    $plan = verifyPlanWith([['config_key' => 'shared_buffers', 'proposed_value' => '3932MB']]);

    (new ApplyPlan)->handle($plan);

    expect($plan->refresh()->verification)->toBeArray()
        ->and($plan->verification[0]['config_key'])->toBe('shared_buffers');
});

test('a file untouched since vito wrote it has not drifted', function () {
    $content = "shared_buffers = 3932MB\n";
    SSH::fake($content);

    $plan = verifyPlanWith([['config_key' => 'shared_buffers', 'proposed_value' => '3932MB']]);

    $change = (new ChangeWriter)->write(
        plan: $plan,
        path: '/etc/postgresql/17/main/conf.d/zz-vito-tuning.conf',
        content: $content,
        validate: fn () => null,
    );

    expect($change->applied_hash)->toBe(ChangeWriter::hash($content));

    $drift = (new DetectDrift)->handle($this->server);

    expect($drift)->toHaveCount(1)
        ->and($drift[0]['drifted'])->toBeFalse();
});

test('a file edited on the server is reported as drifted', function () {
    SSH::fake("shared_buffers = 3932MB\n");

    $plan = verifyPlanWith([['config_key' => 'shared_buffers', 'proposed_value' => '3932MB']]);

    (new ChangeWriter)->write(
        plan: $plan,
        path: '/etc/postgresql/17/main/conf.d/zz-vito-tuning.conf',
        content: "shared_buffers = 3932MB\n",
        validate: fn () => null,
    );

    // Somebody edited the file afterwards.
    SSH::fake("shared_buffers = 8GB # tuned by hand at 3am\n");

    expect((new DetectDrift)->handle($this->server)[0]['drifted'])->toBeTrue();
});

test('a second write refuses to discard an edit made on the server', function () {
    SSH::fake("shared_buffers = 3932MB\n");

    $plan = verifyPlanWith([['config_key' => 'shared_buffers', 'proposed_value' => '3932MB']]);

    (new ChangeWriter)->write(
        plan: $plan,
        path: '/etc/postgresql/17/main/conf.d/zz-vito-tuning.conf',
        content: "shared_buffers = 3932MB\n",
        validate: fn () => null,
    );

    SSH::fake("shared_buffers = 8GB # tuned by hand\n");

    // The check applies without any caller having to remember to ask for it.
    expect(fn () => (new ChangeWriter)->write(
        plan: $plan,
        path: '/etc/postgresql/17/main/conf.d/zz-vito-tuning.conf',
        content: "shared_buffers = 2048MB\n",
        validate: fn () => null,
    ))->toThrow(ConfigurationDriftException::class, 'edited on the server');
});

test('a first write is not blocked by a file vito has never seen', function () {
    SSH::fake("# packaged default\n");

    $plan = verifyPlanWith([['config_key' => 'shared_buffers', 'proposed_value' => '3932MB']]);

    $change = (new ChangeWriter)->write(
        plan: $plan,
        path: '/etc/postgresql/17/main/conf.d/zz-vito-tuning.conf',
        content: "shared_buffers = 3932MB\n",
        validate: fn () => null,
    );

    expect($change->applied_at)->not->toBeNull();
});

test('a reverted change is no longer watched for drift', function () {
    SSH::fake("shared_buffers = 3932MB\n");

    $plan = verifyPlanWith([['config_key' => 'shared_buffers', 'proposed_value' => '3932MB']]);

    $change = (new ChangeWriter)->write(
        plan: $plan,
        path: '/etc/postgresql/17/main/conf.d/zz-vito-tuning.conf',
        content: "shared_buffers = 3932MB\n",
        validate: fn () => null,
    );

    (new ChangeWriter)->restore($change);

    expect((new DetectDrift)->handle($this->server))->toBe([]);
});

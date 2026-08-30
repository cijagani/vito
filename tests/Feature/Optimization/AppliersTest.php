<?php

use App\Facades\SSH;
use App\Models\OptimizationPlan;
use App\Optimizers\OS\KernelApplier;
use App\Optimizers\PHP\FpmApplier;
use App\Optimizers\Webserver\NginxApplier;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// The SSH fake returns one canned string for every command, so fixtures that
// stand in for an existing config file also carry the validator's success
// marker -- otherwise reading the file and checking the result cannot both be
// satisfied in the same test.

function applierPlan(array $proposals): OptimizationPlan
{
    $plan = OptimizationPlan::query()->create([
        'server_id' => test()->server->id,
        'status' => 'draft',
        'source' => 'engine',
    ]);

    foreach ($proposals as $proposal) {
        $plan->proposals()->create(array_merge([
            'current_value' => null,
            'severity' => 'medium',
            'apply_method' => 'reload',
            'rationale' => 'derived from this machine',
            'accepted' => true,
        ], $proposal));
    }

    return $plan->load('proposals');
}

test('kernel settings are written where they survive a reboot', function () {
    SSH::fake('VITO_CONFIG_OK');

    $plan = applierPlan([
        ['component' => 'kernel', 'config_key' => 'vm.swappiness', 'proposed_value' => '10'],
    ]);

    (new KernelApplier)->apply($plan, $plan->proposals);

    expect($plan->changes()->first()->target_path)->toBe('/etc/sysctl.d/60-vito-tuning.conf')
        ->and(SSH::getUploadedContent())->toContain('vm.swappiness = 10');
});

test('opcache settings go into a php ini drop-in', function () {
    SSH::fake('VITO_CONFIG_OK');

    $plan = applierPlan([
        ['component' => 'php-fpm', 'config_key' => 'opcache.memory_consumption', 'proposed_value' => '512'],
    ]);

    (new FpmApplier)->apply($plan, $plan->proposals);

    $paths = $plan->changes()->pluck('target_path')->all();

    // TestCase installs PHP 8.2.
    expect($paths)->toContain('/etc/php/8.2/fpm/conf.d/zz-vito-tuning.ini')
        ->and(SSH::getUploadedContent())->toContain('opcache.memory_consumption = 512');
});

test('pool sizing replaces the existing lines in the pool file', function () {
    SSH::fake("[vito]\nuser = vito\npm = dynamic\npm.max_children = 5\npm.max_requests = 500\n; VITO_CONFIG_OK\n");

    $plan = applierPlan([
        [
            'component' => 'php-fpm',
            'config_key' => 'vito.test · pm.max_children',
            'proposed_value' => '27',
        ],
    ]);

    (new FpmApplier)->apply($plan, $plan->proposals);

    $written = SSH::getUploadedContent();

    expect($written)
        ->toContain('pm.max_children = 27')
        ->not->toContain('pm.max_children = 5')
        // The socket, the user and the jail are left exactly as they were.
        ->toContain('user = vito')
        ->toContain('pm.max_requests = 500');
});

test('a pool for a site that is not on this server is skipped', function () {
    SSH::fake("[vito]\npm.max_children = 5\n; VITO_CONFIG_OK\n");

    $plan = applierPlan([
        [
            'component' => 'php-fpm',
            'config_key' => 'somewhere-else.test · pm.max_children',
            'proposed_value' => '27',
        ],
    ]);

    (new FpmApplier)->apply($plan, $plan->proposals);

    expect($plan->changes()->count())->toBe(0);
});

test('nginx changes a directive where it already lives', function () {
    // The fake answers every command with this, so it stands in for both the
    // located file and the file's contents. The locate step reports nginx.conf.
    SSH::fake("keepalive_timeout:/etc/nginx/nginx.conf\nkeepalive_timeout 65;\nVITO_CONFIG_OK\n");

    $plan = applierPlan([
        ['component' => 'nginx', 'config_key' => 'keepalive_timeout', 'proposed_value' => '30'],
    ]);

    (new NginxApplier)->apply($plan, $plan->proposals);

    // Rewritten in the file that already declared it, not restated in a drop-in.
    // Restating it is what nginx rejects as a duplicate directive.
    expect($plan->changes()->first()?->target_path)->toBe('/etc/nginx/nginx.conf')
        ->and(SSH::getUploadedContent())->toContain('keepalive_timeout 30;')
        ->and(SSH::getUploadedContent())->not->toContain('keepalive_timeout 65;');
});

test('nginx adds a directive that exists nowhere to its own file', function () {
    SSH::fake("client_max_body_size:none\nVITO_CONFIG_OK\n");

    $plan = applierPlan([
        ['component' => 'nginx', 'config_key' => 'client_max_body_size', 'proposed_value' => '64m'],
    ]);

    (new NginxApplier)->apply($plan, $plan->proposals);

    expect($plan->changes()->first()?->target_path)->toBe('/etc/nginx/conf.d/zz-vito-tuning.conf')
        ->and(SSH::getUploadedContent())->toContain('client_max_body_size 64m;');
});

test('a worker directive that exists nowhere is not added to the drop-in', function () {
    // conf.d is included from inside http, so worker_processes there would stop
    // nginx starting -- a configuration that fails to load is worse than a
    // default one.
    SSH::fake("worker_processes:none\nVITO_CONFIG_OK\n");

    $plan = applierPlan([
        ['component' => 'nginx', 'config_key' => 'worker_processes', 'proposed_value' => '4'],
    ]);

    (new NginxApplier)->apply($plan, $plan->proposals);

    expect($plan->changes()->count())->toBe(0);
});

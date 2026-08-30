<?php

use App\Actions\Optimization\Probe;
use App\Facades\SSH;
use App\Models\Service;
use App\Services\Database\Postgresql;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function probeOutput(array $overrides = []): string
{
    $values = array_merge([
        'total_ram_mb' => '16384',
        'swap_total_mb' => '2048',
        'cores' => '4',
        'physical_cores' => '2',
        'disk_rotational' => '0',
        'virtualisation' => 'kvm',
        'nofile_limit' => '65535',
        'php_versions' => '8.3,8.4',
        'fpm_avg_rss_mb' => '96',
        'fpm_active_children' => '12',
    ], $overrides);

    return collect($values)
        ->map(fn ($value, $key): string => "{$key}:{$value}")
        ->implode("\n");
}

test('reads hardware facts from the probe output', function () {
    SSH::fake(probeOutput());

    $facts = (new Probe)->handle($this->server);

    expect($facts->totalRamMb)->toBe(16384)
        ->and($facts->cores)->toBe(4)
        ->and($facts->physicalCores)->toBe(2)
        ->and($facts->swapTotalMb)->toBe(2048)
        ->and($facts->phpVersions)->toBe(['8.3', '8.4'])
        ->and($facts->avgWorkerRssMb)->toBe(96);
});

test('detects flash storage from the rotational flag', function () {
    SSH::fake(probeOutput(['disk_rotational' => '0']));
    expect((new Probe)->handle($this->server)->diskRotational)->toBeFalse();

    SSH::fake(probeOutput(['disk_rotational' => '1']));
    expect((new Probe)->handle($this->server)->diskRotational)->toBeTrue();
});

test('reports bare metal as no virtualisation', function () {
    SSH::fake(probeOutput(['virtualisation' => 'none']));

    expect((new Probe)->handle($this->server)->virtualisation)->toBeNull();
});

test('recognises a container so sysctl phases can skip', function () {
    SSH::fake(probeOutput(['virtualisation' => 'lxc']));

    expect((new Probe)->handle($this->server)->isContainer())->toBeTrue();
});

test('an installed database service means the database is local', function () {
    // TestCase already installs MySQL on the test server.
    SSH::fake(probeOutput());

    expect((new Probe)->handle($this->server)->dbLocal)->toBeTrue();
});

test('no database service means the application talks to a remote database', function () {
    $this->server->services()->where('type', 'database')->delete();

    SSH::fake(probeOutput());

    expect((new Probe)->handle($this->server)->dbLocal)->toBeFalse();
});

test('an unmeasured worker footprint is left null for the budget to default', function () {
    SSH::fake(probeOutput(['fpm_avg_rss_mb' => '']));

    expect((new Probe)->handle($this->server)->avgWorkerRssMb)->toBeNull();
});

test('ignores lines that are not key value pairs', function () {
    SSH::fake("sudo: unable to resolve host\n".probeOutput());

    expect((new Probe)->handle($this->server)->totalRamMb)->toBe(16384);
});

test('queries postgres only when postgres is the installed database', function () {
    Service::query()->where('server_id', $this->server->id)->where('type', 'database')->delete();
    $this->server->services()->create([
        'type' => 'database',
        'name' => Postgresql::id(),
        'version' => '17',
        'is_default' => true,
    ]);

    SSH::fake(probeOutput());

    (new Probe)->handle($this->server);

    SSH::assertExecutedContains('SHOW shared_buffers');
    SSH::assertNotExecutedContains('innodb_buffer_pool_size');
});

test('does not query postgres when the server runs mysql', function () {
    // TestCase installs MySQL by default.
    SSH::fake(probeOutput());

    (new Probe)->handle($this->server);

    SSH::assertNotExecutedContains('SHOW shared_buffers');
});

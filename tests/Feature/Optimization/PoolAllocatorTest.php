<?php

use App\Enums\SiteLoadClass;
use App\Models\Site;
use App\Support\Optimization\PoolAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function siteWithClass(SiteLoadClass $class, string $domain, ?string $php = '8.4'): Site
{
    return Site::factory()->create([
        'server_id' => test()->server->id,
        'domain' => $domain,
        'php_version' => $php,
        'load_class' => $class,
    ]);
}

function allocationsFor(array $sites, int $poolMb): array
{
    $result = (new PoolAllocator)->allocate($sites, $poolMb);

    return collect($result)->keyBy(fn (array $row): string => $row['site']->domain)->all();
}

test('a busy site receives more of the pool than a quiet one', function () {
    $allocations = allocationsFor([
        siteWithClass(SiteLoadClass::HIGH, 'api.test'),
        siteWithClass(SiteLoadClass::MEDIUM, 'app.test'),
        siteWithClass(SiteLoadClass::LOW, 'blog.test'),
    ], 4096);

    expect($allocations['api.test']['max_children'])
        ->toBeGreaterThan($allocations['app.test']['max_children'])
        ->and($allocations['app.test']['max_children'])
        ->toBeGreaterThan($allocations['blog.test']['max_children']);
});

test('sites of the same class receive the same share', function () {
    $allocations = allocationsFor([
        siteWithClass(SiteLoadClass::MEDIUM, 'one.test'),
        siteWithClass(SiteLoadClass::MEDIUM, 'two.test'),
    ], 4096);

    expect($allocations['one.test']['max_children'])->toBe($allocations['two.test']['max_children']);
});

test('the allocated pool never exceeds what is available', function () {
    $sites = [
        siteWithClass(SiteLoadClass::HIGH, 'api.test'),
        siteWithClass(SiteLoadClass::MEDIUM, 'app.test'),
        siteWithClass(SiteLoadClass::LOW, 'blog.test'),
        siteWithClass(SiteLoadClass::LOW, 'docs.test'),
    ];

    $allocated = collect((new PoolAllocator)->allocate($sites, 4096))->sum('pool_mb');

    expect($allocated)->toBeLessThanOrEqual(4096);
});

test('a quiet site keeps a usable floor beside a busy one', function () {
    // Without funding floors first, one high-traffic site rounds its neighbours
    // down to nothing.
    $allocations = allocationsFor([
        siteWithClass(SiteLoadClass::HIGH, 'api.test'),
        siteWithClass(SiteLoadClass::LOW, 'blog.test'),
    ], 1024);

    expect($allocations['blog.test']['max_children'])->toBeGreaterThanOrEqual(2);
});

test('a site with no php version takes no share of the pool', function () {
    $allocations = allocationsFor([
        siteWithClass(SiteLoadClass::MEDIUM, 'app.test'),
        siteWithClass(SiteLoadClass::MEDIUM, 'static.test', null),
    ], 4096);

    expect($allocations)->toHaveKey('app.test')
        ->and($allocations)->not->toHaveKey('static.test');
});

test('a server with no php sites allocates nothing', function () {
    expect((new PoolAllocator)->allocate([], 4096))->toBe([]);
});

test('sites default to the medium class', function () {
    $site = Site::factory()->create([
        'server_id' => $this->server->id,
        'domain' => 'default.test',
        'php_version' => '8.4',
    ]);

    expect($site->load_class)->toBe(SiteLoadClass::MEDIUM);
});

test('memory limit and max children come from one division', function () {
    $allocations = allocationsFor([siteWithClass(SiteLoadClass::HIGH, 'api.test')], 4096);

    $row = $allocations['api.test'];

    // The worst case at full concurrency must fit the share it was given.
    expect($row['max_children'] * $row['memory_limit_mb'])->toBeLessThanOrEqual($row['pool_mb']);
});

test('a worker is never permitted less than a real request needs', function () {
    $allocations = allocationsFor([siteWithClass(SiteLoadClass::LOW, 'tiny.test')], 512);

    expect($allocations['tiny.test']['memory_limit_mb'])->toBe(128);
});

test('a worker is never permitted to starve the machine alone', function () {
    $allocations = allocationsFor([siteWithClass(SiteLoadClass::HIGH, 'huge.test')], 65536);

    expect($allocations['huge.test']['memory_limit_mb'])->toBe(512);
});

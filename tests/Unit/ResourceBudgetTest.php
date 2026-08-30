<?php

use App\DTOs\ServerFacts;
use App\Support\Optimization\ResourceBudget;

function budgetFor(array $overrides = []): App\DTOs\Budget
{
    $facts = new ServerFacts(
        totalRamMb: $overrides['ram'] ?? 8192,
        cores: $overrides['cores'] ?? 4,
        dbLocal: $overrides['db_local'] ?? false,
        redisLocal: $overrides['redis_local'] ?? false,
        hasWorkers: $overrides['workers'] ?? false,
        phpVersions: $overrides['php'] ?? ['8.4'],
        avgWorkerRssMb: $overrides['rss'] ?? 80,
    );

    return (new ResourceBudget)->compute($facts);
}

test('remote database reserves nothing for the database', function () {
    $budget = budgetFor(['ram' => 16384, 'db_local' => false]);

    expect($budget->databaseMb)->toBe(0);
});

test('local database reserves thirty percent', function () {
    $budget = budgetFor(['ram' => 16384, 'db_local' => true]);

    expect($budget->databaseMb)->toBe(4915);
});

test('a remote database hands its slice to the fpm pool', function () {
    $local = budgetFor(['ram' => 16384, 'db_local' => true]);
    $remote = budgetFor(['ram' => 16384, 'db_local' => false]);

    expect($remote->fpmPoolMb)->toBeGreaterThan($local->fpmPoolMb)
        ->and($remote->fpmPoolMb - $local->fpmPoolMb)->toBe($local->databaseMb)
        ->and($remote->maxWorkers)->toBeGreaterThan($local->maxWorkers);
});

test('os reserve is five percent but never below its floor', function () {
    expect(budgetFor(['ram' => 16384])->osMb)->toBe(819)
        ->and(budgetFor(['ram' => 2048])->osMb)->toBe(512);
});

test('redis reserve applies only when redis is local', function () {
    expect(budgetFor(['ram' => 8192, 'redis_local' => true])->redisMb)->toBe(1638)
        ->and(budgetFor(['ram' => 8192, 'redis_local' => false])->redisMb)->toBe(0);
});

test('worker reserve applies only when long lived processes run here', function () {
    expect(budgetFor(['ram' => 8192, 'workers' => true])->workersMb)->toBe(983)
        ->and(budgetFor(['ram' => 8192, 'workers' => false])->workersMb)->toBe(0);
});

test('opcache is reserved once per installed php version', function () {
    $one = budgetFor(['ram' => 8192, 'php' => ['8.4']]);
    $two = budgetFor(['ram' => 8192, 'php' => ['8.3', '8.4']]);

    expect($one->opcacheMb)->toBe(768)
        ->and($two->opcacheMb)->toBe(1536)
        ->and($two->fpmPoolMb)->toBe($one->fpmPoolMb - 768);
});

test('small boxes get a smaller opcache allocation', function () {
    $small = budgetFor(['ram' => 2048]);

    expect($small->opcacheShmMb)->toBe(256)
        ->and($small->opcacheJitMb)->toBe(64);
});

test('max workers derives from the measured per worker footprint', function () {
    $measured = budgetFor(['ram' => 8192, 'rss' => 120]);
    $default = budgetFor(['ram' => 8192, 'rss' => 80]);

    expect($default->maxWorkers)->toBe(intdiv($default->fpmPoolMb, 80))
        ->and($measured->maxWorkers)->toBe(intdiv($measured->fpmPoolMb, 120))
        ->and($measured->maxWorkers)->toBeLessThan($default->maxWorkers);
});

test('an unmeasured worker footprint falls back to a conservative default', function () {
    $facts = new ServerFacts(totalRamMb: 8192, cores: 4, avgWorkerRssMb: null);

    expect((new ResourceBudget)->compute($facts)->workerRssMb)->toBe(80);
});

test('the fpm pool never goes below its floor on a heavily reserved box', function () {
    $budget = budgetFor([
        'ram' => 1024,
        'db_local' => true,
        'redis_local' => true,
        'workers' => true,
        'php' => ['8.3', '8.4'],
    ]);

    expect($budget->fpmPoolMb)->toBe(256)
        ->and($budget->maxWorkers)->toBeGreaterThanOrEqual(2);
});

test('every reserve plus the pool accounts for the whole machine', function () {
    $budget = budgetFor(['ram' => 16384, 'db_local' => true, 'redis_local' => true, 'workers' => true]);

    expect($budget->reservedMb() + $budget->fpmPoolMb)->toBeLessThanOrEqual(16384);
});

test('the documented sixteen gigabyte reference box', function () {
    // The worked example carried in OPTIMIZATION_PLAN.md — if this changes, the
    // plan's numbers are stale.
    $budget = budgetFor([
        'ram' => 16384,
        'cores' => 4,
        'db_local' => true,
        'redis_local' => true,
        'php' => ['8.4'],
        'rss' => 80,
    ]);

    expect($budget->osMb)->toBe(819)
        ->and($budget->databaseMb)->toBe(4915)
        ->and($budget->redisMb)->toBe(3276)
        ->and($budget->opcacheMb)->toBe(768);
});

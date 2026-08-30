<?php

use App\DTOs\ServerFacts;
use App\Enums\ApplyMethod;
use App\Optimizers\Database\MysqlOptimizer;
use App\Support\Optimization\FormulaEvaluator;
use App\Support\Optimization\ResourceBudget;
use App\Support\Optimization\RulesetLoader;

function mysqlOptimizer(): MysqlOptimizer
{
    return new MysqlOptimizer(
        new RulesetLoader(__DIR__.'/../../resources/optimization/rules'),
        new FormulaEvaluator,
    );
}

function mysqlProposals(array $factOverrides = [], array $probeOverrides = []): array
{
    $facts = new ServerFacts(
        totalRamMb: $factOverrides['ram'] ?? 16384,
        cores: $factOverrides['cores'] ?? 4,
        diskRotational: $factOverrides['rotational'] ?? false,
        dbLocal: $factOverrides['db_local'] ?? true,
    );

    $probe = array_merge([
        'mysql_version' => '8.4.3',
        'mysql_innodb_buffer_pool_size' => '134217728',
        'mysql_max_connections' => '151',
        'disk_rotational' => '0',
    ], $probeOverrides);

    $proposals = mysqlOptimizer()->propose($facts, (new ResourceBudget)->compute($facts), $probe);

    return collect($proposals)->keyBy->configKey->all();
}

test('sizes the buffer pool from the database slice of the budget', function () {
    // 16GB box, database local -> 4915MB reserve, 80% of which is the pool.
    $proposal = mysqlProposals()['innodb_buffer_pool_size'];

    expect($proposal->proposedValue)->toBe('3932M')
        ->and($proposal->applyMethod)->toBe(ApplyMethod::RESTART);
});

test('caps the buffer pool below its ceiling share of memory', function () {
    foreach ([1024, 4096, 16384, 65536] as $ram) {
        $value = (int) mysqlProposals(['ram' => $ram])['innodb_buffer_pool_size']->proposedValue;

        expect($value)->toBeLessThanOrEqual((int) ($ram * 0.40));
    }
});

test('stops innodb double-caching against the page cache', function () {
    expect(mysqlProposals()['innodb_flush_method']->proposedValue)->toBe('O_DIRECT');
});

test('picks io capacity from the storage the server actually has', function () {
    $flash = mysqlProposals([], ['disk_rotational' => '0']);
    $spinning = mysqlProposals(['rotational' => true], ['disk_rotational' => '1']);

    expect($flash['innodb_io_capacity']->proposedValue)->toBe('2000')
        ->and($spinning['innodb_io_capacity']->proposedValue)->toBe('200');
});

test('raises the connection ceiling on a machine with more cores', function () {
    expect(mysqlProposals(['cores' => 4])['max_connections']->proposedValue)->toBe('150')
        ->and(mysqlProposals(['cores' => 16])['max_connections']->proposedValue)->toBe('300');
});

test('standard mysql is not offered a thread pool it does not have', function () {
    // Proposing thread_handling to MySQL produces a setting it refuses to start
    // with, so the rule is filtered out by variant rather than merely ignored.
    $proposals = mysqlProposals([], ['mysql_version' => '8.4.3']);

    expect($proposals)->not->toHaveKey('thread_handling')
        ->and($proposals)->not->toHaveKey('thread_pool_size');
});

test('mariadb is offered its thread pool', function () {
    $proposals = mysqlProposals([], ['mysql_version' => '10.11.6-MariaDB-1:10.11.6+maria~ubu2204']);

    expect($proposals['thread_handling']->proposedValue)->toBe('pool-of-threads')
        ->and($proposals['thread_pool_size']->proposedValue)->toBe('4');
});

test('the thread pool is bounded by cores', function () {
    $proposals = mysqlProposals(
        ['cores' => 32],
        ['mysql_version' => '11.4.2-MariaDB'],
    );

    // More threads than cores adds context switching without throughput.
    expect($proposals['thread_pool_size']->proposedValue)->toBe('8');
});

test('the slow query log is turned on', function () {
    expect(mysqlProposals()['slow_query_log']->proposedValue)->toBe('ON')
        ->and(mysqlProposals()['long_query_time']->proposedValue)->toBe('2');
});

test('reverse dns lookups on connect are turned off', function () {
    expect(mysqlProposals()['skip_name_resolve']->proposedValue)->toBe('ON');
});

test('proposes nothing when the database runs on another machine', function () {
    expect(mysqlProposals(['db_local' => false]))->toBe([]);
});

test('proposes nothing when the database did not answer the probe', function () {
    $facts = new ServerFacts(totalRamMb: 16384, cores: 4, dbLocal: true);

    expect(mysqlOptimizer()->propose(
        $facts,
        (new ResourceBudget)->compute($facts),
        ['mysql_version' => '8.4.3'],
    ))->toBe([]);
});

test('every proposal carries its rationale and reference', function () {
    foreach (mysqlProposals() as $proposal) {
        expect($proposal->rationale)->not->toBe('')
            ->and($proposal->kbRef)->not->toBeNull();
    }
});

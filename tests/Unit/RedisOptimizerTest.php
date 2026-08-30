<?php

use App\DTOs\ServerFacts;
use App\Optimizers\Redis\RedisOptimizer;
use App\Support\Optimization\FormulaEvaluator;
use App\Support\Optimization\ResourceBudget;
use App\Support\Optimization\RulesetLoader;

function redisOptimizer(): RedisOptimizer
{
    return new RedisOptimizer(
        new RulesetLoader(__DIR__.'/../../resources/optimization/rules'),
        new FormulaEvaluator,
    );
}

function redisProposals(bool $hasWorkers, array $probeOverrides = []): array
{
    $facts = new ServerFacts(
        totalRamMb: 8192,
        cores: 4,
        redisLocal: true,
        hasWorkers: $hasWorkers,
        phpVersions: ['8.4'],
    );

    $probe = array_merge([
        'redis_version' => '7.2.4',
        'redis_maxmemory' => '0',
        'redis_maxmemory_policy' => 'noeviction',
    ], $probeOverrides);

    $proposals = redisOptimizer()->propose($facts, (new ResourceBudget)->compute($facts), $probe);

    return collect($proposals)->keyBy->configKey->all();
}

test('sizes the memory ceiling from the budget', function () {
    // 8GB box with Redis local -> 20% reserve.
    expect(redisProposals(hasWorkers: false)['maxmemory']->proposedValue)->toBe('1638mb');
});

test('a cache-only server evicts least recently used keys', function () {
    expect(redisProposals(hasWorkers: false)['maxmemory-policy']->proposedValue)->toBe('allkeys-lru');
});

test('a server running queue workers is never given an eviction policy', function () {
    // The guardrail that matters: eviction on an instance holding queued jobs
    // discards accepted work with nothing reporting the loss.
    $proposal = redisProposals(hasWorkers: true)['maxmemory-policy'];

    expect($proposal->proposedValue)->toBe('noeviction')
        ->and($proposal->rationale)->toContain('queue');
});

test('the memory ceiling is still proposed when queues are present', function () {
    // Only eviction is unsafe. An unbounded Redis is unsafe in a different way.
    expect(redisProposals(hasWorkers: true)['maxmemory']->proposedValue)->toBe('1638mb');
});

test('io threads scale with cores but stop at a useful ceiling', function () {
    $facts = new ServerFacts(totalRamMb: 8192, cores: 32, redisLocal: true);

    $proposals = collect(redisOptimizer()->propose(
        $facts,
        (new ResourceBudget)->compute($facts),
        ['redis_version' => '7.2.4', 'redis_maxmemory' => '0'],
    ))->keyBy->configKey;

    // Command execution stays single-threaded, so more than a few buys nothing.
    expect($proposals['io-threads']->proposedValue)->toBe('4');
});

test('io threads are not proposed for a version that lacks them', function () {
    $proposals = redisProposals(hasWorkers: false, probeOverrides: ['redis_version' => '5.0.14']);

    expect($proposals)->not->toHaveKey('io-threads');
});

test('proposes nothing when redis runs elsewhere', function () {
    $facts = new ServerFacts(totalRamMb: 8192, cores: 4, redisLocal: false);

    expect(redisOptimizer()->propose(
        $facts,
        (new ResourceBudget)->compute($facts),
        ['redis_maxmemory' => '0'],
    ))->toBe([]);
});

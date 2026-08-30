<?php

use App\DTOs\ServerFacts;
use App\Enums\ApplyMethod;
use App\Enums\ProposalSeverity;
use App\Optimizers\Database\PostgresOptimizer;
use App\Support\Optimization\FormulaEvaluator;
use App\Support\Optimization\ResourceBudget;
use App\Support\Optimization\RulesetLoader;

function pgOptimizer(): PostgresOptimizer
{
    return new PostgresOptimizer(
        new RulesetLoader(__DIR__.'/../../resources/optimization/rules'),
        new FormulaEvaluator,
    );
}

function pgFacts(array $overrides = []): ServerFacts
{
    return new ServerFacts(
        totalRamMb: $overrides['ram'] ?? 16384,
        cores: $overrides['cores'] ?? 4,
        diskRotational: $overrides['rotational'] ?? false,
        dbLocal: $overrides['db_local'] ?? true,
        phpVersions: ['8.4'],
        avgWorkerRssMb: 80,
    );
}

function pgProbe(array $overrides = []): array
{
    return array_merge([
        'pg_version' => '17.2',
        'pg_shared_buffers' => '128MB',
        'pg_work_mem' => '4MB',
        'pg_max_connections' => '100',
        'disk_rotational' => '0',
    ], $overrides);
}

function pgProposals(array $factOverrides = [], array $probeOverrides = []): array
{
    $facts = pgFacts($factOverrides);
    $budget = (new ResourceBudget)->compute($facts);

    $proposals = pgOptimizer()->propose($facts, $budget, pgProbe($probeOverrides));

    return collect($proposals)->keyBy->configKey->all();
}

test('sizes shared buffers from the database slice of the budget', function () {
    // 16GB box, database local -> 4915MB reserve, 80% of which is shared_buffers.
    $proposal = pgProposals()['shared_buffers'];

    expect($proposal->proposedValue)->toBe('3932MB')
        ->and($proposal->currentValue)->toBe('128MB')
        ->and($proposal->isChange())->toBeTrue();
});

test('flags the packaged default as the highest severity finding', function () {
    $proposal = pgProposals()['shared_buffers'];

    expect($proposal->severity)->toBe(ProposalSeverity::HIGH)
        ->and($proposal->applyMethod)->toBe(ApplyMethod::RESTART)
        ->and($proposal->applyMethod->isDisruptive())->toBeTrue();
});

test('divides the sort envelope by the connection ceiling', function () {
    // (16384 * 0.25) / 100 connections.
    expect(pgProposals()['work_mem']->proposedValue)->toBe('41MB');
});

test('raises the connection ceiling on a machine with more cores', function () {
    expect(pgProposals(['cores' => 4])['max_connections']->proposedValue)->toBe('100')
        ->and(pgProposals(['cores' => 16])['max_connections']->proposedValue)->toBe('200');
});

test('a higher connection ceiling shrinks work_mem to keep the worst case bounded', function () {
    $small = pgProposals(['cores' => 4])['work_mem']->proposedValue;
    $large = pgProposals(['cores' => 16])['work_mem']->proposedValue;

    expect((int) $large)->toBeLessThan((int) $small);
});

test('picks io costs from the storage the server actually has', function () {
    $flash = pgProposals([], ['disk_rotational' => '0']);
    $spinning = pgProposals(['rotational' => true], ['disk_rotational' => '1']);

    expect($flash['random_page_cost']->proposedValue)->toBe('1.1')
        ->and($flash['effective_io_concurrency']->proposedValue)->toBe('200')
        ->and($spinning['random_page_cost']->proposedValue)->toBe('4.0')
        ->and($spinning['effective_io_concurrency']->proposedValue)->toBe('2');
});

test('holds work_mem at its floor on a machine too small to divide', function () {
    $proposal = pgProposals(['ram' => 512])['work_mem'];

    expect($proposal->proposedValue)->toBe('4MB')
        ->and($proposal->clamped)->toBeTrue();
});

test('caps shared buffers below its ceiling share of memory', function () {
    // Nothing in the formula should hand PostgreSQL more than the bound allows.
    foreach ([1024, 4096, 16384, 65536] as $ram) {
        $proposal = pgProposals(['ram' => $ram])['shared_buffers'];

        expect((int) $proposal->proposedValue)->toBeLessThanOrEqual((int) ($ram * 0.40));
    }
});

test('proposes nothing when the database runs on another machine', function () {
    $facts = pgFacts(['db_local' => false]);
    $budget = (new ResourceBudget)->compute($facts);

    expect(pgOptimizer()->propose($facts, $budget, pgProbe()))->toBe([]);
});

test('proposes nothing when postgres did not answer the probe', function () {
    $facts = pgFacts();
    $budget = (new ResourceBudget)->compute($facts);

    $probe = pgProbe();
    unset($probe['pg_shared_buffers']);

    expect(pgOptimizer()->propose($facts, $budget, $probe))->toBe([]);
});

test('reports a setting already at its proposed value as no change', function () {
    $proposals = pgProposals([], ['pg_shared_buffers' => '3932MB']);

    expect($proposals['shared_buffers']->isChange())->toBeFalse();
});

test('compares values across differing units', function () {
    // The server reports 4GB; the proposal is 3932MB. Different spelling, and in
    // this case genuinely different values.
    $proposals = pgProposals([], ['pg_shared_buffers' => '4GB']);

    expect($proposals['shared_buffers']->currentValue)->toBe('4GB')
        ->and($proposals['shared_buffers']->isChange())->toBeTrue();
});

test('treats an unreadable current value as unknown rather than matching', function () {
    $proposals = pgProposals([], ['pg_work_mem' => '']);

    expect($proposals['work_mem']->currentValue)->toBeNull()
        ->and($proposals['work_mem']->isChange())->toBeTrue();
});

test('orders the most severe findings first', function () {
    $facts = pgFacts();
    $budget = (new ResourceBudget)->compute($facts);

    $severities = array_map(
        fn ($proposal): int => $proposal->severity->rank(),
        pgOptimizer()->propose($facts, $budget, pgProbe())
    );

    expect($severities)->toBe(collect($severities)->sortDesc()->values()->all());
});

test('every proposal carries its rationale and reference', function () {
    $facts = pgFacts();
    $budget = (new ResourceBudget)->compute($facts);

    foreach (pgOptimizer()->propose($facts, $budget, pgProbe()) as $proposal) {
        expect($proposal->rationale)->not->toBe('')
            ->and($proposal->kbRef)->not->toBeNull();
    }
});

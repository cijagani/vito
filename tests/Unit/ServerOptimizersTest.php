<?php

use App\DTOs\ServerFacts;
use App\Optimizers\OS\KernelOptimizer;
use App\Optimizers\Webserver\NginxOptimizer;
use App\Support\Optimization\FormulaEvaluator;
use App\Support\Optimization\ResourceBudget;
use App\Support\Optimization\RulesetLoader;

function rulesPath(): string
{
    return __DIR__.'/../../resources/optimization/rules';
}

function nginxProposals(array $factOverrides = [], array $probeOverrides = []): array
{
    $facts = new ServerFacts(
        totalRamMb: $factOverrides['ram'] ?? 8192,
        cores: $factOverrides['cores'] ?? 4,
        virtualisation: $factOverrides['virt'] ?? 'kvm',
    );

    $probe = array_merge([
        'nginx_worker_processes' => '1',
        'nofile_limit' => '65535',
    ], $probeOverrides);

    $optimizer = new NginxOptimizer(new RulesetLoader(rulesPath()), new FormulaEvaluator);

    return collect($optimizer->propose($facts, (new ResourceBudget)->compute($facts), $probe))
        ->keyBy->configKey->all();
}

function kernelProposals(array $factOverrides = []): array
{
    $facts = new ServerFacts(
        totalRamMb: $factOverrides['ram'] ?? 8192,
        cores: $factOverrides['cores'] ?? 4,
        virtualisation: $factOverrides['virt'] ?? 'kvm',
    );

    $optimizer = new KernelOptimizer(new RulesetLoader(rulesPath()), new FormulaEvaluator);

    return collect($optimizer->propose($facts, (new ResourceBudget)->compute($facts), []))
        ->keyBy->configKey->all();
}

test('nginx runs one worker per core', function () {
    expect(nginxProposals(['cores' => 4])['worker_processes']->proposedValue)->toBe('4')
        ->and(nginxProposals(['cores' => 16])['worker_processes']->proposedValue)->toBe('16');
});

test('nginx connections are bounded by the descriptor limit', function () {
    // Each connection consumes a descriptor at both ends, so half the limit.
    expect(nginxProposals([], ['nofile_limit' => '8192'])['worker_connections']->proposedValue)
        ->toBe('4096');
});

test('nginx connections stop at a practical ceiling', function () {
    expect(nginxProposals([], ['nofile_limit' => '1048576'])['worker_connections']->proposedValue)
        ->toBe('16384');
});

test('nginx falls back to a safe descriptor limit when the probe could not read one', function () {
    // Proposing more descriptors than the kernel allows produces a config nginx
    // accepts and then cannot honour.
    expect(nginxProposals([], ['nofile_limit' => ''])['worker_connections']->proposedValue)
        ->toBe('16384');
});

test('nginx stops advertising its version', function () {
    expect(nginxProposals()['server_tokens']->proposedValue)->toBe('off');
});

test('nginx is not tuned when it is not installed', function () {
    $facts = new ServerFacts(totalRamMb: 8192, cores: 4);
    $optimizer = new NginxOptimizer(new RulesetLoader(rulesPath()), new FormulaEvaluator);

    expect($optimizer->propose($facts, (new ResourceBudget)->compute($facts), []))->toBe([]);
});

test('the kernel accepts a full connection backlog', function () {
    expect(kernelProposals()['net.core.somaxconn']->proposedValue)->toBe('65535');
});

test('the kernel stops swapping application memory eagerly', function () {
    expect(kernelProposals()['vm.swappiness']->proposedValue)->toBe('10');
});

test('the descriptor ceiling scales with memory but keeps a floor', function () {
    expect(kernelProposals(['ram' => 16384])['fs.file-max']->proposedValue)->toBe('2097152')
        ->and(kernelProposals(['ram' => 512])['fs.file-max']->proposedValue)->toBe('262144');
});

test('the kernel is left alone inside a container', function () {
    // Most of these keys belong to the host; a container refuses or ignores them,
    // so proposing them would promise a change that cannot happen.
    expect(kernelProposals(['virt' => 'lxc']))->toBe([]);
});

test('bare metal and virtual machines are both tuned', function () {
    expect(kernelProposals(['virt' => null]))->not->toBeEmpty()
        ->and(kernelProposals(['virt' => 'kvm']))->not->toBeEmpty();
});

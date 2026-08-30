<?php

use App\Support\Optimization\FormulaEvaluator;
use App\Support\Optimization\RulesetLoader;

function shippedRulesPath(): string
{
    return __DIR__.'/../../resources/optimization/rules';
}

function writeRuleset(string $yaml): string
{
    $directory = sys_get_temp_dir().'/vito-rulesets-'.uniqid();
    mkdir($directory, 0777, true);
    file_put_contents($directory.'/test.yaml', $yaml);

    return $directory;
}

function validRuleset(string $rules): string
{
    return <<<YAML
    component: test
    ruleset_version: 1
    applies_to:
      service: testsvc
      versions: ['17']
    rules:
    {$rules}
    YAML;
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/vito-rulesets-*') as $directory) {
        array_map('unlink', glob($directory.'/*'));
        rmdir($directory);
    }
});

test('the shipped postgresql ruleset loads and is well formed', function () {
    $ruleset = (new RulesetLoader(shippedRulesPath()))->component('postgresql');

    expect($ruleset)->not->toBeNull()
        ->and($ruleset->service)->toBe('postgresql')
        ->and($ruleset->rulesetVersion)->toBe(1)
        ->and($ruleset->rules)->not->toBeEmpty();
});

test('every formula in every shipped ruleset evaluates', function () {
    // Guards against a ruleset shipping a typo or a variable the engine cannot
    // supply — which would otherwise surface only when tuning a real server.
    $variables = [
        'total_ram_mb' => 16384,
        'db_buffer_mb' => 4915,
        'fpm_pool_mb' => 5000,
        'cores' => 4,
        'max_connections_target' => 100,
        'worker_rss_mb' => 80,
    ];

    $evaluator = new FormulaEvaluator;

    foreach ((new RulesetLoader(shippedRulesPath()))->all() as $ruleset) {
        foreach ($ruleset->rules as $rule) {
            foreach (['formula', 'bounds.max_formula'] as $path) {
                $formula = data_get($rule, $path);

                if ($formula === null) {
                    continue;
                }

                expect(fn () => $evaluator->evaluate($formula, $variables))
                    ->not->toThrow(Exception::class, "{$ruleset->component}.{$rule['key']}");
            }
        }
    }
});

test('every shipped rule explains itself', function () {
    foreach ((new RulesetLoader(shippedRulesPath()))->all() as $ruleset) {
        foreach ($ruleset->rules as $rule) {
            expect(trim((string) ($rule['why'] ?? '')))->not->toBe('');
        }
    }
});

test('matches a service version declared exactly', function () {
    $ruleset = (new RulesetLoader(shippedRulesPath()))->component('postgresql');

    expect($ruleset->appliesTo('postgresql', '17'))->toBeTrue()
        ->and($ruleset->appliesTo('postgresql', '15'))->toBeTrue()
        ->and($ruleset->appliesTo('postgresql', '13'))->toBeFalse()
        ->and($ruleset->appliesTo('mysql', '17'))->toBeFalse();
});

test('matches an installed version carrying more precision than the rule', function () {
    $ruleset = (new RulesetLoader(shippedRulesPath()))->component('postgresql');

    // A box reports 17.2; the ruleset declares 17.
    expect($ruleset->appliesTo('postgresql', '17.2'))->toBeTrue();
});

test('does not treat a mysql minor line as a different major', function () {
    // 8.4 must not be reduced to 8 and matched against an unrelated rule.
    $directory = writeRuleset(<<<'YAML'
    component: test
    ruleset_version: 1
    applies_to:
      service: mysql
      versions: ['8.4']
    rules:
      - key: k
        value: '1'
        apply: reload
        why: because
    YAML);

    $ruleset = (new RulesetLoader($directory))->component('test');

    expect($ruleset->appliesTo('mysql', '8.4'))->toBeTrue()
        ->and($ruleset->appliesTo('mysql', '8.4.3'))->toBeTrue()
        ->and($ruleset->appliesTo('mysql', '8.0'))->toBeFalse();
});

test('rejects a rule with neither formula nor value', function () {
    $directory = writeRuleset(validRuleset(<<<'YAML'
      - key: broken
        apply: reload
        why: because
    YAML));

    expect(fn () => (new RulesetLoader($directory))->all())
        ->toThrow(RuntimeException::class, 'has neither [formula] nor [value]');
});

test('rejects a rule declaring both formula and value', function () {
    $directory = writeRuleset(validRuleset(<<<'YAML'
      - key: broken
        formula: 'cores * 2'
        value: '4'
        apply: reload
        why: because
    YAML));

    expect(fn () => (new RulesetLoader($directory))->all())
        ->toThrow(RuntimeException::class, 'declares both [formula] and [value]');
});

test('rejects an unknown apply method', function () {
    $directory = writeRuleset(validRuleset(<<<'YAML'
      - key: broken
        value: '1'
        apply: hotpatch
        why: because
    YAML));

    expect(fn () => (new RulesetLoader($directory))->all())
        ->toThrow(RuntimeException::class, 'unknown [apply]');
});

test('rejects a rule that does not explain itself', function () {
    $directory = writeRuleset(validRuleset(<<<'YAML'
      - key: broken
        value: '1'
        apply: reload
    YAML));

    expect(fn () => (new RulesetLoader($directory))->all())
        ->toThrow(RuntimeException::class, 'is missing [why]');
});

test('rejects a ruleset with no rules', function () {
    $directory = writeRuleset(<<<'YAML'
    component: test
    ruleset_version: 1
    applies_to:
      service: testsvc
    rules: []
    YAML);

    expect(fn () => (new RulesetLoader($directory))->all())
        ->toThrow(RuntimeException::class, 'declares no rules');
});

test('narrows rules to those supported by the installed version', function () {
    $directory = writeRuleset(<<<'YAML'
    component: test
    ruleset_version: 1
    applies_to:
      service: postgresql
      versions: ['15', '16', '17']
    rules:
      - key: everywhere
        value: '1'
        apply: reload
        why: applies to every supported version
      - key: modern_only
        value: '1'
        apply: reload
        min_version: '16'
        why: the setting does not exist before 16
      - key: legacy_only
        value: '1'
        apply: reload
        max_version: '16'
        why: superseded from 16 onward
    YAML);

    $ruleset = (new RulesetLoader($directory))->component('test');

    $keys = fn (string $version): array => array_column($ruleset->rulesFor($version), 'key');

    expect($keys('15'))->toBe(['everywhere', 'legacy_only'])
        ->and($keys('16'))->toBe(['everywhere', 'modern_only'])
        ->and($keys('17.2'))->toBe(['everywhere', 'modern_only']);
});

test('returns every rule when no version is given', function () {
    $ruleset = (new RulesetLoader(shippedRulesPath()))->component('postgresql');

    expect($ruleset->rulesFor())->toHaveCount(count($ruleset->rules));
});

test('rejects a malformed version bound', function () {
    $directory = writeRuleset(validRuleset(<<<'YAML'
      - key: broken
        value: '1'
        apply: reload
        min_version: 'sixteen'
        why: because
    YAML));

    expect(fn () => (new RulesetLoader($directory))->all())
        ->toThrow(RuntimeException::class, 'malformed [min_version]');
});

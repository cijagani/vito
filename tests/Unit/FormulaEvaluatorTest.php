<?php

use App\Support\Optimization\FormulaEvaluator;

function evaluate(string $formula, array $variables = []): float
{
    return (new FormulaEvaluator)->evaluate($formula, $variables);
}

test('evaluates arithmetic with correct precedence', function () {
    expect(evaluate('2 + 3 * 4'))->toBe(14.0)
        ->and(evaluate('(2 + 3) * 4'))->toBe(20.0)
        ->and(evaluate('10 / 4'))->toBe(2.5)
        ->and(evaluate('10 - 3 - 2'))->toBe(5.0);
});

test('resolves named facts', function () {
    expect(evaluate('db_buffer_mb * 0.80', ['db_buffer_mb' => 4915]))->toBe(3932.0);
});

test('evaluates the documented work_mem formula', function () {
    $result = evaluate('(total_ram_mb * 0.25) / max_connections', [
        'total_ram_mb' => 16384,
        'max_connections' => 100,
    ]);

    expect($result)->toBe(40.96);
});

test('supports min and max', function () {
    expect(evaluate('min(cores, 8)', ['cores' => 16]))->toBe(8.0)
        ->and(evaluate('min(cores, 8)', ['cores' => 4]))->toBe(4.0)
        ->and(evaluate('max(cores * 3, 12)', ['cores' => 2]))->toBe(12.0);
});

test('handles negative numbers', function () {
    expect(evaluate('-5 + 8'))->toBe(3.0)
        ->and(evaluate('10 * -2'))->toBe(-20.0);
});

test('rejects an unknown variable rather than treating it as zero', function () {
    expect(fn () => evaluate('ram_mb * 2', ['total_ram_mb' => 100]))
        ->toThrow(InvalidArgumentException::class, 'Unknown variable "ram_mb"');
});

test('rejects an unknown function', function () {
    expect(fn () => evaluate('sqrt(4)'))
        ->toThrow(InvalidArgumentException::class, 'Unknown function "sqrt"');
});

test('rejects division by zero', function () {
    expect(fn () => evaluate('100 / 0'))
        ->toThrow(InvalidArgumentException::class, 'Division by zero');
});

test('rejects trailing garbage', function () {
    expect(fn () => evaluate('2 + 2)'))
        ->toThrow(InvalidArgumentException::class);
});

test('rejects an unclosed parenthesis', function () {
    expect(fn () => evaluate('(2 + 2'))
        ->toThrow(InvalidArgumentException::class);
});

test('does not execute php in a formula', function () {
    expect(fn () => evaluate('phpinfo()'))
        ->toThrow(InvalidArgumentException::class, 'Unknown function "phpinfo"');
});

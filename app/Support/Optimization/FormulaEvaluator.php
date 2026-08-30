<?php

namespace App\Support\Optimization;

use InvalidArgumentException;

/**
 * Evaluates the arithmetic in a tuning rule's `formula`, e.g.
 * "(total_ram_mb * 0.25) / max_connections".
 *
 * Deliberately a tiny recursive-descent parser rather than a general expression
 * engine: these formulas need +, -, *, / , parentheses, min() and max() over a
 * fixed set of named facts, and nothing else. A narrow grammar cannot be talked
 * into evaluating something a ruleset author did not intend, and adds no
 * dependency. There is no eval() anywhere in this class.
 */
class FormulaEvaluator
{
    private string $input = '';

    private int $position = 0;

    /** @var array<string, float> */
    private array $variables = [];

    /**
     * @param  array<string, float|int>  $variables
     *
     * @throws InvalidArgumentException on unknown variables or malformed input
     */
    public function evaluate(string $formula, array $variables): float
    {
        $this->input = $formula;
        $this->position = 0;
        $this->variables = array_map(fn ($value): float => (float) $value, $variables);

        $result = $this->parseExpression();

        $this->skipWhitespace();
        if ($this->position < strlen($this->input)) {
            throw new InvalidArgumentException(
                sprintf('Unexpected "%s" in formula "%s".', $this->input[$this->position], $formula)
            );
        }

        return $result;
    }

    private function parseExpression(): float
    {
        $left = $this->parseTerm();

        while (true) {
            $this->skipWhitespace();
            $operator = $this->peek();

            if ($operator !== '+' && $operator !== '-') {
                return $left;
            }

            $this->position++;
            $right = $this->parseTerm();
            $left = $operator === '+' ? $left + $right : $left - $right;
        }
    }

    private function parseTerm(): float
    {
        $left = $this->parseFactor();

        while (true) {
            $this->skipWhitespace();
            $operator = $this->peek();

            if ($operator !== '*' && $operator !== '/') {
                return $left;
            }

            $this->position++;
            $right = $this->parseFactor();

            if ($operator === '/') {
                if ($right === 0.0) {
                    throw new InvalidArgumentException('Division by zero in formula "'.$this->input.'".');
                }
                $left /= $right;

                continue;
            }

            $left *= $right;
        }
    }

    private function parseFactor(): float
    {
        $this->skipWhitespace();

        if ($this->peek() === '-') {
            $this->position++;

            return -$this->parseFactor();
        }

        if ($this->peek() === '(') {
            $this->position++;
            $value = $this->parseExpression();
            $this->expect(')');

            return $value;
        }

        if ($this->peek() !== null && preg_match('/[0-9.]/', $this->peek()) === 1) {
            return $this->parseNumber();
        }

        return $this->parseIdentifier();
    }

    private function parseNumber(): float
    {
        $start = $this->position;

        while ($this->position < strlen($this->input)
            && preg_match('/[0-9.]/', $this->input[$this->position]) === 1) {
            $this->position++;
        }

        return (float) substr($this->input, $start, $this->position - $start);
    }

    /**
     * A bare name is a variable; a name followed by "(" is one of the two
     * functions the grammar allows.
     */
    private function parseIdentifier(): float
    {
        $start = $this->position;

        while ($this->position < strlen($this->input)
            && preg_match('/[a-zA-Z0-9_]/', $this->input[$this->position]) === 1) {
            $this->position++;
        }

        $name = substr($this->input, $start, $this->position - $start);

        if ($name === '') {
            throw new InvalidArgumentException('Malformed formula "'.$this->input.'".');
        }

        $this->skipWhitespace();

        if ($this->peek() === '(') {
            return $this->parseFunction($name);
        }

        if (! array_key_exists($name, $this->variables)) {
            throw new InvalidArgumentException(
                sprintf('Unknown variable "%s" in formula "%s".', $name, $this->input)
            );
        }

        return $this->variables[$name];
    }

    private function parseFunction(string $name): float
    {
        if ($name !== 'min' && $name !== 'max') {
            throw new InvalidArgumentException(
                sprintf('Unknown function "%s" in formula "%s".', $name, $this->input)
            );
        }

        $this->expect('(');

        $arguments = [$this->parseExpression()];

        $this->skipWhitespace();
        while ($this->peek() === ',') {
            $this->position++;
            $arguments[] = $this->parseExpression();
            $this->skipWhitespace();
        }

        $this->expect(')');

        return $name === 'min' ? min($arguments) : max($arguments);
    }

    private function expect(string $character): void
    {
        $this->skipWhitespace();

        if ($this->peek() !== $character) {
            throw new InvalidArgumentException(
                sprintf('Expected "%s" in formula "%s".', $character, $this->input)
            );
        }

        $this->position++;
    }

    private function peek(): ?string
    {
        return $this->input[$this->position] ?? null;
    }

    private function skipWhitespace(): void
    {
        while ($this->position < strlen($this->input)
            && preg_match('/\s/', $this->input[$this->position]) === 1) {
            $this->position++;
        }
    }
}

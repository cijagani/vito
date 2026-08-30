<?php

namespace App\DTOs;

use App\Enums\ApplyMethod;
use App\Enums\ProposalSeverity;

/**
 * One proposed configuration change: what is set now, what it should be, and why.
 *
 * The rationale travels with the value deliberately. A tuning panel that writes
 * numbers it cannot explain is indistinguishable from one that guesses.
 */
class TuningProposal
{
    public function __construct(
        public readonly string $component,
        public readonly string $configKey,
        public readonly ?string $currentValue,
        public readonly string $proposedValue,
        public readonly ProposalSeverity $severity,
        public readonly ApplyMethod $applyMethod,
        public readonly string $rationale,
        public readonly ?string $kbRef = null,
        public readonly bool $clamped = false,
    ) {}

    /**
     * Whether applying this would actually change anything. A proposal that
     * matches the running value is reported as already-satisfied rather than
     * hidden, so the panel can show that a setting was checked and is correct.
     */
    public function isChange(): bool
    {
        if ($this->currentValue === null) {
            return true;
        }

        return $this->normalise($this->currentValue) !== $this->normalise($this->proposedValue);
    }

    /**
     * Engines echo the same value in different shapes -- "4GB" and "4096MB",
     * "on" and "ON" -- so compare meaning rather than spelling.
     */
    private function normalise(string $value): string
    {
        $value = strtolower(trim($value));

        if (preg_match('/^(\d+(?:\.\d+)?)\s*([kmgt]b?)$/i', $value, $matches) === 1) {
            $multiplier = match ($matches[2][0]) {
                'k' => 1 / 1024,
                'm' => 1,
                'g' => 1024,
                't' => 1024 * 1024,
                default => 1,
            };

            return (string) (int) round((float) $matches[1] * $multiplier).'mb';
        }

        // A bare number may be a size in bytes or a plain setting, and the two
        // cannot be told apart from the value alone -- MySQL reports 536870912
        // where the rule says 512M, but max_connections of 150 is just 150.
        // Anything large enough to be a megabyte boundary is treated as bytes;
        // below that, comparing as a number is what a reader means.
        if (preg_match('/^\d+$/', $value) === 1) {
            $number = (int) $value;

            return $number >= 1048576 && $number % 1048576 === 0
                ? (string) intdiv($number, 1048576).'mb'
                : (string) $number;
        }

        // Trailing zeros carry no meaning here: MySQL reports long_query_time as
        // 2.000000 where the rule says 2.
        if (preg_match('/^\d+\.\d+$/', $value) === 1) {
            return rtrim(rtrim($value, '0'), '.');
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'component' => $this->component,
            'config_key' => $this->configKey,
            'current_value' => $this->currentValue,
            'proposed_value' => $this->proposedValue,
            'severity' => $this->severity->value,
            'severity_color' => $this->severity->getColor(),
            'apply_method' => $this->applyMethod->value,
            'is_disruptive' => $this->applyMethod->isDisruptive(),
            'rationale' => $this->rationale,
            'kb_ref' => $this->kbRef,
            'clamped' => $this->clamped,
            'is_change' => $this->isChange(),
        ];
    }
}

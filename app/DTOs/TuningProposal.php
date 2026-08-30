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

        if (preg_match('/^(\d+(?:\.\d+)?)\s*([kmgt]b?)$/i', $value, $matches) !== 1) {
            return $value;
        }

        $multiplier = match ($matches[2][0]) {
            'k' => 1 / 1024,
            'm' => 1,
            'g' => 1024,
            't' => 1024 * 1024,
            default => 1,
        };

        return (string) (int) round((float) $matches[1] * $multiplier).'mb';
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

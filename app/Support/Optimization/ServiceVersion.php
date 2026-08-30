<?php

namespace App\Support\Optimization;

/**
 * A service's version, split into the parts a tuning rule can reason about.
 *
 * The engines Vito manages disagree about where the meaningful boundary falls:
 * PostgreSQL 17.2 is major 17 patch 2, while MySQL 8.4.3 is a distinct release
 * line from 8.0 — so "8" alone identifies nothing useful. Rules therefore match
 * on a declared prefix rather than on a hardcoded notion of "the major version",
 * and comparisons walk the numeric parts in order.
 */
class ServiceVersion
{
    /** @var array<int, int> */
    public readonly array $parts;

    public function __construct(public readonly string $raw)
    {
        preg_match_all('/\d+/', $raw, $matches);

        $this->parts = array_map('intval', $matches[0]);
    }

    public static function make(string $version): self
    {
        return new self($version);
    }

    public function major(): int
    {
        return $this->parts[0] ?? 0;
    }

    public function minor(): int
    {
        return $this->parts[1] ?? 0;
    }

    /**
     * The release line a rule targets: "17" for PostgreSQL, "8.4" for MySQL.
     * Which of those is meaningful is the ruleset author's call, expressed by
     * how precisely they write the version in `applies_to` or `min_version`.
     */
    public function matchesPrefix(string $prefix): bool
    {
        if ($this->raw === $prefix) {
            return true;
        }

        return str_starts_with($this->raw, $prefix.'.');
    }

    /**
     * Numeric comparison across however many parts the other version declares,
     * so "17" compares equal to "17.2" at the precision it was written.
     */
    public function isAtLeast(string $other): bool
    {
        return $this->compare($other) >= 0;
    }

    public function isBelow(string $other): bool
    {
        return $this->compare($other) < 0;
    }

    private function compare(string $other): int
    {
        $otherParts = (new self($other))->parts;

        foreach ($otherParts as $index => $part) {
            $mine = $this->parts[$index] ?? 0;

            if ($mine !== $part) {
                return $mine <=> $part;
            }
        }

        return 0;
    }
}

<?php

namespace App\Support\Optimization;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads the tuning rulesets from resources/optimization/rules and validates their
 * shape.
 *
 * Validation happens here, at load time, rather than when a rule is applied to a
 * server — a malformed ruleset should fail the test suite, not a production box
 * mid-tuning.
 */
class RulesetLoader
{
    /** @var array<string, Ruleset>|null */
    private ?array $cache = null;

    private const array APPLY_METHODS = ['reload', 'restart'];

    private const array SEVERITIES = ['low', 'medium', 'high'];

    public function __construct(private readonly ?string $path = null) {}

    /**
     * @return array<string, Ruleset> keyed by component
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $rulesets = [];

        foreach (glob($this->directory().'/*.yaml') ?: [] as $file) {
            $ruleset = $this->load($file);
            $rulesets[$ruleset->component] = $ruleset;
        }

        return $this->cache = $rulesets;
    }

    public function component(string $component): ?Ruleset
    {
        return $this->all()[$component] ?? null;
    }

    /**
     * The ruleset governing an installed service, if one exists.
     */
    public function forService(string $service, ?string $version = null): ?Ruleset
    {
        foreach ($this->all() as $ruleset) {
            if ($ruleset->appliesTo($service, $version)) {
                return $ruleset;
            }
        }

        return null;
    }

    public function load(string $file): Ruleset
    {
        $data = Yaml::parseFile($file);

        if (! is_array($data)) {
            throw new RuntimeException("Ruleset [{$file}] did not parse to a mapping.");
        }

        foreach (['component', 'ruleset_version', 'applies_to', 'rules'] as $required) {
            if (! isset($data[$required])) {
                throw new RuntimeException("Ruleset [{$file}] is missing [{$required}].");
            }
        }

        if (! isset($data['applies_to']['service'])) {
            throw new RuntimeException("Ruleset [{$file}] is missing [applies_to.service].");
        }

        $rules = $data['rules'];

        if (! is_array($rules) || $rules === []) {
            throw new RuntimeException("Ruleset [{$file}] declares no rules.");
        }

        foreach ($rules as $index => $rule) {
            $this->validateRule($rule, $file, $index);
        }

        return new Ruleset(
            component: $data['component'],
            rulesetVersion: (int) $data['ruleset_version'],
            service: $data['applies_to']['service'],
            versions: array_map('strval', $data['applies_to']['versions'] ?? []),
            rules: $rules,
            guardrails: $data['guardrails'] ?? [],
            variants: $data['variants'] ?? [],
        );
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function validateRule(mixed $rule, string $file, int $index): void
    {
        $where = "Ruleset [{$file}] rule #{$index}";

        if (! is_array($rule) || ! isset($rule['key'])) {
            throw new RuntimeException("{$where} is missing [key].");
        }

        $key = $rule['key'];

        // A rule must produce a value somehow: either computed from the machine
        // or stated outright. One or the other, never both — two sources of truth
        // for one setting is exactly the drift this format exists to prevent.
        $hasFormula = isset($rule['formula']);
        $hasValue = isset($rule['value']);

        if (! $hasFormula && ! $hasValue) {
            throw new RuntimeException("{$where} [{$key}] has neither [formula] nor [value].");
        }

        if ($hasFormula && $hasValue) {
            throw new RuntimeException("{$where} [{$key}] declares both [formula] and [value].");
        }

        if (! isset($rule['apply'])) {
            throw new RuntimeException("{$where} [{$key}] is missing [apply].");
        }

        if (! in_array($rule['apply'], self::APPLY_METHODS, true)) {
            throw new RuntimeException(
                "{$where} [{$key}] has an unknown [apply] of [{$rule['apply']}]."
            );
        }

        // Without this the UI cannot explain the change, and an unexplained value
        // written to a production config is exactly what this system exists to stop.
        if (! isset($rule['why']) || trim((string) $rule['why']) === '') {
            throw new RuntimeException("{$where} [{$key}] is missing [why].");
        }

        if (isset($rule['severity_if_default'])
            && ! in_array($rule['severity_if_default'], self::SEVERITIES, true)) {
            throw new RuntimeException(
                "{$where} [{$key}] has an unknown [severity_if_default]."
            );
        }

        // A version bound that is not a version silently matches nothing, which
        // would drop the rule on every server rather than fail loudly.
        foreach (['min_version', 'max_version'] as $bound) {
            if (isset($rule[$bound]) && preg_match('/^\d+(\.\d+)*$/', (string) $rule[$bound]) !== 1) {
                throw new RuntimeException(
                    "{$where} [{$key}] has a malformed [{$bound}] of [{$rule[$bound]}]."
                );
            }
        }
    }

    private function directory(): string
    {
        return $this->path ?? base_path('resources/optimization/rules');
    }
}

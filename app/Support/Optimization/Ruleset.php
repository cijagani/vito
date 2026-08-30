<?php

namespace App\Support\Optimization;

/**
 * One component's tuning rules, as loaded from resources/optimization/rules/*.yaml.
 *
 * A value object over already-validated data — RulesetLoader does the parsing and
 * the shape checking, so anything holding a Ruleset can trust its contents.
 */
class Ruleset
{
    /**
     * @param  array<int, string>  $versions
     * @param  array<int, array<string, mixed>>  $rules
     * @param  array<int, array<string, mixed>>  $guardrails
     * @param  array<string, array<string, mixed>>  $variants
     */
    public function __construct(
        public readonly string $component,
        public readonly int $rulesetVersion,
        public readonly string $service,
        public readonly array $versions,
        public readonly array $rules,
        public readonly array $guardrails = [],
        public readonly array $variants = [],
        public readonly array $extraServices = [],
    ) {}

    /**
     * Every service this ruleset governs.
     *
     * @return array<int, string>
     */
    public function services(): array
    {
        return array_values(array_unique([$this->service, ...$this->extraServices]));
    }

    /**
     * The rules that apply to one variant of the engine.
     *
     * A rule marked `variant_requires` only applies where the named capability is
     * declared -- MariaDB has a thread pool, standard MySQL does not, and
     * proposing one for MySQL would produce a setting it refuses to start with.
     *
     * @param  array<int, array<string, mixed>>  $rules
     * @return array<int, array<string, mixed>>
     */
    public function rulesForVariant(array $rules, ?string $variant): array
    {
        $capabilities = $variant === null ? [] : ($this->variants[$variant] ?? []);

        return array_values(array_filter($rules, function (array $rule) use ($capabilities): bool {
            if (! isset($rule['variant_requires'])) {
                return true;
            }

            return ($capabilities[$rule['variant_requires']] ?? false) === true;
        }));
    }

    /**
     * Whether this ruleset governs the given installed service.
     *
     * An empty version list means the rules apply to every version of the service.
     */
    public function appliesTo(string $service, ?string $version = null): bool
    {
        // A ruleset may cover several services that share an engine -- MySQL and
        // MariaDB differ in a handful of settings, not in how InnoDB is tuned.
        if (! in_array($service, $this->services(), true)) {
            return false;
        }

        if ($this->versions === [] || $version === null) {
            return true;
        }

        // Installed versions carry more precision than the ruleset declares --
        // PostgreSQL reports "17.2" against a "17" rule, MySQL "8.4.3" against
        // "8.4" -- so match the declared prefix rather than assuming where the
        // meaningful boundary falls, which differs between these engines.
        $installed = ServiceVersion::make($version);

        foreach ($this->versions as $declared) {
            if ($installed->matchesPrefix($declared)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The rules that apply to one installed version.
     *
     * A rule may narrow itself with `min_version` or `max_version` when a setting
     * only exists, or only behaves as described, on part of the supported range.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rulesFor(?string $version = null): array
    {
        if ($version === null) {
            return $this->rules;
        }

        $installed = ServiceVersion::make($version);

        return array_values(array_filter(
            $this->rules,
            function (array $rule) use ($installed): bool {
                if (isset($rule['min_version']) && $installed->isBelow((string) $rule['min_version'])) {
                    return false;
                }

                return ! (isset($rule['max_version']) && ! $installed->isBelow((string) $rule['max_version']));
            }
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function rule(string $key): ?array
    {
        foreach ($this->rules as $rule) {
            if ($rule['key'] === $key) {
                return $rule;
            }
        }

        return null;
    }
}

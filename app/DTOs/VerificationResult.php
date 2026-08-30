<?php

namespace App\DTOs;

/**
 * What a server actually reports for one setting after a plan was applied.
 *
 * A successful write is not the same as a setting taking effect. A value can be
 * written correctly, accepted by the config parser, and still be overridden later
 * in the file, ignored because it needs a restart that only reloaded, or clamped
 * by the service to something it considers sane. Verification asks the running
 * service what it believes rather than trusting the write.
 */
class VerificationResult
{
    public const string PASS = 'pass';

    public const string FAIL = 'fail';

    public const string UNKNOWN = 'unknown';

    public function __construct(
        public readonly string $component,
        public readonly string $configKey,
        public readonly string $expected,
        public readonly ?string $actual,
        public readonly string $status,
        public readonly ?string $note = null,
    ) {}

    public function passed(): bool
    {
        return $this->status === self::PASS;
    }

    public function failed(): bool
    {
        return $this->status === self::FAIL;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'component' => $this->component,
            'config_key' => $this->configKey,
            'expected' => $this->expected,
            'actual' => $this->actual,
            'status' => $this->status,
            'note' => $this->note,
        ];
    }
}

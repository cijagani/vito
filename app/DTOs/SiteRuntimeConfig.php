<?php

namespace App\DTOs;

use App\Enums\SiteRuntimeConfigType;
use InvalidArgumentException;

final readonly class SiteRuntimeConfig
{
    public string $checksum;

    public function __construct(
        public SiteRuntimeConfigType $type,
        public string $targetPath,
        public string $contents,
        public int $desiredRevision,
    ) {
        if (
            preg_match('/[\x00-\x1F\x7F]/', $targetPath) === 1
            || preg_match('#\A/(?!.*//)(?!.*(?:\A|/)\.\.?(?:/|\z)).+\z#', $targetPath) !== 1
        ) {
            throw new InvalidArgumentException('A runtime configuration target must be an absolute normalized path.');
        }

        if ($contents === '') {
            throw new InvalidArgumentException('A runtime configuration cannot be empty.');
        }

        if ($desiredRevision < 1) {
            throw new InvalidArgumentException('A runtime configuration revision must be positive.');
        }

        $this->checksum = hash('sha256', $contents);
    }
}

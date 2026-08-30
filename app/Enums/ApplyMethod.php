<?php

namespace App\Enums;

use App\Contracts\VitoEnum;
use App\Traits\HasEnumHelpers;

enum ApplyMethod: string implements VitoEnum
{
    use HasEnumHelpers;

    case RELOAD = 'reload';
    case RESTART = 'restart';

    /**
     * A restart drops every open connection and any in-flight request, so it can
     * never be applied silently. Carrying that as data rather than as a comment
     * is what lets the UI confirm it without anyone having to remember which
     * settings are dangerous.
     */
    public function isDisruptive(): bool
    {
        return $this === self::RESTART;
    }

    public function getColor(): string
    {
        return match ($this) {
            self::RELOAD => 'success',
            self::RESTART => 'warning',
        };
    }

    public function getText(): string
    {
        return $this->value;
    }
}

<?php

namespace App\Enums;

use App\Contracts\VitoEnum;
use App\Traits\HasEnumHelpers;

enum ProposalSeverity: string implements VitoEnum
{
    use HasEnumHelpers;

    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';

    /**
     * Higher sorts first, so the list opens on what actually costs the operator
     * something rather than on whichever component happened to run first.
     */
    public function rank(): int
    {
        return match ($this) {
            self::HIGH => 3,
            self::MEDIUM => 2,
            self::LOW => 1,
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::HIGH => 'danger',
            self::MEDIUM => 'warning',
            self::LOW => 'gray',
        };
    }

    public function getText(): string
    {
        return $this->value;
    }
}

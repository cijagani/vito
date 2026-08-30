<?php

namespace App\Enums;

use App\Contracts\VitoEnum;
use App\Traits\HasEnumHelpers;

enum OptimizationPlanStatus: string implements VitoEnum
{
    use HasEnumHelpers;

    case DRAFT = 'draft';
    case APPLYING = 'applying';
    case APPLIED = 'applied';
    case FAILED = 'failed';
    case ROLLED_BACK = 'rolled_back';

    /**
     * A plan that has already touched the server cannot be applied a second time;
     * its proposals were computed against facts that have since changed.
     */
    public function isApplicable(): bool
    {
        return $this === self::DRAFT;
    }

    public function getColor(): string
    {
        return match ($this) {
            self::APPLIED => 'success',
            self::DRAFT => 'info',
            self::APPLYING => 'warning',
            self::FAILED => 'danger',
            self::ROLLED_BACK => 'gray',
        };
    }

    public function getText(): string
    {
        return $this->value;
    }
}

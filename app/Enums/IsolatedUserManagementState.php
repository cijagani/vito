<?php

namespace App\Enums;

use App\Contracts\VitoEnum;

enum IsolatedUserManagementState: string implements VitoEnum
{
    case LEGACY_UNKNOWN = 'legacy_unknown';
    case PENDING = 'pending';
    case MANAGED = 'managed';

    public function getColor(): string
    {
        return match ($this) {
            self::LEGACY_UNKNOWN => 'warning',
            self::PENDING => 'gray',
            self::MANAGED => 'success',
        };
    }

    public function getText(): string
    {
        return match ($this) {
            self::LEGACY_UNKNOWN => 'Legacy unverified',
            self::PENDING => 'Pending verification',
            self::MANAGED => 'Vito managed',
        };
    }
}

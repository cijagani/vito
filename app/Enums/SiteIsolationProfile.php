<?php

namespace App\Enums;

use App\Contracts\VitoEnum;

enum SiteIsolationProfile: string implements VitoEnum
{
    case LEGACY_UNISOLATED = 'legacy_unisolated';
    case SHARED = 'shared';
    case ISOLATED = 'isolated';
    case HARDENED = 'hardened';

    public function getColor(): string
    {
        return match ($this) {
            self::LEGACY_UNISOLATED => 'danger',
            self::SHARED => 'warning',
            self::ISOLATED => 'success',
            self::HARDENED => 'info',
        };
    }

    public function getText(): string
    {
        return match ($this) {
            self::LEGACY_UNISOLATED => 'Legacy unisolated',
            self::SHARED => 'Shared trust group',
            self::ISOLATED => 'Isolated',
            self::HARDENED => 'Hardened',
        };
    }
}

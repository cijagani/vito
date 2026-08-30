<?php

namespace App\Enums;

use App\Contracts\VitoEnum;
use App\Traits\HasEnumHelpers;

enum SiteLoadClass: string implements VitoEnum
{
    use HasEnumHelpers;

    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';

    /**
     * Share of the PHP-FPM pool this site claims, relative to its siblings.
     *
     * Deliberately non-linear: a busy application does not need three times a
     * brochure site, it needs closer to an order of magnitude, because the
     * brochure site is served largely from static files and cache while the
     * application spends real time in PHP.
     */
    public function weight(): int
    {
        return match ($this) {
            self::LOW => 1,
            self::MEDIUM => 3,
            self::HIGH => 6,
        };
    }

    /**
     * Low-traffic sites return idle memory to the machine instead of holding
     * workers open, which is usually the largest single win on a shared box.
     */
    public function processManager(): string
    {
        return $this === self::LOW ? 'ondemand' : 'dynamic';
    }

    public function getColor(): string
    {
        return match ($this) {
            self::LOW => 'default',
            self::MEDIUM => 'info',
            self::HIGH => 'warning',
        };
    }

    public function getText(): string
    {
        return $this->value;
    }
}

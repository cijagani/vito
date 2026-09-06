<?php

namespace App\Enums;

use App\Contracts\VitoEnum;

enum FpmServiceMode: string implements VitoEnum
{
    case SHARED_MASTER = 'shared_master';
    case DEDICATED_MASTER = 'dedicated_master';

    public function getColor(): string
    {
        return match ($this) {
            self::SHARED_MASTER => 'gray',
            self::DEDICATED_MASTER => 'info',
        };
    }

    public function getText(): string
    {
        return match ($this) {
            self::SHARED_MASTER => 'Shared PHP-FPM master',
            self::DEDICATED_MASTER => 'Dedicated PHP-FPM master',
        };
    }
}

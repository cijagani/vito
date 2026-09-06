<?php

namespace App\Enums;

use App\Contracts\VitoEnum;

enum FpmProcessManager: string implements VitoEnum
{
    case ONDEMAND = 'ondemand';
    case DYNAMIC = 'dynamic';

    public function getColor(): string
    {
        return match ($this) {
            self::ONDEMAND => 'success',
            self::DYNAMIC => 'info',
        };
    }

    public function getText(): string
    {
        return $this->value;
    }
}

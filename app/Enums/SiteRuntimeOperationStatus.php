<?php

namespace App\Enums;

use App\Contracts\VitoEnum;

enum SiteRuntimeOperationStatus: string implements VitoEnum
{
    case PENDING = 'pending';
    case RUNNING = 'running';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
    case ROLLED_BACK = 'rolled_back';

    public function getColor(): string
    {
        return match ($this) {
            self::PENDING => 'gray',
            self::RUNNING => 'warning',
            self::SUCCEEDED => 'success',
            self::FAILED => 'danger',
            self::ROLLED_BACK => 'info',
        };
    }

    public function getText(): string
    {
        return str_replace('_', ' ', $this->value);
    }
}

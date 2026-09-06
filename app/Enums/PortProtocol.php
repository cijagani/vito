<?php

namespace App\Enums;

use App\Contracts\VitoEnum;

enum PortProtocol: string implements VitoEnum
{
    case TCP = 'tcp';
    case UDP = 'udp';

    public function getColor(): string
    {
        return 'gray';
    }

    public function getText(): string
    {
        return strtoupper($this->value);
    }
}

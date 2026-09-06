<?php

namespace App\Enums;

use App\Contracts\VitoEnum;

enum SiteRuntimeConfigType: string implements VitoEnum
{
    case NGINX = 'nginx';
    case PHP_FPM = 'php_fpm';
    case PHP_CLI = 'php_cli';
    case FILESYSTEM = 'filesystem';
    case WORKERS = 'workers';
    case CRON = 'cron';

    public function getColor(): string
    {
        return 'gray';
    }

    public function getText(): string
    {
        return match ($this) {
            self::NGINX => 'Nginx',
            self::PHP_FPM => 'PHP-FPM',
            self::PHP_CLI => 'PHP CLI',
            self::FILESYSTEM => 'Filesystem',
            self::WORKERS => 'Workers',
            self::CRON => 'Cron',
        };
    }
}

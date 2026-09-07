<?php

namespace App\Support;

use InvalidArgumentException;

final class SiteStorage
{
    public const string ROOT = '/srv/vito/sites';

    public static function homeDirectory(string $user): string
    {
        if (preg_match('/\A[a-z_][a-z0-9_-]*[a-z0-9]\z/', $user) !== 1) {
            throw new InvalidArgumentException('A normalized Linux username is required for site storage.');
        }

        return self::ROOT.'/'.$user;
    }
}

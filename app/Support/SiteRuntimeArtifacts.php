<?php

namespace App\Support;

use App\Enums\SiteRuntimeConfigType;
use App\Models\Site;
use InvalidArgumentException;
use LogicException;

final readonly class SiteRuntimeArtifacts
{
    public function __construct(public int $siteId)
    {
        if ($siteId < 1) {
            throw new InvalidArgumentException('A persisted site ID is required for runtime artifacts.');
        }
    }

    public static function fromSite(Site $site): self
    {
        if (! $site->exists || ! is_int($site->getKey())) {
            throw new LogicException('Runtime artifacts cannot be derived for an unpersisted site.');
        }

        return new self($site->getKey());
    }

    public function key(): string
    {
        return 'vito-site-'.$this->siteId;
    }

    public function nginxAvailablePath(): string
    {
        return '/etc/nginx/sites-available/'.$this->key().'.conf';
    }

    public function nginxEnabledPath(): string
    {
        return '/etc/nginx/sites-enabled/'.$this->key().'.conf';
    }

    public function fpmPoolName(): string
    {
        return $this->key();
    }

    public function fpmPoolPath(string $phpVersion): string
    {
        $this->ensurePhpVersion($phpVersion);

        return '/etc/php/'.$phpVersion.'/fpm/pool.d/'.$this->key().'.conf';
    }

    public function fpmSocketPath(): string
    {
        return '/run/php/'.$this->key().'.sock';
    }

    public function logDirectory(): string
    {
        return '/var/log/vito/sites/'.$this->siteId;
    }

    public function systemdSlice(): string
    {
        return 'site-'.$this->siteId.'.slice';
    }

    public function phpCliProfilePath(): string
    {
        return '/etc/profile.d/'.$this->key().'.sh';
    }

    public function systemdLimitsPath(): string
    {
        return '/etc/systemd/system/'.$this->systemdSlice().'.d/limits.conf';
    }

    public function workersPath(): string
    {
        return '/etc/supervisor/conf.d/'.$this->key().'-workers.conf';
    }

    public function cronPath(): string
    {
        return '/etc/cron.d/'.$this->key();
    }

    public function expectedTargetPath(SiteRuntimeConfigType $type, ?string $phpVersion = null): string
    {
        return match ($type) {
            SiteRuntimeConfigType::NGINX => $this->nginxAvailablePath(),
            SiteRuntimeConfigType::PHP_FPM => $this->fpmPoolPath($phpVersion ?? ''),
            SiteRuntimeConfigType::PHP_CLI => $this->phpCliProfilePath(),
            SiteRuntimeConfigType::FILESYSTEM => $this->systemdLimitsPath(),
            SiteRuntimeConfigType::WORKERS => $this->workersPath(),
            SiteRuntimeConfigType::CRON => $this->cronPath(),
        };
    }

    private function ensurePhpVersion(string $phpVersion): void
    {
        if (preg_match('/\A\d+\.\d+\z/', $phpVersion) !== 1) {
            throw new InvalidArgumentException('A major.minor PHP version is required.');
        }
    }
}

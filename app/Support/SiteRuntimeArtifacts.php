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

    public function nginxLegacyAvailablePath(string $domain): string
    {
        $this->ensureDomain($domain);

        return '/etc/nginx/sites-available/'.$domain;
    }

    public function nginxLegacyEnabledPath(string $domain): string
    {
        $this->ensureDomain($domain);

        return '/etc/nginx/sites-enabled/'.$domain;
    }

    public function nginxStateDirectory(): string
    {
        return '/var/lib/vito/nginx/'.$this->key();
    }

    public function nginxCandidatePath(string $checksum): string
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $checksum) !== 1) {
            throw new InvalidArgumentException('A SHA-256 checksum is required for an Nginx candidate.');
        }

        return $this->nginxStateDirectory().'/candidate-'.$checksum.'.conf';
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

    public function fpmSocketPath(string $phpVersion): string
    {
        $this->ensurePhpVersion($phpVersion);

        return '/run/php/'.$this->key().'-php'.$phpVersion.'.sock';
    }

    public function fpmLegacyPoolPath(string $phpVersion, string $user): string
    {
        $this->ensurePhpVersion($phpVersion);
        $this->ensureUser($user);

        return '/etc/php/'.$phpVersion.'/fpm/pool.d/'.$user.'.conf';
    }

    public function fpmStateDirectory(string $phpVersion): string
    {
        $this->ensurePhpVersion($phpVersion);

        return '/var/lib/vito/php-fpm/'.$phpVersion.'/'.$this->key();
    }

    public function fpmCandidatePath(string $phpVersion, string $checksum): string
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $checksum) !== 1) {
            throw new InvalidArgumentException('A SHA-256 checksum is required for a PHP-FPM candidate.');
        }

        return $this->fpmStateDirectory($phpVersion).'/candidate-'.$checksum.'.conf';
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
        return $this->phpCliIniDirectory().'/environment.sh';
    }

    public function phpCliIniDirectory(): string
    {
        return '/var/lib/vito/php-cli/'.$this->key();
    }

    public function phpCliIniPath(): string
    {
        return $this->phpCliIniDirectory().'/99-vito-site.ini';
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

    private function ensureDomain(string $domain): void
    {
        if (preg_match('/\A[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?\z/i', $domain) !== 1) {
            throw new InvalidArgumentException('A normalized hostname is required for a legacy Nginx artifact.');
        }
    }

    private function ensureUser(string $user): void
    {
        if (preg_match('/\A[a-z_][a-z0-9_-]*[a-z0-9]\z/', $user) !== 1) {
            throw new InvalidArgumentException('A normalized Linux username is required.');
        }
    }
}

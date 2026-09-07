<?php

namespace App\Actions\PHP;

use App\Actions\Site\SyncSiteRuntimeProfiles;
use App\Models\Site;
use App\Models\SiteRuntimeProfile;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

class EnsureSitePhpCli
{
    public function __construct(private readonly SyncSiteRuntimeProfiles $profiles) {}

    public function ensure(Site $site): void
    {
        if (! $site->exists || ! $site->isIsolated() || ! $site->php_version) {
            throw new LogicException('A site PHP CLI requires a persisted isolated PHP site.');
        }

        if (! $site->server->php($site->php_version)) {
            throw new LogicException('The selected PHP version is not installed on the site server.');
        }

        $runtimeSites = $this->runtimeSites($site);

        foreach ($runtimeSites as $runtimeSite) {
            $this->writeSiteRuntime($runtimeSite);
        }

        $artifacts = $site->runtimeArtifacts();
        $sharedUser = $runtimeSites->count() > 1;

        if ($sharedUser) {
            $site->server->ssh()->write(
                $site->homeDirectory().'/bin/php',
                view('ssh.services.php.site-php-cli-wrapper', [
                    'defaultPhpBinary' => '/usr/bin/php'.$site->php_version,
                    'defaultIniScanDirectory' => '/etc/php/'.$site->php_version.'/cli/conf.d',
                    'homeDirectory' => $site->homeDirectory(),
                    'runtimeSites' => $runtimeSites,
                ]),
                'root',
            );
        }

        $site->server->ssh()->exec(
            view('ssh.services.php.activate-site-php-cli', [
                'siteUser' => $site->user,
                'phpBinary' => '/usr/bin/php'.$site->php_version,
                'runtimeDirectory' => $artifacts->phpCliIniDirectory(),
                'profilePath' => $artifacts->phpCliProfilePath(),
                'iniScanDirectory' => '/etc/php/'.$site->php_version.'/cli/conf.d:'.$artifacts->phpCliIniDirectory(),
                'sharedUser' => $sharedUser,
                'homeDirectory' => $site->homeDirectory(),
            ]),
            'activate-site-php-cli',
            $site->id,
        );
    }

    private function writeSiteRuntime(Site $site): void
    {
        if (! $site->php_version) {
            return;
        }

        $this->profiles->sync($site);
        $profile = SiteRuntimeProfile::query()->where('site_id', $site->id)->firstOrFail();
        $artifacts = $site->runtimeArtifacts();

        $site->server->ssh()->exec(
            view('ssh.services.php.prepare-site-php-cli', [
                'siteUser' => $site->user,
                'homeDirectory' => $site->homeDirectory(),
                'runtimeDirectory' => $artifacts->phpCliIniDirectory(),
            ]),
            'prepare-site-php-cli',
            $site->id,
        );
        $site->server->ssh()->write(
            $artifacts->phpCliIniPath(),
            view('ssh.services.php.site-php-cli-ini', [
                'siteUser' => $site->user,
                'homeDirectory' => $site->homeDirectory(),
                'memoryLimitMb' => $profile->memory_limit_mb,
                'maxExecutionTimeSeconds' => $profile->max_execution_time_seconds,
                'maxInputTimeSeconds' => $profile->max_input_time_seconds,
                'maxInputVars' => $profile->max_input_vars,
                'postMaxSizeMb' => $profile->post_max_size_mb,
                'uploadMaxFilesizeMb' => $profile->upload_max_filesize_mb,
                'temporaryPath' => $site->homeDirectory().'/tmp/'.$artifacts->key(),
            ]),
            'root',
        );
        $site->server->ssh()->write(
            $artifacts->phpCliProfilePath(),
            view('ssh.services.php.site-php-cli-profile', [
                'siteId' => $site->id,
                'phpVersion' => $site->php_version,
                'phpBinary' => '/usr/bin/php'.$site->php_version,
                'iniScanDirectory' => '/etc/php/'.$site->php_version.'/cli/conf.d:'.$artifacts->phpCliIniDirectory(),
                'siteBin' => $site->homeDirectory().'/bin',
                'temporaryPath' => $site->homeDirectory().'/tmp/'.$artifacts->key(),
            ]),
            'root',
        );
    }

    /**
     * @return Collection<int, Site>
     */
    private function runtimeSites(Site $site): Collection
    {
        if ($site->isolated_user_id === null) {
            return new Collection([$site]);
        }

        return $site->siblingsSharingUser(includeSelf: true)
            ->whereNotNull('php_version')
            ->orderBy('id')
            ->get();
    }
}

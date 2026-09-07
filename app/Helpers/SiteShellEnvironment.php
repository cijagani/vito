<?php

namespace App\Helpers;

use App\Models\Site;
use App\Tooling\ToolingRegistry;

final class SiteShellEnvironment
{
    /**
     * @return array<string, string>
     */
    public static function collect(Site $site): array
    {
        if (! $site->isolatedUser || $site->user === '' || $site->user === null) {
            return [];
        }

        $paths = ['/home/'.$site->user.'/bin'];
        foreach (ToolingRegistry::all() as $tool) {
            if ($tool->installedVersion($site) === null) {
                continue;
            }
            foreach ($tool->pathContributions($site) as $entry) {
                if ($entry !== '' && ! in_array($entry, $paths, true)) {
                    $paths[] = $entry;
                }
            }
        }

        $base = "/usr/local/bin:/usr/bin:/bin:/home/{$site->user}/.local/bin";

        $environment = [
            'VITO_SITE_ID' => (string) $site->id,
            'PATH' => implode(':', $paths).':'.$base,
        ];

        if ($site->php_version) {
            $artifacts = $site->runtimeArtifacts();
            $environment['PHP_VERSION'] = $site->php_version;
            $environment['PHP_BINARY'] = self::phpBinary($site);
            $environment['PHP_PATH'] = self::phpBinary($site);
            $environment['PHP_INI_SCAN_DIR'] = '/etc/php/'.$site->php_version.'/cli/conf.d:'.$artifacts->phpCliIniDirectory();
            $environment['TMPDIR'] = '/home/'.$site->user.'/tmp/'.$artifacts->key();
        }

        return $environment;
    }

    public static function phpBinary(Site $site): string
    {
        return '/usr/bin/php'.$site->php_version;
    }

    public static function wrap(Site $site, string $command, bool $cdToSitePath = false): string
    {
        $exports = '';
        foreach (self::collect($site) as $key => $value) {
            $exports .= sprintf('export %s=%s && ', $key, escapeshellarg($value));
        }

        $cd = $cdToSitePath && $site->path ? 'cd '.escapeshellarg($site->path).' && ' : '';

        return 'bash -c '.escapeshellarg($exports.$cd.$command);
    }
}

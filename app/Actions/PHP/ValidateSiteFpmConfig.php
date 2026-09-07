<?php

namespace App\Actions\PHP;

use App\Contracts\SiteRuntimeConfigValidator;
use App\DTOs\SiteRuntimeConfig;
use App\Enums\SiteRuntimeConfigType;
use App\Models\Site;
use LogicException;

class ValidateSiteFpmConfig implements SiteRuntimeConfigValidator
{
    public function validate(Site $site, SiteRuntimeConfig $config): void
    {
        if ($config->type !== SiteRuntimeConfigType::PHP_FPM || ! $site->php_version) {
            throw new LogicException('The PHP-FPM validator only accepts PHP-FPM site configuration.');
        }

        $artifacts = $site->runtimeArtifacts();
        $candidatePath = $artifacts->fpmCandidatePath($site->php_version, $config->checksum);
        $site->server->ssh()->write($candidatePath, $config->contents, 'root');
        $site->server->ssh()->exec(
            view('ssh.services.php.validate-site-fpm-config', [
                'candidatePath' => $candidatePath,
                'targetPath' => $config->targetPath,
                'stateDirectory' => $artifacts->fpmStateDirectory($site->php_version),
                'fpmBinary' => '/usr/sbin/php-fpm'.$site->php_version,
            ]),
            'validate-site-fpm-config',
            $site->id,
        );
    }
}

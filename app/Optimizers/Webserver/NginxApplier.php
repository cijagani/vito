<?php

namespace App\Optimizers\Webserver;

use App\Models\OptimizationProposal;
use App\Models\Server;
use App\Optimizers\ServerConfigApplier;
use Illuminate\Support\Collection;

/**
 * Writes nginx settings into a managed drop-in under conf.d.
 *
 * nginx only includes conf.d from inside the http block, so only http-context
 * directives can go there. worker_processes belongs to the main context and
 * worker_connections to events{}; both are edited in nginx.conf itself by
 * NginxContextApplier, which is why they are excluded here rather than written
 * into a file nginx would refuse to load.
 */
class NginxApplier extends ServerConfigApplier
{
    /**
     * @var array<int, string>
     */
    public const array MAIN_CONTEXT_KEYS = [
        'worker_processes',
        'worker_connections',
    ];

    protected function path(Server $server): ?string
    {
        return '/etc/nginx/conf.d/zz-vito-tuning.conf';
    }

    /**
     * @param  Collection<int, OptimizationProposal>  $proposals
     */
    protected function render(Collection $proposals): string
    {
        return parent::render($proposals->reject(
            fn (OptimizationProposal $proposal): bool => in_array(
                $proposal->config_key,
                self::MAIN_CONTEXT_KEYS,
                true
            )
        ));
    }

    protected function assign(string $key, string $value): string
    {
        return "{$key} {$value};";
    }

    protected function validateCommand(Server $server): string
    {
        return "if sudo nginx -t > /dev/null 2>&1; then\n"
            ."    echo 'VITO_CONFIG_OK'\n"
            ."else\n"
            ."    echo 'VITO_SSH_ERROR: nginx rejected the configuration' >&2\n"
            ."    exit 1\n"
            .'fi';
    }
}

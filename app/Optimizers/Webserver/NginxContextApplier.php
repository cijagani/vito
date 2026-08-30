<?php

namespace App\Optimizers\Webserver;

use App\Exceptions\SSHCommandError;
use App\Exceptions\SSHError;
use App\Models\OptimizationPlan;
use App\Models\OptimizationProposal;
use App\Models\Server;
use App\Support\Optimization\ChangeWriter;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Edits the two nginx directives that cannot live in a drop-in.
 *
 * conf.d is included from inside the http block, so worker_processes (main
 * context) and worker_connections (events block) have to be changed in
 * nginx.conf itself. The existing lines are replaced in place rather than the
 * file being regenerated, so everything else the operator or the packager put
 * there survives.
 */
class NginxContextApplier
{
    private const string CONFIG_PATH = '/etc/nginx/nginx.conf';

    public function __construct(private readonly ChangeWriter $writer = new ChangeWriter) {}

    /**
     * @param  Collection<int, OptimizationProposal>  $proposals
     *
     * @throws SSHError
     * @throws Throwable
     */
    public function apply(OptimizationPlan $plan, Collection $proposals): void
    {
        $relevant = $proposals->filter(
            fn (OptimizationProposal $proposal): bool => in_array(
                $proposal->config_key,
                NginxApplier::MAIN_CONTEXT_KEYS,
                true
            )
        );

        if ($relevant->isEmpty()) {
            return;
        }

        $server = $plan->server;
        $existing = $this->read($server);

        if ($existing === null) {
            return;
        }

        $this->writer->write(
            plan: $plan,
            path: self::CONFIG_PATH,
            content: $this->rewrite($existing, $relevant),
            validate: fn () => $this->validate($server),
        );
    }

    /**
     * @param  Collection<int, OptimizationProposal>  $proposals
     */
    private function rewrite(string $existing, Collection $proposals): string
    {
        $content = $existing;

        foreach ($proposals as $proposal) {
            $key = $proposal->config_key;
            $pattern = '/^\s*'.preg_quote($key, '/').'\s+[^;]*;/m';

            // Only replaced when the directive is already present. Appending
            // worker_connections would put it outside events{}, where nginx
            // rejects it -- and a config that fails to load is worse than one
            // left at its default.
            if (preg_match($pattern, $content) !== 1) {
                continue;
            }

            $indent = $key === 'worker_connections' ? '    ' : '';

            $content = preg_replace(
                $pattern,
                $indent.$key.' '.$proposal->proposed_value.';',
                $content,
                1
            );
        }

        return $content;
    }

    /**
     * @throws SSHError
     */
    private function validate(Server $server): void
    {
        $output = $server->ssh()->exec(
            "if sudo nginx -t > /dev/null 2>&1; then\n"
            ."    echo 'VITO_CONFIG_OK'\n"
            ."else\n"
            ."    echo 'VITO_SSH_ERROR: nginx rejected the configuration' >&2\n"
            ."    exit 1\n"
            .'fi',
            'optimization-nginx-context-validate',
        );

        if (! str_contains($output, 'VITO_CONFIG_OK')) {
            throw new SSHCommandError('nginx rejected the configuration.');
        }
    }

    /**
     * @throws SSHError
     */
    private function read(Server $server): ?string
    {
        try {
            $content = $server->os()->readFile(self::CONFIG_PATH);
        } catch (Throwable) {
            return null;
        }

        return $content === '' ? null : $content;
    }
}

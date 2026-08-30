<?php

namespace App\Optimizers\Database;

use App\Exceptions\SSHCommandError;
use App\Exceptions\SSHError;
use App\Models\OptimizationPlan;
use App\Models\OptimizationProposal;
use App\Models\Server;
use App\Services\Database\Postgresql;
use App\Support\Optimization\ChangeWriter;
use Illuminate\Support\Collection;

/**
 * Writes the accepted PostgreSQL settings to a server.
 *
 * Settings go into a managed drop-in under conf.d rather than into
 * postgresql.conf itself. Editing the packaged file in place makes every change
 * hard to see and hard to undo, and puts the tuning in the way of package
 * upgrades; a single owned file is a readable diff and a one-line rollback.
 */
class PostgresApplier
{
    private const string MANAGED_FILE = 'zz-vito-tuning.conf';

    public function __construct(private readonly ChangeWriter $writer = new ChangeWriter) {}

    /**
     * @param  Collection<int, OptimizationProposal>  $proposals
     *
     * @throws SSHError
     */
    public function apply(OptimizationPlan $plan, Collection $proposals): void
    {
        if ($proposals->isEmpty()) {
            return;
        }

        $server = $plan->server;
        $version = $this->version($server);

        if ($version === null) {
            return;
        }

        $path = "/etc/postgresql/{$version}/main/conf.d/".self::MANAGED_FILE;

        $this->writer->write(
            plan: $plan,
            path: $path,
            content: $this->render($proposals),
            validate: fn () => $this->validate($server, $version),
            component: 'postgresql',
        );
    }

    /**
     * @param  Collection<int, OptimizationProposal>  $proposals
     */
    private function render(Collection $proposals): string
    {
        $lines = [
            '# Managed by Vito. Values are derived from this server\'s own resources.',
            '# Edits here are replaced the next time an optimization plan is applied.',
            '',
        ];

        foreach ($proposals as $proposal) {
            $lines[] = "# {$proposal->rationale}";
            $lines[] = "{$proposal->config_key} = {$this->quote($proposal->proposed_value)}";
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Numbers and sizes are written bare; anything else is quoted, since an
     * unquoted string is a syntax error PostgreSQL only reports at startup.
     */
    private function quote(string $value): string
    {
        return preg_match('/^[0-9]+(\.[0-9]+)?([kKmMgG][bB])?$/', $value) === 1
            ? $value
            : "'{$value}'";
    }

    /**
     * Asks PostgreSQL to parse the configuration before it is asked to use it.
     *
     * @throws SSHError
     */
    private function validate(Server $server, string $version): void
    {
        $output = $server->ssh()->exec(
            view('ssh.optimization.postgres-validate', ['version' => $version]),
            'optimization-postgres-validate',
        );

        if (! str_contains($output, 'VITO_CONFIG_OK')) {
            throw new SSHCommandError('PostgreSQL rejected the configuration.');
        }
    }

    /**
     * @throws SSHError
     */
    private function version(Server $server): ?string
    {
        $service = $server->database();

        return $service?->name === Postgresql::id() ? $service->version : null;
    }
}

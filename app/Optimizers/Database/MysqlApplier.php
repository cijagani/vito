<?php

namespace App\Optimizers\Database;

use App\Exceptions\SSHCommandError;
use App\Exceptions\SSHError;
use App\Models\OptimizationPlan;
use App\Models\OptimizationProposal;
use App\Models\Server;
use App\Services\Database\Mariadb;
use App\Support\Optimization\ChangeWriter;
use Illuminate\Support\Collection;

/**
 * Writes the accepted MySQL or MariaDB settings to a server.
 *
 * Both engines read a drop-in directory, so the tuning goes in a file of its own
 * rather than into mysqld.cnf. The directory differs between them, which is the
 * only thing this class needs to know about the difference.
 */
class MysqlApplier
{
    private const string MANAGED_FILE = 'zz-vito-tuning.cnf';

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
        $path = $this->path($server);

        if ($path === null) {
            return;
        }

        $this->writer->write(
            plan: $plan,
            path: $path,
            content: $this->render($proposals),
            validate: fn () => $this->validate($server),
            component: 'mysql',
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
            // Without the section header the server reads the file and applies
            // nothing, silently.
            '[mysqld]',
            '',
        ];

        foreach ($proposals as $proposal) {
            foreach (explode("\n", trim($proposal->rationale)) as $line) {
                $lines[] = trim('# '.$line);
            }

            $lines[] = "{$proposal->config_key} = {$proposal->proposed_value}";
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * @throws SSHError
     */
    private function validate(Server $server): void
    {
        $output = $server->ssh()->exec(
            view('ssh.optimization.mysql-validate'),
            'optimization-mysql-validate',
        );

        if (! str_contains($output, 'VITO_CONFIG_OK')) {
            throw new SSHCommandError('The database rejected the configuration.');
        }
    }

    /**
     * @throws SSHError
     */
    private function path(Server $server): ?string
    {
        $service = $server->database();

        if ($service === null) {
            return null;
        }

        $directory = $service->name === Mariadb::id()
            ? '/etc/mysql/mariadb.conf.d'
            : '/etc/mysql/mysql.conf.d';

        return $directory.'/'.self::MANAGED_FILE;
    }
}

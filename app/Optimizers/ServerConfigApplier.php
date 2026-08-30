<?php

namespace App\Optimizers;

use App\Exceptions\SSHCommandError;
use App\Exceptions\SSHError;
use App\Models\OptimizationPlan;
use App\Models\OptimizationProposal;
use App\Models\Server;
use App\Support\Optimization\ChangeWriter;
use Illuminate\Support\Collection;

/**
 * Writes a component's settings into a single managed file.
 *
 * nginx, sysctl and Redis all work the same way: one owned file in a directory the
 * service already reads, holding every value this component sets. The differences
 * are the path, the comment character and the command that checks the result, so
 * those are all a subclass has to supply.
 */
abstract class ServerConfigApplier
{
    public function __construct(protected readonly ChangeWriter $writer = new ChangeWriter) {}

    abstract protected function path(Server $server): ?string;

    abstract protected function validateCommand(Server $server): string;

    /**
     * Which group this applier writes for, so a rollback knows which proposals to
     * reopen.
     */
    abstract protected function component(): string;

    protected function commentPrefix(): string
    {
        return '#';
    }

    protected function assign(string $key, string $value): string
    {
        return "{$key} = {$value}";
    }

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

        $path = $this->path($plan->server);

        if ($path === null) {
            return;
        }

        $this->writer->write(
            plan: $plan,
            path: $path,
            content: $this->render($proposals),
            validate: fn () => $this->validate($plan->server),
            component: $this->component(),
        );
    }

    /**
     * @param  Collection<int, OptimizationProposal>  $proposals
     */
    protected function render(Collection $proposals): string
    {
        $comment = $this->commentPrefix();

        $lines = [
            "{$comment} Managed by Vito. Values are derived from this server's own resources.",
            "{$comment} Edits here are replaced the next time an optimization plan is applied.",
            '',
        ];

        foreach ($proposals as $proposal) {
            foreach (explode("\n", trim($proposal->rationale)) as $line) {
                $lines[] = trim("{$comment} {$line}");
            }

            $lines[] = $this->assign($proposal->config_key, $proposal->proposed_value);
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * @throws SSHError
     */
    protected function validate(Server $server): void
    {
        // The component, not the class name: a fully qualified name carries
        // backslashes, which do not belong in a log file name.
        $output = $server->ssh()->exec(
            $this->validateCommand($server),
            'optimization-validate-'.$this->component(),
        );

        if (! str_contains($output, 'VITO_CONFIG_OK')) {
            throw new SSHCommandError('The service rejected the configuration.');
        }
    }
}

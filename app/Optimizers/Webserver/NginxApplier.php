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
 * Writes nginx settings.
 *
 * nginx refuses a directive declared twice in the same context, so a value that
 * already exists somewhere cannot simply be restated in a file of our own -- the
 * whole configuration then fails to load. Each directive is therefore changed
 * where it already lives, and only one that exists nowhere is added to a managed
 * drop-in.
 *
 * Editing in place also respects what is already there: the operator's line keeps
 * its comments and its position, where a drop-in would quietly override them from
 * a file nobody thinks to look in.
 */
class NginxApplier
{
    private const string MANAGED_FILE = '/etc/nginx/conf.d/zz-vito-tuning.conf';

    /**
     * Directives nginx accepts only in the main or events context. conf.d is
     * included from inside http, so these can never be added there -- and
     * appending them to nginx.conf blind would land them in the wrong block.
     *
     * @var array<int, string>
     */
    public const array OUTSIDE_HTTP = [
        'worker_processes',
        'worker_connections',
    ];

    public function __construct(private readonly ChangeWriter $writer = new ChangeWriter) {}

    /**
     * @param  Collection<int, OptimizationProposal>  $proposals
     *
     * @throws SSHError
     * @throws Throwable
     */
    public function apply(OptimizationPlan $plan, Collection $proposals): void
    {
        if ($proposals->isEmpty()) {
            return;
        }

        $server = $plan->server;
        $locations = $this->locate($server, $proposals);

        // Grouped by file so each is read, rewritten and backed up once, however
        // many directives it happens to hold.
        $byFile = $proposals
            ->filter(fn (OptimizationProposal $p): bool => isset($locations[$p->config_key]))
            ->groupBy(fn (OptimizationProposal $p): string => $locations[$p->config_key]);

        foreach ($byFile as $path => $inFile) {
            $this->rewriteFile($plan, $server, (string) $path, $inFile);
        }

        $this->addMissing(
            $plan,
            $server,
            $proposals->reject(fn (OptimizationProposal $p): bool => isset($locations[$p->config_key])),
        );
    }

    /**
     * Which file already declares each directive, if any.
     *
     * Asked of the server rather than assumed: a distribution, a control panel and
     * an operator may each have put the same directive somewhere different.
     *
     * @param  Collection<int, OptimizationProposal>  $proposals
     * @return array<string, string>
     *
     * @throws SSHError
     */
    private function locate(Server $server, Collection $proposals): array
    {
        $directives = $proposals
            ->map(fn (OptimizationProposal $p): string => $p->config_key)
            ->unique()
            ->implode(' ');

        $output = $server->ssh()->exec(
            view('ssh.optimization.nginx-locate', ['directives' => $directives]),
            'optimization-nginx-locate',
        );

        $locations = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match('/^([a-z_]+):(.+)$/', trim($line), $matches) === 1 && $matches[2] !== 'none') {
                $locations[$matches[1]] = $matches[2];
            }
        }

        return $locations;
    }

    /**
     * @param  Collection<int, OptimizationProposal>  $proposals
     *
     * @throws SSHError
     * @throws Throwable
     */
    private function rewriteFile(OptimizationPlan $plan, Server $server, string $path, Collection $proposals): void
    {
        $existing = $this->read($server, $path);

        if ($existing === null) {
            return;
        }

        $this->writer->write(
            plan: $plan,
            path: $path,
            content: $this->rewrite($existing, $proposals),
            validate: fn () => $this->validate($server),
            component: 'nginx',
        );
    }

    /**
     * Directives that exist nowhere go into a file of our own -- the only case
     * where declaring one cannot collide with an existing declaration.
     *
     * @param  Collection<int, OptimizationProposal>  $proposals
     *
     * @throws SSHError
     * @throws Throwable
     */
    private function addMissing(OptimizationPlan $plan, Server $server, Collection $proposals): void
    {
        $addable = $proposals->reject(
            fn (OptimizationProposal $p): bool => in_array($p->config_key, self::OUTSIDE_HTTP, true)
        );

        if ($addable->isEmpty()) {
            return;
        }

        $this->writer->write(
            plan: $plan,
            path: self::MANAGED_FILE,
            content: $this->render($addable),
            validate: fn () => $this->validate($server),
            component: 'nginx',
        );
    }

    /**
     * Replaces each directive's existing line and leaves the rest of the file --
     * its comments, its ordering, whatever else it configures -- untouched.
     *
     * @param  Collection<int, OptimizationProposal>  $proposals
     */
    private function rewrite(string $existing, Collection $proposals): string
    {
        $content = $existing;

        foreach ($proposals as $proposal) {
            $key = $proposal->config_key;
            $pattern = '/^([ \t]*)'.preg_quote($key, '/').'[ \t]+[^;]*;/m';

            if (preg_match($pattern, $content, $matches) !== 1) {
                continue;
            }

            // The original indentation is kept: it is what places the line
            // visually inside its block.
            $content = preg_replace(
                $pattern,
                $matches[1].$key.' '.$proposal->proposed_value.';',
                $content,
                1
            );
        }

        return $content;
    }

    /**
     * @param  Collection<int, OptimizationProposal>  $proposals
     */
    private function render(Collection $proposals): string
    {
        $lines = [
            "# Managed by Vito. Values are derived from this server's own resources.",
            '# Only directives not already declared elsewhere appear here; the rest',
            '# are changed where nginx already reads them.',
            '',
        ];

        foreach ($proposals as $proposal) {
            foreach (explode("\n", trim($proposal->rationale)) as $line) {
                $lines[] = trim('# '.$line);
            }

            $lines[] = "{$proposal->config_key} {$proposal->proposed_value};";
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
            view('ssh.optimization.nginx-validate'),
            'optimization-nginx-validate',
        );

        if (! str_contains($output, 'VITO_CONFIG_OK')) {
            throw new SSHCommandError('nginx rejected the configuration.');
        }
    }

    /**
     * @throws SSHError
     */
    private function read(Server $server, string $path): ?string
    {
        try {
            $content = $server->os()->readFile($path);
        } catch (Throwable) {
            return null;
        }

        return $content === '' ? null : $content;
    }
}

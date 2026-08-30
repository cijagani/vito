<?php

namespace App\Optimizers\PHP;

use App\Exceptions\SSHCommandError;
use App\Exceptions\SSHError;
use App\Models\OptimizationPlan;
use App\Models\OptimizationProposal;
use App\Models\Server;
use App\Support\Optimization\ChangeWriter;
use Illuminate\Support\Collection;

/**
 * Writes the accepted PHP-FPM settings to a server.
 *
 * Two kinds of value, and they cannot live in the same place. OPcache is a PHP
 * setting, so it goes in a conf.d drop-in that applies to every pool of that
 * version. Pool sizing is per pool and PHP-FPM only reads pm.* from inside the
 * pool's own section, so those are written into the pool file itself -- backed up
 * first, like anything else this touches.
 */
class FpmApplier
{
    private const string MANAGED_INI = 'zz-vito-tuning.ini';

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

        foreach ($this->phpVersions($server) as $version) {
            $this->applyOpcache($plan, $server, $version, $proposals);
            $this->applyPools($plan, $server, $version, $proposals);
        }
    }

    /**
     * @param  Collection<int, OptimizationProposal>  $proposals
     *
     * @throws SSHError
     */
    private function applyOpcache(
        OptimizationPlan $plan,
        Server $server,
        string $version,
        Collection $proposals,
    ): void {
        $opcache = $proposals->filter(
            fn (OptimizationProposal $proposal): bool => str_starts_with($proposal->config_key, 'opcache.')
        );

        if ($opcache->isEmpty()) {
            return;
        }

        $this->writer->write(
            plan: $plan,
            path: "/etc/php/{$version}/fpm/conf.d/".self::MANAGED_INI,
            content: $this->renderIni($opcache),
            validate: fn () => $this->validate($server, $version),
        );
    }

    /**
     * Pool values arrive keyed as "domain · pm.max_children", because two sites on
     * one server legitimately hold different numbers for the same setting.
     *
     * @param  Collection<int, OptimizationProposal>  $proposals
     *
     * @throws SSHError
     */
    private function applyPools(
        OptimizationPlan $plan,
        Server $server,
        string $version,
        Collection $proposals,
    ): void {
        $byPool = $proposals
            ->filter(fn (OptimizationProposal $proposal): bool => str_contains($proposal->config_key, ' · pm.'))
            ->groupBy(fn (OptimizationProposal $proposal): string => explode(' · ', $proposal->config_key)[0]);

        foreach ($byPool as $domain => $poolProposals) {
            $site = $server->sites->firstWhere('domain', $domain);

            if ($site === null || $site->php_version !== $version) {
                continue;
            }

            $path = "/etc/php/{$version}/fpm/pool.d/{$site->user}.conf";

            $existing = $this->read($server, $path);

            if ($existing === null) {
                continue;
            }

            $this->writer->write(
                plan: $plan,
                path: $path,
                content: $this->rewritePool($existing, $poolProposals),
                validate: fn () => $this->validate($server, $version),
            );
        }
    }

    /**
     * Replaces the pm.* lines in an existing pool file, leaving everything else --
     * the socket, the user, the open_basedir jail -- exactly as it was. Rewriting
     * the whole file from a template would silently discard anything Vito or the
     * operator had put there.
     *
     * @param  Collection<int, OptimizationProposal>  $proposals
     */
    private function rewritePool(string $existing, Collection $proposals): string
    {
        $content = $existing;

        foreach ($proposals as $proposal) {
            $key = explode(' · ', $proposal->config_key)[1];
            $line = "{$key} = {$proposal->proposed_value}";
            $pattern = '/^\s*'.preg_quote($key, '/').'\s*=.*$/m';

            $content = preg_match($pattern, $content) === 1
                ? preg_replace($pattern, $line, $content)
                : rtrim($content)."\n".$line."\n";
        }

        return $content;
    }

    /**
     * @param  Collection<int, OptimizationProposal>  $proposals
     */
    private function renderIni(Collection $proposals): string
    {
        $lines = [
            '; Managed by Vito. Values are derived from this server\'s own resources.',
            '; Edits here are replaced the next time an optimization plan is applied.',
            '',
        ];

        foreach ($proposals as $proposal) {
            $lines[] = '; '.str_replace("\n", "\n; ", trim($proposal->rationale));
            $lines[] = "{$proposal->config_key} = {$proposal->proposed_value}";
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * @throws SSHError
     */
    private function validate(Server $server, string $version): void
    {
        $output = $server->ssh()->exec(
            view('ssh.optimization.php-fpm-validate', ['version' => $version]),
            'optimization-php-fpm-validate',
        );

        if (! str_contains($output, 'VITO_CONFIG_OK')) {
            throw new SSHCommandError('PHP-FPM rejected the configuration.');
        }
    }

    /**
     * @return array<int, string>
     */
    private function phpVersions(Server $server): array
    {
        return $server->services()
            ->where('type', 'php')
            ->pluck('version')
            ->all();
    }

    /**
     * @throws SSHError
     */
    private function read(Server $server, string $path): ?string
    {
        try {
            $content = $server->os()->readFile($path);
        } catch (\Throwable) {
            return null;
        }

        return $content === '' ? null : $content;
    }
}

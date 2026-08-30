<?php

namespace App\Optimizers\Redis;

use App\Exceptions\SSHCommandError;
use App\Exceptions\SSHError;
use App\Models\OptimizationPlan;
use App\Models\OptimizationProposal;
use App\Models\Server;
use App\Support\Optimization\ChangeWriter;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Applies Redis settings.
 *
 * Redis has no include directory, so there is no managed file to own -- editing
 * redis.conf directly is the only option. It is backed up first like anything
 * else, and the running server is changed through CONFIG SET so the values take
 * effect without dropping every connected client.
 */
class RedisApplier
{
    private const string CONFIG_PATH = '/etc/redis/redis.conf';

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
        $existing = $this->read($server);

        if ($existing === null) {
            return;
        }

        $this->writer->write(
            plan: $plan,
            path: self::CONFIG_PATH,
            content: $this->rewrite($existing, $proposals),
            // Redis has no offline config check, so the running server is asked to
            // accept the values instead. A rejected value throws here and the file
            // is restored, exactly as a failed parse would be elsewhere.
            validate: fn () => $this->applyLive($server, $proposals),
            component: 'redis',
        );
    }

    /**
     * Replaces the directives being tuned and leaves the rest of the file alone.
     * Rewriting redis.conf wholesale would discard the bind address, the password
     * and anything else the operator set.
     *
     * @param  Collection<int, OptimizationProposal>  $proposals
     */
    private function rewrite(string $existing, Collection $proposals): string
    {
        $content = $existing;

        foreach ($proposals as $proposal) {
            $key = $proposal->config_key;
            $line = "{$key} {$proposal->proposed_value}";
            $pattern = '/^\s*#?\s*'.preg_quote($key, '/').'\s+.*$/m';

            $content = preg_match($pattern, $content) === 1
                ? preg_replace($pattern, $line, $content, 1)
                : rtrim($content)."\n".$line."\n";
        }

        return $content;
    }

    /**
     * @param  Collection<int, OptimizationProposal>  $proposals
     *
     * @throws SSHError
     */
    private function applyLive(Server $server, Collection $proposals): void
    {
        foreach ($proposals as $proposal) {
            $output = $server->ssh()->exec(
                view('ssh.optimization.redis-config-set', [
                    'key' => $proposal->config_key,
                    'value' => $proposal->proposed_value,
                ]),
                'optimization-redis-config-set',
            );

            if (! str_contains($output, 'VITO_CONFIG_OK')) {
                throw new SSHCommandError("Redis rejected {$proposal->config_key}.");
            }
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

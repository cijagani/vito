<?php

namespace App\Support\Optimization;

use App\Exceptions\SSHError;
use App\Models\OptimizationChange;
use App\Models\OptimizationPlan;
use App\Models\Server;
use Throwable;

/**
 * The only code that changes a configuration file on a server.
 *
 * Everything goes through one path so the guarantees hold everywhere rather than
 * wherever someone remembered them: the original is recorded before anything is
 * written, the result is checked before the service is told to use it, and a
 * configuration that fails its check is put back immediately rather than left for
 * the next reload to discover.
 */
class ChangeWriter
{
    /**
     * Writes content to a managed file, or restores and throws if the service
     * rejects it.
     *
     * @param  callable(): void  $validate  throws when the written config is invalid
     *
     * @throws SSHError
     * @throws Throwable
     */
    public function write(
        OptimizationPlan $plan,
        string $path,
        string $content,
        callable $validate,
        ?string $expectedHash = null,
    ): OptimizationChange {
        $server = $plan->server;

        $existing = $this->read($server, $path);

        // A file edited between drawing the plan and applying it is no longer the
        // file the plan was reasoned about. Overwriting it would silently discard
        // whatever the operator did in the meantime.
        if ($expectedHash !== null && $existing !== null && hash('sha256', $existing) !== $expectedHash) {
            throw new ConfigurationDriftException(
                "[{$path}] has changed on the server since this plan was created."
            );
        }

        $change = $plan->changes()->create([
            'target_path' => $path,
            'action' => $existing === null
                ? OptimizationChange::ACTION_CREATED
                : OptimizationChange::ACTION_MODIFIED,
            'backup_content' => $existing,
            'backup_hash' => $existing === null ? null : hash('sha256', $existing),
        ]);

        $server->os()->write($path, $content, 'root');

        try {
            $validate();
        } catch (Throwable $exception) {
            // Put the server back before surfacing the failure. A broken config
            // left in place is a service that dies at its next restart, long after
            // anyone connects it to this change.
            $this->restore($change);

            throw $exception;
        }

        $change->applied_at = now();
        $change->save();

        return $change;
    }

    /**
     * Puts one file back the way it was found.
     *
     * @throws SSHError
     */
    public function restore(OptimizationChange $change): void
    {
        $server = $change->plan->server;

        if ($change->wasCreated()) {
            // Restoring a file that did not exist means removing it, not writing
            // an empty one -- an empty include is not the same as no include.
            $server->os()->deleteFile($change->target_path);
        } else {
            $server->os()->write($change->target_path, (string) $change->backup_content, 'root');
        }

        $change->reverted_at = now();
        $change->save();
    }

    /**
     * The file's current contents, or null when it does not exist.
     *
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

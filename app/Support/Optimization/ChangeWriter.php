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

        // A file edited since Vito last wrote it is no longer the file any plan was
        // reasoned about, and overwriting it discards that work silently. The
        // caller may name the hash it expects; otherwise the last hash Vito
        // recorded for this path is used, so every applier gets the check without
        // having to remember to ask for it.
        $expectedHash ??= $this->lastAppliedHash($server, $path);

        if ($expectedHash !== null && $existing !== null && self::hash($existing) !== $expectedHash) {
            throw new ConfigurationDriftException(
                "[{$path}] has been edited on the server since Vito last wrote it."
            );
        }

        $change = $plan->changes()->create([
            'target_path' => $path,
            'action' => $existing === null
                ? OptimizationChange::ACTION_CREATED
                : OptimizationChange::ACTION_MODIFIED,
            'backup_content' => $existing,
            'backup_hash' => $existing === null ? null : self::hash($existing),
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

        // Recorded so drift detection can tell later whether anything has edited
        // the file since Vito wrote it.
        $change->applied_hash = self::hash($content);
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
     * Hashes file content for comparison.
     *
     * Reading a file over SSH trims it, so content written with a trailing newline
     * comes back without one. Hashing the raw strings would report every managed
     * file as drifted the moment it was written; both sides are trimmed so the
     * comparison is about the content rather than its whitespace.
     */
    public static function hash(string $content): string
    {
        return hash('sha256', trim($content));
    }

    /**
     * The hash of the last content Vito wrote to this path on this server, or null
     * when Vito has never written it -- in which case there is nothing to compare
     * against and the write proceeds.
     */
    private function lastAppliedHash(Server $server, string $path): ?string
    {
        return OptimizationChange::query()
            ->whereHas('plan', fn ($query) => $query->where('server_id', $server->id))
            ->where('target_path', $path)
            ->whereNotNull('applied_at')
            ->whereNull('reverted_at')
            ->latest('applied_at')
            ->value('applied_hash');
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

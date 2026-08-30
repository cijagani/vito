<?php

namespace App\Optimizers\OS;

use App\Models\Server;
use App\Optimizers\ServerConfigApplier;

/**
 * Writes kernel settings into /etc/sysctl.d.
 *
 * A file there rather than `sysctl -w`, so the values survive a reboot -- a tuned
 * machine that quietly reverts on restart is worse than an untuned one, because
 * nobody looks again.
 */
class KernelApplier extends ServerConfigApplier
{
    protected function path(Server $server): ?string
    {
        return '/etc/sysctl.d/60-vito-tuning.conf';
    }

    protected function validateCommand(Server $server): string
    {
        // --dry-run parses the file and reports what it would set without
        // applying anything, so a typo is caught before it reaches the kernel.
        return "if sudo sysctl -p /etc/sysctl.d/60-vito-tuning.conf --dry-run > /dev/null 2>&1; then\n"
            ."    echo 'VITO_CONFIG_OK'\n"
            ."else\n"
            ."    echo 'VITO_SSH_ERROR: sysctl rejected the configuration' >&2\n"
            ."    exit 1\n"
            .'fi';
    }
}

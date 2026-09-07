<?php

namespace App\Actions\Site;

use App\Models\Site;
use App\Support\SiteStorage;
use LogicException;

class ApplySiteFilesystemQuota
{
    public function apply(Site $site): void
    {
        if (! $site->isIsolated()) {
            return;
        }

        if ($site->userSharedWithSiblings()) {
            throw new LogicException('Filesystem quotas require one Linux user per site.');
        }

        $profile = $site->runtimeProfile()->firstOrFail();
        $site->server->ssh()->exec(
            view('ssh.os.apply-site-filesystem-quota', [
                'siteUser' => $site->user,
                'homeDirectory' => SiteStorage::homeDirectory((string) $site->user),
                'quotaMb' => $profile->disk_quota_mb,
                'storageRoot' => SiteStorage::ROOT,
            ]),
            'apply-site-filesystem-quota',
            $site->id,
        );
    }

    public function remove(Site $site): void
    {
        if (! $site->isIsolated() || $site->userSharedWithSiblings()) {
            return;
        }

        $site->server->ssh()->exec(
            view('ssh.os.apply-site-filesystem-quota', [
                'siteUser' => $site->user,
                'homeDirectory' => SiteStorage::homeDirectory((string) $site->user),
                'quotaMb' => null,
                'storageRoot' => SiteStorage::ROOT,
            ]),
            'remove-site-filesystem-quota',
            $site->id,
        );
    }
}

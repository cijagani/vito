<?php

namespace App\Actions\HostedDomain;

use App\Actions\Site\BroadcastSiteUpdate;
use App\Actions\Site\SyncSiteReservations;
use App\Models\HostedDomain;
use Illuminate\Support\Facades\DB;

class DeleteHostedDomain
{
    public function delete(HostedDomain $hostedDomain): void
    {
        $hostedDomain->ensureModifiable('delete');

        $site = $hostedDomain->site;

        DB::transaction(function () use ($hostedDomain, $site): void {
            $hostedDomain->delete();
            app(SyncSiteReservations::class)->sync($site);
        });

        $site->webserver()->updateVHost($site);

        app(BroadcastSiteUpdate::class)->broadcast($site);
    }
}

<?php

namespace App\Actions\Site;

use App\Exceptions\SSHError;
use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class UpdatePort
{
    /**
     * @param  array<string, mixed>  $input
     *
     * @throws SSHError
     */
    public function update(Site $site, array $input): void
    {
        $validated = Validator::make($input, [
            'port' => ['required', 'integer', 'between:1024,65535'],
        ])->validate();

        DB::transaction(function () use ($site, $validated): void {
            $site->port = (int) $validated['port'];
            $site->save();
            app(SyncSiteReservations::class)->sync($site);
        });

        $site->webserver()->updateVHost($site);

        app(BroadcastSiteUpdate::class)->broadcast($site);
    }
}

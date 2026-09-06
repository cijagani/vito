<?php

namespace App\Actions\Site;

use App\Enums\PortProtocol;
use App\Models\Site;

class SyncSiteReservations
{
    public function __construct(
        private readonly SyncSiteHostnameReservations $hostnames,
        private readonly SyncSitePortReservations $ports,
    ) {}

    public function sync(Site $site): void
    {
        $hostnames = collect([$site->domain])
            ->merge($site->hostedDomains()->orderBy('id')->pluck('domain'))
            ->unique()
            ->values()
            ->all();

        $this->hostnames->sync($site, $hostnames);

        $reservations = $site->port === null
            ? []
            : [[
                'protocol' => PortProtocol::TCP->value,
                'port' => $site->port,
                'purpose' => 'site_proxy',
            ]];

        $this->ports->sync($site, $reservations);
    }
}

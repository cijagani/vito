<?php

namespace App\Actions\Site;

use App\Models\ServerHostnameReservation;
use App\Models\Site;
use App\ValidationRules\DomainRule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;

class SyncSiteHostnameReservations
{
    /**
     * @param  array<int, mixed>  $hostnames
     * @return Collection<int, ServerHostnameReservation>
     */
    public function sync(Site $site, array $hostnames): Collection
    {
        $this->ensurePersistedSite($site);
        $normalized = $this->validate($hostnames);

        try {
            return DB::transaction(function () use ($site, $normalized): Collection {
                $lockedSite = Site::query()->whereKey($site->id)->lockForUpdate()->firstOrFail();
                $conflicts = ServerHostnameReservation::query()
                    ->where('server_id', $lockedSite->server_id)
                    ->where('site_id', '!=', $lockedSite->id)
                    ->whereIn('hostname', $normalized)
                    ->lockForUpdate()
                    ->pluck('hostname');

                if ($conflicts->isNotEmpty()) {
                    throw ValidationException::withMessages([
                        'hostnames' => 'Already reserved on this server: '.$conflicts->sort()->implode(', '),
                    ]);
                }

                ServerHostnameReservation::query()
                    ->where('site_id', $lockedSite->id)
                    ->whereNotIn('hostname', $normalized)
                    ->delete();

                foreach ($normalized as $hostname) {
                    ServerHostnameReservation::query()->updateOrCreate(
                        [
                            'server_id' => $lockedSite->server_id,
                            'hostname' => $hostname,
                        ],
                        ['site_id' => $lockedSite->id]
                    );
                }

                return ServerHostnameReservation::query()
                    ->where('site_id', $lockedSite->id)
                    ->orderBy('hostname')
                    ->get();
            });
        } catch (QueryException $exception) {
            $conflicts = ServerHostnameReservation::query()
                ->where('server_id', $site->server_id)
                ->where('site_id', '!=', $site->id)
                ->whereIn('hostname', $normalized)
                ->pluck('hostname');

            if ($conflicts->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'hostnames' => 'Already reserved on this server: '.$conflicts->sort()->implode(', '),
                ]);
            }

            throw $exception;
        }
    }

    /**
     * @param  array<int, mixed>  $hostnames
     * @return array<int, string>
     */
    private function validate(array $hostnames): array
    {
        $normalized = array_map(
            fn (mixed $hostname): mixed => is_string($hostname) ? strtolower($hostname) : $hostname,
            $hostnames
        );

        Validator::make(['hostnames' => $normalized], [
            'hostnames' => ['required', 'array', 'min:1'],
            'hostnames.*' => ['required', 'string', 'max:253', new DomainRule],
        ])->validate();

        return array_values(array_unique($normalized));
    }

    private function ensurePersistedSite(Site $site): void
    {
        if (! $site->exists || $site->id < 1 || $site->server_id < 1) {
            throw new LogicException('Hostname reservations require a persisted site and server.');
        }
    }
}

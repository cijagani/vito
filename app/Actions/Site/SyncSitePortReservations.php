<?php

namespace App\Actions\Site;

use App\Enums\PortProtocol;
use App\Models\ServerPortReservation;
use App\Models\Site;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;

class SyncSitePortReservations
{
    /**
     * @param  array<int, mixed>  $reservations
     * @return Collection<int, ServerPortReservation>
     */
    public function sync(Site $site, array $reservations): Collection
    {
        $this->ensurePersistedSite($site);
        $validated = $this->validate($reservations);
        $keys = array_map(
            fn (array $reservation): string => $reservation['protocol'].':'.$reservation['port'],
            $validated
        );

        try {
            return DB::transaction(function () use ($site, $validated, $keys): Collection {
                $lockedSite = Site::query()->whereKey($site->id)->lockForUpdate()->firstOrFail();
                $serverReservations = ServerPortReservation::query()
                    ->where('server_id', $lockedSite->server_id)
                    ->where('site_id', '!=', $lockedSite->id)
                    ->lockForUpdate()
                    ->get();

                $conflicts = $serverReservations
                    ->filter(fn (ServerPortReservation $reservation): bool => in_array(
                        $reservation->protocol->value.':'.$reservation->port,
                        $keys,
                        true
                    ));

                if ($conflicts->isNotEmpty()) {
                    throw ValidationException::withMessages([
                        'reservations' => 'Already reserved on this server: '.$conflicts
                            ->map(fn (ServerPortReservation $reservation): string => $reservation->protocol->value.':'.$reservation->port)
                            ->sort()
                            ->implode(', '),
                    ]);
                }

                ServerPortReservation::query()->where('site_id', $lockedSite->id)->delete();

                foreach ($validated as $reservation) {
                    ServerPortReservation::query()->create([
                        'server_id' => $lockedSite->server_id,
                        'site_id' => $lockedSite->id,
                        ...$reservation,
                    ]);
                }

                return ServerPortReservation::query()
                    ->where('site_id', $lockedSite->id)
                    ->orderBy('protocol')
                    ->orderBy('port')
                    ->get();
            });
        } catch (QueryException $exception) {
            $conflicts = ServerPortReservation::query()
                ->where('server_id', $site->server_id)
                ->where('site_id', '!=', $site->id)
                ->get()
                ->filter(fn (ServerPortReservation $reservation): bool => in_array(
                    $reservation->protocol->value.':'.$reservation->port,
                    $keys,
                    true
                ));

            if ($conflicts->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'reservations' => 'One or more ports are already reserved on this server.',
                ]);
            }

            throw $exception;
        }
    }

    /**
     * @param  array<int, mixed>  $reservations
     * @return array<int, array{protocol: string, port: int, purpose: string}>
     */
    private function validate(array $reservations): array
    {
        $normalized = array_map(function (mixed $reservation): mixed {
            if (! is_array($reservation)) {
                return $reservation;
            }

            return [
                'protocol' => isset($reservation['protocol']) && is_string($reservation['protocol'])
                    ? strtolower($reservation['protocol'])
                    : ($reservation['protocol'] ?? null),
                'port' => $reservation['port'] ?? null,
                'purpose' => isset($reservation['purpose']) && is_string($reservation['purpose'])
                    ? strtolower($reservation['purpose'])
                    : ($reservation['purpose'] ?? null),
            ];
        }, $reservations);

        $validator = Validator::make(['reservations' => $normalized], [
            'reservations' => ['array'],
            'reservations.*' => ['array'],
            'reservations.*.protocol' => ['required', Rule::enum(PortProtocol::class)],
            'reservations.*.port' => ['required', 'integer', 'between:1,65535'],
            'reservations.*.purpose' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_-]*$/'],
        ]);

        $validator->after(function ($validator) use ($normalized): void {
            $keys = [];
            foreach ($normalized as $reservation) {
                if (is_array($reservation)) {
                    $keys[] = ($reservation['protocol'] ?? '').':'.($reservation['port'] ?? '');
                }
            }

            if (count($keys) !== count(array_unique($keys))) {
                $validator->errors()->add('reservations', 'A protocol and port may only be reserved once per site.');
            }
        });

        $validated = $validator->validate()['reservations'];

        return array_map(fn (array $reservation): array => [
            'protocol' => $reservation['protocol'],
            'port' => (int) $reservation['port'],
            'purpose' => $reservation['purpose'],
        ], $validated);
    }

    private function ensurePersistedSite(Site $site): void
    {
        if (! $site->exists || $site->id < 1 || $site->server_id < 1) {
            throw new LogicException('Port reservations require a persisted site and server.');
        }
    }
}

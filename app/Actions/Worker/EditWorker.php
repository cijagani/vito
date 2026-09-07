<?php

namespace App\Actions\Worker;

use App\Enums\WorkerStatus;
use App\Jobs\Worker\EditJob;
use App\Models\Site;
use App\Models\Worker;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EditWorker
{
    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function edit(Worker $worker, array $input): Worker
    {
        if ($worker->isSiteBootstrap()) {
            throw ValidationException::withMessages([
                'name' => "This worker is managed by its site. Edit the Start command on the site's Application page instead.",
            ]);
        }

        $site = $this->resolveSite($worker, $input);
        $this->validate($worker, $input, $site);

        $siteId = $worker->site_id;
        if (isset($input['site_id'])) {
            $siteId = ! empty($input['site_id']) ? (int) $input['site_id'] : null;
        }

        $worker->fill([
            'site_id' => $siteId,
            'name' => $input['name'],
            'command' => $input['command'],
            'user' => $input['user'],
            'auto_start' => $input['auto_start'] ? 1 : 0,
            'auto_restart' => $input['auto_restart'] ? 1 : 0,
            'numprocs' => $input['numprocs'],
            'status' => WorkerStatus::RESTARTING,
        ]);
        $worker->error = null;
        $worker->save();

        dispatch(new EditJob($worker))->onQueue('ssh');

        return $worker;
    }

    private function validate(Worker $worker, array $input, ?Site $site = null): void
    {
        $allowedUsers = $site?->isIsolated()
            ? [$site->user]
            : ($site?->getSshUsers() ?? $worker->server->getSshUsers());

        $rules = [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('workers')->where(function ($query) use ($worker, $site) {
                    return $query->where('server_id', $worker->server_id)
                        ->where(function ($query) use ($site) {
                            if ($site) {
                                $query->where('site_id', $site->id);
                            }
                        });
                })
                    ->ignore($worker->id),
            ],
            'command' => [
                'required',
            ],
            'user' => [
                'required',
                Rule::in($allowedUsers),
            ],
            'auto_start' => [
                'required',
                'boolean',
            ],
            'auto_restart' => [
                'required',
                'boolean',
            ],
            'numprocs' => [
                'required',
                'numeric',
                'min:1',
            ],
        ];

        if (isset($input['site_id']) && ! empty($input['site_id'])) {
            $rules['site_id'] = [
                'required',
                'integer',
                Rule::exists('sites', 'id')->where('server_id', $worker->server_id),
            ];
        }

        Validator::make($input, $rules)->validate();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function resolveSite(Worker $worker, array $input): ?Site
    {
        if (! array_key_exists('site_id', $input)) {
            return $worker->site;
        }

        if (empty($input['site_id'])) {
            return null;
        }

        $siteId = filter_var($input['site_id'], FILTER_VALIDATE_INT);
        if ($siteId === false) {
            return null;
        }

        return Site::query()
            ->where('server_id', $worker->server_id)
            ->find($siteId);
    }
}

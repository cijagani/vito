<?php

namespace App\Actions\CronJob;

use App\Enums\CronjobStatus;
use App\Exceptions\SSHError;
use App\Models\CronJob;
use App\Models\Server;
use App\Models\Site;
use App\ValidationRules\CronRule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CreateCronJob
{
    /**
     * @param  array<string, mixed>  $input
     *
     * @throws SSHError
     */
    public function create(Server $server, array $input, ?Site $site = null): CronJob
    {
        $site = $this->resolveSite($server, $input, $site);
        $this->validate($input, $server, $site);

        $siteId = $site?->id;

        $cronJob = new CronJob([
            'name' => $input['name'] ?? null,
            'server_id' => $server->id,
            'site_id' => $siteId,
            'user' => $input['user'],
            'command' => $input['command'],
            'frequency' => $input['frequency'] == 'custom' ? $input['custom'] : $input['frequency'],
            'status' => CronjobStatus::CREATING,
        ]);
        $cronJob->save();

        $server->cron()->update($cronJob->user, CronJob::crontab($server, $cronJob->user));
        $cronJob->status = CronjobStatus::READY;
        $cronJob->save();

        return $cronJob;
    }

    private function validate(array $input, Server $server, ?Site $site = null): void
    {
        $allowedUsers = $site?->isIsolated()
            ? [$site->user]
            : ($site?->getSshUsers() ?? $server->getSshUsers());

        $rules = [
            'command' => [
                'required',
            ],
            'user' => [
                'required',
                Rule::in($allowedUsers),
            ],
            'frequency' => [
                'required',
                new CronRule(acceptCustom: true),
            ],
            'name' => [
                'nullable',
                'string',
                'max:255',
            ],
        ];

        // Add site_id validation if provided in input
        if (isset($input['site_id']) && ! empty($input['site_id'])) {
            $rules['site_id'] = [
                'required',
                'integer',
                Rule::exists('sites', 'id')->where('server_id', $server->id),
            ];
        }

        if (isset($input['frequency']) && $input['frequency'] == 'custom') {
            $rules['custom'] = [
                'required',
                new CronRule,
            ];
        }

        Validator::make($input, $rules)->validate();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function resolveSite(Server $server, array $input, ?Site $site): ?Site
    {
        if ($site instanceof Site || empty($input['site_id'])) {
            return $site;
        }

        $siteId = filter_var($input['site_id'], FILTER_VALIDATE_INT);
        if ($siteId === false) {
            return null;
        }

        return Site::query()
            ->where('server_id', $server->id)
            ->find($siteId);
    }
}

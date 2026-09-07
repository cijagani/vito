<?php

namespace App\Actions\Site;

use App\Enums\FpmProcessManager;
use App\Enums\FpmServiceMode;
use App\Exceptions\SSHError;
use App\Models\Site;
use App\Models\SiteRuntimeProfile;
use App\Models\SiteWebProfile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class UpdatePHPSettings
{
    /**
     * @param  array<string, mixed>  $input
     *
     * @throws SSHError
     */
    public function update(Site $site, array $input): void
    {
        app(SyncSiteRuntimeProfiles::class)->sync($site);
        $validated = $this->validate($site, $input);

        $runtime = $site->runtimeProfile()->firstOrFail();
        $web = $site->webProfile()->firstOrFail();

        $typeData = $site->type_data ?? [];
        $previousTypeData = $typeData;
        $previousRuntime = $runtime->getAttributes();
        $previousWeb = $web->getAttributes();
        $typeData['php'] = Arr::only($validated, [
            'max_upload_size',
            'max_execution_time',
            'memory_limit',
            'max_input_vars',
        ]);

        DB::transaction(function () use ($site, $typeData, $runtime, $web, $validated): void {
            $site->update(['type_data' => $typeData]);
            app(SyncSiteRuntimeProfiles::class)->sync($site);

            if ($site->isIsolated()) {
                $runtime->refresh();
                $runtime->fill(Arr::only($validated, [
                    'fpm_process_manager',
                    'fpm_max_children',
                    'fpm_start_servers',
                    'fpm_min_spare_servers',
                    'fpm_max_spare_servers',
                    'fpm_idle_timeout_seconds',
                    'fpm_max_requests',
                    'request_timeout_seconds',
                    'slow_request_seconds',
                    'cpu_quota_percent',
                    'memory_high_mb',
                    'memory_max_mb',
                    'tasks_max',
                ]));
                $this->saveWithRevision($runtime);
            }

            $web->refresh();
            $webFields = $site->isIsolated() ? [
                'client_max_body_size_mb',
                'fastcgi_read_timeout_seconds',
                'proxy_connect_timeout_seconds',
                'proxy_read_timeout_seconds',
                'static_cache_policy',
                'rate_limit_profile',
                'access_log_enabled',
            ] : [
                'client_max_body_size_mb',
                'fastcgi_read_timeout_seconds',
            ];
            $web->fill(Arr::only($validated, $webFields));
            $this->saveWithRevision($web);
        });

        try {
            $site->webserver()->updateVHost($site);
            if ($site->isIsolated()) {
                app(RefreshSiteRuntimeConsumers::class)->refresh($site);
            }
        } catch (Throwable $exception) {
            DB::transaction(function () use ($site, $previousTypeData, $runtime, $web, $previousRuntime, $previousWeb): void {
                $site->update(['type_data' => $previousTypeData]);
                if ($site->isIsolated()) {
                    $runtime->setRawAttributes($previousRuntime);
                    $runtime->save();
                }
                $web->setRawAttributes($previousWeb);
                $web->save();
            });

            if ($site->isIsolated()) {
                try {
                    $site->webserver()->updateVHost($site);
                    app(RefreshSiteRuntimeConsumers::class)->refresh($site);
                } catch (Throwable $rollbackException) {
                    Log::error('PHP settings rollback requires manual repair', [
                        'site_id' => $site->id,
                        'server_id' => $site->server_id,
                        'exception' => $rollbackException->getMessage(),
                    ]);
                }
            }

            throw $exception;
        }

        app(BroadcastSiteUpdate::class)->broadcast($site);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, int|string|bool|null>
     */
    private function validate(Site $site, array $input): array
    {
        $validator = Validator::make($input, [
            'max_upload_size' => ['nullable', 'integer', 'min:1', 'max:10240'],
            'max_execution_time' => ['nullable', 'integer', 'min:1', 'max:3600'],
            'memory_limit' => ['nullable', 'integer', 'min:16', 'max:8192'],
            'max_input_vars' => ['nullable', 'integer', 'min:100', 'max:100000'],
            'fpm_process_manager' => ['sometimes', Rule::enum(FpmProcessManager::class)],
            'fpm_max_children' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'fpm_start_servers' => ['nullable', 'integer', 'min:1', 'max:500'],
            'fpm_min_spare_servers' => ['nullable', 'integer', 'min:1', 'max:500'],
            'fpm_max_spare_servers' => ['nullable', 'integer', 'min:1', 'max:500'],
            'fpm_idle_timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:3600'],
            'fpm_max_requests' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'request_timeout_seconds' => ['sometimes', 'integer', 'min:1', 'max:3600'],
            'slow_request_seconds' => ['nullable', 'integer', 'min:1', 'max:3600'],
            'cpu_quota_percent' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'memory_high_mb' => ['nullable', 'integer', 'min:1', 'max:1048576'],
            'memory_max_mb' => ['nullable', 'integer', 'min:1', 'max:1048576'],
            'tasks_max' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'client_max_body_size_mb' => ['nullable', 'integer', 'min:1', 'max:10240'],
            'fastcgi_read_timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:3600'],
            'proxy_connect_timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:600'],
            'proxy_read_timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:3600'],
            'static_cache_policy' => ['sometimes', Rule::in(['disabled', 'default', 'aggressive'])],
            'rate_limit_profile' => ['nullable', Rule::in(['standard', 'strict'])],
            'access_log_enabled' => ['sometimes', 'boolean'],
        ]);

        $validator->after(function ($validator) use ($site, $input): void {
            if ($validator->errors()->hasAny(['max_upload_size', 'memory_limit'])) {
                return;
            }

            $upload = $input['max_upload_size'] ?? null;
            $memory = $input['memory_limit'] ?? null;

            if (is_numeric($upload) && is_numeric($memory) && (int) $memory < (int) $upload) {
                $validator->errors()->add(
                    'memory_limit',
                    'The memory limit must be greater than or equal to the max upload size.'
                );
            }

            $profile = SiteRuntimeProfile::query()->where('site_id', $site->id)->firstOrFail();
            $manager = $input['fpm_process_manager'] ?? $profile->fpm_process_manager->value;
            $maxChildren = (int) ($input['fpm_max_children'] ?? $profile->fpm_max_children);

            if ($manager === FpmProcessManager::DYNAMIC->value) {
                $start = $this->intOrNull($input['fpm_start_servers'] ?? $profile->fpm_start_servers);
                $min = $this->intOrNull($input['fpm_min_spare_servers'] ?? $profile->fpm_min_spare_servers);
                $max = $this->intOrNull($input['fpm_max_spare_servers'] ?? $profile->fpm_max_spare_servers);
                if ($start === null || $min === null || $max === null || $min > $start || $start > $max || $max > $maxChildren) {
                    $validator->errors()->add(
                        'fpm_start_servers',
                        'Dynamic FPM requires min spare <= start <= max spare <= max children.'
                    );
                }
            }

            $requestTimeout = (int) ($input['request_timeout_seconds'] ?? $profile->request_timeout_seconds);
            $slowTimeout = $this->intOrNull($input['slow_request_seconds'] ?? $profile->slow_request_seconds);
            if ($slowTimeout !== null && $slowTimeout >= $requestTimeout) {
                $validator->errors()->add('slow_request_seconds', 'The slow request threshold must be below the request timeout.');
            }

            $memoryHigh = $this->intOrNull($input['memory_high_mb'] ?? $profile->memory_high_mb);
            $memoryMax = $this->intOrNull($input['memory_max_mb'] ?? $profile->memory_max_mb);
            if ($memoryHigh !== null && $memoryMax !== null && $memoryHigh > $memoryMax) {
                $validator->errors()->add('memory_high_mb', 'Memory high must be less than or equal to memory max.');
            }

            if ($profile->fpm_service_mode !== FpmServiceMode::DEDICATED_MASTER) {
                foreach (['cpu_quota_percent', 'memory_high_mb', 'memory_max_mb', 'tasks_max'] as $field) {
                    if (array_key_exists($field, $input) && $input[$field] !== null && $input[$field] !== '') {
                        $validator->errors()->add($field, 'Resource limits require a dedicated PHP-FPM service.');
                    }
                }
            }

            if ($site->webserver()->id() !== 'nginx' && ! empty($input['rate_limit_profile'])) {
                $validator->errors()->add('rate_limit_profile', 'Rate-limit profiles are currently supported only by Nginx.');
            }
        });

        $validated = $validator->validate();

        $runtime = SiteRuntimeProfile::query()->where('site_id', $site->id)->firstOrFail();
        $web = SiteWebProfile::query()->where('site_id', $site->id)->firstOrFail();
        $manager = (string) ($validated['fpm_process_manager'] ?? $runtime->fpm_process_manager->value);

        return [
            'max_upload_size' => $this->intOrNull($validated['max_upload_size'] ?? null),
            'max_execution_time' => $this->intOrNull($validated['max_execution_time'] ?? null),
            'memory_limit' => $this->intOrNull($validated['memory_limit'] ?? null),
            'max_input_vars' => $this->intOrNull($validated['max_input_vars'] ?? null),
            'fpm_process_manager' => $manager,
            'fpm_max_children' => (int) ($validated['fpm_max_children'] ?? $runtime->fpm_max_children),
            'fpm_start_servers' => $manager === FpmProcessManager::DYNAMIC->value
                ? $this->intOrNull($validated['fpm_start_servers'] ?? $runtime->fpm_start_servers)
                : null,
            'fpm_min_spare_servers' => $manager === FpmProcessManager::DYNAMIC->value
                ? $this->intOrNull($validated['fpm_min_spare_servers'] ?? $runtime->fpm_min_spare_servers)
                : null,
            'fpm_max_spare_servers' => $manager === FpmProcessManager::DYNAMIC->value
                ? $this->intOrNull($validated['fpm_max_spare_servers'] ?? $runtime->fpm_max_spare_servers)
                : null,
            'fpm_idle_timeout_seconds' => $manager === FpmProcessManager::ONDEMAND->value
                ? $this->intOrNull($validated['fpm_idle_timeout_seconds'] ?? $runtime->fpm_idle_timeout_seconds ?? 10)
                : null,
            'fpm_max_requests' => (int) ($validated['fpm_max_requests'] ?? $runtime->fpm_max_requests),
            'request_timeout_seconds' => (int) ($validated['request_timeout_seconds'] ?? $runtime->request_timeout_seconds),
            'slow_request_seconds' => array_key_exists('slow_request_seconds', $validated)
                ? $this->intOrNull($validated['slow_request_seconds'])
                : $runtime->slow_request_seconds,
            'cpu_quota_percent' => array_key_exists('cpu_quota_percent', $validated)
                ? $this->intOrNull($validated['cpu_quota_percent'])
                : $runtime->cpu_quota_percent,
            'memory_high_mb' => array_key_exists('memory_high_mb', $validated)
                ? $this->intOrNull($validated['memory_high_mb'])
                : $runtime->memory_high_mb,
            'memory_max_mb' => array_key_exists('memory_max_mb', $validated)
                ? $this->intOrNull($validated['memory_max_mb'])
                : $runtime->memory_max_mb,
            'tasks_max' => array_key_exists('tasks_max', $validated)
                ? $this->intOrNull($validated['tasks_max'])
                : $runtime->tasks_max,
            'client_max_body_size_mb' => array_key_exists('client_max_body_size_mb', $validated)
                ? $this->intOrNull($validated['client_max_body_size_mb'])
                : $this->intOrNull($validated['max_upload_size'] ?? $web->client_max_body_size_mb),
            'fastcgi_read_timeout_seconds' => array_key_exists('fastcgi_read_timeout_seconds', $validated)
                ? $this->intOrNull($validated['fastcgi_read_timeout_seconds'])
                : $this->intOrNull($validated['max_execution_time'] ?? $web->fastcgi_read_timeout_seconds),
            'proxy_connect_timeout_seconds' => array_key_exists('proxy_connect_timeout_seconds', $validated)
                ? $this->intOrNull($validated['proxy_connect_timeout_seconds'])
                : $web->proxy_connect_timeout_seconds,
            'proxy_read_timeout_seconds' => array_key_exists('proxy_read_timeout_seconds', $validated)
                ? $this->intOrNull($validated['proxy_read_timeout_seconds'])
                : $web->proxy_read_timeout_seconds,
            'static_cache_policy' => (string) ($validated['static_cache_policy'] ?? $web->static_cache_policy),
            'rate_limit_profile' => array_key_exists('rate_limit_profile', $validated)
                ? ($validated['rate_limit_profile'] === null ? null : (string) $validated['rate_limit_profile'])
                : $web->rate_limit_profile,
            'access_log_enabled' => (bool) ($validated['access_log_enabled'] ?? $web->access_log_enabled),
        ];
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function saveWithRevision(SiteRuntimeProfile|SiteWebProfile $profile): void
    {
        if (! $profile->isDirty()) {
            return;
        }

        $profile->desired_revision++;
        $profile->save();
    }
}

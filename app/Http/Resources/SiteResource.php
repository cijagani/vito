<?php

namespace App\Http\Resources;

use App\Enums\SiteIsolationProfile;
use App\Models\Site;
use App\Models\SiteRuntimeProfile;
use App\SiteTypes\AbstractProxiedSiteType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Site */
class SiteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'server_id' => $this->server_id,
            'server' => new ServerResource($this->whenLoaded('server')),
            'source_control_id' => $this->source_control_id,
            'type' => $this->type,
            'type_data' => $this->sanitisedTypeData(),
            'basic_auth' => [
                'enabled' => (bool) data_get($this->type_data, 'basic_auth.enabled', false),
                'users' => array_map(
                    fn (array $u) => ['username' => $u['username'] ?? ''],
                    array_values(data_get($this->type_data, 'basic_auth.users', []))
                ),
            ],
            'domain' => $this->domain,
            'web_directory' => $this->web_directory,
            'webserver' => $this->webserver()->id(),
            'webserver_creates_site_ssls' => $this->webserver()->createsSiteSSLs(),
            'path' => $this->path,
            'php_version' => $this->php_version,
            'php_settings' => $this->phpSettings(),
            'runtime_tuning' => $this->runtimeTuning(),
            'supports_php_settings' => $this->supportsPhpSettings(),
            'repository' => $this->repository,
            'branch' => $this->branch,
            'status' => $this->status->getText(),
            'status_color' => $this->status->getColor(),
            'auto_deploy' => $this->isAutoDeployment(),
            'port' => $this->port,
            'user' => $this->user,
            'isolated_user_id' => $this->isolated_user_id,
            'url' => $this->getUrl(),
            'force_ssl' => $this->force_ssl,
            'ssl_enabled' => $this->ssl_enabled,
            'progress' => $this->progress,
            'progress_step' => $this->progress_step,
            'last_error' => $this->last_error,
            'features' => $this->features(),
            'can_configure_ssl' => $this->webserver()->canConfigureSSL(),
            'webserver_allowed_ssl_methods' => $this->webserver()->allowedSslMethods(),
            'webserver_default_ssl_method' => $this->webserver()->defaultSslMethod()->value,
            'vhost_generation_enabled' => $this->vhost_generation_enabled,
            'has_custom_vhost_template' => $this->vhost_template !== null,
            'modern_deployment' => $this->modernDeploymentEnabled(),
            'stats_enabled' => $this->statsEnabled(),
            'is_proxied_site_type' => $this->type() instanceof AbstractProxiedSiteType,
            'available_tooling_commands' => $this->availableToolingCommands(),
            'start_command' => $this->type_data['start_command'] ?? null,
            'bootstrap_worker_id' => isset($this->type_data['bootstrap_worker_id'])
                ? (int) $this->type_data['bootstrap_worker_id']
                : null,
            'warnings' => $this->getWarnings(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * Strip basic-auth password hashes from type_data before sending to the client.
     *
     * @return array<string, mixed>
     */
    private function sanitisedTypeData(): array
    {
        $typeData = $this->type_data ?? [];

        unset($typeData['php']);

        if (isset($typeData['basic_auth']['users']) && is_array($typeData['basic_auth']['users'])) {
            $typeData['basic_auth']['users'] = array_map(
                fn (array $u) => ['username' => $u['username'] ?? ''],
                $typeData['basic_auth']['users']
            );
        }

        return $typeData;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function runtimeTuning(): ?array
    {
        if (! $this->isIsolated() || ! $this->php_version || $this->type()->language() !== 'php') {
            return null;
        }

        if (! $this->relationLoaded('runtimeProfile') || ! $this->relationLoaded('webProfile')) {
            return null;
        }

        $runtime = $this->runtimeProfile;
        $web = $this->webProfile;
        if ($runtime === null || $web === null) {
            return null;
        }

        $memoryPerWorkerMb = $runtime->memory_limit_mb ?? 128;
        $estimatedMemoryMb = $runtime->fpm_max_children * $memoryPerWorkerMb;
        $aggregateMemoryMb = SiteRuntimeProfile::query()
            ->whereHas('site', fn ($query) => $query
                ->where('server_id', $this->server_id)
                ->whereNotNull('php_version'))
            ->where('isolation_profile', '!=', SiteIsolationProfile::LEGACY_UNISOLATED->value)
            ->get(['fpm_max_children', 'memory_limit_mb'])
            ->sum(fn (SiteRuntimeProfile $profile): int => $profile->fpm_max_children * ($profile->memory_limit_mb ?? 128));
        $memoryTotalKb = $this->server->latestMetric?->memory_total;
        $serverMemoryMb = is_numeric($memoryTotalKb) ? (int) floor((float) $memoryTotalKb / 1024) : null;
        $metric = $this->relationLoaded('latestRuntimeMetric') ? $this->latestRuntimeMetric : null;

        return [
            'isolation_profile' => $runtime->isolation_profile->value,
            'fpm_service_mode' => $runtime->fpm_service_mode->value,
            'fpm_process_manager' => $runtime->fpm_process_manager->value,
            'fpm_max_children' => $runtime->fpm_max_children,
            'fpm_start_servers' => $runtime->fpm_start_servers,
            'fpm_min_spare_servers' => $runtime->fpm_min_spare_servers,
            'fpm_max_spare_servers' => $runtime->fpm_max_spare_servers,
            'fpm_idle_timeout_seconds' => $runtime->fpm_idle_timeout_seconds,
            'fpm_max_requests' => $runtime->fpm_max_requests,
            'request_timeout_seconds' => $runtime->request_timeout_seconds,
            'slow_request_seconds' => $runtime->slow_request_seconds,
            'cpu_quota_percent' => $runtime->cpu_quota_percent,
            'memory_high_mb' => $runtime->memory_high_mb,
            'memory_max_mb' => $runtime->memory_max_mb,
            'tasks_max' => $runtime->tasks_max,
            'disk_quota_mb' => $runtime->disk_quota_mb,
            'client_max_body_size_mb' => $web->client_max_body_size_mb,
            'fastcgi_read_timeout_seconds' => $web->fastcgi_read_timeout_seconds,
            'proxy_connect_timeout_seconds' => $web->proxy_connect_timeout_seconds,
            'proxy_read_timeout_seconds' => $web->proxy_read_timeout_seconds,
            'static_cache_policy' => $web->static_cache_policy,
            'rate_limit_profile' => $web->rate_limit_profile,
            'access_log_enabled' => $web->access_log_enabled,
            'effective_socket' => $this->runtimeArtifacts()->fpmSocketPath($this->php_version),
            'estimated_fpm_memory_mb' => $estimatedMemoryMb,
            'aggregate_fpm_memory_mb' => $aggregateMemoryMb,
            'server_memory_mb' => $serverMemoryMb,
            'capacity_warning' => $serverMemoryMb !== null && $aggregateMemoryMb > (int) floor($serverMemoryMb * 0.8),
            'runtime_drifted' => $runtime->needsApply() || $runtime->applied_checksum === null,
            'web_drifted' => $web->needsApply() || $web->applied_checksum === null,
            'runtime_applied_revision' => $runtime->applied_revision,
            'web_applied_revision' => $web->applied_revision,
            'runtime_last_applied_at' => $runtime->last_applied_at,
            'web_last_applied_at' => $web->last_applied_at,
            'observed_memory_current_mb' => $metric?->memory_current_bytes !== null
                ? round($metric->memory_current_bytes / 1024 / 1024, 2)
                : null,
            'observed_memory_peak_mb' => $metric?->memory_peak_bytes !== null
                ? round($metric->memory_peak_bytes / 1024 / 1024, 2)
                : null,
            'observed_tasks_current' => $metric?->tasks_current,
            'observed_oom_kill_count' => $metric?->oom_kill_count,
            'observed_at' => $metric?->created_at,
        ];
    }
}

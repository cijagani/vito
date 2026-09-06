<?php

use App\Enums\FpmProcessManager;
use App\Enums\FpmServiceMode;
use App\Enums\SiteIsolationProfile;
use App\Enums\SiteRuntimeOperationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->unique(['id', 'server_id'], 'sites_id_server_unique');
        });

        Schema::create('site_runtime_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('isolation_profile')->default(SiteIsolationProfile::LEGACY_UNISOLATED->value);
            $table->string('php_version')->nullable();
            $table->string('fpm_service_mode')->default(FpmServiceMode::SHARED_MASTER->value);
            $table->string('fpm_process_manager')->default(FpmProcessManager::DYNAMIC->value);
            $table->unsignedInteger('fpm_max_children')->default(5);
            $table->unsignedInteger('fpm_start_servers')->nullable()->default(2);
            $table->unsignedInteger('fpm_min_spare_servers')->nullable()->default(1);
            $table->unsignedInteger('fpm_max_spare_servers')->nullable()->default(3);
            $table->unsignedInteger('fpm_idle_timeout_seconds')->nullable();
            $table->unsignedInteger('fpm_max_requests')->default(500);
            $table->unsignedInteger('request_timeout_seconds')->default(60);
            $table->unsignedInteger('slow_request_seconds')->nullable();
            $table->unsignedInteger('memory_limit_mb')->nullable();
            $table->unsignedInteger('max_execution_time_seconds')->nullable();
            $table->unsignedInteger('max_input_time_seconds')->nullable();
            $table->unsignedInteger('max_input_vars')->nullable();
            $table->unsignedInteger('post_max_size_mb')->nullable();
            $table->unsignedInteger('upload_max_filesize_mb')->nullable();
            $table->unsignedInteger('cpu_quota_percent')->nullable();
            $table->unsignedInteger('memory_high_mb')->nullable();
            $table->unsignedInteger('memory_max_mb')->nullable();
            $table->unsignedInteger('tasks_max')->nullable();
            $table->unsignedBigInteger('disk_quota_mb')->nullable();
            $table->unsignedBigInteger('desired_revision')->default(1);
            $table->unsignedBigInteger('applied_revision')->nullable();
            $table->timestamp('last_applied_at')->nullable();
            $table->text('last_apply_error')->nullable();
            $table->timestamps();

            $table->index('isolation_profile');
        });

        Schema::create('site_web_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('client_max_body_size_mb')->nullable();
            $table->unsignedInteger('fastcgi_read_timeout_seconds')->nullable();
            $table->unsignedInteger('proxy_connect_timeout_seconds')->nullable();
            $table->unsignedInteger('proxy_read_timeout_seconds')->nullable();
            $table->string('static_cache_policy')->default('default');
            $table->string('rate_limit_profile')->nullable();
            $table->string('symlink_policy')->default('legacy');
            $table->boolean('access_log_enabled')->default(true);
            $table->unsignedBigInteger('desired_revision')->default(1);
            $table->unsignedBigInteger('applied_revision')->nullable();
            $table->timestamp('last_applied_at')->nullable();
            $table->text('last_apply_error')->nullable();
            $table->timestamps();
        });

        Schema::create('server_hostname_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('site_id');
            $table->string('hostname', 253);
            $table->timestamps();

            $table->unique(['server_id', 'hostname'], 'server_hostname_unique');
            $table->index(['site_id', 'hostname']);
            $table->foreign(['site_id', 'server_id'], 'hostname_site_server_foreign')
                ->references(['id', 'server_id'])
                ->on('sites')
                ->cascadeOnDelete();
        });

        Schema::create('server_port_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('site_id');
            $table->string('protocol', 8);
            $table->unsignedInteger('port');
            $table->string('purpose', 64);
            $table->timestamps();

            $table->unique(['server_id', 'protocol', 'port'], 'server_protocol_port_unique');
            $table->index(['site_id', 'purpose']);
            $table->foreign(['site_id', 'server_id'], 'port_site_server_foreign')
                ->references(['id', 'server_id'])
                ->on('sites')
                ->cascadeOnDelete();
        });

        Schema::create('site_runtime_operations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('server_id');
            $table->unsignedBigInteger('site_id');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type');
            $table->string('status')->default(SiteRuntimeOperationStatus::PENDING->value);
            $table->string('target_path', 1024);
            $table->unsignedBigInteger('desired_revision');
            $table->string('desired_checksum', 64)->nullable();
            $table->string('previous_checksum', 64)->nullable();
            $table->text('metadata')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'type', 'status'], 'site_runtime_operation_lookup');
            $table->index(['server_id', 'created_at']);
        });

        $this->backfillProfiles();
        $this->backfillReservations();
    }

    public function down(): void
    {
        Schema::dropIfExists('site_runtime_operations');
        Schema::dropIfExists('server_port_reservations');
        Schema::dropIfExists('server_hostname_reservations');
        Schema::dropIfExists('site_web_profiles');
        Schema::dropIfExists('site_runtime_profiles');
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropUnique('sites_id_server_unique');
        });
    }

    private function backfillProfiles(): void
    {
        $sharedUserCounts = DB::table('sites')
            ->whereNotNull('isolated_user_id')
            ->selectRaw('isolated_user_id, COUNT(*) as aggregate')
            ->groupBy('isolated_user_id')
            ->pluck('aggregate', 'isolated_user_id');

        DB::table('sites')
            ->orderBy('id')
            ->chunkById(250, function ($sites) use ($sharedUserCounts): void {
                $runtimeProfiles = [];
                $webProfiles = [];

                foreach ($sites as $site) {
                    $php = $this->phpSettings($site->type_data);
                    $now = Carbon::now();

                    $runtimeProfiles[] = [
                        'site_id' => $site->id,
                        'isolation_profile' => $this->isolationProfile(
                            $site->isolated_user_id,
                            $sharedUserCounts
                        )->value,
                        'php_version' => $site->php_version,
                        'fpm_service_mode' => FpmServiceMode::SHARED_MASTER->value,
                        'fpm_process_manager' => FpmProcessManager::DYNAMIC->value,
                        'fpm_max_children' => 5,
                        'fpm_start_servers' => 2,
                        'fpm_min_spare_servers' => 1,
                        'fpm_max_spare_servers' => 3,
                        'fpm_idle_timeout_seconds' => null,
                        'fpm_max_requests' => 500,
                        'request_timeout_seconds' => $php['max_execution_time'] ?? 60,
                        'slow_request_seconds' => null,
                        'memory_limit_mb' => $php['memory_limit'] ?? null,
                        'max_execution_time_seconds' => $php['max_execution_time'] ?? null,
                        'max_input_time_seconds' => null,
                        'max_input_vars' => $php['max_input_vars'] ?? null,
                        'post_max_size_mb' => $php['max_upload_size'] ?? null,
                        'upload_max_filesize_mb' => $php['max_upload_size'] ?? null,
                        'cpu_quota_percent' => null,
                        'memory_high_mb' => null,
                        'memory_max_mb' => null,
                        'tasks_max' => null,
                        'disk_quota_mb' => null,
                        'desired_revision' => 1,
                        'applied_revision' => null,
                        'last_applied_at' => null,
                        'last_apply_error' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    $webProfiles[] = [
                        'site_id' => $site->id,
                        'client_max_body_size_mb' => $php['max_upload_size'] ?? null,
                        'fastcgi_read_timeout_seconds' => $php['max_execution_time'] ?? null,
                        'proxy_connect_timeout_seconds' => null,
                        'proxy_read_timeout_seconds' => null,
                        'static_cache_policy' => 'default',
                        'rate_limit_profile' => null,
                        'symlink_policy' => 'legacy',
                        'access_log_enabled' => true,
                        'desired_revision' => 1,
                        'applied_revision' => null,
                        'last_applied_at' => null,
                        'last_apply_error' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($runtimeProfiles !== []) {
                    DB::table('site_runtime_profiles')->insert($runtimeProfiles);
                    DB::table('site_web_profiles')->insert($webProfiles);
                }
            });
    }

    private function isolationProfile(?int $isolatedUserId, mixed $sharedUserCounts): SiteIsolationProfile
    {
        if ($isolatedUserId === null) {
            return SiteIsolationProfile::LEGACY_UNISOLATED;
        }

        return (int) ($sharedUserCounts[$isolatedUserId] ?? 0) > 1
            ? SiteIsolationProfile::SHARED
            : SiteIsolationProfile::ISOLATED;
    }

    private function backfillReservations(): void
    {
        DB::table('hosted_domains')
            ->join('sites', 'sites.id', '=', 'hosted_domains.site_id')
            ->orderBy('hosted_domains.id')
            ->select(['sites.server_id', 'sites.id as site_id', 'hosted_domains.domain'])
            ->chunk(250, function ($domains): void {
                $now = Carbon::now();
                $rows = [];

                foreach ($domains as $domain) {
                    $rows[] = [
                        'server_id' => $domain->server_id,
                        'site_id' => $domain->site_id,
                        'hostname' => strtolower($domain->domain),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                DB::table('server_hostname_reservations')->insertOrIgnore($rows);
            });

        DB::table('sites')
            ->whereNotNull('port')
            ->orderBy('id')
            ->select(['id', 'server_id', 'port'])
            ->chunkById(250, function ($sites): void {
                $now = Carbon::now();
                $rows = [];

                foreach ($sites as $site) {
                    $rows[] = [
                        'server_id' => $site->server_id,
                        'site_id' => $site->id,
                        'protocol' => 'tcp',
                        'port' => $site->port,
                        'purpose' => 'site_proxy',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                DB::table('server_port_reservations')->insertOrIgnore($rows);
            });
    }

    /**
     * @return array<string, int>
     */
    private function phpSettings(?string $typeData): array
    {
        if ($typeData === null || $typeData === '') {
            return [];
        }

        $decoded = json_decode($typeData, true);
        $php = is_array($decoded) ? ($decoded['php'] ?? []) : [];

        if (! is_array($php)) {
            return [];
        }

        $settings = [];
        foreach (['max_upload_size', 'max_execution_time', 'memory_limit', 'max_input_vars'] as $key) {
            if (isset($php[$key]) && is_numeric($php[$key])) {
                $settings[$key] = (int) $php[$key];
            }
        }

        return $settings;
    }
};

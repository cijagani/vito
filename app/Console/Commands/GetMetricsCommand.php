<?php

namespace App\Console\Commands;

use App\Actions\Site\CollectSiteRuntimeMetrics;
use App\Actions\Service\CheckServiceStatuses;
use App\Enums\FpmServiceMode;
use App\Enums\ServerStatus;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

class GetMetricsCommand extends Command
{
    protected $signature = 'metrics:get';

    protected $description = 'Get server metrics';

    public function handle(): void
    {
        $checkedMetrics = 0;
        Server::query()
            ->where('status', ServerStatus::READY)
            ->whereHas('services', function (Builder $query): void {
                $query->where('type', 'monitoring')
                    ->where('name', 'remote-monitor');
            })->chunk(10, function ($servers) use (&$checkedMetrics): void {
                /** @var Server $server */
                foreach ($servers as $server) {
                    try {
                        $info = $server->os()->resourceInfo();
                        $server->metrics()->create(array_merge($info, ['server_id' => $server->id]));
                        $checkedMetrics++;
                    } catch (Throwable $e) {
                        Log::warning('Failed to collect metrics for server', [
                            'server_id' => $server->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                    try {
                        $server->sites()
                            ->with('runtimeProfile')
                            ->whereHas('runtimeProfile', fn (Builder $query) => $query
                                ->where('fpm_service_mode', FpmServiceMode::DEDICATED_MASTER->value))
                            ->each(function (Site $site) use ($server): void {
                                try {
                                    app(CollectSiteRuntimeMetrics::class)->collect($server, $site);
                                } catch (Throwable $e) {
                                    Log::warning('Failed to collect site runtime metrics', [
                                        'server_id' => $server->id,
                                        'site_id' => $site->id,
                                        'error' => $e->getMessage(),
                                    ]);
                                }
                            });
                    } catch (Throwable $e) {
                        Log::warning('Failed to collect site runtime metrics for server', [
                            'server_id' => $server->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                    try {
                        app(CheckServiceStatuses::class)->check($server);
                    } catch (Throwable $e) {
                        Log::warning('Failed to check service statuses for server', [
                            'server_id' => $server->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });
        $this->info("Checked $checkedMetrics metrics");
    }
}

<?php

namespace App\Actions\Site;

use App\Enums\WorkerStatus;
use App\Models\CronJob;
use App\Models\Service;
use App\Models\Site;
use App\Services\ProcessManager\ProcessManager;

class RefreshSiteRuntimeConsumers
{
    public function refresh(Site $site): void
    {
        $workers = $site->workers()->with('site.isolatedUser')->get();
        $processManagerService = $site->server->processManager();

        if ($workers->isNotEmpty() && $processManagerService instanceof Service) {
            $handler = $processManagerService->handler();
            if ($handler instanceof ProcessManager) {
                foreach ($workers as $worker) {
                    $handler->writeConfig($worker);
                }

                $runningWorkerIds = $workers
                    ->where('status', WorkerStatus::RUNNING)
                    ->pluck('id')
                    ->all();

                if ($runningWorkerIds !== []) {
                    $handler->restartMany($runningWorkerIds, $site->id);
                }
            }
        }

        $users = $site->cronJobs()->distinct()->pluck('user');
        foreach ($users as $user) {
            $site->server->cron()->update($user, CronJob::crontab($site->server, $user));
        }
    }
}

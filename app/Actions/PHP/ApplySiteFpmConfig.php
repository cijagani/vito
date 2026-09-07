<?php

namespace App\Actions\PHP;

use App\Actions\Site\CreateSiteRuntimeOperation;
use App\Actions\Site\UpdateSiteRuntimeOperationStatus;
use App\DTOs\SiteRuntimeConfig;
use App\Enums\SiteRuntimeOperationStatus;
use App\Models\Site;
use App\Models\SiteRuntimeOperation;
use App\Models\SiteRuntimeProfile;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class ApplySiteFpmConfig
{
    public function __construct(
        private readonly RenderSiteFpmConfig $renderer,
        private readonly ValidateSiteFpmConfig $validator,
        private readonly DeploySiteFpmConfig $applier,
        private readonly CheckSiteFpmConfigHealth $healthChecker,
        private readonly PrepareSiteFpmFilesystem $filesystem,
        private readonly CreateSiteRuntimeOperation $operations,
        private readonly UpdateSiteRuntimeOperationStatus $operationStatus,
    ) {}

    public function apply(Site $site): void
    {
        $serverLock = $site->server->runtimeLock();
        $siteLock = $site->runtimeLock();
        $this->acquire($serverLock, 'Another server runtime operation is in progress.');

        try {
            $this->acquire($siteLock, 'Another operation for this site is in progress.');

            try {
                $config = $this->renderer->render($site);
                $operation = $this->operations->create(
                    $site,
                    $config,
                    previousChecksum: $site->runtimeProfile()->value('applied_checksum'),
                    metadata: ['php_version' => $site->php_version],
                );
                $this->operationStatus->update($operation, SiteRuntimeOperationStatus::RUNNING);
                $this->applyLocked($site, $config, $operation);
            } finally {
                $siteLock->release();
            }
        } finally {
            $serverLock->release();
        }
    }

    private function applyLocked(Site $site, SiteRuntimeConfig $config, SiteRuntimeOperation $operation): void
    {
        $applyAttempted = false;

        try {
            $this->filesystem->prepare($site, (string) $site->php_version);
            $this->validator->validate($site, $config);
            $applyAttempted = true;
            $this->applier->apply($site, $config);
            $this->healthChecker->check($site, $config);
            $this->markSucceeded($site, $config, $operation);

            try {
                $this->applier->finish($site, $config);
            } catch (Throwable) {
                Log::warning('PHP-FPM configuration applied but backup cleanup failed', [
                    'site_id' => $site->id,
                    'server_id' => $site->server_id,
                ]);
            }
        } catch (Throwable $exception) {
            $this->recordFailure($site, $operation, $applyAttempted);

            throw $exception;
        }
    }

    private function recordFailure(Site $site, SiteRuntimeOperation $operation, bool $applyAttempted): void
    {
        $message = 'PHP-FPM configuration could not be applied.';

        if ($applyAttempted) {
            try {
                $this->applier->rollback($site, $operation);
                $message = 'PHP-FPM configuration failed and the previous configuration was restored.';
                $this->operationStatus->update($operation, SiteRuntimeOperationStatus::ROLLED_BACK, $message);
                $this->markFailed($site, $message);

                return;
            } catch (Throwable) {
                $message = 'PHP-FPM configuration and automatic rollback both failed. Manual repair is required.';
            }
        }

        $this->operationStatus->update($operation, SiteRuntimeOperationStatus::FAILED, $message);
        $this->markFailed($site, $message);
    }

    private function markSucceeded(Site $site, SiteRuntimeConfig $config, SiteRuntimeOperation $operation): void
    {
        DB::transaction(function () use ($site, $config, $operation): void {
            $profile = SiteRuntimeProfile::query()->where('site_id', $site->id)->lockForUpdate()->firstOrFail();
            $profile->applied_revision = $config->desiredRevision;
            $profile->applied_checksum = $config->checksum;
            $profile->last_applied_at = now();
            $profile->last_apply_error = null;
            $profile->save();
            $this->operationStatus->update($operation, SiteRuntimeOperationStatus::SUCCEEDED);
        });
    }

    private function markFailed(Site $site, string $message): void
    {
        $profile = SiteRuntimeProfile::query()->where('site_id', $site->id)->firstOrFail();
        $profile->last_apply_error = $message;
        $profile->save();
    }

    private function acquire(Lock $lock, string $message): void
    {
        try {
            $lock->block(60);
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['site' => $message]);
        }
    }
}

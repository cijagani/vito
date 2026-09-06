<?php

namespace App\Actions\Webserver;

use App\Actions\Site\CreateSiteRuntimeOperation;
use App\Actions\Site\UpdateSiteRuntimeOperationStatus;
use App\DTOs\SiteRuntimeConfig;
use App\Enums\SiteRuntimeOperationStatus;
use App\Models\Site;
use App\Models\SiteRuntimeOperation;
use App\Models\SiteWebProfile;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class ApplyNginxSiteConfig
{
    public function __construct(
        private readonly RenderNginxSiteConfig $renderer,
        private readonly ValidateNginxSiteConfig $validator,
        private readonly DeployNginxSiteConfig $applier,
        private readonly CheckNginxSiteConfigHealth $healthChecker,
        private readonly EnsureNginxRuntimeIdentity $runtimeIdentity,
        private readonly EnsureNginxDefaultVhost $defaultVhost,
        private readonly PrepareNginxSiteFilesystem $filesystem,
        private readonly CreateSiteRuntimeOperation $operations,
        private readonly UpdateSiteRuntimeOperationStatus $operationStatus,
    ) {}

    public function apply(Site $site, ?string $contents = null, bool $restart = false): void
    {
        $serverLock = $site->server->runtimeLock();
        $siteLock = $site->runtimeLock();
        $this->acquire($serverLock, 'Another server runtime operation is in progress.');

        try {
            $this->acquire($siteLock, 'Another operation for this site is in progress.');

            try {
                $config = $contents === null
                    ? $this->renderer->render($site)
                    : $this->renderer->renderContent($site, $contents);
                $operation = $this->operations->create(
                    $site,
                    $config,
                    previousChecksum: $site->webProfile()->value('applied_checksum'),
                );
                $this->operationStatus->update($operation, SiteRuntimeOperationStatus::RUNNING);
                $this->applyLocked($site, $config, $operation, $restart);
            } finally {
                $siteLock->release();
            }
        } finally {
            $serverLock->release();
        }
    }

    private function applyLocked(
        Site $site,
        SiteRuntimeConfig $config,
        SiteRuntimeOperation $operation,
        bool $restart,
    ): void {
        $applyAttempted = false;

        try {
            $this->runtimeIdentity->ensure($site->server);
            $this->defaultVhost->ensure($site->server);
            $this->filesystem->prepare($site);
            $this->validator->validate($site, $config);
            $applyAttempted = true;
            $this->applier->apply($site, $config, $restart);
            $this->healthChecker->check($site, $config);
            $this->markSucceeded($site, $config, $operation);

            try {
                $this->applier->finish($site, $config);
            } catch (Throwable) {
                Log::warning('Nginx configuration applied but backup cleanup failed', [
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
        $message = 'Nginx configuration could not be applied.';

        if ($applyAttempted) {
            try {
                $this->applier->rollback($site, $operation);
                $message = 'Nginx configuration failed and the previous configuration was restored.';
                $this->operationStatus->update($operation, SiteRuntimeOperationStatus::ROLLED_BACK, $message);
                $this->markFailed($site, $message);

                return;
            } catch (Throwable) {
                $message = 'Nginx configuration and automatic rollback both failed. Manual repair is required.';
            }
        }

        $this->operationStatus->update($operation, SiteRuntimeOperationStatus::FAILED, $message);
        $this->markFailed($site, $message);
    }

    private function markApplied(Site $site, SiteRuntimeConfig $config): void
    {
        $profile = SiteWebProfile::query()
            ->where('site_id', $site->id)
            ->lockForUpdate()
            ->firstOrFail();
        $profile->applied_revision = $config->desiredRevision;
        $profile->applied_checksum = $config->checksum;
        $profile->last_applied_at = now();
        $profile->last_apply_error = null;
        $profile->save();
    }

    private function markSucceeded(
        Site $site,
        SiteRuntimeConfig $config,
        SiteRuntimeOperation $operation,
    ): void {
        DB::transaction(function () use ($site, $config, $operation): void {
            $this->markApplied($site, $config);
            $this->operationStatus->update($operation, SiteRuntimeOperationStatus::SUCCEEDED);
        });
    }

    private function markFailed(Site $site, string $message): void
    {
        $profile = SiteWebProfile::query()->where('site_id', $site->id)->firstOrFail();
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

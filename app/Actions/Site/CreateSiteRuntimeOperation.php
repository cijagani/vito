<?php

namespace App\Actions\Site;

use App\DTOs\SiteRuntimeConfig;
use App\Enums\FpmServiceMode;
use App\Enums\SiteRuntimeConfigType;
use App\Enums\SiteRuntimeOperationStatus;
use App\Models\Site;
use App\Models\SiteRuntimeOperation;
use App\Models\User;
use LogicException;

class CreateSiteRuntimeOperation
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function create(
        Site $site,
        SiteRuntimeConfig $config,
        ?User $actor = null,
        ?string $previousChecksum = null,
        array $metadata = [],
    ): SiteRuntimeOperation {
        if (! $site->exists || $site->id < 1 || $site->server_id < 1) {
            throw new LogicException('Runtime operations require a persisted site and server.');
        }

        if ($actor !== null && (! $actor->exists || $actor->id < 1)) {
            throw new LogicException('A runtime operation actor must be persisted.');
        }

        if ($previousChecksum !== null && preg_match('/^[a-f0-9]{64}$/', $previousChecksum) !== 1) {
            throw new LogicException('A previous runtime configuration checksum must be SHA-256.');
        }

        app(SyncSiteRuntimeProfiles::class)->sync($site);
        $site->unsetRelation('runtimeProfile')->unsetRelation('webProfile');

        $profile = $config->type === SiteRuntimeConfigType::NGINX
            ? $site->webProfile
            : $site->runtimeProfile;

        if ($profile === null || $profile->desired_revision !== $config->desiredRevision) {
            throw new LogicException('A runtime operation revision must match the current desired profile revision.');
        }

        $expectedTarget = $site->runtimeArtifacts()->expectedTargetPath(
            $config->type,
            $site->runtimeProfile?->php_version,
            $site->runtimeProfile->fpm_service_mode ?? FpmServiceMode::SHARED_MASTER,
        );

        if ($config->targetPath !== $expectedTarget) {
            throw new LogicException('A runtime operation target must match the immutable site artifact path.');
        }

        return SiteRuntimeOperation::query()->create([
            'server_id' => $site->server_id,
            'site_id' => $site->id,
            'actor_id' => $actor?->id,
            'type' => $config->type,
            'status' => SiteRuntimeOperationStatus::PENDING,
            'target_path' => $config->targetPath,
            'desired_revision' => $config->desiredRevision,
            'desired_checksum' => $config->checksum,
            'previous_checksum' => $previousChecksum,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }
}

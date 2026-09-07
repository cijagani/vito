<?php

namespace App\Actions\Site;

use App\Exceptions\SSHError;
use App\Models\Site;
use App\Services\PHP\PHP;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class UpdatePHPVersion
{
    /**
     * @param  array<string, mixed>  $input
     *
     * @throws SSHError
     */
    public function update(Site $site, array $input): void
    {
        Validator::make($input, [
            'version' => [
                'required',
                Rule::exists('services', 'version')
                    ->where('server_id', $site->server_id)
                    ->where('type', 'php'),
            ],
        ])->validate();

        $newVersion = (string) $input['version'];
        $oldVersion = $site->php_version;

        if ($oldVersion === $newVersion) {
            return;
        }

        if ($site->isIsolated()) {
            $sites = $this->isolatedPhpSites($site);
            $this->validateGroupSwitch($sites, $oldVersion);

            $lock = $site->isolatedUser?->lock() ?? $site->server->isolatedUserLock($site->user);

            try {
                $lock->block(30);
            } catch (LockTimeoutException) {
                throw ValidationException::withMessages([
                    'version' => "Another operation on isolated user '{$site->user}' is in progress, please retry.",
                ]);
            }

            try {
                $sites = $this->isolatedPhpSites($site->fresh());
                $this->validateGroupSwitch($sites, $oldVersion);

                try {
                    foreach ($sites as $runtimeSite) {
                        $runtimeSite->php_version = $newVersion;
                        $runtimeSite->save();
                        app(SyncSiteRuntimeProfiles::class)->sync($runtimeSite);
                    }

                    foreach ($sites as $runtimeSite) {
                        $runtimeSite->webserver()->updateVHost($runtimeSite);
                        app(RefreshSiteRuntimeConsumers::class)->refresh($runtimeSite);
                    }
                } catch (Throwable $e) {
                    $this->rollbackGroup($sites, $oldVersion, $newVersion);

                    throw $e;
                }

                $this->cleanupOldRuntime($sites, $oldVersion, $newVersion);
            } finally {
                $lock->release();
            }

            return;
        }

        $site->php_version = $newVersion;
        $site->save();
        app(SyncSiteRuntimeProfiles::class)->sync($site);

        $site->webserver()->updateVHost($site);
    }

    /**
     * @return Collection<int, Site>
     */
    private function isolatedPhpSites(Site $site): Collection
    {
        if ($site->isolated_user_id === null) {
            return new Collection([$site]);
        }

        return $site->siblingsSharingUser(includeSelf: true)
            ->whereNotNull('php_version')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, Site>  $sites
     */
    private function validateGroupSwitch(Collection $sites, string $oldVersion): void
    {
        if ($sites->contains(fn (Site $site) => ! $site->vhost_generation_enabled || $site->vhost_template !== null)) {
            throw ValidationException::withMessages([
                'version' => 'Every site sharing this user must use a managed default vhost before changing PHP.',
            ]);
        }

        if ($sites->contains(fn (Site $site) => $site->php_version !== $oldVersion)) {
            throw ValidationException::withMessages([
                'version' => 'Sites sharing this user have inconsistent PHP versions and must be aligned before a group switch.',
            ]);
        }
    }

    /**
     * @param  Collection<int, Site>  $sites
     */
    private function rollbackGroup(Collection $sites, string $oldVersion, string $newVersion): void
    {
        foreach ($sites as $runtimeSite) {
            $runtimeSite->php_version = $oldVersion;
            $runtimeSite->save();
            app(SyncSiteRuntimeProfiles::class)->sync($runtimeSite);
        }

        foreach ($sites as $runtimeSite) {
            try {
                $runtimeSite->webserver()->updateVHost($runtimeSite);
                app(RefreshSiteRuntimeConsumers::class)->refresh($runtimeSite);

                $newService = $runtimeSite->server->php($newVersion);
                if ($newService) {
                    /** @var PHP $newPhp */
                    $newPhp = $newService->handler();
                    $newPhp->removeSiteFpmPool($runtimeSite, $newVersion);
                }
            } catch (Throwable $rollbackException) {
                Log::error('PHP version group switch rollback requires manual repair', [
                    'site_id' => $runtimeSite->id,
                    'server_id' => $runtimeSite->server_id,
                    'old_version' => $oldVersion,
                    'new_version' => $newVersion,
                    'exception' => $rollbackException->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param  Collection<int, Site>  $sites
     */
    private function cleanupOldRuntime(Collection $sites, string $oldVersion, string $newVersion): void
    {
        foreach ($sites as $runtimeSite) {
            try {
                $oldService = $runtimeSite->server->php($oldVersion);
                if (! $oldService) {
                    continue;
                }

                /** @var PHP $oldPhp */
                $oldPhp = $oldService->handler();
                $oldPhp->removeSiteFpmPool($runtimeSite, $oldVersion);
                $oldPhp->retireLegacyFpmPoolIfUnused($runtimeSite, $oldVersion);
            } catch (Throwable $cleanupException) {
                Log::warning('Old PHP-FPM runtime could not be removed after version switch', [
                    'site_id' => $runtimeSite->id,
                    'server_id' => $runtimeSite->server_id,
                    'old_version' => $oldVersion,
                    'new_version' => $newVersion,
                    'exception' => $cleanupException->getMessage(),
                ]);
            }
        }
    }
}

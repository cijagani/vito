<?php

namespace App\Actions\Webserver;

use App\Actions\Site\EnsureSiteVerificationKey;
use App\Actions\Site\SyncSiteRuntimeProfiles;
use App\Contracts\SiteRuntimeConfigRenderer;
use App\DTOs\SiteRuntimeConfig;
use App\Enums\SiteRuntimeConfigType;
use App\Models\Site;
use App\Models\SiteWebProfile;
use Illuminate\Support\Facades\DB;

class RenderNginxSiteConfig implements SiteRuntimeConfigRenderer
{
    public function __construct(
        private readonly GenerateNginxConfig $generator,
        private readonly SyncSiteRuntimeProfiles $profiles,
        private readonly EnsureSiteVerificationKey $verificationKey,
    ) {}

    public function render(Site $site): SiteRuntimeConfig
    {
        $this->verificationKey->ensure($site);

        return $this->renderContent($site, $this->generator->generate($site));
    }

    public function renderContent(Site $site, string $contents): SiteRuntimeConfig
    {
        $this->profiles->sync($site);
        $checksum = hash('sha256', $contents);

        $revision = DB::transaction(function () use ($site, $checksum): int {
            $profile = SiteWebProfile::query()
                ->where('site_id', $site->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($profile->desired_checksum !== $checksum) {
                $profile->desired_revision++;
                $profile->desired_checksum = $checksum;
                $profile->save();
            }

            return $profile->desired_revision;
        });

        return new SiteRuntimeConfig(
            SiteRuntimeConfigType::NGINX,
            $site->runtimeArtifacts()->nginxAvailablePath(),
            $contents,
            $revision,
        );
    }
}

<?php

use App\Actions\Site\CreateSiteRuntimeOperation;
use App\Actions\Site\SyncSiteRuntimeProfiles;
use App\Actions\Site\UpdateSiteRuntimeOperationStatus;
use App\DTOs\SiteRuntimeConfig;
use App\Enums\FpmProcessManager;
use App\Enums\SiteIsolationProfile;
use App\Enums\SiteRuntimeConfigType;
use App\Enums\SiteRuntimeOperationStatus;
use App\Models\Site;
use App\Models\SiteRuntimeProfile;
use App\Models\SiteWebProfile;
use App\Support\SiteRuntimeArtifacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('runtime and web profiles cast state and expose apply drift', function () {
    $runtime = SiteRuntimeProfile::factory()->create([
        'site_id' => $this->site->id,
        'isolation_profile' => SiteIsolationProfile::HARDENED,
        'fpm_process_manager' => FpmProcessManager::DYNAMIC,
        'desired_revision' => 2,
        'applied_revision' => 1,
    ]);
    $web = SiteWebProfile::factory()->create([
        'site_id' => $this->site->id,
        'desired_revision' => 3,
        'applied_revision' => 3,
    ]);

    $site = $this->site->fresh();

    expect($site->runtimeProfile->isolation_profile)->toBe(SiteIsolationProfile::HARDENED)
        ->and($site->runtimeProfile->fpm_process_manager)->toBe(FpmProcessManager::DYNAMIC)
        ->and($runtime->needsApply())->toBeTrue()
        ->and($site->webProfile->is($web))->toBeTrue()
        ->and($web->needsApply())->toBeFalse();
});

test('runtime artifacts use immutable site identity', function () {
    $artifacts = $this->site->runtimeArtifacts();

    expect($artifacts->key())->toBe('vito-site-'.$this->site->id)
        ->and($artifacts->nginxAvailablePath())->toBe('/etc/nginx/sites-available/vito-site-'.$this->site->id.'.conf')
        ->and($artifacts->nginxEnabledPath())->toBe('/etc/nginx/sites-enabled/vito-site-'.$this->site->id.'.conf')
        ->and($artifacts->fpmPoolName())->toBe('vito-site-'.$this->site->id)
        ->and($artifacts->fpmPoolPath('8.4'))->toBe('/etc/php/8.4/fpm/pool.d/vito-site-'.$this->site->id.'.conf')
        ->and($artifacts->fpmSocketPath('8.4'))->toBe('/run/php/vito-site-'.$this->site->id.'-php8.4.sock')
        ->and($artifacts->phpCliProfilePath())->toBe('/var/lib/vito/php-cli/vito-site-'.$this->site->id.'/environment.sh')
        ->and($artifacts->phpCliIniPath())->toBe('/var/lib/vito/php-cli/vito-site-'.$this->site->id.'/99-vito-site.ini')
        ->and($artifacts->logDirectory())->toBe('/var/log/vito/sites/'.$this->site->id)
        ->and($artifacts->systemdSlice())->toBe('site-'.$this->site->id.'.slice');
});

test('runtime artifacts reject unpersisted sites and unsafe php versions', function () {
    expect(fn () => SiteRuntimeArtifacts::fromSite(new Site))
        ->toThrow(LogicException::class)
        ->and(fn () => new SiteRuntimeArtifacts(0))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->site->runtimeArtifacts()->fpmPoolPath('8.4;id'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->site->runtimeArtifacts()->fpmPoolPath("8.4\n"))
        ->toThrow(InvalidArgumentException::class);
});

test('runtime profiles are created and revisioned from legacy site state', function () {
    $action = app(SyncSiteRuntimeProfiles::class);
    $action->sync($this->site);

    $runtime = $this->site->runtimeProfile()->firstOrFail();
    $web = $this->site->webProfile()->firstOrFail();

    expect($runtime->php_version)->toBe($this->site->php_version)
        ->and($runtime->desired_revision)->toBe(1)
        ->and($web->desired_revision)->toBe(1);

    $typeData = $this->site->type_data;
    $typeData['php'] = [
        'max_upload_size' => 128,
        'max_execution_time' => 120,
        'memory_limit' => 256,
        'max_input_vars' => 5000,
    ];
    $this->site->update(['type_data' => $typeData]);
    $action->sync($this->site->fresh());

    expect($runtime->fresh()->desired_revision)->toBe(2)
        ->and($runtime->fresh()->memory_limit_mb)->toBe(256)
        ->and($web->fresh()->desired_revision)->toBe(2)
        ->and($web->fresh()->client_max_body_size_mb)->toBe(128);
});

test('site and server runtime locks use stable separate keys', function () {
    $siteLock = $this->site->runtimeLock();
    expect($siteLock->get())->toBeTrue();
    expect($this->site->runtimeLock()->get())->toBeFalse();

    $serverLock = $this->server->runtimeLock();
    expect($serverLock->get())->toBeTrue();
    expect($this->server->runtimeLock()->get())->toBeFalse();

    $siteLock->release();
    $serverLock->release();
});

test('runtime config calculates checksum and rejects unsafe targets', function () {
    $config = new SiteRuntimeConfig(
        SiteRuntimeConfigType::NGINX,
        '/etc/nginx/sites-available/vito-site-1.conf',
        'server { return 444; }',
        4,
    );

    expect($config->checksum)->toBe(hash('sha256', 'server { return 444; }'))
        ->and(fn () => new SiteRuntimeConfig(
            SiteRuntimeConfigType::NGINX,
            '../nginx.conf',
            'invalid',
            1,
        ))->toThrow(InvalidArgumentException::class);
});

test('runtime operations derive server and site identity from the site', function () {
    app(SyncSiteRuntimeProfiles::class)->sync($this->site);
    $this->site->runtimeProfile()->update(['desired_revision' => 2]);

    $config = new SiteRuntimeConfig(
        SiteRuntimeConfigType::PHP_FPM,
        '/etc/php/8.2/fpm/pool.d/vito-site-'.$this->site->id.'.conf',
        '[vito-site-'.$this->site->id.']',
        2,
    );

    $operation = app(CreateSiteRuntimeOperation::class)->create(
        $this->site,
        $config,
        $this->user,
        str_repeat('a', 64),
        ['source' => 'test']
    );

    expect($operation->server_id)->toBe($this->site->server_id)
        ->and($operation->site_id)->toBe($this->site->id)
        ->and($operation->actor_id)->toBe($this->user->id)
        ->and($operation->type)->toBe(SiteRuntimeConfigType::PHP_FPM)
        ->and($operation->status)->toBe(SiteRuntimeOperationStatus::PENDING)
        ->and($operation->target_path)->toBe($config->targetPath)
        ->and($operation->desired_checksum)->toBe($config->checksum)
        ->and($operation->metadata)->toBe(['source' => 'test'])
        ->and($operation->getRawOriginal('metadata'))->not->toContain('source');
});

test('runtime operations reject noncanonical targets and stale revisions', function () {
    app(SyncSiteRuntimeProfiles::class)->sync($this->site);

    $wrongTarget = new SiteRuntimeConfig(
        SiteRuntimeConfigType::NGINX,
        '/etc/nginx/sites-available/another-site.conf',
        'server { return 444; }',
        1,
    );
    $staleRevision = new SiteRuntimeConfig(
        SiteRuntimeConfigType::NGINX,
        $this->site->runtimeArtifacts()->nginxAvailablePath(),
        'server { return 444; }',
        2,
    );

    expect(fn () => app(CreateSiteRuntimeOperation::class)->create($this->site, $wrongTarget))
        ->toThrow(LogicException::class)
        ->and(fn () => app(CreateSiteRuntimeOperation::class)->create($this->site, $staleRevision))
        ->toThrow(LogicException::class);
});

test('runtime operation status transitions fail closed', function () {
    $config = new SiteRuntimeConfig(
        SiteRuntimeConfigType::NGINX,
        '/etc/nginx/sites-available/vito-site-'.$this->site->id.'.conf',
        'server { return 444; }',
        1,
    );
    $operation = app(CreateSiteRuntimeOperation::class)->create($this->site, $config);
    $action = app(UpdateSiteRuntimeOperationStatus::class);

    $action->update($operation, SiteRuntimeOperationStatus::RUNNING);
    expect($operation->started_at)->not->toBeNull();

    $action->update($operation, SiteRuntimeOperationStatus::SUCCEEDED);
    expect($operation->finished_at)->not->toBeNull();

    expect(fn () => $action->update($operation, SiteRuntimeOperationStatus::FAILED, 'late failure'))
        ->toThrow(LogicException::class);
});

test('failed runtime operations require a redacted error', function () {
    $config = new SiteRuntimeConfig(
        SiteRuntimeConfigType::NGINX,
        '/etc/nginx/sites-available/vito-site-'.$this->site->id.'.conf',
        'server { return 444; }',
        1,
    );
    $operation = app(CreateSiteRuntimeOperation::class)->create($this->site, $config);

    expect(fn () => app(UpdateSiteRuntimeOperationStatus::class)->update(
        $operation,
        SiteRuntimeOperationStatus::FAILED
    ))->toThrow(LogicException::class);
});

test('runtime operation audit survives site deletion', function () {
    $config = new SiteRuntimeConfig(
        SiteRuntimeConfigType::NGINX,
        $this->site->runtimeArtifacts()->nginxAvailablePath(),
        'server { return 444; }',
        1,
    );
    $operation = app(CreateSiteRuntimeOperation::class)->create($this->site, $config);

    DB::table('sites')->where('id', $this->site->id)->delete();

    expect($operation->fresh())->not->toBeNull()
        ->and($operation->fresh()->site_id)->toBe($this->site->id)
        ->and($operation->fresh()->server_id)->toBe($this->site->server_id);
});

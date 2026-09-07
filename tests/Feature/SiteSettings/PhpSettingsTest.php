<?php

use App\Facades\SSH;
use App\Http\Resources\SiteResource;
use App\Models\HostedDomain;
use App\Models\Site;
use App\Models\User;
use App\Services\Webserver\Caddy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('endpoint persists php settings', function () {
    SSH::fake();
    $this->actingAs($this->user);

    $this->patch(route('site-settings.update-php-settings', [
        'server' => $this->server->id,
        'site' => $this->site,
    ]), [
        'max_upload_size' => 64,
        'max_execution_time' => 120,
        'memory_limit' => 256,
        'max_input_vars' => 5000,
    ])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    $this->site->refresh();

    expect($this->site->type_data['php'])->toBe([
        'max_upload_size' => 64,
        'max_execution_time' => 120,
        'memory_limit' => 256,
        'max_input_vars' => 5000,
    ]);
});

test('blank values persist as null', function () {
    SSH::fake();
    $this->actingAs($this->user);

    $this->patch(route('site-settings.update-php-settings', [
        'server' => $this->server->id,
        'site' => $this->site,
    ]), [
        'max_upload_size' => '',
        'max_execution_time' => null,
    ])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    $this->site->refresh();

    expect($this->site->type_data['php']['max_upload_size'])->toBeNull();
    expect($this->site->type_data['php']['memory_limit'])->toBeNull();
});

test('nginx vhost includes php directives intact', function () {
    HostedDomain::factory()->primary()->create([
        'site_id' => $this->site->id,
        'domain' => $this->site->domain,
    ]);

    $this->site->type_data = [
        'php' => [
            'max_upload_size' => 64,
            'max_execution_time' => 120,
            'memory_limit' => 256,
            'max_input_vars' => 5000,
        ],
    ];
    $this->site->save();

    $vhost = $this->site->webserver()->generateVhost($this->site);

    $this->assertStringContainsString('fastcgi_param PHP_VALUE "upload_max_filesize=64M', $vhost);
    $this->assertStringContainsString("\npost_max_size=64M", $vhost);
    $this->assertStringContainsString("\nmemory_limit=256M", $vhost);
    $this->assertStringContainsString('max_input_vars=5000";', $vhost);

    $this->assertStringContainsString('client_max_body_size 64M;', $vhost);
    $this->assertStringContainsString('fastcgi_read_timeout 120s;', $vhost);
});

test('nginx vhost omits directives when unset', function () {
    HostedDomain::factory()->primary()->create([
        'site_id' => $this->site->id,
        'domain' => $this->site->domain,
    ]);

    $vhost = $this->site->webserver()->generateVhost($this->site);

    $this->assertStringNotContainsString('PHP_VALUE', $vhost);
    $this->assertStringNotContainsString('client_max_body_size', $vhost);
    $this->assertStringNotContainsString('fastcgi_read_timeout', $vhost);
});

test('nginx vhost emits only set directives', function () {
    HostedDomain::factory()->primary()->create([
        'site_id' => $this->site->id,
        'domain' => $this->site->domain,
    ]);

    $this->site->type_data = ['php' => ['max_input_vars' => 5000]];
    $this->site->save();

    $vhost = $this->site->webserver()->generateVhost($this->site);

    $this->assertStringContainsString('fastcgi_param PHP_VALUE "max_input_vars=5000";', $vhost);
    $this->assertStringNotContainsString('client_max_body_size', $vhost);
    $this->assertStringNotContainsString('fastcgi_read_timeout', $vhost);
});

test('caddy vhost includes php directives', function () {
    $this->server->webserver()?->update(['name' => Caddy::id()]);

    HostedDomain::factory()->primary()->create([
        'site_id' => $this->site->id,
        'domain' => $this->site->domain,
    ]);

    $this->site->type_data = [
        'php' => [
            'max_upload_size' => 64,
            'max_execution_time' => 120,
            'memory_limit' => 256,
            'max_input_vars' => 5000,
        ],
    ];
    $this->site->save();

    $vhost = $this->site->webserver()->generateVhost($this->site);

    $this->assertStringContainsString('env PHP_VALUE "upload_max_filesize=64M', $vhost);
    $this->assertStringContainsString('max_size 64MB', $vhost);
});

test('custom template sentinel is stripped', function () {
    HostedDomain::factory()->primary()->create([
        'site_id' => $this->site->id,
        'domain' => $this->site->domain,
    ]);

    $this->site->type_data = ['php' => ['max_upload_size' => 64]];
    $this->site->vhost_template = "server {\n    fastcgi_param PHP_VALUE \"@@VITO_PHP_VALUE@@\";\n}";
    $this->site->save();

    $vhost = $this->site->webserver()->generateVhost($this->site);

    $this->assertStringNotContainsString('@@VITO_PHP_VALUE@@', $vhost);
});

test('validation requires memory limit at least upload size', function () {
    SSH::fake();
    $this->actingAs($this->user);

    $this->patch(route('site-settings.update-php-settings', [
        'server' => $this->server->id,
        'site' => $this->site,
    ]), [
        'max_upload_size' => 512,
        'memory_limit' => 256,
    ])->assertSessionHasErrors('memory_limit');
});

test('validation rejects out of range values', function () {
    SSH::fake();
    $this->actingAs($this->user);

    $this->patch(route('site-settings.update-php-settings', [
        'server' => $this->server->id,
        'site' => $this->site,
    ]), [
        'max_input_vars' => 5,
    ])->assertSessionHasErrors('max_input_vars');
});

test('route 404s for custom vhost template', function () {
    $this->site->vhost_template = 'server { }';
    $this->site->save();

    $this->actingAs($this->user);

    $this->patch(route('site-settings.update-php-settings', [
        'server' => $this->server->id,
        'site' => $this->site,
    ]), [
        'max_upload_size' => 64,
    ])->assertNotFound();
});

test('route 404s for octane site', function () {
    $this->site->type_data = ['octane' => true];
    $this->site->save();

    $this->actingAs($this->user);

    $this->patch(route('site-settings.update-php-settings', [
        'server' => $this->server->id,
        'site' => $this->site,
    ]), [
        'max_upload_size' => 64,
    ])->assertNotFound();
});

test('route 404s when vhost generation disabled', function () {
    $this->site->vhost_generation_enabled = false;
    $this->site->save();

    $this->actingAs($this->user);

    $this->patch(route('site-settings.update-php-settings', [
        'server' => $this->server->id,
        'site' => $this->site,
    ]), [
        'max_upload_size' => 64,
    ])->assertNotFound();
});

test('resource exposes php settings', function () {
    $this->site->type_data = ['php' => ['max_upload_size' => 64, 'max_execution_time' => null, 'memory_limit' => null, 'max_input_vars' => null]];
    $this->site->save();
    $this->site->load('server');

    $resource = SiteResource::make($this->site)->toArray(request());

    expect($resource['supports_php_settings'])->toBeTrue();
    expect($resource['php_settings']['max_upload_size'])->toBe(64);
    expect($resource['php_settings']['memory_limit'])->toBeNull();
    $this->assertArrayNotHasKey('php', $resource['type_data']);
});

test('isolated site applies typed fpm and nginx tuning', function () {
    SSH::fake();
    $this->actingAs($this->user);

    $site = Site::factory()->create([
        'server_id' => $this->server->id,
        'domain' => 'isolated.test',
        'path' => '/home/isolated/isolated.test',
        'user' => 'isolated',
        'php_version' => '8.2',
    ]);
    HostedDomain::factory()->primary()->create([
        'site_id' => $site->id,
        'domain' => $site->domain,
    ]);

    $this->patch(route('site-settings.update-php-settings', [
        'server' => $this->server->id,
        'site' => $site,
    ]), [
        'max_upload_size' => 64,
        'max_execution_time' => 120,
        'memory_limit' => 256,
        'max_input_vars' => 5000,
        'fpm_process_manager' => 'ondemand',
        'fpm_max_children' => 8,
        'fpm_idle_timeout_seconds' => 15,
        'fpm_max_requests' => 750,
        'request_timeout_seconds' => 120,
        'slow_request_seconds' => 10,
        'client_max_body_size_mb' => 80,
        'fastcgi_read_timeout_seconds' => 150,
        'proxy_connect_timeout_seconds' => 8,
        'proxy_read_timeout_seconds' => 90,
        'static_cache_policy' => 'aggressive',
        'rate_limit_profile' => 'strict',
        'access_log_enabled' => false,
    ])->assertRedirect()->assertSessionDoesntHaveErrors();

    $runtime = $site->runtimeProfile()->firstOrFail();
    $web = $site->webProfile()->firstOrFail();
    $fpm = app(\App\Actions\PHP\RenderSiteFpmConfig::class)->preview($site->refresh());
    $vhost = $site->webserver()->generateVhost($site);

    expect($runtime->fpm_process_manager->value)->toBe('ondemand')
        ->and($runtime->fpm_max_children)->toBe(8)
        ->and($runtime->applied_revision)->toBe($runtime->desired_revision)
        ->and($web->static_cache_policy)->toBe('aggressive')
        ->and($web->rate_limit_profile)->toBe('strict')
        ->and($web->access_log_enabled)->toBeFalse()
        ->and($web->applied_revision)->toBe($web->desired_revision);
    expect($fpm)->toContain('pm = ondemand')
        ->toContain('pm.process_idle_timeout = 15s')
        ->toContain('request_slowlog_timeout = 10s')
        ->toContain('php_admin_value[memory_limit] = 256M');
    expect($vhost)->toContain('limit_req_zone')
        ->toContain('rate=5r/s')
        ->toContain('expires 30d;')
        ->toContain('client_max_body_size 80M;')
        ->toContain('fastcgi_read_timeout 150s;')
        ->toContain('access_log off;');
});

test('isolated tuning validates dynamic process relationships and slow log threshold', function () {
    SSH::fake();
    $this->actingAs($this->user);

    $site = Site::factory()->create([
        'server_id' => $this->server->id,
        'domain' => 'validation.test',
        'path' => '/home/validation/validation.test',
        'user' => 'validation',
    ]);

    $this->patch(route('site-settings.update-php-settings', [
        'server' => $this->server->id,
        'site' => $site,
    ]), [
        'fpm_process_manager' => 'dynamic',
        'fpm_max_children' => 4,
        'fpm_start_servers' => 3,
        'fpm_min_spare_servers' => 4,
        'fpm_max_spare_servers' => 5,
        'request_timeout_seconds' => 30,
        'slow_request_seconds' => 30,
    ])->assertSessionHasErrors(['fpm_start_servers', 'slow_request_seconds']);
});

test('caddy rejects nginx rate limit profiles', function () {
    SSH::fake();
    $this->actingAs($this->user);
    $this->server->webserver()?->update(['name' => Caddy::id()]);

    $site = Site::factory()->create([
        'server_id' => $this->server->id,
        'domain' => 'caddy-isolated.test',
        'path' => '/home/caddyisolated/caddy-isolated.test',
        'user' => 'caddyisolated',
    ]);

    $this->patch(route('site-settings.update-php-settings', [
        'server' => $this->server->id,
        'site' => $site,
    ]), [
        'rate_limit_profile' => 'strict',
    ])->assertSessionHasErrors('rate_limit_profile');
});

test('runtime preview and resource expose effective isolated configuration', function () {
    $this->actingAs($this->user);

    $site = Site::factory()->create([
        'server_id' => $this->server->id,
        'domain' => 'preview.test',
        'path' => '/home/preview/preview.test',
        'user' => 'preview',
    ]);
    HostedDomain::factory()->primary()->create([
        'site_id' => $site->id,
        'domain' => $site->domain,
    ]);
    app(\App\Actions\Site\SyncSiteRuntimeProfiles::class)->sync($site);

    $this->getJson(route('site-settings.runtime-preview', [
        'server' => $this->server->id,
        'site' => $site,
    ]))->assertOk()->assertJsonStructure(['fpm', 'vhost']);

    $site->load('server.latestMetric', 'runtimeProfile', 'webProfile');
    $resource = SiteResource::make($site)->toArray(request());

    expect($resource['runtime_tuning']['effective_socket'])
        ->toBe('/run/php/vito-site-'.$site->id.'-php8.2.sock')
        ->and($resource['runtime_tuning']['estimated_fpm_memory_mb'])->toBe(640)
        ->and($resource['runtime_tuning']['runtime_drifted'])->toBeTrue()
        ->and($resource['runtime_tuning']['web_drifted'])->toBeTrue();
});

test('authorization requires project access', function () {
    $otherUser = User::factory()->create();
    $otherUser->ensureHasDefaultProject();

    $this->actingAs($otherUser);

    $this->patch(route('site-settings.update-php-settings', [
        'server' => $this->server->id,
        'site' => $this->site,
    ]), [
        'max_upload_size' => 64,
    ])->assertForbidden();
});

<?php

use App\Actions\Webserver\PrepareNginxSiteFilesystem;
use App\Enums\ServiceStatus;
use App\Enums\SiteRuntimeOperationStatus;
use App\Facades\SSH;
use App\Models\HostedDomain;
use App\Models\IsolatedUser;
use App\Models\SiteRuntimeOperation;
use App\Services\Webserver\Caddy;
use App\Services\Webserver\Nginx;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('nginx deploy splash runs expected commands and writes default vhost', function () {
    $ssh = SSH::fake();

    /** @var Nginx $nginx */
    $nginx = $this->server->webserver()->handler();

    $nginx->deploySplash();

    SSH::assertExecutedContains('sudo rm -f /etc/nginx/sites-enabled/default');
    SSH::assertExecutedContains('/etc/nginx/sites-available/000-default');
    SSH::assertExecutedContains('sudo ln -sf /etc/nginx/sites-available/000-default /etc/nginx/sites-enabled/000-default');
    SSH::assertExecutedContains('sudo nginx -t');
    expect($ssh->getUploadedContent())->toContain('return 444;')
        ->toContain('listen 443 ssl default_server;');

    SSH::assertNotExecutedContains(
        'systemctl reload nginx',
        'deploySplash() must not reload nginx — install() restarts it and the reload can fail on fresh installs.'
    );

    $this->addToAssertionCount(5);
});

test('nginx site configuration is validated activated and audited', function () {
    $ssh = SSH::fake();

    HostedDomain::factory()->primary()->create([
        'site_id' => $this->site->id,
        'domain' => $this->site->domain,
    ]);

    /** @var Nginx $nginx */
    $nginx = $this->server->webserver()->handler();
    $nginx->createVHost($this->site);

    $artifact = 'vito-site-'.$this->site->id.'.conf';
    SSH::assertExecutedContains('disable_symlinks if_not_owner from=$document_root;');
    SSH::assertExecutedContains("VITO_DEFAULT_TARGET='/etc/nginx/sites-available/000-default'");
    SSH::assertExecutedContains('sudo nginx -t');
    SSH::assertExecutedContains('/etc/nginx/sites-available/'.$artifact);
    SSH::assertExecutedContains('/etc/nginx/sites-available/.vito-default-candidate-'.$this->server->id.'.conf');
    SSH::assertExecutedContains('sudo systemctl "$VITO_SERVICE_ACTION" nginx');
    SSH::assertExecutedContains('grep -i -x -F "X-Vito-Site-ID: $VITO_SITE_ID"');
    SSH::assertNotExecutedContains('usermod -a -G "$VITO_SITE_USER" "$VITO_NGINX_USER"');
    expect($ssh->getUploadedContent())->toContain('add_header X-Vito-Site-ID "'.$this->site->id.'" always;');

    $operation = SiteRuntimeOperation::query()->where('site_id', $this->site->id)->sole();
    $profile = $this->site->webProfile()->firstOrFail();

    expect($operation->status)->toBe(SiteRuntimeOperationStatus::SUCCEEDED)
        ->and($profile->applied_revision)->toBe($profile->desired_revision)
        ->and($profile->applied_checksum)->toBe($operation->desired_checksum)
        ->and($profile->last_apply_error)->toBeNull();
});

test('nginx refuses to delete a site outside its canonical home path', function () {
    $ssh = SSH::fake();
    $this->site->path = '/home/../etc';

    /** @var Nginx $nginx */
    $nginx = $this->server->webserver()->handler();

    expect(fn () => $nginx->deleteSite($this->site))->toThrow(LogicException::class);
    expect($ssh->getExecutedCommands())->toBe([]);
});

test('isolated nginx filesystem grants document root acl without joining the site group', function () {
    $isolatedUser = IsolatedUser::factory()->create([
        'server_id' => $this->server->id,
        'username' => 'isolated-site',
    ]);
    $this->site->update([
        'isolated_user_id' => $isolatedUser->id,
        'user' => $isolatedUser->username,
        'path' => '/home/isolated-site/'.$this->site->domain,
    ]);
    SSH::fake();

    app(PrepareNginxSiteFilesystem::class)->prepare($this->site->refresh());

    SSH::assertExecutedContains('setfacl -m u:"$VITO_NGINX_USER":--x');
    SSH::assertExecutedContains('gpasswd -d "$VITO_NGINX_USER"');
    SSH::assertNotExecutedContains('usermod -a -G "$VITO_SITE_USER" "$VITO_NGINX_USER"');
});

test('caddy deploy splash runs expected commands and writes default vhost', function () {
    $this->server->webserver()->delete();
    $this->server->services()->create([
        'type' => Caddy::type(),
        'name' => Caddy::id(),
        'version' => 'latest',
        'status' => ServiceStatus::READY,
    ]);

    SSH::fake();

    /** @var Caddy $caddy */
    $caddy = $this->server->refresh()->webserver()->handler();

    $caddy->deploySplash();

    SSH::assertExecutedContains('sudo mkdir -p /var/www/vito-splash');
    SSH::assertExecutedContains("> '/var/www/vito-splash/index.html'");
    SSH::assertExecutedContains("> '/etc/caddy/sites-enabled/000-default.caddy'");

    SSH::assertNotExecutedContains(
        'caddy reload',
        'deploySplash() must not reload caddy — install() restarts it and the reload can fail on fresh installs.'
    );

    $this->addToAssertionCount(4);
});

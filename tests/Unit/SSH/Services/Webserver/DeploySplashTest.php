<?php

use App\Actions\PHP\EnsureSitePhpRuntime;
use App\Actions\Webserver\PrepareNginxSiteFilesystem;
use App\Enums\ServiceStatus;
use App\Enums\SiteRuntimeConfigType;
use App\Enums\SiteRuntimeOperationStatus;
use App\Facades\SSH;
use App\Models\HostedDomain;
use App\Models\IsolatedUser;
use App\Models\Site;
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

    $operation = SiteRuntimeOperation::query()
        ->where('site_id', $this->site->id)
        ->where('type', SiteRuntimeConfigType::NGINX)
        ->sole();
    $profile = $this->site->webProfile()->firstOrFail();

    expect($operation->status)->toBe(SiteRuntimeOperationStatus::SUCCEEDED)
        ->and($profile->applied_revision)->toBe($profile->desired_revision)
        ->and($profile->applied_checksum)->toBe($operation->desired_checksum)
        ->and($profile->last_apply_error)->toBeNull();
});

test('isolated php runtime uses site keyed pool socket and cli configuration', function () {
    $isolatedUser = IsolatedUser::factory()->create([
        'server_id' => $this->server->id,
        'username' => 'isolated-php',
    ]);
    $this->site->update([
        'isolated_user_id' => $isolatedUser->id,
        'user' => $isolatedUser->username,
        'path' => '/home/isolated-php/'.$this->site->domain,
    ]);
    HostedDomain::factory()->primary()->create([
        'site_id' => $this->site->id,
        'domain' => $this->site->domain,
    ]);
    $ssh = SSH::fake();

    $site = $this->site->refresh();
    app(EnsureSitePhpRuntime::class)->ensure($site);

    $key = 'vito-site-'.$this->site->id;
    SSH::assertExecutedContains('/etc/php/8.2/fpm/pool.d/'.$key.'.conf');
    SSH::assertExecutedContains('sudo "$VITO_FPM_BINARY" -t');
    SSH::assertExecutedContains('sudo systemctl reload "$VITO_SERVICE"');
    SSH::assertExecutedContains('PHP-FPM site socket was not created');
    SSH::assertExecutedContains('PHP_INI_SCAN_DIR="$VITO_INI_SCAN_DIR"');
    expect($ssh->getUploadedContent())->toContain('export PHP_BINARY=')
        ->toContain('/usr/bin/php8.2');

    /** @var Nginx $nginx */
    $nginx = $this->server->webserver()->handler();
    expect($nginx->generateVhost($site))->toContain('fastcgi_pass unix:/run/php/'.$key.'-php8.2.sock;');

    $operation = SiteRuntimeOperation::query()
        ->where('site_id', $this->site->id)
        ->where('type', SiteRuntimeConfigType::PHP_FPM)
        ->sole();
    $profile = $this->site->runtimeProfile()->firstOrFail();

    expect($operation->status)->toBe(SiteRuntimeOperationStatus::SUCCEEDED)
        ->and($operation->target_path)->toBe('/etc/php/8.2/fpm/pool.d/'.$key.'.conf')
        ->and($profile->applied_revision)->toBe($profile->desired_revision)
        ->and($profile->applied_checksum)->toBe($operation->desired_checksum);
});

test('shared user php wrapper selects cli configuration from the working directory', function () {
    $isolatedUser = IsolatedUser::factory()->create([
        'server_id' => $this->server->id,
        'username' => 'shared-php',
    ]);
    $this->site->update([
        'isolated_user_id' => $isolatedUser->id,
        'user' => $isolatedUser->username,
        'path' => '/home/shared-php/'.$this->site->domain,
        'php_version' => '8.2',
    ]);
    $sibling = Site::factory()->create([
        'server_id' => $this->server->id,
        'isolated_user_id' => $isolatedUser->id,
        'user' => $isolatedUser->username,
        'domain' => 'sibling.test',
        'path' => '/home/shared-php/sibling.test',
        'php_version' => '8.2',
    ]);
    $ssh = SSH::fake();

    app(EnsureSitePhpRuntime::class)->ensure($this->site->refresh());

    expect($ssh->getUploadedContent())
        ->toContain('VITO_WORKING_DIRECTORY=$(pwd -P)')
        ->toContain('/home/shared-php/'.$this->site->domain.'/')
        ->toContain('/home/shared-php/sibling.test/')
        ->toContain('VITO_SITE_ID=')
        ->toContain('vito-site-'.$sibling->id);
    SSH::assertExecutedContains("sed -i '\\|/var/lib/vito/php-cli/vito-site-.*/profile|d'");
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

test('caddy retires the legacy php pool only after vhost activation', function () {
    $this->server->webserver()->delete();
    $this->server->services()->create([
        'type' => Caddy::type(),
        'name' => Caddy::id(),
        'version' => 'latest',
        'status' => ServiceStatus::READY,
    ]);
    $isolatedUser = IsolatedUser::factory()->create([
        'server_id' => $this->server->id,
        'username' => 'caddy-php',
    ]);
    $this->site->update([
        'isolated_user_id' => $isolatedUser->id,
        'user' => $isolatedUser->username,
        'path' => '/home/caddy-php/'.$this->site->domain,
        'php_version' => '8.2',
    ]);
    HostedDomain::factory()->primary()->create([
        'site_id' => $this->site->id,
        'domain' => $this->site->domain,
    ]);
    $ssh = SSH::fake();

    /** @var Caddy $caddy */
    $caddy = $this->server->refresh()->webserver()->handler();
    $caddy->createVHost($this->site->refresh());

    $commands = collect($ssh->getExecutedCommands());
    $activationIndex = $commands->search(fn (string $command): bool => str_contains($command, 'service caddy reload'));
    $retirementIndex = $commands->search(fn (string $command): bool => str_contains(
        $command,
        '/etc/php/8.2/fpm/pool.d/caddy-php.conf',
    ));

    expect($activationIndex)->not->toBeFalse()
        ->and($retirementIndex)->not->toBeFalse()
        ->and($retirementIndex)->toBeGreaterThan($activationIndex)
        ->and($this->site->runtimeProfile()->value('legacy_fpm_migrated_at'))->not->toBeNull();
});

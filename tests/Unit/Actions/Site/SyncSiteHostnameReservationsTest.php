<?php

use App\Actions\Site\SyncSiteHostnameReservations;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('sync normalizes deduplicates and replaces site hostnames', function () {
    $action = app(SyncSiteHostnameReservations::class);

    $first = $action->sync($this->site, ['Example.COM', 'www.example.com', 'example.com']);

    expect($first->pluck('hostname')->all())->toBe(['example.com', 'www.example.com']);

    $second = $action->sync($this->site, ['api.example.com']);

    expect($second->pluck('hostname')->all())->toBe(['api.example.com']);
    $this->assertDatabaseMissing('server_hostname_reservations', [
        'site_id' => $this->site->id,
        'hostname' => 'example.com',
    ]);
});

test('sync rejects a hostname reserved by another site on the same server', function () {
    $other = Site::factory()->create([
        'server_id' => $this->server->id,
        'domain' => 'other.test',
        'user' => 'other-site',
    ]);
    $action = app(SyncSiteHostnameReservations::class);
    $action->sync($this->site, ['shared.example.com']);

    expect(fn () => $action->sync($other, ['shared.example.com']))
        ->toThrow(ValidationException::class);

    $this->assertDatabaseHas('server_hostname_reservations', [
        'server_id' => $this->server->id,
        'site_id' => $this->site->id,
        'hostname' => 'shared.example.com',
    ]);
});

test('the same hostname can be reserved on different servers', function () {
    $server = Server::factory()->create([
        'project_id' => $this->user->current_project_id,
        'user_id' => $this->user->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'domain' => 'second.test',
        'user' => 'second-site',
    ]);
    $action = app(SyncSiteHostnameReservations::class);

    $action->sync($this->site, ['shared.example.com']);
    $action->sync($site, ['shared.example.com']);

    $this->assertDatabaseCount('server_hostname_reservations', 2);
});

test('sync validates every hostname', function () {
    expect(fn () => app(SyncSiteHostnameReservations::class)->sync($this->site, ['invalid host']))
        ->toThrow(ValidationException::class);
});

test('reservation schema rejects a site from another server', function () {
    $server = Server::factory()->create([
        'project_id' => $this->user->current_project_id,
        'user_id' => $this->user->id,
    ]);

    expect(fn () => $this->site->hostnameReservations()->create([
        'server_id' => $server->id,
        'hostname' => 'cross-server.example.com',
    ]))->toThrow(QueryException::class);
});

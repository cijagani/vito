<?php

use App\Actions\Site\SyncSitePortReservations;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('sync normalizes and replaces site port reservations', function () {
    $action = app(SyncSitePortReservations::class);

    $first = $action->sync($this->site, [
        ['protocol' => 'TCP', 'port' => 3000, 'purpose' => 'OCTANE'],
        ['protocol' => 'udp', 'port' => 3000, 'purpose' => 'realtime'],
    ]);

    expect($first)->toHaveCount(2)
        ->and($first->first()->protocol->value)->toBe('tcp')
        ->and($first->first()->purpose)->toBe('octane');

    $second = $action->sync($this->site, [
        ['protocol' => 'tcp', 'port' => 4000, 'purpose' => 'application'],
    ]);

    expect($second)->toHaveCount(1)
        ->and($second->first()->port)->toBe(4000);
    $this->assertDatabaseMissing('server_port_reservations', [
        'site_id' => $this->site->id,
        'port' => 3000,
    ]);
});

test('sync rejects a protocol and port reserved by another site on the same server', function () {
    $other = Site::factory()->create([
        'server_id' => $this->server->id,
        'domain' => 'other.test',
        'user' => 'other-site',
    ]);
    $action = app(SyncSitePortReservations::class);
    $action->sync($this->site, [
        ['protocol' => 'tcp', 'port' => 3000, 'purpose' => 'application'],
    ]);

    expect(fn () => $action->sync($other, [
        ['protocol' => 'tcp', 'port' => 3000, 'purpose' => 'application'],
    ]))->toThrow(ValidationException::class);
});

test('the same port can be reserved for another protocol or server', function () {
    $server = Server::factory()->create([
        'project_id' => $this->user->current_project_id,
        'user_id' => $this->user->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'domain' => 'second.test',
        'user' => 'second-site',
    ]);
    $action = app(SyncSitePortReservations::class);

    $action->sync($this->site, [
        ['protocol' => 'tcp', 'port' => 3000, 'purpose' => 'application'],
        ['protocol' => 'udp', 'port' => 3000, 'purpose' => 'realtime'],
    ]);
    $action->sync($site, [
        ['protocol' => 'tcp', 'port' => 3000, 'purpose' => 'application'],
    ]);

    $this->assertDatabaseCount('server_port_reservations', 3);
});

test('sync rejects duplicate and invalid port reservations', function () {
    $action = app(SyncSitePortReservations::class);

    expect(fn () => $action->sync($this->site, [
        ['protocol' => 'tcp', 'port' => 3000, 'purpose' => 'application'],
        ['protocol' => 'tcp', 'port' => 3000, 'purpose' => 'octane'],
    ]))->toThrow(ValidationException::class)
        ->and(fn () => $action->sync($this->site, [
            ['protocol' => 'tcp', 'port' => 70000, 'purpose' => 'application'],
        ]))->toThrow(ValidationException::class);
});

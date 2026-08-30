<?php

use App\Enums\SiteLoadClass;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs($this->user);
});

test('a site can be marked as busier than its neighbours', function () {
    $this->patch(
        route('site-settings.update-load-class', ['server' => $this->server, 'site' => $this->site]),
        ['load_class' => 'high']
    )->assertRedirect();

    expect($this->site->refresh()->load_class)->toBe(SiteLoadClass::HIGH);
});

test('an unknown load class is rejected', function () {
    $this->patch(
        route('site-settings.update-load-class', ['server' => $this->server, 'site' => $this->site]),
        ['load_class' => 'enormous']
    )->assertSessionHasErrors('load_class');

    expect($this->site->refresh()->load_class)->toBe(SiteLoadClass::MEDIUM);
});

test('a load class is required', function () {
    $this->patch(
        route('site-settings.update-load-class', ['server' => $this->server, 'site' => $this->site]),
        []
    )->assertSessionHasErrors('load_class');
});

test('a user outside the project cannot change it', function () {
    $this->actingAs(App\Models\User::factory()->create());

    $this->patch(
        route('site-settings.update-load-class', ['server' => $this->server, 'site' => $this->site]),
        ['load_class' => 'high']
    )->assertForbidden();

    expect($this->site->refresh()->load_class)->toBe(SiteLoadClass::MEDIUM);
});

test('the site resource exposes the load class', function () {
    $this->site->update(['load_class' => SiteLoadClass::HIGH]);

    $resource = (new App\Http\Resources\SiteResource($this->site->refresh()))
        ->toArray(request());

    expect($resource['load_class'])->toBe('high');
});

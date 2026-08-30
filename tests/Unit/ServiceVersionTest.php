<?php

use App\Support\Optimization\ServiceVersion;

test('splits a version into its numeric parts', function () {
    expect(ServiceVersion::make('17.2')->parts)->toBe([17, 2])
        ->and(ServiceVersion::make('8.4.3')->parts)->toBe([8, 4, 3])
        ->and(ServiceVersion::make('17')->parts)->toBe([17]);
});

test('reads major and minor', function () {
    $version = ServiceVersion::make('8.4.3');

    expect($version->major())->toBe(8)
        ->and($version->minor())->toBe(4);
});

test('treats a missing minor as zero', function () {
    expect(ServiceVersion::make('17')->minor())->toBe(0);
});

test('tolerates a version carrying suffixes', function () {
    // Engines report things like "10.11.6-MariaDB" or "8.4.3-0ubuntu0.1".
    expect(ServiceVersion::make('10.11.6-MariaDB')->major())->toBe(10)
        ->and(ServiceVersion::make('10.11.6-MariaDB')->minor())->toBe(11);
});

test('matches a declared release line by prefix', function () {
    expect(ServiceVersion::make('17.2')->matchesPrefix('17'))->toBeTrue()
        ->and(ServiceVersion::make('17')->matchesPrefix('17'))->toBeTrue()
        ->and(ServiceVersion::make('8.4.3')->matchesPrefix('8.4'))->toBeTrue();
});

test('does not match a different release line sharing a leading digit', function () {
    // The bug this guards: reducing 8.4 to "8" would match 8.0, a distinct line.
    expect(ServiceVersion::make('8.0.36')->matchesPrefix('8.4'))->toBeFalse()
        ->and(ServiceVersion::make('170')->matchesPrefix('17'))->toBeFalse()
        ->and(ServiceVersion::make('17.2')->matchesPrefix('1'))->toBeFalse();
});

test('compares at the precision the other version declares', function () {
    expect(ServiceVersion::make('17.2')->isAtLeast('17'))->toBeTrue()
        ->and(ServiceVersion::make('17.2')->isAtLeast('16'))->toBeTrue()
        ->and(ServiceVersion::make('15.6')->isAtLeast('16'))->toBeFalse()
        ->and(ServiceVersion::make('8.4.3')->isAtLeast('8.4'))->toBeTrue()
        ->and(ServiceVersion::make('8.0.36')->isAtLeast('8.4'))->toBeFalse();
});

test('compares minor versions rather than stopping at the major', function () {
    expect(ServiceVersion::make('8.4')->isBelow('8.5'))->toBeTrue()
        ->and(ServiceVersion::make('8.5')->isBelow('8.4'))->toBeFalse();
});

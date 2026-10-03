<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use RoundlyConsulting\OpeningHours\Cache\DefinitionCache;
use RoundlyConsulting\OpeningHours\Support\Materialize;
use RoundlyConsulting\OpeningHours\Support\TimezoneResolver;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

/*
 | A typo in a host's config or env must fail loudly, never quietly become a default. A
 | blank or non-string cache prefix used to become `opening-hours`, a cache store, queue or
 | connection that was not a non-empty string quietly meant the default one, and a
 | non-string timezone fell through to the app's.
 */

it('refuses a blank or non-string cache prefix (strict config)', function (mixed $prefix): void {
    config()->set('opening-hours.cache.prefix', $prefix);

    expect(fn () => app(DefinitionCache::class)->key(1, 1))
        ->toThrow(InvalidConfigurationException::class, 'opening-hours.cache.prefix');
})->with(['blank' => '', 'whitespace' => ' ', 'array' => [['oh']], 'integer' => 5]);

it('uses the packaged prefix when none is configured (strict config)', function (): void {
    config()->set('opening-hours.cache.prefix', null);

    expect(app(DefinitionCache::class)->key(1, 2))->toStartWith('opening-hours:1:2:');
});

it('refuses a blank or non-string cache store (strict config)', function (mixed $store): void {
    config()->set('opening-hours.cache.store', $store);

    expect(fn () => app(DefinitionCache::class)->store())
        ->toThrow(InvalidConfigurationException::class, 'opening-hours.cache.store');
})->with(['blank' => '', 'array' => [['redis']], 'bool' => true]);

it('refuses a blank or non-string queue or connection (strict config)', function (string $key, Closure $read, mixed $value): void {
    config()->set($key, $value);

    expect($read)->toThrow(InvalidConfigurationException::class, $key);
})->with([
    'connection' => ['opening-hours.materialize.connection', fn () => Materialize::connection()],
    'queue' => ['opening-hours.materialize.queue', fn () => Materialize::queue()],
])->with(['blank' => '', 'array' => [['hours']], 'integer' => 3]);

it('uses the default queue and connection when none is configured (strict config)', function (): void {
    config()->set('opening-hours.materialize.connection', null);
    config()->set('opening-hours.materialize.queue', null);

    expect(Materialize::connection())->toBeNull()
        ->and(Materialize::queue())->toBeNull();
});

it('refuses a non-string default timezone (strict config)', function (mixed $timezone): void {
    config()->set('opening-hours.timezone', $timezone);

    expect(fn () => TimezoneResolver::fallback())
        ->toThrow(InvalidConfigurationException::class, 'opening-hours.timezone');
})->with(['array' => [['UTC']], 'integer' => 2, 'bool' => true]);

it('reads an empty default timezone as the app timezone (strict config)', function (): void {
    config()->set('opening-hours.timezone', '');
    config()->set('app.timezone', 'Asia/Tokyo');

    expect(TimezoneResolver::fallback())->toBe('Asia/Tokyo');
});

it('keeps the about section rendering on a malformed host config (strict config)', function (): void {
    config()->set('opening-hours.timezone', ['UTC']);
    config()->set('opening-hours.cache.store', '');

    Artisan::call('about', ['--only' => 'opening-hours']);

    expect(Artisan::output())
        ->toMatch('/Default timezone \.+ INVALID/')
        ->toMatch('/Cache store \.+ INVALID/');
});

it('reports the facade alias a true switch registers (strict config)', function (): void {
    config()->set('opening-hours.facade_alias', true);

    Artisan::call('about', ['--only' => 'opening-hours']);

    expect(Artisan::output())->toMatch('/Facade alias \.+ OpeningHours/');
});

it('reports an unparseable facade alias switch as invalid (strict config)', function (): void {
    config()->set('opening-hours.facade_alias', 2);

    Artisan::call('about', ['--only' => 'opening-hours']);

    expect(Artisan::output())->toMatch('/Facade alias \.+ INVALID/');
});

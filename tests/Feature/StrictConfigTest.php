<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Artisan;
use RoundlyConsulting\OpeningHours\Cache\DefinitionCache;
use RoundlyConsulting\OpeningHours\Enums\Weekday;
use RoundlyConsulting\OpeningHours\Enums\WeekMode;
use RoundlyConsulting\OpeningHours\Support\Materialize;
use RoundlyConsulting\OpeningHours\Support\Settings;
use RoundlyConsulting\OpeningHours\Support\TimezoneResolver;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

/*
 | A typo in a host's config or env must fail loudly, never quietly become a default. A
 | non-string cache prefix used to become `opening-hours`, a cache store, queue or
 | connection that was not a string quietly meant the default one, and a non-string
 | timezone fell through to the app's. A blank value (a host's `KEY=`) is different: it is
 | not set, so the default applies.
 */

it('refuses a non-string cache prefix (strict config)', function (mixed $prefix): void {
    config()->set('opening-hours.cache.prefix', $prefix);

    expect(fn () => app(DefinitionCache::class)->key(1, 1))
        ->toThrow(InvalidConfigurationException::class, 'opening-hours.cache.prefix');
})->with(['array' => [['oh']], 'integer' => 5]);

it('uses the packaged prefix when none is set (strict config)', function (?string $prefix): void {
    config()->set('opening-hours.cache.prefix', $prefix);

    expect(app(DefinitionCache::class)->key(1, 2))->toStartWith('opening-hours:1:2:');
})->with(['absent' => null, 'blank' => '', 'whitespace' => ' ']);

it('refuses a non-string cache store (strict config)', function (mixed $store): void {
    config()->set('opening-hours.cache.store', $store);

    expect(fn () => app(DefinitionCache::class)->store())
        ->toThrow(InvalidConfigurationException::class, 'opening-hours.cache.store');
})->with(['array' => [['redis']], 'bool' => true]);

it('uses the default cache store when none is set (strict config)', function (?string $store): void {
    config()->set('opening-hours.cache.store', $store);

    Artisan::call('about', ['--only' => 'opening-hours']);

    expect(Settings::cacheStore())->toBeNull()
        ->and(app(DefinitionCache::class)->store())->toBeInstanceOf(Repository::class)
        ->and(Artisan::output())->toMatch('/Cache store \.+ default/');
})->with(['absent' => null, 'blank' => '', 'whitespace' => ' ']);

it('refuses a non-string queue or connection (strict config)', function (string $key, Closure $read, mixed $value): void {
    config()->set($key, $value);

    expect($read)->toThrow(InvalidConfigurationException::class, $key);
})->with([
    'connection' => ['opening-hours.materialize.connection', fn () => Materialize::connection()],
    'queue' => ['opening-hours.materialize.queue', fn () => Materialize::queue()],
])->with(['array' => [['hours']], 'integer' => 3]);

it('uses the default queue and connection when none is set (strict config)', function (?string $unset): void {
    config()->set('opening-hours.materialize.connection', $unset);
    config()->set('opening-hours.materialize.queue', $unset);

    expect(Materialize::connection())->toBeNull()
        ->and(Materialize::queue())->toBeNull();
})->with(['absent' => null, 'blank' => '', 'whitespace' => ' ']);

it('takes the shipped default calendar, first weekday and week mode when they are not set (strict config)', function (?string $unset): void {
    config()->set('opening-hours.default_calendar', $unset);
    config()->set('opening-hours.first_day_of_week', $unset);
    config()->set('opening-hours.api.week_mode', $unset);

    expect(Settings::defaultCalendar())->toBe('default')
        ->and(Settings::firstDayOfWeek())->toBe(Weekday::Monday)
        ->and(Settings::weekMode())->toBe(WeekMode::Upcoming);
})->with(['absent' => null, 'blank' => '', 'whitespace' => '  ']);

it('refuses a junk default calendar, first weekday or week mode (strict config)', function (string $key, mixed $value, Closure $read): void {
    config()->set($key, $value);

    expect($read)->toThrow(InvalidConfigurationException::class, $key);
})->with([
    'calendar array' => ['opening-hours.default_calendar', ['default'], fn () => Settings::defaultCalendar()],
    'weekday typo' => ['opening-hours.first_day_of_week', 'mondya', fn () => Settings::firstDayOfWeek()],
    'week mode typo' => ['opening-hours.api.week_mode', 'calendar-week', fn () => Settings::weekMode()],
]);

it('reads an unset prune window as off (strict config)', function (?string $unset): void {
    config()->set('opening-hours.prune.exceptions_after_days', $unset);
    config()->set('opening-hours.prune.trashed_after_days', $unset);

    expect(Settings::pruneExceptionsAfterDays())->toBeNull()
        ->and(Settings::pruneTrashedAfterDays())->toBeNull();
})->with(['absent' => null, 'blank' => '', 'whitespace' => ' ']);

it('caches for a day on a blank ttl, and forever only on an explicit null (strict config)', function (): void {
    config()->set('opening-hours.cache.ttl', '');
    expect(app(DefinitionCache::class)->ttl())->toBe(86400);

    config()->set('opening-hours.cache.ttl', ' ');
    expect(app(DefinitionCache::class)->ttl())->toBe(86400);

    config()->set('opening-hours.cache.ttl', null);
    expect(app(DefinitionCache::class)->ttl())->toBeNull();

    config()->set('opening-hours.cache.ttl', 'a day');
    expect(fn () => app(DefinitionCache::class)->ttl())->toThrow(InvalidConfigurationException::class, 'opening-hours.cache.ttl');
});

it('refuses a non-string default timezone (strict config)', function (mixed $timezone): void {
    config()->set('opening-hours.timezone', $timezone);

    expect(fn () => TimezoneResolver::fallback())
        ->toThrow(InvalidConfigurationException::class, 'opening-hours.timezone');
})->with(['array' => [['UTC']], 'integer' => 2, 'bool' => true]);

it('reads an unset default timezone as the app timezone (strict config)', function (?string $timezone): void {
    config()->set('opening-hours.timezone', $timezone);
    config()->set('app.timezone', 'Asia/Tokyo');

    Artisan::call('about', ['--only' => 'opening-hours']);

    expect(TimezoneResolver::fallback())->toBe('Asia/Tokyo')
        ->and(Artisan::output())->toMatch('/Default timezone \.+ app/');
})->with(['absent' => null, 'blank' => '', 'whitespace' => '  ']);

it('keeps the about section rendering on a malformed host config (strict config)', function (): void {
    config()->set('opening-hours.timezone', ['UTC']);
    config()->set('opening-hours.cache.store', ['redis']);

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

it('reports the declared facade alias when the switch is blank (strict config)', function (string $alias): void {
    config()->set('opening-hours.facade_alias', $alias);

    Artisan::call('about', ['--only' => 'opening-hours']);

    expect(Artisan::output())->toMatch('/Facade alias \.+ OpeningHours/');
})->with(['blank' => '', 'whitespace' => '  ']);

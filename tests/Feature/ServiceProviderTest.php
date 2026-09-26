<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use RoundlyConsulting\OpeningHours\Cache\DefinitionCache;
use RoundlyConsulting\OpeningHours\Facades\OpeningHours as Hours;
use RoundlyConsulting\OpeningHours\OpeningHours;
use RoundlyConsulting\OpeningHours\OpeningHoursManager;
use RoundlyConsulting\OpeningHours\Tests\Fixtures\Clinic;
use RoundlyConsulting\OpeningHours\Tests\Fixtures\CustomCalendar;

function aboutOpeningHours(): string
{
    Artisan::call('about', ['--only' => 'opening-hours']);

    return Artisan::output();
}

it('binds a scoped manager and a singleton cache', function (): void {
    expect(app(OpeningHoursManager::class))->toBe(app(OpeningHoursManager::class))
        ->and(app(DefinitionCache::class))->toBe(app(DefinitionCache::class));

    app()->forgetScopedInstances();

    expect(app(OpeningHoursManager::class))->toBeInstanceOf(OpeningHoursManager::class);
});

it('registers the global facade alias', function (): void {
    expect(class_exists('OpeningHours'))->toBeTrue()
        ->and(\OpeningHours::make(['week' => ['monday' => ['09:00-10:00']]], 'UTC'))->toBeInstanceOf(OpeningHours::class);
});

it('drives the manager through the facade', function (): void {
    $clinic = Clinic::query()->create();
    Hours::sync($clinic, ['week' => ['monday' => ['09:00-10:00']]]);

    expect(Hours::has($clinic))->toBeTrue()
        ->and(Hours::calendar($clinic)?->key)->toBe('default')
        ->and(Hours::for($clinic)->revision())->toBe(1)
        ->and(Hours::edit($clinic)->toData()->schedules)->toHaveCount(1)
        ->and(Hours::validate(['week' => ['monday' => ['9-10']]]))->toHaveCount(1)
        ->and(Hours::make([])->isAlwaysClosed())->toBeTrue();

    Hours::refresh($clinic);
    Hours::flushMemo();

    expect(Hours::for($clinic)->revision())->toBe(2);
    Hours::refresh(Clinic::query()->create());
});

it('contributes an about section without leaking the cache store', function (): void {
    config()->set('opening-hours.cache.store', 'acme-redis-hours-store');
    config()->set('opening-hours.timezone', 'Europe/Bratislava');
    config()->set('opening-hours.models.calendar', CustomCalendar::class);

    expect('opening-hours')->toLeakNoSecrets(
        secrets: ['acme-redis-hours-store', CustomCalendar::class],
        mustRender: ['Calendar model', 'Schedule model', 'Exception rule model', 'bigint', 'Europe/Bratislava', 'ON', 'custom', 'CustomCalendar'],
    );
});

it('reports defaults and a disabled alias in about', function (): void {
    config()->set('opening-hours.facade_alias', null);
    config()->set('opening-hours.cache.enabled', false);

    expect(aboutOpeningHours())->toContain('DISABLED')->toContain('OFF')->toContain('app')->toContain('default');
});

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\OpeningHours\Cache\DefinitionCache;
use RoundlyConsulting\OpeningHours\Engine\Definition;
use RoundlyConsulting\OpeningHours\Facades\OpeningHours;
use RoundlyConsulting\OpeningHours\OpeningHoursManager;
use RoundlyConsulting\OpeningHours\Tests\Fixtures\Clinic;

function cachedClinic(): Clinic
{
    $clinic = Clinic::query()->create();
    $clinic->setOpeningHours(['week' => ['monday' => ['09:00-10:00']], 'exceptions' => [['date' => '12-25']]]);

    return $clinic;
}

it('caches the definition under a revision key', function (): void {
    $clinic = cachedClinic();
    $calendar = $clinic->openingHoursCalendar();
    $cache = app(DefinitionCache::class);

    expect($cache->key($calendar->id, 1))->toBe('opening-hours:'.$calendar->id.':1:v'.Definition::FORMAT)
        ->and(Cache::get($cache->key($calendar->id, 1)))->toBeArray()
        ->and($cache->get($calendar->id, 1)?->exceptions)->toHaveCount(1);
});

it('serves repeated reads from the request memo without queries', function (): void {
    $clinic = cachedClinic();
    $clinic->load('openingHoursCalendars');
    $clinic->openingHours();

    DB::enableQueryLog();
    DB::flushQueryLog();
    $clinic->openingHours()->isOpenAt(at('2026-09-28 09:30', 'UTC'));

    expect(DB::getQueryLog())->toBe([]);
});

it('reads a list page with a warm cache in one query per page', function (): void {
    foreach (range(1, 5) as $i) {
        cachedClinic();
    }

    app(OpeningHoursManager::class)->flushMemo();

    DB::enableQueryLog();
    DB::flushQueryLog();

    $clinics = Clinic::query()->withOpeningHours()->get();
    $open = $clinics->filter(fn (Clinic $clinic) => $clinic->openingHours()->isOpenAt(at('2026-09-28 09:30', 'UTC')));

    expect($open)->toHaveCount(5)
        ->and(count(DB::getQueryLog()))->toBe(2);
});

it('compiles from the database when the cache is off', function (): void {
    config()->set('opening-hours.cache.enabled', false);
    $clinic = cachedClinic();
    app(OpeningHoursManager::class)->flushMemo();
    Cache::flush();

    expect($clinic->openingHours()->forDate('2026-12-25')->isClosed())->toBeTrue()
        ->and(Cache::get(app(DefinitionCache::class)->key($clinic->openingHoursCalendar()->id, 1)))->toBeNull()
        ->and(app(DefinitionCache::class)->get(1, 1))->toBeNull();
});

it('recovers from a corrupt or foreign payload', function (mixed $payload): void {
    $clinic = cachedClinic();
    $calendar = $clinic->openingHoursCalendar();
    $cache = app(DefinitionCache::class);
    Cache::put($cache->key($calendar->id, 1), $payload);
    app(OpeningHoursManager::class)->flushMemo();

    expect($clinic->openingHours()->forDate('2026-12-25')->isClosed())->toBeTrue()
        ->and(Cache::get($cache->key($calendar->id, 1)))->toBeArray();
})->with([['garbage'], [['schedules' => 'x']], [['exceptions' => [['date' => 'nope']]]]]);

it('uses a custom store and a forever ttl', function (): void {
    config()->set('cache.stores.hours', ['driver' => 'array']);
    config()->set('opening-hours.cache.store', 'hours');
    config()->set('opening-hours.cache.ttl', null);
    config()->set('opening-hours.cache.prefix', 'oh');
    $clinic = cachedClinic();
    $cache = app(DefinitionCache::class);

    expect($cache->ttl())->toBeNull()
        ->and(Cache::store('hours')->get('oh:'.$clinic->openingHoursCalendar()->id.':1:v1'))->toBeArray();

    $cache->forget($clinic->openingHoursCalendar()->id, 1);
    expect(Cache::store('hours')->get('oh:'.$clinic->openingHoursCalendar()->id.':1:v1'))->toBeNull();

    config()->set('opening-hours.cache.ttl', 60);
    expect($cache->ttl())->toBe(60);
});

it('forgets memo entries when a calendar changes', function (): void {
    $clinic = cachedClinic();
    $clinic->openingHours();
    OpeningHours::refresh($clinic);

    expect($clinic->openingHours()->revision())->toBe(2);
});

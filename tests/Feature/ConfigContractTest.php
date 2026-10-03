<?php

declare(strict_types=1);

use RoundlyConsulting\OpeningHours\Support\Limits;
use RoundlyConsulting\OpeningHours\Support\Materialize;
use RoundlyConsulting\OpeningHours\Support\Settings;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * The config contract in both directions: every key the code reads is shipped,
 * every shipped leaf is read. Most reads go through the toolkit's validated
 * accessors (`Config::integer('opening-hours.search_days', …)`,
 * `ModelResolver::for('opening-hours.models.calendar', …)`,
 * `KeyType::fromConfig('opening-hours.key_type')` in the migrations) rather than a
 * `config(` token, so the `opening-hours.` prefix is what makes them visible.
 */
it('ships exactly the config keys it reads', function (): void {
    expect(__DIR__.'/../../config/opening-hours.php')->toSatisfyConfigContract(
        [__DIR__.'/../../src', __DIR__.'/../../database'],
        ['extraReadPrefixes' => ['opening-hours.']],
    );
});

it('caps list limits at what the position columns hold on every engine', function (): void {
    // `position` is a smallint on PostgreSQL (max 32 767): a larger exception limit would
    // let a sync pass validation and then fail inside the transaction.
    config()->set('opening-hours.limits.exceptions', 32_767);
    expect(Limits::exceptions())->toBe(32_767);

    config()->set('opening-hours.limits.exceptions', 32_768);
    expect(fn () => Limits::exceptions())->toThrow(InvalidConfigurationException::class);
});

it('reads boolean env switches written as on/off or yes/no', function (): void {
    $switches = ['OPENING_HOURS_DELETE_WITH_OWNER' => 'off', 'OPENING_HOURS_CACHE_ENABLED' => 'no', 'OPENING_HOURS_MATERIALIZE' => 'on'];

    foreach ($switches as $name => $value) {
        putenv("{$name}={$value}");
    }

    try {
        config()->set('opening-hours', require __DIR__.'/../../config/opening-hours.php');

        expect(Settings::deleteWithOwner())->toBeFalse()
            ->and(Config::boolean('opening-hours.cache.enabled', true))->toBeFalse()
            ->and(Materialize::enabled())->toBeTrue();
    } finally {
        foreach (array_keys($switches) as $name) {
            putenv($name);
        }
    }
});

it('refuses a mistyped boolean env switch (strict config)', function (): void {
    putenv('OPENING_HOURS_DELETE_WITH_OWNER=disabled');

    try {
        config()->set('opening-hours', require __DIR__.'/../../config/opening-hours.php');

        expect(fn () => Settings::deleteWithOwner())
            ->toThrow(InvalidConfigurationException::class, 'Configuration value [opening-hours.delete_with_owner] must be a boolean');
    } finally {
        putenv('OPENING_HOURS_DELETE_WITH_OWNER');
    }
});

it('hands mistyped integer env values to the strict reader raw (strict config)', function (string $name, Closure $read): void {
    // An `(int)` cast in the config file used to turn `abc` into 0 — and `days_behind`
    // accepts 0, so the typo was silently a valid setting. The reader must see the string.
    putenv("{$name}=abc");

    try {
        config()->set('opening-hours', require __DIR__.'/../../config/opening-hours.php');

        expect($read)->toThrow(InvalidConfigurationException::class, 'must be an integer');
    } finally {
        putenv($name);
    }
})->with([
    'search days' => ['OPENING_HOURS_SEARCH_DAYS', fn () => Settings::searchDays()],
    'max query days' => ['OPENING_HOURS_MAX_QUERY_DAYS', fn () => Settings::maxQueryDays()],
    'days ahead' => ['OPENING_HOURS_MATERIALIZE_DAYS_AHEAD', fn () => Materialize::daysAhead()],
    'days behind' => ['OPENING_HOURS_MATERIALIZE_DAYS_BEHIND', fn () => Materialize::daysBehind()],
]);

it('reads integer env strings and keeps the integer defaults', function (): void {
    putenv('OPENING_HOURS_SEARCH_DAYS=30');

    try {
        config()->set('opening-hours', require __DIR__.'/../../config/opening-hours.php');

        expect(Settings::searchDays())->toBe(30)
            ->and(Settings::maxQueryDays())->toBe(366)
            ->and(Materialize::daysAhead())->toBe(60)
            ->and(Materialize::daysBehind())->toBe(1);
    } finally {
        putenv('OPENING_HOURS_SEARCH_DAYS');
    }
});

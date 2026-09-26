<?php

declare(strict_types=1);

use RoundlyConsulting\OpeningHours\Support\Limits;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

/**
 * The config contract in both directions: every key the code reads is shipped,
 * every shipped leaf is read. Most reads go through the toolkit's validated
 * accessors (`Config::intBetween('opening-hours.search_days', …)`,
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

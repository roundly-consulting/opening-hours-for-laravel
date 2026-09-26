<?php

declare(strict_types=1);

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

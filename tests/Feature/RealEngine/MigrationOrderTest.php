<?php

declare(strict_types=1);

use RoundlyConsulting\OpeningHours\OpeningHoursServiceProvider;
use RoundlyConsulting\PackageToolkit\Enums\DatabaseDriver;
use RoundlyConsulting\Testing\Database\DriverMatrix;

$migrations = __DIR__.'/../../../database/migrations';

/**
 * Six files, five FK edges: schedules, exception rules and intervals point at
 * calendars; schedule ranges at schedules; exception ranges at exception rules.
 */
it('creates every foreign key target before the table that references it', function () use ($migrations): void {
    expect($migrations)->toHaveRunnableMigrationOrder(foreignKeys: 5);
});

it('never auto-loads its migrations — the host publishes them', function (): void {
    expect(OpeningHoursServiceProvider::class)->toNotAutoLoadMigrations();
});

it('publishes its migrations timestamp-injected into the host', function (): void {
    expect(OpeningHoursServiceProvider::class)->toPublishMigrationsTimestamped('opening-hours-migrations', 6);
});

it('applies its migrations on postgres', function () use ($migrations): void {
    expect($migrations)->toApplyOnConnection('pgsql', migrations: 6);
})->skip(fn (): bool => ! test()->connectionAvailable('pgsql'), 'no postgres connection available');

it('rejects a child-before-parent order on postgres', function () use ($migrations): void {
    expect($migrations)->toRejectBrokenOrderOnConnection(fn (array $files): array => array_reverse($files), 'pgsql');
})->skip(fn (): bool => ! test()->connectionAvailable('pgsql'), 'no postgres connection available');

/**
 * The driver-truth pin: a leg that quietly stayed on SQLite fails here instead
 * of passing as a "postgres" or "mysql" run.
 */
it('runs on the driver the environment declares', function (): void {
    expect(DatabaseDriver::current())->toBe(DatabaseDriver::from(DriverMatrix::driver()));
});

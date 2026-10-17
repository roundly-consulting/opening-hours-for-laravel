<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Tests;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\OpeningHours\OpeningHoursServiceProvider;
use RoundlyConsulting\OpeningHours\Tests\Fixtures\Clinic;
use RoundlyConsulting\Testing\PackageTestCase;

abstract class TestCase extends PackageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Clinic::$providers = [];
    }

    /** @return list<class-string<ServiceProvider>> */
    protected function packageProviders(): array
    {
        return [OpeningHoursServiceProvider::class];
    }

    /** @return list<class-string<ServiceProvider>|string> */
    protected function migrationSources(): array
    {
        // Fixtures: the host-owned owner tables, migrated with the package's own.
        return [OpeningHoursServiceProvider::class, __DIR__.'/Fixtures/migrations'];
    }

    /**
     * Migrate once per worker and reset only the data between tests. The migrations read
     * config alone (the key type), which the schema signature already covers.
     */
    protected function keepsMigratedSchema(): bool
    {
        return true;
    }
}

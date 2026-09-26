<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
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
        return [OpeningHoursServiceProvider::class];
    }

    /**
     * Host-owned owner tables (not shipped): owners are polymorphic, so their
     * tables are the host's business.
     */
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        Schema::create('test_owners', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('timezone')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('plain_owners', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });

        Schema::create('test_bookings', function (Blueprint $table): void {
            $table->id();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->unsignedSmallInteger('seats')->nullable();
            $table->string('status')->default('confirmed');
        });

        Schema::create('uuid_owners', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->timestamps();
        });
    }
}

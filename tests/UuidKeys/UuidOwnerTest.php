<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RoundlyConsulting\OpeningHours\Models\Calendar;
use RoundlyConsulting\OpeningHours\Models\Interval;
use RoundlyConsulting\OpeningHours\Tests\Fixtures\UuidOwner;

it('stores calendars of uuid-keyed owners', function (): void {
    $owner = UuidOwner::query()->create();
    $owner->setOpeningHours(['week' => ['monday' => ['09:00-10:00']]]);

    expect(Calendar::query()->firstOrFail()->owner_id)->toBe($owner->id)
        ->and($owner->openingHours()->isOpenAt(at('2026-09-28 09:30', 'UTC')))->toBeTrue()
        ->and(Schema::getColumnType('opening_hours_calendars', 'owner_id'))->not->toBeIn(['integer', 'bigint', 'int8']);
});

it('creates factory rows with an owner key of the configured type', function (): void {
    // Without forOwner(): a uuid owner_id column refuses an integer on a strict engine (PostgreSQL).
    $calendar = Calendar::factory()->create();
    $interval = Interval::factory()->create();

    expect(Str::isUuid((string) $calendar->owner_id))->toBeTrue()
        ->and(Str::isUuid((string) $interval->owner_id))->toBeTrue()
        ->and(Calendar::query()->count())->toBe(2);
});

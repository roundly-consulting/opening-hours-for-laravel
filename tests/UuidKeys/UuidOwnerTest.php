<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\OpeningHours\Models\Calendar;
use RoundlyConsulting\OpeningHours\Tests\Fixtures\UuidOwner;

it('stores calendars of uuid-keyed owners', function (): void {
    $owner = UuidOwner::query()->create();
    $owner->setOpeningHours(['week' => ['monday' => ['09:00-10:00']]]);

    expect(Calendar::query()->firstOrFail()->owner_id)->toBe($owner->id)
        ->and($owner->openingHours()->isOpenAt(at('2026-09-28 09:30', 'UTC')))->toBeTrue()
        ->and(Schema::getColumnType('opening_hours_calendars', 'owner_id'))->not->toBeIn(['integer', 'bigint', 'int8']);
});

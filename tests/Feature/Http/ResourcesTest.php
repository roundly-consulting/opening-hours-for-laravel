<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\OpeningHours\Availability\Slot;
use RoundlyConsulting\OpeningHours\DataTransferObjects\CalendarData;
use RoundlyConsulting\OpeningHours\Enums\UnavailableReason;
use RoundlyConsulting\OpeningHours\Http\Resources\CalendarResource;
use RoundlyConsulting\OpeningHours\Http\Resources\DayHoursResource;
use RoundlyConsulting\OpeningHours\Http\Resources\OpeningStatusResource;
use RoundlyConsulting\OpeningHours\Http\Resources\SlotResource;
use RoundlyConsulting\OpeningHours\Tests\Fixtures\Clinic;

afterEach(fn () => CarbonImmutable::setTestNow());

function resolveResource(JsonResource $resource): array
{
    return $resource->toArray(Request::create('/'));
}

it('renders the status shape', function (): void {
    $hours = hours([
        'week' => ['monday' => ['08:00-12:00'], 'saturday' => ['09:00-14:00']],
        'exceptions' => [['date' => '2026-12-24', 'label' => 'Christmas']],
    ]);

    expect(resolveResource(OpeningStatusResource::make($hours)->at(at('2026-09-26 12:00'))))->toBe([
        'timezone' => 'Europe/Bratislava',
        'at' => '2026-09-26T12:00:00+02:00',
        'is_open' => true,
        'current_period' => ['start' => '2026-09-26T09:00:00+02:00', 'end' => '2026-09-26T14:00:00+02:00', 'label' => null, 'start_unbounded' => false, 'end_unbounded' => false],
        'next_open' => '2026-09-28T08:00:00+02:00',
        'next_close' => '2026-09-26T14:00:00+02:00',
        'today' => ['date' => '2026-09-26', 'weekday' => 'saturday', 'weekday_label' => 'Saturday', 'closed' => false, 'all_day' => false, 'source' => 'schedule', 'label' => null,
            'ranges' => [['from' => '09:00', 'to' => '14:00', 'label' => null, 'capacity' => null]], 'text' => '09:00–14:00'],
        'week' => resolveResource(OpeningStatusResource::make($hours)->at(at('2026-09-26 12:00')))['week'],
        'upcoming_exceptions' => [],
    ]);
});

it('lists seven upcoming dates or the calendar week, and upcoming exceptions', function (): void {
    CarbonImmutable::setTestNow(at('2026-12-01 10:00'));
    $hours = hours(['week' => ['monday' => ['08:00-12:00']], 'exceptions' => [['date' => '2026-12-24', 'label' => 'Christmas', 'ranges' => ['09:00-10:00']]]]);

    $upcoming = resolveResource(OpeningStatusResource::make($hours));
    config()->set('opening-hours.api.week_mode', 'calendar_week');
    $calendarWeek = resolveResource(OpeningStatusResource::make($hours));

    expect(array_column($upcoming['week'], 'date'))->toBe(['2026-12-01', '2026-12-02', '2026-12-03', '2026-12-04', '2026-12-05', '2026-12-06', '2026-12-07'])
        ->and($calendarWeek['week'][0]['date'])->toBe('2026-11-30')
        ->and($upcoming['upcoming_exceptions'])->toBe([['date' => '2026-12-24', 'closed' => false, 'label' => 'Christmas', 'ranges' => [['from' => '09:00', 'to' => '10:00', 'label' => null, 'capacity' => null]]]])
        ->and($upcoming['is_open'])->toBeFalse();
});

it('reports a closed-all-week status', function (): void {
    $status = resolveResource(OpeningStatusResource::make(hours([]))->at(at('2026-09-26 12:00')));

    expect($status['next_open'])->toBeNull()
        ->and($status['current_period'])->toBeNull()
        ->and($status['today']['text'])->toBe('Closed');
});

it('renders a definition that round-trips, hiding meta by default', function (): void {
    $clinic = Clinic::query()->create();
    $clinic->setOpeningHours(['meta' => ['secret' => 'x'], 'week' => ['monday' => [['from' => '09:00', 'to' => '10:00', 'meta' => ['a' => 1]]]], 'exceptions' => [['date' => '12-25']]]);
    $calendar = $clinic->openingHoursCalendar();

    $array = resolveResource(CalendarResource::make($calendar));

    expect($array)->toHaveKeys(['id', 'key', 'revision', 'updated_at', 'timezone', 'schedules', 'exceptions'])
        ->and($array)->not->toHaveKey('meta')
        ->and($array['revision'])->toBe(1)
        ->and($array['schedules'][0]['id'])->toBeInt()
        ->and(CalendarData::fromArray($array)->toArray(false))->toBe($clinic->openingHours()->definition()->toArray(false));

    config()->set('opening-hours.api.expose_meta', true);
    $withMeta = resolveResource(CalendarResource::make($calendar));

    expect($withMeta['meta'])->toBe(['secret' => 'x'])
        ->and($clinic->setOpeningHours($withMeta, expectedRevision: $withMeta['revision'])->definition()->meta)->toBe(['secret' => 'x']);
});

it('renders a detached definition and a day', function (): void {
    $data = CalendarData::fromArray(['week' => ['monday' => ['09:00-10:00']]])->withRevision(4);
    $day = hours(['week' => ['monday' => ['09:00-10:00']]])->forDate('2026-09-28');

    expect(resolveResource(CalendarResource::make($data)))->toMatchArray(['id' => null, 'key' => null, 'revision' => 4])
        ->and(resolveResource(DayHoursResource::make($day))['text'])->toBe('09:00–10:00');
});

it('renders a slot', function (): void {
    $slot = new Slot(at('2026-09-28 09:00'), at('2026-09-28 09:30'), false, 0, UnavailableReason::Busy);

    expect(resolveResource(SlotResource::make($slot)))->toBe([
        'start' => '2026-09-28T09:00:00+02:00', 'end' => '2026-09-28T09:30:00+02:00', 'available' => false, 'remaining_capacity' => 0, 'reason' => 'busy',
    ]);
});

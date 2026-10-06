<?php

declare(strict_types=1);

use RoundlyConsulting\OpeningHours\Builders\CalendarBuilder;
use RoundlyConsulting\OpeningHours\DataTransferObjects\ExceptionData;
use RoundlyConsulting\OpeningHours\Enums\Recurrence;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidDateException;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidTimeException;
use RoundlyConsulting\OpeningHours\ValueObjects\AbsoluteWindow;
use RoundlyConsulting\OpeningHours\ValueObjects\TimeRange;
use RoundlyConsulting\OpeningHours\ValueObjects\YearlyWindow;

it('makes a closed one-off day from a Y-m-d string', function (): void {
    $data = ExceptionData::make('2026-12-24', label: 'Eve', meta: ['k' => 1]);

    expect($data->window)->toEqual(AbsoluteWindow::single(ld('2026-12-24')))
        ->and($data->isClosed())->toBeTrue()
        ->and($data->label)->toBe('Eve')
        ->and($data->meta)->toBe(['k' => 1])
        ->and($data->id)->toBeNull();
});

it('makes a span with parsed ranges', function (): void {
    $data = ExceptionData::make(ld('2026-08-03'), '2026-08-07', ['10:00-12:00', TimeRange::fromString('13:00-14:00')]);

    expect($data->window)->toEqual(new AbsoluteWindow(ld('2026-08-03'), ld('2026-08-07')))
        ->and(array_map(fn (TimeRange $range) => $range->toString(), $data->ranges))->toBe(['10:00–12:00', '13:00–14:00']);
});

it('makes a yearly window from m-d or on request', function (): void {
    expect(ExceptionData::make('12-24', '12-26')->window)->toEqual(new YearlyWindow(md('12-24'), md('12-26')))
        ->and(ExceptionData::make('12-24', yearly: true)->window)->toEqual(new YearlyWindow(md('12-24'), md('12-24')))
        ->and(ExceptionData::make(ld('2026-01-01'), yearly: true)->recurrence())->toBe(Recurrence::Yearly)
        ->and(ExceptionData::make('12-24', ld('2026-12-26'), yearly: true)->window)->toEqual(new YearlyWindow(md('12-24'), md('12-26')));
});

it('rejects malformed dates and ranges', function (Closure $make, string $exception): void {
    expect($make)->toThrow($exception);
})->with([
    'date' => [fn () => ExceptionData::make('2026-13-01'), InvalidDateException::class],
    'month-day' => [fn () => ExceptionData::make('02-30', yearly: true), InvalidDateException::class],
    'yearly Y-m-d string' => [fn () => ExceptionData::make('2026-12-24', yearly: true), InvalidDateException::class],
    'range' => [fn () => ExceptionData::make('2026-12-24', ranges: ['nope']), InvalidTimeException::class],
]);

it('is what the builder uses', function (): void {
    $built = CalendarBuilder::make()->exception('12-24', '12-26', ['09:00-12:00'], 'Holidays')->toData()->exceptions[0];

    expect($built)->toEqual(ExceptionData::make('12-24', '12-26', ['09:00-12:00'], 'Holidays'));
});

it('keeps its ranges a list whatever keys they came with', function (array $ranges): void {
    $builder = CalendarBuilder::make()->exception('2026-12-24', ranges: $ranges);

    expect($builder->violations()->isEmpty())->toBeTrue()
        ->and(array_is_list($builder->toData()->exceptions[0]->ranges))->toBeTrue()
        ->and(array_map(fn (TimeRange $range) => $range->toString('-'), ExceptionData::make('2026-12-24', ranges: $ranges)->ranges))->toBe(['09:00-10:00', '11:00-12:00']);
})->with([
    'filtered' => [array_filter(['09:00-10:00', '', '11:00-12:00'])],
    'string keys' => [['morning' => '09:00-10:00', 'noon' => '11:00-12:00']],
]);

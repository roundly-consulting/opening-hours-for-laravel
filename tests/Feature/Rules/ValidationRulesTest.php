<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use RoundlyConsulting\OpeningHours\Rules\ValidOpeningHours;
use RoundlyConsulting\OpeningHours\Rules\ValidTimeRange;

it('reports nested error keys with translated messages', function (): void {
    $validator = Validator::make([
        'opening_hours' => [
            'schedules' => [['week' => ['monday' => ['09:00-12:00', '11:00-13:00', '9:00-10:00']]]],
        ],
    ], ['opening_hours' => ['required', 'array', new ValidOpeningHours]]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->keys())->toBe(['opening_hours.schedules.0.week.monday.2', 'opening_hours.schedules.0.week.monday.1'])
        ->and($validator->errors()->first('opening_hours.schedules.0.week.monday.1'))
        ->toBe('The schedules.0.week.monday.1 range overlaps schedules.0.week.monday.0.');
});

it('translates messages into Slovak', function (): void {
    app()->setLocale('sk');

    $validator = Validator::make(['hours' => ['week' => ['monday' => ['12:00-12:00']]]], ['hours' => [new ValidOpeningHours]]);

    expect($validator->errors()->first('hours.week.monday.0'))->toBe('Rozsah week.monday.0 je prázdny; začína a končí v rovnakom čase.');
});

it('passes a valid payload and honours its options', function (): void {
    $ok = Validator::make(['h' => ['timezone' => 'UTC', 'week' => ['monday' => ['09:00-12:00', '11:00-13:00']]]], [
        'h' => [new ValidOpeningHours(mergeOverlapping: true, requireTimezone: true)],
    ]);
    $missingTz = Validator::make(['h' => ['week' => []]], ['h' => [new ValidOpeningHours(requireTimezone: true)]]);

    expect($ok->passes())->toBeTrue()
        ->and($missingTz->fails())->toBeTrue()
        ->and($missingTz->errors()->keys())->toBe(['h.timezone']);
});

it('fails a non-array payload on the attribute itself', function (): void {
    $validator = Validator::make(['h' => 'nope'], ['h' => [new ValidOpeningHours]]);

    expect($validator->errors()->first('h'))->toBe('The h value has an invalid structure.');
});

it('falls back to the fail callback without a validator', function (): void {
    $messages = [];
    (new ValidOpeningHours)->validate('h', ['timezone' => 'x'], function (string $message) use (&$messages): void {
        $messages[] = $message;
    });

    expect($messages)->toBe(['The timezone timezone is not a valid IANA timezone.']);
});

it('validates a single time range', function (mixed $value, bool $passes): void {
    expect(Validator::make(['r' => $value], ['r' => [new ValidTimeRange]])->passes())->toBe($passes);
})->with([
    ['09:00-12:00', true],
    [['from' => '22:00', 'to' => '02:00'], true],
    ['12:00-12:00', false],
    ['24:00-01:00', false],
    [['from' => '09:00'], false],
    [['from' => '09:00', 'to' => '10:00', 'capacity' => 'x'], false],
    [12, false],
]);

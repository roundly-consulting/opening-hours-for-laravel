<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use RoundlyConsulting\OpeningHours\Enums\ViolationCode;
use RoundlyConsulting\OpeningHours\Rules\ValidOpeningHours;
use RoundlyConsulting\OpeningHours\Rules\ValidTimeRange;
use RoundlyConsulting\OpeningHours\Validation\Violation;

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

    expect($validator->errors()->first('hours.week.monday.0'))->toBe('Hodnota week.monday.0 je prázdny rozsah; začína a končí v rovnakom čase.');
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

    expect($messages)->toBe(['The timezone value is not a valid IANA timezone.']);
});

/**
 * The path already names the field (`timezone`, `schedules.0.label`, `key`…): the
 * noun around it must not repeat it ("The timezone timezone…", "Časové pásmo timezone…").
 */
it('never repeats the attribute word around the path', function (ViolationCode $code, string $path): void {
    $segments = array_values(array_filter(explode('.', $path), fn (string $s): bool => ! ctype_digit($s)));
    $stem = rtrim((string) end($segments), 's');
    $slovak = ['timezone' => 'pásm', 'label' => 'popis', 'meta' => 'meta', 'priority' => 'priorit', 'capacity' => 'kapacit', 'id' => 'identifik',
        'date' => 'dátum', 'window' => 'obdob', 'key' => 'kľúč', 'recurrence' => 'opakov', 'schedule' => 'rozvrh', 'exception' => 'výnimk', 'range' => 'rozsah'][$stem];
    $violation = new Violation($code, $path, ['id' => 5, 'min' => 1, 'max' => 9, 'other' => 'x.0']);

    $english = $violation->message('en');
    $afterPath = implode(' ', array_slice(explode(' ', trim(substr($english, strpos($english, $path) + strlen($path)))), 0, 2));
    $beforePath = mb_strtolower(substr($violation->message('sk'), 0, (int) strpos($violation->message('sk'), $path)));

    expect(stripos($afterPath, $stem))->toBeFalse("en: {$english}")
        ->and(str_contains($beforePath, $slovak))->toBeFalse('sk: '.$violation->message('sk'));
})->with([
    'timezone' => [ViolationCode::InvalidTimezone, 'timezone'],
    'label' => [ViolationCode::LabelTooLong, 'label'],
    'schedule label' => [ViolationCode::LabelTooLong, 'schedules.0.label'],
    'meta' => [ViolationCode::MetaTooLarge, 'meta'],
    'priority' => [ViolationCode::InvalidPriority, 'schedules.0.priority'],
    'capacity' => [ViolationCode::InvalidCapacity, 'week.monday.0.capacity'],
    'unknown id' => [ViolationCode::UnknownId, 'schedules.0.id'],
    'duplicate id' => [ViolationCode::DuplicateId, 'exceptions.1.id'],
    'date' => [ViolationCode::InvalidDate, 'exceptions.0.date'],
    'inverted window' => [ViolationCode::WindowInverted, 'schedules.0.window'],
    'recurrence' => [ViolationCode::RecurrenceMismatch, 'exceptions.0.recurrence'],
    'unbounded window' => [ViolationCode::YearlyWindowUnbounded, 'schedules.0.window'],
    'second base' => [ViolationCode::DuplicateBaseSchedule, 'schedules.1'],
    'ambiguous schedule' => [ViolationCode::AmbiguousScheduleWindow, 'schedules.1'],
    'duplicate exception' => [ViolationCode::DuplicateException, 'exceptions.1'],
    'ambiguous exception' => [ViolationCode::AmbiguousException, 'exceptions.1'],
    'calendar key' => [ViolationCode::InvalidCalendarKey, 'key'],
    // ValidTimeRange reports under the attribute name, e.g. the README's `range`.
    'empty range' => [ViolationCode::EmptyRange, 'range'],
    'range at 24' => [ViolationCode::StartAt24, 'range'],
]);

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

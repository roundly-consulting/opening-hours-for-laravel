<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use RoundlyConsulting\OpeningHours\Rules\ValidOpeningHours;
use RoundlyConsulting\OpeningHours\Rules\ValidTimeRange;
use RoundlyConsulting\OpeningHours\Validation\CalendarParser;
use RoundlyConsulting\OpeningHours\Validation\PathLabel;
use RoundlyConsulting\OpeningHours\Validation\WeekArrayParser;

it('reports nested error keys with translated messages', function (): void {
    $validator = Validator::make([
        'opening_hours' => [
            'schedules' => [['week' => ['monday' => ['09:00-12:00', '11:00-13:00', '9:00-10:00']]]],
        ],
    ], ['opening_hours' => ['required', 'array', new ValidOpeningHours]]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->keys())->toBe(['opening_hours.schedules.0.week.monday.2', 'opening_hours.schedules.0.week.monday.1'])
        ->and($validator->errors()->first('opening_hours.schedules.0.week.monday.1'))
        ->toBe('Schedule 1, Monday, range 2 (11:00–13:00) overlaps Schedule 1, Monday, range 1 (09:00–12:00).');
});

it('translates messages into Slovak', function (): void {
    app()->setLocale('sk');

    $validator = Validator::make(['hours' => ['week' => ['monday' => ['12:00-12:00']]]], ['hours' => [new ValidOpeningHours]]);

    expect($validator->errors()->first('hours.week.monday.0'))->toBe('Pondelok, 1. interval: 12:00-12:00 je prázdny interval; začína a končí v rovnakom čase.');
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

    expect($validator->errors()->first('h'))->toBe('H: missing or not in the expected format.');
});

it('falls back to the fail callback without a validator', function (): void {
    $messages = [];
    (new ValidOpeningHours)->validate('h', ['timezone' => 'x'], function (string $message) use (&$messages): void {
        $messages[] = $message;
    });

    expect($messages)->toBe(['Time zone: “x” is not a valid IANA time zone (e.g. Europe/Bratislava).']);
});

/**
 * The human-testing kit's deliberately broken payload: every message names the spot
 * the way an editor sees it — never a raw dot path — in both locales.
 */
it('names every problem the way an editor sees it', function (string $locale, array $expected): void {
    $violations = CalendarParser::parse([
        'timezone' => '+02:00',
        'week' => [
            'monday' => ['08:00-12:00', '11:00-13:00'],
            'tuesday' => ['25:00-26:00'],
            'wednesday' => ['09:00-09:00'],
            'friday' => ['22:00-03:00'],
            'saturday' => ['01:00-02:00'],
            'funday' => ['10:00-11:00'],
        ],
        'exceptions' => [['date' => '2026-02-30', 'label' => 'Nope']],
    ])->violations;

    $messages = [];

    foreach ($violations as $violation) {
        $messages[$violation->path] = $violation->message($locale);
    }

    expect($messages)->toBe($expected);
})->with([
    'en' => ['en', [
        'timezone' => 'Time zone: “+02:00” is not a valid IANA time zone (e.g. Europe/Bratislava).',
        'week.tuesday.0' => 'Tuesday, range 1: “25:00” is not a valid time; use HH:MM between 00:00 and 24:00.',
        'week.wednesday.0' => 'Wednesday, range 1: 09:00-09:00 is empty; it starts and ends at the same time.',
        'week.funday' => 'Weekly hours: “funday” is not a weekday; use monday to sunday.',
        'exceptions.0.date' => 'Exception 1 (date): “2026-02-30” is not a real date; use YYYY-MM-DD.',
        'week.monday.1' => 'Monday, range 2 (11:00–13:00) overlaps Monday, range 1 (08:00–12:00).',
        'week.saturday.0' => 'Saturday, range 1 (01:00–02:00) overlaps Friday, range 1 (22:00–03:00).',
    ]],
    'sk' => ['sk', [
        'timezone' => 'Časové pásmo: „+02:00“ nie je platné časové pásmo IANA (napr. Europe/Bratislava).',
        'week.tuesday.0' => 'Utorok, 1. interval: „25:00“ nie je platný čas; použite HH:MM od 00:00 do 24:00.',
        'week.wednesday.0' => 'Streda, 1. interval: 09:00-09:00 je prázdny interval; začína a končí v rovnakom čase.',
        'week.funday' => 'Týždenné hodiny: „funday“ nie je deň v týždni; použite monday až sunday.',
        'exceptions.0.date' => 'Výnimka 1 (dátum): „2026-02-30“ nie je skutočný dátum; použite RRRR-MM-DD.',
        'week.monday.1' => 'Pondelok, 2. interval (11:00–13:00): prekrýva sa s iným intervalom – Pondelok, 1. interval (08:00–12:00).',
        'week.saturday.0' => 'Sobota, 1. interval (01:00–02:00): prekrýva sa s iným intervalom – Piatok, 1. interval (22:00–03:00).',
    ]],
]);

it('names schedules, windows and duplicate exceptions with their dates', function (): void {
    $violations = CalendarParser::parse([
        'schedules' => [
            ['week' => ['monday' => [['from' => '09:00', 'to' => '25:00']]]],
            ['priority' => 5000, 'week' => ['xday' => []]],
            ['window' => ['from' => '07-01', 'until' => '2026-08-31']],
        ],
        'exceptions' => [
            ['date' => '2026-12-24'],
            ['date' => '2026-12-24'],
            ['from' => '2026-12-27', 'until' => '2026-12-26'],
        ],
    ])->violations;

    $messages = [];

    foreach ($violations as $violation) {
        $messages[$violation->path] = [$violation->message('en'), $violation->message('sk')];
    }

    expect($messages)->toBe([
        'schedules.0.week.monday.0.to' => [
            'Schedule 1, Monday, range 1 (end time): “25:00” is not a valid time; use HH:MM between 00:00 and 24:00.',
            'Rozvrh 1, Pondelok, 1. interval (čas konca): „25:00“ nie je platný čas; použite HH:MM od 00:00 do 24:00.',
        ],
        'schedules.1.priority' => [
            'Schedule 2 (priority): must be a whole number between -1000 and 1000.',
            'Rozvrh 2 (priorita): musí byť celé číslo od -1000 do 1000.',
        ],
        'schedules.1.week.xday' => [
            'Schedule 2 (weekly hours): “xday” is not a weekday; use monday to sunday.',
            'Rozvrh 2 (týždenné hodiny): „xday“ nie je deň v týždni; použite monday až sunday.',
        ],
        'schedules.2.window' => [
            'Schedule 3 (validity period): one-off and yearly dates are mixed; use YYYY-MM-DD for one-off and MM-DD for yearly dates.',
            'Rozvrh 3 (obdobie platnosti): mieša jednorazové a každoročné dátumy; pre jednorazové použite RRRR-MM-DD, pre každoročné MM-DD.',
        ],
        'exceptions.2' => [
            'Exception 3: ends (2026-12-26) before it starts (2026-12-27).',
            'Výnimka 3: končí (2026-12-26) skôr, ako začína (2026-12-27).',
        ],
        'exceptions.1' => [
            'Exception 2 (2026-12-24) has the same dates as Exception 1.',
            'Výnimka 2 (2026-12-24): rovnaké dátumy už má iná výnimka (Výnimka 1).',
        ],
    ]);
});

it('labels every kind of path in both locales', function (string $path, string $english, string $slovak): void {
    expect(PathLabel::for($path, 'en'))->toBe($english)
        ->and(PathLabel::for($path, 'sk'))->toBe($slovak);
})->with([
    'root' => ['', 'Opening hours', 'Otváracie hodiny'],
    'timezone' => ['timezone', 'Time zone', 'Časové pásmo'],
    'calendar key' => ['key', 'Calendar key', 'Kľúč kalendára'],
    'week' => ['week', 'Weekly hours', 'Týždenné hodiny'],
    'day' => ['week.sunday', 'Sunday', 'Nedeľa'],
    'range capacity' => ['week.friday.2.capacity', 'Friday, range 3 (capacity)', 'Piatok, 3. interval (kapacita)'],
    'schedules' => ['schedules', 'Schedules', 'Rozvrhy'],
    'schedule' => ['schedules.1', 'Schedule 2', 'Rozvrh 2'],
    'schedule week' => ['schedules.0.week', 'Schedule 1 (weekly hours)', 'Rozvrh 1 (týždenné hodiny)'],
    'schedule label' => ['schedules.0.label', 'Schedule 1 (label)', 'Rozvrh 1 (popis)'],
    'window bound' => ['schedules.0.window.until', 'Schedule 1 (valid until)', 'Rozvrh 1 (platnosť do)'],
    'exceptions' => ['exceptions', 'Exceptions', 'Výnimky'],
    'exception id' => ['exceptions.4.id', 'Exception 5 (ID)', 'Výnimka 5 (ID)'],
    'exception start' => ['exceptions.0.from', 'Exception 1 (start date)', 'Výnimka 1 (dátum začiatku)'],
    'exception hours' => ['exceptions.0.ranges', 'Exception 1 (hours)', 'Výnimka 1 (otváracie časy)'],
    'exception range' => ['exceptions.0.ranges.1.from', 'Exception 1, range 2 (start time)', 'Výnimka 1, 2. interval (čas začiatku)'],
    'week-array day' => ['monday.0', 'Monday, range 1', 'Pondelok, 1. interval'],
    'week-array unknown day' => ['funday', 'Opening hours', 'Otváracie hodiny'],
    'week-array exception' => ['exceptions.2026-12-24.0', 'Exception 2026-12-24, range 1', 'Výnimka 2026-12-24, 1. interval'],
    'unknown stays raw' => ['schedules.x', 'schedules.x', 'schedules.x'],
    'unknown field stays raw' => ['week.monday.0.colour', 'week.monday.0.colour', 'week.monday.0.colour'],
    'non-numeric range stays raw' => ['week.monday.first', 'week.monday.first', 'week.monday.first'],
    'unknown nested key stays raw' => ['timezone.x', 'timezone.x', 'timezone.x'],
]);

it('names a single range after its form field', function (): void {
    $validator = Validator::make(
        ['lunch' => ['from' => '12:00', 'to' => '25:00'], 'r' => '12:00-12:00'],
        ['lunch' => [new ValidTimeRange], 'r' => [new ValidTimeRange]],
        attributes: ['r' => 'lunch break'],
    );

    expect($validator->errors()->first('lunch'))->toBe('Lunch (end time): “25:00” is not a valid time; use HH:MM between 00:00 and 24:00.')
        ->and($validator->errors()->first('r'))->toBe('Lunch break: 12:00-12:00 is empty; it starts and ends at the same time.')
        ->and(PathLabel::forRange('Obed', 'from', 'sk'))->toBe('Obed (čas začiatku)');
});

it('points week-array overlaps at the legacy keys on both sides', function (): void {
    $violation = WeekArrayParser::parse(['exceptions' => ['2026-12-24' => ['10:00-12:00', '11:00-13:00']]])->violations->first();

    expect($violation?->path)->toBe('exceptions.2026-12-24.1')
        ->and($violation?->params['other'])->toBe('exceptions.2026-12-24.0')
        ->and($violation?->message())->toBe('Exception 2026-12-24, range 2 (11:00–13:00) overlaps Exception 2026-12-24, range 1 (10:00–12:00).')
        ->and($violation?->withPath('x')->path)->toBe('x');
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

it('fails a single range on the label and meta limits a save would refuse', function (): void {
    config()->set('opening-hours.limits.label_length', 10);
    config()->set('opening-hours.limits.meta_bytes', 64);
    $validator = Validator::make(
        ['label' => ['from' => '09:00', 'to' => '10:00', 'label' => str_repeat('x', 11)], 'meta' => ['from' => '09:00', 'to' => '10:00', 'meta' => ['note' => str_repeat('x', 64)]]],
        ['label' => [new ValidTimeRange], 'meta' => [new ValidTimeRange]],
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('label'))->toBe('Label (label): may not be longer than 10 characters.')
        ->and($validator->errors()->first('meta'))->toBe('Meta (metadata): may not be larger than 64 bytes.')
        ->and(Validator::make(['r' => ['from' => '09:00', 'to' => '10:00', 'label' => str_repeat('x', 10)]], ['r' => [new ValidTimeRange]])->passes())->toBeTrue();
});

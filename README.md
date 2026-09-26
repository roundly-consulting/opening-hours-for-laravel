# Opening Hours for Laravel

Opening hours, seasonal schedules, exceptions and bookable availability for any Eloquent
model — DST-correct, native, zero third-party dependencies.

- Weekly schedules with several ranges per day, overnight ranges (`22:00-02:00`), 24-hour days
  and closed days.
- Seasonal schedules (one-off or yearly, with priorities) and exceptions (single dates, date
  spans, yearly holidays, closed or custom hours, movable holidays such as Easter).
- A complete query API — `isOpenAt`, `nextOpen`, `nextClose`, `previousOpen`, `forDate`,
  `forWeek`, open duration, schema.org structured data — with **one explicit DST rule**.
- Availability checks and bookable slots with capacity, buffers, minimum notice and a horizon,
  fed by **any** busy-period source (no coupling to a booking package).
- Validation rule, fluent builder, optimistic concurrency, caching, events, API resources and
  opt-in SQL "open now" scopes.

## Contents

- [Requirements](#requirements) · [Installation](#installation) · [Configuration](#configuration)
- Usage: [owners](#1-make-a-model-an-owner) · [defining hours](#2-define-opening-hours) ·
  [querying](#3-query-opening-hours) · [days and weeks](#4-days-and-weeks) ·
  [spans and durations](#5-spans-and-durations) · [exceptions and holidays](#6-exceptions-and-holidays) ·
  [validation](#7-validation-and-http-input) · [availability and slots](#8-availability-and-slots) ·
  [API resources](#9-api-resources) · [caching](#10-caching) · [events](#11-events) ·
  [SQL scopes](#12-materialized-intervals-and-sql-scopes-opt-in) · [commands](#13-console-commands) ·
  [importing week arrays](#14-importing-legacy-week-arrays)
- [Timezones and DST](#timezones-and-dst) · [Security notes](#security-notes) · [Testing](#testing)

## Requirements

- PHP ^8.4
- Laravel 12.x or 13.x
- SQLite, PostgreSQL or MySQL (all three are tested)

## Installation

```bash
composer require roundly-consulting/opening-hours-for-laravel
```

Publish and run the migrations (six tables, all prefixed `opening_hours_`):

```bash
php artisan vendor:publish --tag="opening-hours-migrations"
php artisan migrate
```

Optionally publish the config file and the translations (`en`, `sk`):

```bash
php artisan vendor:publish --tag="opening-hours-config"
php artisan vendor:publish --tag="opening-hours-translations"
```

If the models that own opening hours use UUID or ULID keys, set `OPENING_HOURS_KEY_TYPE`
(`uuid` / `ulid`) **before** running the migrations.

## Configuration

`config/opening-hours.php`:

```php
return [
    'key_type' => env('OPENING_HOURS_KEY_TYPE', 'bigint'),
    'models' => [
        'calendar' => Calendar::class,
        'schedule' => Schedule::class,
        'exception_rule' => ExceptionRule::class,
    ],
    'default_calendar' => env('OPENING_HOURS_DEFAULT_CALENDAR', 'default'),
    'timezone' => env('OPENING_HOURS_TIMEZONE'),
    'first_day_of_week' => env('OPENING_HOURS_FIRST_DAY_OF_WEEK', 'monday'),
    'search_days' => (int) env('OPENING_HOURS_SEARCH_DAYS', 366),
    'max_query_days' => (int) env('OPENING_HOURS_MAX_QUERY_DAYS', 366),
    'delete_with_owner' => (bool) env('OPENING_HOURS_DELETE_WITH_OWNER', true),
    'limits' => [
        'calendars' => 16, 'schedules' => 20, 'ranges_per_day' => 12, 'exceptions' => 1000,
        'label_length' => 191, 'meta_bytes' => 4096, 'busy_periods' => 10000, 'slots' => 2000,
    ],
    'cache' => [
        'enabled' => (bool) env('OPENING_HOURS_CACHE_ENABLED', true),
        'store' => env('OPENING_HOURS_CACHE_STORE'),
        'ttl' => env('OPENING_HOURS_CACHE_TTL', 86400),
        'prefix' => env('OPENING_HOURS_CACHE_PREFIX', 'opening-hours'),
    ],
    'api' => ['expose_meta' => false, 'week_mode' => 'upcoming', 'upcoming_exceptions_days' => 60],
    'prune' => [
        'exceptions_after_days' => env('OPENING_HOURS_PRUNE_EXCEPTIONS_AFTER_DAYS'),
        'trashed_after_days' => env('OPENING_HOURS_PRUNE_TRASHED_AFTER_DAYS'),
    ],
    'materialize' => [
        'enabled' => (bool) env('OPENING_HOURS_MATERIALIZE', false),
        'days_ahead' => (int) env('OPENING_HOURS_MATERIALIZE_DAYS_AHEAD', 60),
        'days_behind' => (int) env('OPENING_HOURS_MATERIALIZE_DAYS_BEHIND', 1),
        'connection' => env('OPENING_HOURS_QUEUE_CONNECTION'),
        'queue' => env('OPENING_HOURS_QUEUE'),
    ],
    'facade_alias' => 'OpeningHours',
];
```

| Key | Default | Env | Meaning |
|---|---|---|---|
| `key_type` | `bigint` | `OPENING_HOURS_KEY_TYPE` | Owner primary-key type (`bigint`, `uuid`, `ulid`); decides the `owner_id` column type in the migrations. |
| `models.calendar` | `Calendar::class` | — | Calendar model; swap in a subclass. |
| `models.schedule` | `Schedule::class` | — | Schedule model; swap in a subclass. |
| `models.exception_rule` | `ExceptionRule::class` | — | Exception model; swap in a subclass. |
| `default_calendar` | `default` | `OPENING_HOURS_DEFAULT_CALENDAR` | Calendar key used when none is given. |
| `timezone` | `null` | `OPENING_HOURS_TIMEZONE` | Fallback timezone before `app.timezone` (IANA names only). |
| `first_day_of_week` | `monday` | `OPENING_HOURS_FIRST_DAY_OF_WEEK` | Ordering of `forWeek()`, `forWeekOf()` and the calendar-week API mode. |
| `search_days` | `366` | `OPENING_HOURS_SEARCH_DAYS` | How far `next*`/`previous*` look (1–3660); beyond it they return `null`. |
| `max_query_days` | `366` | `OPENING_HOURS_MAX_QUERY_DAYS` | Largest span a span query or slot search may cover (1–3660). |
| `delete_with_owner` | `true` | `OPENING_HOURS_DELETE_WITH_OWNER` | Remove calendars when their owner is deleted permanently. |
| `limits.calendars` | `16` | — | Named calendars per owner. |
| `limits.schedules` | `20` | — | Schedules per calendar. |
| `limits.ranges_per_day` | `12` | — | Ranges per weekday / per exception. |
| `limits.exceptions` | `1000` | — | Exceptions per calendar. |
| `limits.label_length` | `191` | — | Maximum label length. |
| `limits.meta_bytes` | `4096` | — | Maximum JSON size of a `meta` payload. |
| `limits.busy_periods` | `10000` | — | Busy periods read per availability evaluation. |
| `limits.slots` | `2000` | — | Maximum slots per slot query. |
| `cache.enabled` | `true` | `OPENING_HOURS_CACHE_ENABLED` | Cache compiled definitions. |
| `cache.store` | `null` | `OPENING_HOURS_CACHE_STORE` | Cache store (`null` = default store). |
| `cache.ttl` | `86400` | `OPENING_HOURS_CACHE_TTL` | Seconds; `null` = forever. |
| `cache.prefix` | `opening-hours` | `OPENING_HOURS_CACHE_PREFIX` | Cache key prefix. |
| `api.expose_meta` | `false` | — | Include `meta` in API resources. |
| `api.week_mode` | `upcoming` | — | Status resource week: `upcoming` (next 7 dates) or `calendar_week`. |
| `api.upcoming_exceptions_days` | `60` | — | Look-ahead of the status resource and structured data. |
| `prune.exceptions_after_days` | `null` | `OPENING_HOURS_PRUNE_EXCEPTIONS_AFTER_DAYS` | Prune one-off exceptions that ended N days ago. |
| `prune.trashed_after_days` | `null` | `OPENING_HOURS_PRUNE_TRASHED_AFTER_DAYS` | Purge soft-deleted rows older than N days. |
| `materialize.enabled` | `false` | `OPENING_HOURS_MATERIALIZE` | Maintain the `opening_hours_intervals` table for SQL scopes. |
| `materialize.days_ahead` | `60` | `OPENING_HOURS_MATERIALIZE_DAYS_AHEAD` | Materialized horizon forward. |
| `materialize.days_behind` | `1` | `OPENING_HOURS_MATERIALIZE_DAYS_BEHIND` | Materialized horizon backward. |
| `materialize.connection` | `null` | `OPENING_HOURS_QUEUE_CONNECTION` | Queue connection of the materialization job. |
| `materialize.queue` | `null` | `OPENING_HOURS_QUEUE` | Queue of the materialization job. |
| `facade_alias` | `OpeningHours` | — | Global facade alias; `null`/`false` disables it. |

## Usage

### 1. Make a model an owner

```php
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\OpeningHours\Concerns\HasOpeningHours;
use RoundlyConsulting\OpeningHours\Contracts\OpeningHoursOwner;

final class Clinic extends Model implements OpeningHoursOwner
{
    use HasOpeningHours;

    // Optional: evaluate calendars without their own timezone in the clinic's.
    public function openingHoursTimezone(): ?string
    {
        return $this->timezone;
    }
}
```

`openingHours()` is a **method** that returns the query object, not a relation;
`$clinic->openingHours` (property) throws. The relation is `openingHoursCalendars()`.

An owner can have several named calendars (`default`, `pickup`, `reception`…); every method
takes an optional calendar key.

### 2. Define opening hours

From an array (the same shape the validation rule accepts; top-level `week` is sugar for one
base schedule):

```php
$clinic->setOpeningHours([
    'timezone' => 'Europe/Bratislava',
    'week' => [
        'monday' => ['08:00-12:00', '13:00-17:00'],
        'friday' => ['08:00-15:00'],
        'saturday' => [['from' => '09:00', 'to' => '12:00', 'label' => 'Short day', 'capacity' => 2]],
    ],
    'exceptions' => [['date' => '12-25', 'label' => 'Christmas']],
]);

$clinic->setOpeningHours(['week' => ['monday' => ['14:00-16:00']]], 'pickup'); // a second calendar
```

The full canonical shape:

```php
[
    'timezone' => 'Europe/Bratislava',                  // optional, IANA only
    'label' => 'Reception',                              // optional
    'schedules' => [
        ['label' => 'Regular', 'week' => [
            'monday' => ['08:00-12:00', '13:00-17:00'],
            'friday' => ['22:00-03:00'],                 // overnight: ends 03:00 on Saturday
            'sunday' => ['00:00-24:00'],                 // 24 hours
        ]],
        ['label' => 'Summer', 'priority' => 10,
         'window' => ['from' => '07-01', 'until' => '08-31'],        // m-d = every year
         'week' => ['monday' => ['07:00-14:00']]],
        ['label' => 'New hours',
         'window' => ['from' => '2026-11-01'],                       // Y-m-d = one-off, open-ended
         'week' => ['monday' => ['09:00-18:00']]],
    ],
    'exceptions' => [
        ['date' => '12-25', 'label' => 'Christmas'],                           // yearly, closed
        ['from' => '12-24', 'until' => '01-02', 'label' => 'Holidays'],        // yearly, wraps the year
        ['from' => '2026-08-03', 'until' => '2026-08-14', 'label' => 'Break'], // one-off span, closed
        ['date' => '2026-10-17', 'ranges' => ['10:00-12:00'], 'label' => 'Short day'],
    ],
]
```

Weekday keys accept `monday`, `Monday`, `mon` or `1`–`7`. Ranges accept `"HH:MM-HH:MM"` or
`{from, to, label?, capacity?, meta?}`. An end of `00:00` means midnight (`24:00`).

With the fluent builder — it starts from the current definition and saves optimistically:

```php
use RoundlyConsulting\OpeningHours\Builders\ScheduleBuilder;
use RoundlyConsulting\OpeningHours\Enums\Weekday;

$clinic->editOpeningHours()
    ->timezone('Europe/Bratislava')
    ->baseSchedule(fn (ScheduleBuilder $week) => $week
        ->weekdays('08:00-12:00', '13:00-17:00')
        ->saturday('09:00-12:00')
        ->closedOn(Weekday::Sunday))
    ->schedule(fn (ScheduleBuilder $week) => $week
        ->label('Summer')->yearly('07-01', '08-31')->priority(10)
        ->weekdays('07:00-14:00'))
    ->closed('2026-12-24', '2026-12-26', label: 'Christmas')
    ->closedYearly('01-01', label: 'New Year')
    ->exception('2026-10-17', ranges: ['10:00-12:00'], label: 'Short day')
    ->save();
```

`ScheduleBuilder` also has `monday()`…`sunday()`, `weekend()`, `everyDay()`, `days([...], ...)`,
`open24Hours(...)`, `between()`, `from()`, `until()`, `meta()`. `CalendarBuilder` also has
`label()`, `meta()`, `removeSchedule($id)`, `removeException($id)`, `withoutSchedules()`,
`withoutExceptions()`, `mergeOverlapping()`, `violations()`, `toData()`.

**Concurrency.** A builder remembers the revision it loaded; if someone else saved in between,
`save()` throws `StaleOpeningHoursException` instead of silently discarding their change.
`expectRevision($n)` checks against a revision a client echoed back; `ignoreConcurrentChanges()`
makes the last writer win. `setOpeningHours($data, expectedRevision: 0)` means "must not exist yet".

Without a database (previews, tests, imports):

```php
use RoundlyConsulting\OpeningHours\Builders\CalendarBuilder;
use RoundlyConsulting\OpeningHours\OpeningHours;

$preview = OpeningHours::make(['week' => ['saturday' => ['22:00-03:00']]], 'Europe/Bratislava');
$built = CalendarBuilder::make()->baseSchedule(fn ($week) => $week->weekdays('09:00-17:00'))->build();
```

### 3. Query opening hours

```php
use Carbon\CarbonImmutable;

$hours = $clinic->openingHours();                         // default calendar
$hours->isOpen();                                         // now
$hours->isOpenAt(CarbonImmutable::parse('2026-10-25 02:30', 'Europe/Bratislava'));
$hours->nextOpen();                                       // ?CarbonImmutable, calendar timezone
$hours->nextClose();
$hours->previousOpen();
$hours->previousClose();
$hours->currentPeriod();                                  // ?OpeningPeriod (coalesced run)
$hours->currentRange();                                   // ?TimeRange as defined (label, capacity)
$hours->nextPeriod();
$hours->withOutputTimezone('UTC')->nextClose();           // same answer, reported in UTC
$clinic->openingHours('pickup')->isOpenDuring($from, $to);
```

Navigation is strict (`nextOpen($t)` is after `$t`) and returns `null` when nothing is found
within `search_days` (pass `searchDays:` to override). An always-open calendar has no
`nextClose()`. Touching ranges (`09–12` + `12–13`) count as one period for navigation.

### 4. Days and weeks

```php
$hours->forDate('2026-12-24')->toString();     // "Closed" or "09:00–12:00" (translated)
$hours->forDate('2026-12-24')->ranges;         // list<TimeRange>
$hours->forDate('2026-12-24')->source;         // DaySource::Schedule|Exception|Dynamic|None
$hours->isOpenOnDate('2026-12-24');
$hours->isOpenOn('monday');                    // regular schedule, exceptions ignored
$hours->forWeekday(Weekday::Monday);
$hours->forWeek()->grouped();                  // Mon–Fri 08:00–17:00 · Sat 09:00–12:00 · Sun closed
$hours->forWeek()->keyed();                    // ['monday' => DayHours, …]
$hours->forWeekOf('2026-09-30');               // the seven actual dates, exceptions applied
$hours->forPeriod('2026-12-20', '2026-12-31');
$hours->regularClosingDays();                  // list<Weekday>
$hours->toStructuredData()->toJson();          // schema.org OpeningHoursSpecification
```

`DayHours::toString(rangeSeparator, timeSeparator, locale, closedText)` renders a day; pass
`closedText: ''` for literal output (`toString(',', '-', closedText: '')` → `"09:00-12:00,13:00-18:00"`).
Group labels: `$group->label()` → `Mon–Fri`.

### 5. Spans and durations

```php
$hours->openingPeriodsBetween($monday, $monday->addWeek());   // list<OpeningPeriod>, clipped
$hours->isOpenDuring($from, $to);                              // open for the whole span
$hours->isClosedDuring($from, $to);                            // not open at any moment
$hours->openSecondsBetween($monday, $monday->addWeek());       // real elapsed seconds
$hours->closedSecondsBetween($from, $to);
$hours->openDurationBetween($from, $to);                       // CarbonInterval
```

Span queries longer than `max_query_days` throw `QueryRangeTooLargeException`.

### 6. Exceptions and holidays

Precedence on a date: one-off exception (narrowest span wins) → dynamic providers → yearly
exception (narrowest wins) → the schedule in effect (highest priority, windowed before base) →
closed. An overnight range from the day before still runs into a closed exception day.

```php
$hours->exceptionalClosingDates();                         // default: today … +365 days
$hours->exceptionalClosingDates('2026-01-01', '2026-12-31');
$hours->exceptionsBetween('2026-12-01', '2026-12-31');     // list<DayHours>
```

Movable holidays come from a `DynamicExceptionProvider`; Easter-relative ones ship built in:

```php
use RoundlyConsulting\OpeningHours\Dynamic\EasterOffsetProvider;

// On the owner:
public function openingHoursDynamicExceptions(): array
{
    return [
        new EasterOffsetProvider(-2, 'Good Friday'),
        new EasterOffsetProvider(1, 'Easter Monday', ['10:00-12:00']),
    ];
}

// Or ad hoc:
$hours->withDynamicExceptions(new EasterOffsetProvider(50, 'Whit Monday'));
```

### 7. Validation and HTTP input

```php
use RoundlyConsulting\OpeningHours\Rules\ValidOpeningHours;
use RoundlyConsulting\OpeningHours\Rules\ValidTimeRange;

public function rules(): array
{
    return [
        'opening_hours' => ['required', 'array', new ValidOpeningHours(requireTimezone: true)],
        'range' => [new ValidTimeRange],
    ];
}

$clinic->setOpeningHours(
    $request->validated('opening_hours'),
    expectedRevision: $request->filled('revision') ? $request->integer('revision') : null,
);
```

Every problem is reported under its nested key (`opening_hours.schedules.0.week.monday.1`) with a
translated message: invalid times, empty ranges, overlaps (also across midnight and Sunday →
Monday), two base schedules, ambiguous seasonal windows or exceptions, unknown weekdays, invalid
timezones, limits… `new ValidOpeningHours(mergeOverlapping: true)` merges overlaps instead.

Programmatically: `OpeningHours::validate($payload)` (facade) returns a `ViolationList`;
`CalendarData::fromArray($payload)` throws `InvalidOpeningHoursException` carrying all of them
(`$e->violations()->toMessageBag('opening_hours')`).

### 8. Availability and slots

Availability knows nothing about bookings; it asks a `BusyPeriodProvider` what is occupied.

```php
use Carbon\CarbonImmutable;
use RoundlyConsulting\OpeningHours\Availability\BusyPeriod;

$availability = $clinic->openingHours()->availability()
    ->withBusyPeriods(fn (CarbonImmutable $start, CarbonImmutable $end) => $bookings->between($start, $end))
    ->capacity(2)                           // parallel bookings (a range's own capacity wins)
    ->buffers(before: 5, after: 10)         // must fall inside opening hours by default
    ->minNotice(minutes: 120)
    ->horizon(days: 60);

$result = $availability->check($start, $end);   // AvailabilityResult
$result->available;                             // bool
$result->reason;                                // ?UnavailableReason: invalid_range, in_past, too_soon, too_far, closed, busy
$result->conflict;                              // ?BusyPeriod
$result->message();                             // translated reason
$availability->isAvailable($start, $end, weight: 1);

$slots = $availability->slots('2026-10-01', '2026-10-07')     // whole local days, inclusive
    ->duration(30)->step(15)->alignTo(15)
    ->limit(200)
    ->get();                                                   // SlotCollection
$slots->groupByDate();                                         // ['2026-10-01' => [Slot, …], …]
$availability->nextAvailableSlot(duration: 30);                // ?Slot
$availability->freePeriods('2026-10-01', '2026-10-01');        // list<Period>
```

Busy sources: a `BusyPeriodProvider`, an iterable of `BusyPeriod::make($start, $end, weight: 1)`,
or a closure. Shipped providers: `ArrayBusyPeriodProvider`, `ClosureBusyPeriodProvider`,
`CompositeBusyPeriodProvider`, `NullBusyPeriodProvider` and `EloquentBusyPeriodProvider`:

```php
use RoundlyConsulting\OpeningHours\Availability\Providers\EloquentBusyPeriodProvider;

$busy = EloquentBusyPeriodProvider::for(
        Appointment::query()->where('vet_id', $vet->id)->whereNotIn('status', ['cancelled']),
    )
    ->columns(start: 'starts_at', end: 'ends_at')
    ->durationColumn('duration_minutes')   // rows with a NULL end
    ->defaultDuration(minutes: 30)         // … and no duration either
    ->weightColumn(null)                   // or a column with capacity units
    ->storedIn('UTC');                     // timezone of the raw column values

$clinic->openingHours()->availability()->withBusyPeriods($busy)->isAvailable($start, $end);
```

Capacity: open capacity is `range.capacity ?? availability capacity ?? 1` (overlapping ranges take
the maximum); a booking fits when `usage + weight ≤ capacity` at every moment of
`[start − before, end + after)`. Durations, notice and buffers are real elapsed time; the horizon is
counted in calendar days on the local wall clock.

> Availability is a read. When you persist a booking, re-check inside your own lock or unique
> constraint (e.g. `Cache::lock("book:{$vet->id}:{$start}")`) — another request may book the same
> slot between your check and your insert.

### 9. API resources

```php
use RoundlyConsulting\OpeningHours\Http\Resources\CalendarResource;
use RoundlyConsulting\OpeningHours\Http\Resources\DayHoursResource;
use RoundlyConsulting\OpeningHours\Http\Resources\OpeningStatusResource;
use RoundlyConsulting\OpeningHours\Http\Resources\SlotResource;

return OpeningStatusResource::make($clinic->openingHours());           // status (optionally ->at($instant))
return CalendarResource::make($clinic->openingHoursCalendar());        // editable definition
return DayHoursResource::collection($clinic->openingHours()->forWeekOf(now()));
return SlotResource::collection($slots);
```

Status shape: `timezone`, `at`, `is_open`, `current_period`, `next_open`, `next_close`, `today`,
`week` (7 days), `upcoming_exceptions`. Instants are ISO-8601 with offset. The definition shape is
the canonical input plus `id`, `key`, `revision` and `updated_at`; it can be submitted back to
`setOpeningHours()` unchanged (send `revision` as `expectedRevision`). `meta` appears only with
`api.expose_meta` — and a definition resubmitted without `meta` clears it.

No routes or controllers ship; endpoints are yours.

### 10. Caching

Compiled definitions are cached per `(calendar, revision)`; every write bumps the revision inside
its transaction, so there is nothing to invalidate. List pages avoid N+1 with:

```php
Clinic::query()->withOpeningHours()->paginate();                  // headers only (cache on)
Clinic::query()->withOpeningHours(definitions: true)->paginate(); // full definitions (cache off)
```

Direct Eloquent edits of schedules, exceptions and ranges bump the revision automatically. After
raw SQL edits, or when what `openingHoursTimezone()` returns changes, call
`OpeningHours::refresh($clinic)` (facade).

### 11. Events

Both are dispatched after commit, with scalar payloads (queue-safe):

- `OpeningHoursUpdated(calendarId, ownerType, ownerId, calendarKey, revision)`
- `OpeningHoursDeleted(calendarId, ownerType, ownerId, calendarKey, forced)`

### 12. Materialized intervals and SQL scopes (opt-in)

To filter owners in SQL ("clinics open now"), enable `materialize.enabled`, schedule the command
daily, and use the scopes:

```php
// routes/console.php
Schedule::command('opening-hours:materialize')->daily();

Clinic::query()->whereOpenAt(now())->get();
Clinic::query()->whereOpenThroughout($start, $end, 'pickup')->get();
```

Every change queues a unique `MaterializeIntervalsJob` for that calendar. Instants outside
`[now − days_behind, now + days_ahead]` throw `OutsideMaterializedHorizonException` instead of
silently answering "closed".

### 13. Console commands

```bash
php artisan opening-hours:show clinic 42 --calendar=pickup --date=2026-12-20 --days=14 --at=2026-12-24T10:00:00+01:00
php artisan opening-hours:prune --exceptions-after-days=30 --trashed-after-days=90 --dry-run
php artisan opening-hours:materialize --calendar=5 --sync
```

A sync without ids replaces schedules and exceptions (the old rows are soft-deleted); schedule
`opening-hours:prune` daily with `prune.trashed_after_days` to purge them.

### 14. Importing legacy week arrays

The widely used weekday-keyed array format is read natively:

```php
use RoundlyConsulting\OpeningHours\DataTransferObjects\CalendarData;
use RoundlyConsulting\OpeningHours\Validation\ParseOptions;

$data = CalendarData::fromWeekArray([
    'monday' => ['09:00-12:00', ['hours' => '13:00-18:00', 'data' => 'Afternoon']],
    'exceptions' => [
        '2026-12-24' => [],
        '12-25' => ['data' => 'Christmas'],
        '2026-12-27 to 2026-12-30' => ['10:00-12:00'],
    ],
], new ParseOptions(mergeOverlapping: true));

$clinic->setOpeningHours($data);
```

`data` (string ⇒ label, array ⇒ meta) is kept, `overflow` is ignored (overnight ranges always run
past midnight) and `filters` is rejected (use a `DynamicExceptionProvider`).

### Other facade methods

`OpeningHours::for($owner, $key)`, `calendar()`, `has()`, `edit()`, `sync()`, `make()`, `delete()`
(soft; the next sync restores it), `refresh()`, `validate()`, `flushMemo()`. The query object and the
facade share the name `OpeningHours`; alias one where you need both
(`use RoundlyConsulting\OpeningHours\Facades\OpeningHours as Hours;`).

## Timezones and DST

A calendar is evaluated in: its own `timezone` → the owner's `openingHoursTimezone()` →
`opening-hours.timezone` → `app.timezone`. Only IANA names are accepted (`+02:00` is rejected).

Every wall-clock boundary follows **one rule**: it resolves to the first instant at which the local
clock reads that time or later.

- Spring forward (02:30 does not exist): a boundary at 02:30 is the moment the clock jumps
  (03:00). A `01:00-02:30` range lasts one real hour that day.
- Fall back (02:30 happens twice): the first occurrence — for starts and ends, in every zone.
- A `00:00-24:00` day is 23 or 25 hours long on transition days; durations are real time.
- Dates given as instants are converted to the calendar timezone first; the weekday is the local one.

## Security notes

- Ids in a sync payload must belong to the calendar being written; foreign ids are rejected.
- Labels are returned raw — escape them in your templates.
- `meta` is hidden from resources by default; `php artisan about` shows the cache store only as
  `default`/`custom`.
- Column names given to `EloquentBusyPeriodProvider` are allow-list validated; values are bound.
- Deleting owners with a mass query (`Clinic::query()->delete()`) fires no model events and leaves
  their calendars behind (polymorphic owners cannot carry a foreign key).

## Testing

```bash
composer test
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).

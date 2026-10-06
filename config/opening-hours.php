<?php

declare(strict_types=1);

use RoundlyConsulting\OpeningHours\Models\Calendar;
use RoundlyConsulting\OpeningHours\Models\ExceptionRule;
use RoundlyConsulting\OpeningHours\Models\Schedule;

return [

    /*
    |--------------------------------------------------------------------------
    | Owner key type
    |--------------------------------------------------------------------------
    |
    | The primary-key type of the models that own calendars: bigint, uuid or
    | ulid. Decides the owner_id column type in the published migrations.
    |
    */

    'key_type' => env('OPENING_HOURS_KEY_TYPE', 'bigint'),

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Swap in your own subclasses (they must extend the packaged models).
    |
    */

    'models' => [
        'calendar' => Calendar::class,
        'schedule' => Schedule::class,
        'exception_rule' => ExceptionRule::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Default calendar
    |--------------------------------------------------------------------------
    |
    | The calendar key used when none is given ($clinic->openingHours()).
    |
    */

    'default_calendar' => env('OPENING_HOURS_DEFAULT_CALENDAR', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Default timezone
    |--------------------------------------------------------------------------
    |
    | The timezone a calendar is evaluated in when neither the calendar nor its
    | owner's openingHoursTimezone() names one. Null (or blank) uses
    | config('app.timezone'). IANA identifiers only (e.g. Europe/Bratislava);
    | anything else throws.
    |
    */

    'timezone' => env('OPENING_HOURS_TIMEZONE'),

    /*
    |--------------------------------------------------------------------------
    | First day of the week
    |--------------------------------------------------------------------------
    |
    | Orders forWeek(), forWeekOf() and the calendar-week API mode.
    | One of: monday, tuesday, …, sunday.
    |
    */

    'first_day_of_week' => env('OPENING_HOURS_FIRST_DAY_OF_WEEK', 'monday'),

    /*
    |--------------------------------------------------------------------------
    | Search window
    |--------------------------------------------------------------------------
    |
    | nextOpen() / nextClose() / previousOpen() / previousClose() and
    | nextAvailableSlot() look this many local days after (or before) the day
    | they start from and return null when nothing is found (1..3660).
    |
    */

    'search_days' => env('OPENING_HOURS_SEARCH_DAYS', 366),

    /*
    |--------------------------------------------------------------------------
    | Largest span query
    |--------------------------------------------------------------------------
    |
    | forPeriod(), openingPeriodsBetween(), open/closed seconds, isOpenDuring(),
    | isClosedDuring(), slots, availability checks and exceptionalClosingDates()
    | throw QueryRangeTooLargeException beyond this many days (1..3660); slots
    | and checks also count a booking's buffers and duration. nextAvailableSlot()
    | and the status resource stay within it on their own (chunked search,
    | fixed seven-day week).
    |
    */

    'max_query_days' => env('OPENING_HOURS_MAX_QUERY_DAYS', 366),

    /*
    |--------------------------------------------------------------------------
    | Delete with owner
    |--------------------------------------------------------------------------
    |
    | Permanently deleting an owner (a model without SoftDeletes, or
    | forceDelete()) removes its calendars too. Soft deletes keep them.
    |
    */

    'delete_with_owner' => env('OPENING_HOURS_DELETE_WITH_OWNER', true),

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    |
    | Guards against oversized definitions.
    |
    */

    'limits' => [
        'calendars' => 16,
        'schedules' => 20,
        'ranges_per_day' => 12,
        'exceptions' => 1000,
        'label_length' => 191,
        'meta_bytes' => 4096,
        'busy_periods' => 10000,
        'slots' => 2000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Definition cache
    |--------------------------------------------------------------------------
    |
    | Compiled definitions are cached per (calendar, revision); every write
    | bumps the revision, so there is nothing to invalidate. ttl in seconds,
    | null = forever (a blank env value is not set, so 86400). store null (or
    | blank) = the default cache store. A store or prefix that is set must be
    | a string, or it throws.
    |
    */

    'cache' => [
        'enabled' => env('OPENING_HOURS_CACHE_ENABLED', true),
        'store' => env('OPENING_HOURS_CACHE_STORE'),
        'ttl' => env('OPENING_HOURS_CACHE_TTL', 86400),
        'prefix' => env('OPENING_HOURS_CACHE_PREFIX', 'opening-hours'),
    ],

    /*
    |--------------------------------------------------------------------------
    | API output
    |--------------------------------------------------------------------------
    |
    | expose_meta: include `meta` in resources. week_mode: `upcoming` (next
    | seven dates) or `calendar_week`. upcoming_exceptions_days: how far ahead
    | the status resource and the structured data list exceptions.
    |
    */

    'api' => [
        'expose_meta' => false,
        'week_mode' => 'upcoming',
        'upcoming_exceptions_days' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pruning
    |--------------------------------------------------------------------------
    |
    | Defaults for `opening-hours:prune` (schedule it daily). Null (or blank)
    | = off.
    |
    */

    'prune' => [
        'exceptions_after_days' => env('OPENING_HOURS_PRUNE_EXCEPTIONS_AFTER_DAYS'),
        'trashed_after_days' => env('OPENING_HOURS_PRUNE_TRASHED_AFTER_DAYS'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Materialized intervals (opt-in)
    |--------------------------------------------------------------------------
    |
    | Keeps opening_hours_intervals filled so whereOpenAt()/whereOpenThroughout()
    | can filter owners in SQL. Schedule `opening-hours:materialize` daily.
    | connection / queue null (or blank) = the defaults; a set value must be a
    | string, or it throws.
    |
    */

    'materialize' => [
        'enabled' => env('OPENING_HOURS_MATERIALIZE', false),
        'days_ahead' => env('OPENING_HOURS_MATERIALIZE_DAYS_AHEAD', 60),
        'days_behind' => env('OPENING_HOURS_MATERIALIZE_DAYS_BEHIND', 1),
        'connection' => env('OPENING_HOURS_QUEUE_CONNECTION'),
        'queue' => env('OPENING_HOURS_QUEUE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Facade alias
    |--------------------------------------------------------------------------
    |
    | Global class alias for the facade; null or false disables it. A blank
    | value is not set and keeps "OpeningHours".
    |
    */

    'facade_alias' => 'OpeningHours',

];

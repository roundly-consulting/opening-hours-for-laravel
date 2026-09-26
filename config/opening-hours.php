<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default timezone
    |--------------------------------------------------------------------------
    |
    | The timezone a calendar is evaluated in when neither the calendar nor its
    | owner's openingHoursTimezone() names one. Null falls back to
    | config('app.timezone'). IANA identifiers only (e.g. Europe/Bratislava).
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
    | nextOpen() / nextClose() / previousOpen() / previousClose() scan at most
    | this many days and return null when nothing is found (1..3660).
    |
    */

    'search_days' => (int) env('OPENING_HOURS_SEARCH_DAYS', 366),

    /*
    |--------------------------------------------------------------------------
    | Largest span query
    |--------------------------------------------------------------------------
    |
    | forPeriod(), openingPeriodsBetween(), open/closed seconds, slots and
    | exceptionalClosingDates() throw QueryRangeTooLargeException beyond this
    | many days (1..3660).
    |
    */

    'max_query_days' => (int) env('OPENING_HOURS_MAX_QUERY_DAYS', 366),

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    |
    | Guards against oversized definitions.
    |
    */

    'limits' => [
        'schedules' => 20,
        'ranges_per_day' => 12,
        'exceptions' => 1000,
        'label_length' => 191,
        'meta_bytes' => 4096,
    ],

    /*
    |--------------------------------------------------------------------------
    | API output
    |--------------------------------------------------------------------------
    |
    | upcoming_exceptions_days: how far ahead the status resource and the
    | structured data list upcoming exceptions.
    |
    */

    'api' => [
        'upcoming_exceptions_days' => 60,
    ],

];

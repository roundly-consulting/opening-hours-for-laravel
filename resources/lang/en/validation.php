<?php

declare(strict_types=1);

return [
    'invalid_structure' => 'The :path value has an invalid structure.',
    'invalid_time' => 'The :path time is invalid; use HH:MM between 00:00 and 24:00.',
    'empty_range' => 'The :path value is an empty range; it starts and ends at the same time.',
    'start_at_24' => 'The :path value cannot start at 24:00.',
    'overlap' => 'The :path range overlaps :other.',
    'invalid_date' => 'The :path value is invalid; use a real Y-m-d date.',
    'invalid_month_day' => 'The :path day is invalid; use m-d.',
    'window_inverted' => 'The :path value ends before it starts.',
    'recurrence_mismatch' => 'The :path value mixes one-off and yearly dates; use Y-m-d for one-off and m-d for yearly dates.',
    'yearly_window_unbounded' => 'The :path value is yearly and needs both a start and an end.',
    'duplicate_base_schedule' => 'The :path value has no validity window, but another schedule already has none.',
    'ambiguous_schedule_window' => 'The :path window overlaps :other with the same priority.',
    'duplicate_exception' => 'The :path value duplicates :other.',
    'ambiguous_exception' => 'The :path window overlaps :other with the same length, so neither can take precedence.',
    'invalid_timezone' => 'The :path value is not a valid IANA timezone.',
    'timezone_required' => 'A timezone is required.',
    'invalid_calendar_key' => 'The :path value may only contain up to 64 lowercase letters, digits, dashes and underscores.',
    'unknown_weekday' => 'The :path weekday is unknown.',
    'invalid_priority' => 'The :path value must be between :min and :max.',
    'invalid_capacity' => 'The :path value must be between :min and :max.',
    'limit_exceeded' => 'The :path list may not have more than :limit items.',
    'label_too_long' => 'The :path value may not be longer than :max characters.',
    'meta_too_large' => 'The :path value may not be larger than :max bytes.',
    'unknown_id' => 'The :path value :id does not belong to this calendar.',
    'duplicate_id' => 'The :path value :id is used more than once.',
    'unsupported_week_array_feature' => 'The week-array feature ":feature" is not supported.',
];

<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Enums;

use RoundlyConsulting\Enums\Helpers;

/**
 * Every way an opening-hours definition can be invalid. Each code has a
 * translated message under `opening-hours::validation.<value>`.
 */
enum ViolationCode: string
{
    use Helpers;

    case InvalidStructure = 'invalid_structure';
    case InvalidTime = 'invalid_time';
    case EmptyRange = 'empty_range';
    case StartAt24 = 'start_at_24';
    case Overlap = 'overlap';
    case InvalidDate = 'invalid_date';
    case InvalidMonthDay = 'invalid_month_day';
    case WindowInverted = 'window_inverted';
    case RecurrenceMismatch = 'recurrence_mismatch';
    case YearlyWindowUnbounded = 'yearly_window_unbounded';
    case DuplicateBaseSchedule = 'duplicate_base_schedule';
    case AmbiguousScheduleWindow = 'ambiguous_schedule_window';
    case DuplicateException = 'duplicate_exception';
    case AmbiguousException = 'ambiguous_exception';
    case InvalidTimezone = 'invalid_timezone';
    case TimezoneRequired = 'timezone_required';
    case InvalidCalendarKey = 'invalid_calendar_key';
    case UnknownWeekday = 'unknown_weekday';
    case InvalidPriority = 'invalid_priority';
    case InvalidCapacity = 'invalid_capacity';
    case LimitExceeded = 'limit_exceeded';
    case LabelTooLong = 'label_too_long';
    case MetaTooLarge = 'meta_too_large';
    case UnknownId = 'unknown_id';
    case UnsupportedWeekArrayFeature = 'unsupported_week_array_feature';
}

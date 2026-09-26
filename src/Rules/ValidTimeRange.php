<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use RoundlyConsulting\OpeningHours\Validation\CalendarParser;

/**
 * Validates a single range: `"HH:MM-HH:MM"` or `{from, to, label?, capacity?}`.
 */
final class ValidTimeRange implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        [, $violations] = CalendarParser::parseRangeValue($value, $attribute);

        foreach ($violations as $violation) {
            $fail($violation->message());
        }
    }
}

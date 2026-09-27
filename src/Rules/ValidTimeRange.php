<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Validation\Validator;
use RoundlyConsulting\OpeningHours\Validation\CalendarParser;
use RoundlyConsulting\OpeningHours\Validation\PathLabel;

/**
 * Validates a single range: `"HH:MM-HH:MM"` or `{from, to, label?, capacity?}`.
 * Messages name the form field ("Lunch (end time): …").
 */
final class ValidTimeRange implements ValidationRule, ValidatorAwareRule
{
    private ?Validator $validator = null;

    public function setValidator(Validator $validator): static
    {
        $this->validator = $validator;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        [, $violations] = CalendarParser::parseRangeValue($value, $attribute);
        $name = ucfirst($this->validator?->getDisplayableAttribute($attribute) ?? $attribute);

        foreach ($violations as $violation) {
            $fail($violation->message(subject: PathLabel::forRange($name, ltrim(substr($violation->path, strlen($attribute)), '.'))));
        }
    }
}

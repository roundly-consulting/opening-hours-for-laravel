<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Validation\Validator;
use RoundlyConsulting\OpeningHours\Enums\ViolationCode;
use RoundlyConsulting\OpeningHours\Validation\CalendarParser;
use RoundlyConsulting\OpeningHours\Validation\ParseOptions;
use RoundlyConsulting\OpeningHours\Validation\Violation;

/**
 * Validates an opening-hours payload and reports EVERY violation under its
 * nested key (`opening_hours.schedules.0.week.monday.1`) with a translated message
 * that names the spot the way an editor sees it ("Schedule 1, Monday, range 2: …").
 */
final class ValidOpeningHours implements ValidationRule, ValidatorAwareRule
{
    private ?Validator $validator = null;

    public function __construct(
        public readonly bool $mergeOverlapping = false,
        public readonly bool $requireTimezone = false,
    ) {}

    public function setValidator(Validator $validator): static
    {
        $this->validator = $validator;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            $fail((new Violation(ViolationCode::InvalidStructure, ''))->message(subject: $this->displayName($attribute)));

            return;
        }

        $violations = CalendarParser::parse($value, new ParseOptions($this->mergeOverlapping, $this->requireTimezone))->violations;

        foreach ($violations as $violation) {
            $key = $violation->path === '' ? $attribute : $attribute.'.'.$violation->path;

            if ($this->validator === null || $key === $attribute) {
                $fail($violation->message());

                continue;
            }

            $this->validator->errors()->add($key, $violation->message());
        }
    }

    private function displayName(string $attribute): string
    {
        return ucfirst($this->validator?->getDisplayableAttribute($attribute) ?? $attribute);
    }
}

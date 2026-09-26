<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Validation;

use RoundlyConsulting\OpeningHours\DataTransferObjects\CalendarData;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidOpeningHoursException;

/**
 * The outcome of a parse: the definition (null when structurally unusable)
 * and every violation found.
 */
final readonly class ParseResult
{
    public function __construct(
        public ?CalendarData $data,
        public ViolationList $violations,
    ) {}

    public function isValid(): bool
    {
        return $this->data !== null && $this->violations->isEmpty();
    }

    /**
     * @throws InvalidOpeningHoursException
     */
    public function dataOrFail(): CalendarData
    {
        if ($this->data === null || ! $this->violations->isEmpty()) {
            throw InvalidOpeningHoursException::withViolations($this->violations);
        }

        return $this->data;
    }
}

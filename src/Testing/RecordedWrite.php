<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Testing;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\OpeningHours\DataTransferObjects\CalendarData;
use RoundlyConsulting\OpeningHours\DataTransferObjects\ExceptionData;

/**
 * One write `OpeningHoursFake` intercepted. `$payload` is the definition
 * (`sync`), the exception (`add`), the rule id (`remove`), the force flag
 * (`delete`) or null (`refresh`).
 */
final readonly class RecordedWrite
{
    public function __construct(
        public string $operation,
        public Model $owner,
        public string $calendar,
        public CalendarData|ExceptionData|int|bool|null $payload = null,
    ) {}

    public function isFor(string $operation, Model $owner, string $calendar): bool
    {
        return $this->operation === $operation
            && $this->calendar === $calendar
            && ($this->owner === $owner || $this->owner->is($owner));
    }
}

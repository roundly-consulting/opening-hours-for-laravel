<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\OpeningHours\Contracts\OpeningHoursOwner;
use RoundlyConsulting\OpeningHours\DataTransferObjects\ExceptionData;
use RoundlyConsulting\OpeningHours\Exceptions\CalendarNotFoundException;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidOpeningHoursException;
use RoundlyConsulting\OpeningHours\Models\ExceptionRule;
use RoundlyConsulting\OpeningHours\ValueObjects\LocalDate;
use RoundlyConsulting\OpeningHours\ValueObjects\TimeRange;

/**
 * The exceptions of one owner's calendar (`OpeningHours::exceptions($shop)`).
 * Each write locks the calendar row, validates the one exception against the
 * whole current definition and bumps the revision once — it never rewrites
 * the rest, so a concurrent edit is neither clobbered nor made stale.
 *
 * Writes throw `CalendarNotFoundException` when the owner has no live
 * calendar under the key; `remove()` refuses a rule of any other calendar.
 */
final readonly class CalendarExceptions
{
    /**
     * @param  Model&OpeningHoursOwner  $owner
     */
    public function __construct(
        private OpeningHoursManager $manager,
        private Model $owner,
        private ?string $calendar = null,
    ) {}

    /**
     * @throws CalendarNotFoundException
     * @throws InvalidOpeningHoursException
     */
    public function add(ExceptionData $data): ExceptionRule
    {
        return $this->manager->addException($this->owner, $data, $this->calendar);
    }

    /**
     * Closed on a date, a date span (`Y-m-d`) or every year (`m-d`, or `yearly: true`).
     *
     * @param  array<string, mixed>|null  $meta
     *
     * @throws CalendarNotFoundException
     * @throws InvalidOpeningHoursException
     */
    public function closed(
        LocalDate|string $from,
        LocalDate|string|null $until = null,
        ?string $label = null,
        bool $yearly = false,
        ?array $meta = null,
    ): ExceptionRule {
        return $this->add(ExceptionData::make($from, $until, [], $label, $yearly, $meta));
    }

    /**
     * Custom hours that replace the schedule on a date, a span or every year.
     *
     * @param  non-empty-list<TimeRange|string>  $ranges
     * @param  array<string, mixed>|null  $meta
     *
     * @throws CalendarNotFoundException
     * @throws InvalidOpeningHoursException
     */
    public function open(
        LocalDate|string $from,
        array $ranges,
        LocalDate|string|null $until = null,
        ?string $label = null,
        bool $yearly = false,
        ?array $meta = null,
    ): ExceptionRule {
        return $this->add(ExceptionData::make($from, $until, $ranges, $label, $yearly, $meta));
    }

    /**
     * Soft-delete one exception of this calendar. `false` when the id is
     * unknown or belongs to another calendar (nothing is touched).
     *
     * @throws CalendarNotFoundException
     */
    public function remove(int $ruleId): bool
    {
        return $this->manager->removeException($this->owner, $ruleId, $this->calendar);
    }

    /**
     * The stored exceptions (with their ids), in definition order; `[]` when
     * the calendar does not exist.
     *
     * @return list<ExceptionData>
     */
    public function all(): array
    {
        $header = $this->manager->calendar($this->owner, $this->calendar);

        return $header === null ? [] : $this->manager->definitionData($header)->exceptions;
    }
}

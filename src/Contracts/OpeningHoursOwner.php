<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use RoundlyConsulting\OpeningHours\Models\Calendar;

/**
 * A model that owns opening-hours calendars. Implement it with the
 * `HasOpeningHours` trait and override the two hooks as needed.
 *
 * @phpstan-require-extends Model
 */
interface OpeningHoursOwner
{
    /**
     * `$this&Model`, not `Model`: relation templates are invariant, so an interface
     * declaring `MorphMany<Calendar, Model>` would reject every implementor.
     *
     * @return MorphMany<Calendar, $this&Model>
     */
    public function openingHoursCalendars(): MorphMany;

    /**
     * The owner's timezone, used when a calendar names none (null = config).
     */
    public function openingHoursTimezone(): ?string;

    /**
     * @return list<DynamicExceptionProvider>
     */
    public function openingHoursDynamicExceptions(): array;
}

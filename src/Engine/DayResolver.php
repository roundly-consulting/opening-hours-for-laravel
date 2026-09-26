<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Engine;

use RoundlyConsulting\OpeningHours\Contracts\DynamicExceptionProvider;
use RoundlyConsulting\OpeningHours\Enums\DaySource;
use RoundlyConsulting\OpeningHours\ValueObjects\LocalDate;

/**
 * Decides which ranges apply on a local date:
 * one-off exception (narrowest) → dynamic providers → yearly exception
 * (narrowest) → schedule in effect → closed.
 *
 * @internal
 */
final readonly class DayResolver
{
    /**
     * @param  list<DynamicExceptionProvider>  $providers
     */
    public function __construct(
        public Definition $definition,
        public array $providers = [],
    ) {}

    public function plan(LocalDate $date): DayPlan
    {
        $exception = $this->definition->oneOffExceptionFor($date);

        if ($exception !== null) {
            return new DayPlan($exception->ranges, DaySource::Exception, $exception->label, $exception->meta);
        }

        foreach ($this->providers as $provider) {
            $dynamic = $provider->exceptionFor($date);

            if ($dynamic !== null) {
                return new DayPlan($dynamic->ranges, DaySource::Dynamic, $dynamic->label, $dynamic->meta);
            }
        }

        $exception = $this->definition->yearlyExceptionFor($date);

        if ($exception !== null) {
            return new DayPlan($exception->ranges, DaySource::Exception, $exception->label, $exception->meta);
        }

        return $this->regular($date);
    }

    /**
     * The schedule's ranges for the date's weekday, ignoring exceptions.
     */
    public function regular(LocalDate $date): DayPlan
    {
        $schedule = $this->definition->scheduleFor($date);

        if ($schedule === null) {
            return new DayPlan([], DaySource::None);
        }

        return new DayPlan($schedule->week->for($date->weekday()), DaySource::Schedule, $schedule->label, $schedule->meta);
    }
}

<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Actions;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use DateTimeInterface;
use RoundlyConsulting\OpeningHours\Exceptions\OpenTimeNotFoundException;
use RoundlyConsulting\OpeningHours\OpeningHours;
use RoundlyConsulting\OpeningHours\Support\Settings;

/**
 * "N business hours": the instant at which `$duration` of open time has elapsed after
 * `$from`, skipping every closed stretch (nights, weekends, holidays, exceptions). Open time is
 * real elapsed time, so a 25-hour fall-back day holds 25 open hours. Open periods are
 * half-open: `$from` exactly at a close counts as closed. The duration counts in whole seconds
 * (`CarbonInterval::totalSeconds`, a fraction dropped); zero or negative returns `$from`. The
 * result must fall within `opening-hours.search_days` days of `$from`'s local date, or
 * `OpenTimeNotFoundException` is thrown.
 *
 * Hosts reach it through `OpeningHours::addOpenTime()`, `OpeningHours::for($owner)->addOpenTime()`
 * and the owner trait.
 */
final readonly class AddOpenTimeAction
{
    public function execute(OpeningHours $hours, DateTimeInterface $from, CarbonInterval $duration): CarbonImmutable
    {
        $remaining = (int) floor($duration->totalSeconds);

        if ($remaining <= 0) {
            return CarbonImmutable::instance($from)->setTimezone($hours->outputTimezone());
        }

        $timeline = $hours->timeline();
        $start = $from->getTimestamp();
        $max = Settings::searchDays();
        $edge = $timeline->forwardEdge($start, $max);

        foreach ($timeline->forward($start, $max) as $run) {
            if ($run->start >= $edge) {
                break;
            }

            $cursor = max($run->start, $start);
            $available = min($run->end, $edge) - $cursor;

            if ($remaining <= $available) {
                return $hours->instant($cursor + $remaining);
            }

            $remaining -= $available;
        }

        throw OpenTimeNotFoundException::within($max);
    }
}

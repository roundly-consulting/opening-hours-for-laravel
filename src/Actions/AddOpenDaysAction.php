<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Actions;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use RoundlyConsulting\OpeningHours\Exceptions\OpenTimeNotFoundException;
use RoundlyConsulting\OpeningHours\OpeningHours;
use RoundlyConsulting\OpeningHours\Support\Settings;
use RoundlyConsulting\OpeningHours\ValueObjects\LocalDate;

/**
 * "N business days": the same local wall-clock time on the n-th open date strictly after
 * `$from`'s local date (Fri 15:00 + 2 → Tue 15:00). An open date is one any range starts on,
 * exceptions and dynamic exceptions applied, so holidays are skipped. A time inside a DST gap
 * moves forward by the gap (02:30 → 03:30); a time that occurs twice takes its first
 * occurrence. Zero or negative `$days` returns `$from`. The n-th open date must fall within
 * `opening-hours.search_days` days of `$from`'s date, or `OpenTimeNotFoundException` is thrown.
 *
 * Hosts reach it through `OpeningHours::addOpenDays()`, `OpeningHours::for($owner)->addOpenDays()`
 * and the owner trait.
 */
final readonly class AddOpenDaysAction
{
    public function execute(OpeningHours $hours, DateTimeInterface $from, int $days): CarbonImmutable
    {
        if ($days <= 0) {
            return CarbonImmutable::instance($from)->setTimezone($hours->outputTimezone());
        }

        $clock = $hours->timeline()->clock;
        $start = $from->getTimestamp();
        $day = $clock->localEpochDay($start);
        $second = $clock->localSecondOfDay($start);
        $max = Settings::searchDays();
        $found = 0;

        for ($i = 1; $i <= $max; $i++) {
            if ($hours->isOpenOnDate(LocalDate::fromEpochDay($day + $i)) && ++$found === $days) {
                return $hours->instant($this->wallTime($hours, $day + $i, $second));
            }
        }

        throw OpenTimeNotFoundException::within($max);
    }

    /**
     * The instant the local clock reads `$second` on `$epochDay`: its first occurrence, or in a
     * DST gap the wall time read with the offset before the gap (how PHP resolves it).
     */
    private function wallTime(OpeningHours $hours, int $epochDay, int $second): int
    {
        $clock = $hours->timeline()->clock;
        $boundary = $clock->boundaryAt($epochDay, intdiv($second, 60));
        $instant = $boundary + $second % 60;

        if ($clock->localEpochDay($instant) === $epochDay && $clock->localSecondOfDay($instant) === $second) {
            return $instant;
        }

        return $epochDay * 86400 + $second - $clock->offsetAt($boundary - 1);
    }
}

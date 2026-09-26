<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Engine;

use RoundlyConsulting\OpeningHours\DataTransferObjects\CalendarData;
use RoundlyConsulting\OpeningHours\DataTransferObjects\ScheduleData;
use RoundlyConsulting\OpeningHours\Enums\Recurrence;

/**
 * Turns a `CalendarData` into a `Definition`: schedules sorted by precedence
 * (priority desc, windowed before base, id asc) and exceptions split by kind.
 *
 * @internal
 */
final class Compiler
{
    public static function compile(CalendarData $data): Definition
    {
        $indexed = [];

        foreach ($data->schedules as $position => $schedule) {
            $indexed[] = [$schedule, $position];
        }

        usort($indexed, static function (array $a, array $b): int {
            /** @var ScheduleData $x */
            $x = $a[0];
            /** @var ScheduleData $y */
            $y = $b[0];

            return [$y->priority, $x->isBase() ? 1 : 0, $x->id ?? PHP_INT_MAX, $a[1]]
                <=> [$x->priority, $y->isBase() ? 1 : 0, $y->id ?? PHP_INT_MAX, $b[1]];
        });

        $oneOff = [];
        $yearly = [];

        foreach ($data->exceptions as $exception) {
            if ($exception->recurrence() === Recurrence::Yearly) {
                $yearly[] = $exception;
            } else {
                $oneOff[] = $exception;
            }
        }

        return new Definition(
            $data,
            array_map(static fn (array $pair): ScheduleData => $pair[0], $indexed),
            $oneOff,
            $yearly,
        );
    }
}

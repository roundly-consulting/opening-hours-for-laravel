<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Actions;

use RoundlyConsulting\OpeningHours\Models\Calendar;
use RoundlyConsulting\OpeningHours\Models\Interval;
use RoundlyConsulting\OpeningHours\Support\CalendarWriter;
use RoundlyConsulting\OpeningHours\Support\Clock;
use RoundlyConsulting\OpeningHours\Support\Materialize;
use RoundlyConsulting\OpeningHours\Support\Settings;
use RoundlyConsulting\OpeningHours\ValueObjects\LocalDate;

/**
 * Replaces ALL of a calendar's materialized intervals with the coalesced
 * periods of `[$from, $to]` (calendar-local days, UTC rows). A full replace
 * keeps the table bounded — trimming only the future would leave every past
 * day behind forever. Trashed calendars hold no intervals.
 *
 * @internal driven by `MaterializeIntervalsJob` and `opening-hours:materialize`
 */
final class MaterializeIntervalsAction
{
    public function execute(Calendar $calendar, LocalDate $from, LocalDate $to): int
    {
        if ($calendar->trashed()) {
            Interval::query()->where('calendar_id', $calendar->id)->delete();

            return 0;
        }

        $hours = Materialize::hoursFor($calendar);
        $clock = $hours->timeline()->clock;
        $chunk = max(1, Settings::maxQueryDays() - 1);
        $rows = [];
        $now = Clock::now();

        for ($day = $from; ! $day->isAfter($to); $day = $day->addDays($chunk)) {
            $last = $day->addDays($chunk - 1);
            $last = $last->isAfter($to) ? $to : $last;
            $start = $hours->instant($clock->boundary($day, 0));
            $end = $hours->instant($clock->boundary($last->addDays(1), 0));

            foreach ($hours->openingPeriodsBetween($start, $end) as $period) {
                $opens = $period->start->getTimestamp();
                $closes = $period->end->getTimestamp();
                $previous = array_key_last($rows);

                // A run clipped at a chunk edge continues in the next chunk: stitch it.
                if ($previous !== null && $rows[$previous]['closes'] === $opens) {
                    $rows[$previous]['closes'] = $closes;

                    continue;
                }

                $rows[] = ['opens' => $opens, 'closes' => $closes];
            }
        }

        return CalendarWriter::transaction(function () use ($calendar, $rows, $now): int {
            Interval::query()->where('calendar_id', $calendar->id)->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                Interval::query()->insert(array_map(static fn (array $row): array => [
                    'calendar_id' => $calendar->id,
                    'owner_type' => $calendar->owner_type,
                    'owner_id' => $calendar->owner_id,
                    'calendar_key' => $calendar->key,
                    'opens_at' => gmdate('Y-m-d H:i:s', $row['opens']),
                    'closes_at' => gmdate('Y-m-d H:i:s', $row['closes']),
                    'created_at' => $now,
                ], $chunk));
            }

            return count($rows);
        });
    }
}

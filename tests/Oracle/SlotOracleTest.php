<?php

declare(strict_types=1);

use Random\Engine\Mt19937;
use Random\Randomizer;
use RoundlyConsulting\OpeningHours\Availability\BusyPeriod;
use RoundlyConsulting\OpeningHours\OpeningHours;
use RoundlyConsulting\OpeningHours\ValueObjects\TimeRange;

/**
 * Slots against a per-minute brute force: for random calendars (per-range
 * capacities, overnight ranges, a DST day), random busy periods, buffers and
 * weights, every grid start in the window is classified independently — open
 * for the whole occupancy (buffers included) and `usage + weight ≤ capacity`
 * at every minute — and the generated slot list must match exactly.
 */
it('matches a per-minute brute force of capacity and hours', function (int $seed): void {
    $random = new Randomizer(new Mt19937($seed));
    $week = [];

    foreach (['friday', 'saturday', 'sunday', 'monday'] as $day) {
        $ranges = [];
        $cursor = $random->getInt(0, 600);

        for ($r = $random->getInt(0, 3); $r > 0 && $cursor < 1380; $r--) {
            $end = min(1440, $cursor + $random->getInt(30, 300));
            $ranges[] = ['from' => TimeRange::fromMinutes($cursor, $end)->start->format(), 'to' => TimeRange::fromMinutes($cursor, $end)->end->format(), 'capacity' => $random->getInt(1, 3)];
            $cursor = $end + $random->getInt(0, 120);
        }

        $week[$day] = $ranges;
    }

    $hours = OpeningHours::make(['week' => $week], 'Europe/Bratislava');
    $from = at('2026-10-24 00:00')->getTimestamp();
    $until = at('2026-10-26 00:00')->getTimestamp();
    $busy = [];

    for ($b = $random->getInt(0, 12); $b > 0; $b--) {
        $start = $from + 60 * $random->getInt(0, 2 * 1440);
        $busy[] = BusyPeriod::make(new DateTimeImmutable('@'.$start), new DateTimeImmutable('@'.($start + 60 * $random->getInt(5, 180))), $random->getInt(1, 2));
    }

    $duration = [15, 30, 45, 60][$random->getInt(0, 3)];
    $before = [0, 5, 15][$random->getInt(0, 2)];
    $after = [0, 10][$random->getInt(0, 1)];
    $weight = $random->getInt(1, 2);
    $capacity = $random->getInt(1, 2);

    $slots = $hours->availability()->at(at('2026-10-20 00:00'))->withBusyPeriods($busy)->capacity($capacity)
        ->buffers($before, $after)
        ->slots(new DateTimeImmutable('@'.$from), new DateTimeImmutable('@'.$until))
        ->duration($duration)->step(15)->weight($weight)->includeUnavailable()->get();

    $actual = [];

    foreach ($slots as $slot) {
        $actual[$slot->start->getTimestamp()] = [$slot->available, $slot->remainingCapacity];
    }

    // Brute force: capacity per minute from the raw periods, usage per minute from busy periods.
    $timeline = $hours->timeline();
    $open = [];
    $cap = [];

    foreach ($timeline->periodsBetween($timeline->clock->localEpochDay($from) - 2, $timeline->clock->localEpochDay($until) + 1) as $period) {
        for ($t = $period->start; $t < $period->end; $t += 60) {
            $open[$t] = true;
            $cap[$t] = max($cap[$t] ?? 0, $period->range->capacity ?? $capacity);
        }
    }

    $usage = static function (int $t) use ($busy): int {
        $sum = 0;

        foreach ($busy as $period) {
            $sum += $period->overlaps($t, $t + 60) ? $period->weight : 0;
        }

        return $sum;
    };

    $expected = [];

    for ($c = $from; $c < $until; $c += 60) {
        if ($timeline->clock->localMinute($c) % 15 !== 0) {
            continue;
        }

        $occupiedFrom = $c - $before * 60;
        $occupiedUntil = $c + ($duration + $after) * 60;
        $fits = true;
        $remaining = PHP_INT_MAX;

        for ($t = $occupiedFrom; $t < $occupiedUntil; $t += 60) {
            if (! isset($open[$t])) {
                $fits = false;
                break;
            }

            $remaining = min($remaining, $cap[$t] - $usage($t));
        }

        if ($fits) {
            $expected[$c] = [$remaining >= $weight, max(0, $remaining)];
        }
    }

    expect($actual)->toBe($expected);
})->with(range(1, 25))->group('oracle');

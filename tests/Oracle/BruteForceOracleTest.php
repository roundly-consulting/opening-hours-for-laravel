<?php

declare(strict_types=1);

use RoundlyConsulting\OpeningHours\Engine\Compiler;
use RoundlyConsulting\OpeningHours\OpeningHours;
use RoundlyConsulting\OpeningHours\Tests\Support\BruteForceOracle;
use RoundlyConsulting\OpeningHours\Tests\Support\CalendarGenerator;
use RoundlyConsulting\OpeningHours\Tests\Support\OracleGrid;

/**
 * The engine against an independent brute-force reference, across every DST
 * shape the boundary rule has to survive: gaps, overlaps (in zones where PHP
 * itself disagrees), 30-minute shifts, midnight transitions, a skipped calendar
 * day and a +05:45 zone. 40 seeded calendars × 10 zone transitions; each case
 * checks isOpenAt at every minute of a 72-hour window and the navigation and
 * duration queries at every boundary ±1 minute plus every 15th minute.
 */
dataset('transitions', [
    'Bratislava spring' => ['Europe/Bratislava', '2026-03-29'],
    'Bratislava autumn' => ['Europe/Bratislava', '2026-10-25'],
    'New York spring' => ['America/New_York', '2026-03-08'],
    'New York autumn' => ['America/New_York', '2026-11-01'],
    'Lord Howe autumn' => ['Australia/Lord_Howe', '2026-04-05'],
    'Lord Howe spring' => ['Australia/Lord_Howe', '2026-10-04'],
    'Santiago autumn' => ['America/Santiago', '2026-04-05'],
    'Santiago spring' => ['America/Santiago', '2026-09-06'],
    'Apia skipped day' => ['Pacific/Apia', '2011-12-30'],
    'Kathmandu' => ['Asia/Kathmandu', '2026-06-15'],
]);

it('agrees with the brute-force oracle around every transition', function (string $timezone, string $date): void {
    $zone = new DateTimeZone($timezone);
    $focus = ld($date);
    $centre = (new DateTimeImmutable($date.' 12:00:00', $zone))->getTimestamp();
    $grid = new OracleGrid($zone, $centre - 36 * 3600, $centre + 36 * 3600);
    $generator = new CalendarGenerator(40);
    $checks = 0;

    for ($c = 0; $c < $generator->count(); $c++) {
        $data = $generator->calendar($c, $focus);
        $oracle = new BruteForceOracle($data, $grid);
        $hours = OpeningHours::fromDefinition(Compiler::compile($data), $zone);
        $timeline = $hours->timeline();

        for ($t = $grid->windowStart; $t < $grid->windowEnd; $t += 60) {
            if ($timeline->isOpenAt($t) !== $oracle->isOpenAt($t)) {
                throw new RuntimeException(sprintf('isOpenAt mismatch: calendar %d, %s at %s', $c, $timezone, gmdate('c', $t)));
            }
        }

        $probes = [];

        foreach ($oracle->boundariesBetween($grid->windowStart, $grid->windowEnd) as $boundary) {
            array_push($probes, $boundary - 60, $boundary, $boundary + 60);
        }

        for ($t = $grid->windowStart; $t < $grid->windowEnd; $t += 15 * 60) {
            $probes[] = $t;
        }

        foreach (array_unique($probes) as $t) {
            $instant = new DateTimeImmutable('@'.$t);

            foreach ([
                'nextOpen' => $oracle->nextOpen($t),
                'nextClose' => $oracle->nextClose($t),
                'previousOpen' => $oracle->previousOpen($t),
                'previousClose' => $oracle->previousClose($t),
            ] as $method => [$determinable, $expected]) {
                if (! $determinable) {
                    continue;
                }

                // The oracle is reliable a day past the 72-hour window, so an answer can
                // sit up to 5 local days away (4 real days when Apia skips one).
                $actual = $hours->{$method}($instant, 5)?->getTimestamp();

                if ($actual !== $expected) {
                    throw new RuntimeException(sprintf('%s mismatch: calendar %d, %s at %s: expected %s, got %s',
                        $method, $c, $timezone, gmdate('c', $t), gmdate('c', (int) $expected), $actual === null ? 'null' : gmdate('c', $actual)));
                }

                $checks++;
            }

            $until = $t + 6 * 3600;

            if ($until <= $grid->windowEnd) {
                $expected = $oracle->openSecondsBetween($t, $until);
                $actual = $hours->openSecondsBetween($instant, new DateTimeImmutable('@'.$until));

                if ($actual !== $expected) {
                    throw new RuntimeException(sprintf('openSecondsBetween mismatch: calendar %d, %s at %s: expected %d, got %d', $c, $timezone, gmdate('c', $t), $expected, $actual));
                }

                $checks++;
            }
        }
    }

    expect($checks)->toBeGreaterThan(1000);
})->with('transitions')->group('oracle');

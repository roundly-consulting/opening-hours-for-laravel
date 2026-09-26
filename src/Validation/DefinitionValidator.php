<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Validation;

use Closure;
use RoundlyConsulting\OpeningHours\DataTransferObjects\CalendarData;
use RoundlyConsulting\OpeningHours\DataTransferObjects\ExceptionData;
use RoundlyConsulting\OpeningHours\DataTransferObjects\ScheduleData;
use RoundlyConsulting\OpeningHours\Engine\WeekCoverage;
use RoundlyConsulting\OpeningHours\Enums\ViolationCode;
use RoundlyConsulting\OpeningHours\Enums\Weekday;
use RoundlyConsulting\OpeningHours\Support\Limits;
use RoundlyConsulting\OpeningHours\Support\TimezoneResolver;
use RoundlyConsulting\OpeningHours\ValueObjects\AbsoluteWindow;
use RoundlyConsulting\OpeningHours\ValueObjects\TimeRange;

/**
 * Semantic checks on a structurally valid definition: no overlapping ranges
 * (weekly ones on the circular week), at most one base schedule, unambiguous
 * seasonal windows and exceptions, unique row ids, and every configured limit.
 */
final class DefinitionValidator
{
    /** @var list<Violation> */
    private array $violations = [];

    private function __construct(private readonly Paths $paths) {}

    public static function validate(CalendarData $data, ?Paths $paths = null): ViolationList
    {
        $validator = new self($paths ?? Paths::canonical($data));
        $validator->run($data);

        return new ViolationList($validator->violations);
    }

    private function run(CalendarData $data): void
    {
        if ($data->timezone !== null && ! TimezoneResolver::isValid($data->timezone)) {
            $this->violate(ViolationCode::InvalidTimezone, 'timezone', ['value' => $data->timezone]);
        }

        $this->label($data->label, 'label');
        $this->meta($data->meta, 'meta');

        if (count($data->schedules) > Limits::schedules()) {
            $this->violate(ViolationCode::LimitExceeded, 'schedules', ['limit' => Limits::schedules()]);
        }

        if (count($data->exceptions) > Limits::exceptions()) {
            $this->violate(ViolationCode::LimitExceeded, 'exceptions', ['limit' => Limits::exceptions()]);
        }

        $this->schedules($data);
        $this->exceptions($data);
        $this->uniqueIds($data->schedules, fn (int $i): string => $this->paths->schedule($i));
        $this->uniqueIds($data->exceptions, fn (int $i): string => $this->paths->exception($i));
    }

    /**
     * An id names one stored row: given twice, the second write would silently
     * replace the first and one of the rows would be lost.
     *
     * @param  list<ScheduleData>|list<ExceptionData>  $items
     * @param  Closure(int): string  $path
     */
    private function uniqueIds(array $items, Closure $path): void
    {
        $seen = [];

        foreach ($items as $i => $item) {
            if ($item->id === null) {
                continue;
            }

            if (isset($seen[$item->id])) {
                $this->violate(ViolationCode::DuplicateId, $path($i).'.id', ['id' => $item->id]);
            }

            $seen[$item->id] = true;
        }
    }

    private function schedules(CalendarData $data): void
    {
        $baseSeen = false;

        foreach ($data->schedules as $i => $schedule) {
            $path = $this->paths->schedule($i);
            $this->label($schedule->label, "{$path}.label");
            $this->meta($schedule->meta, "{$path}.meta");

            if ($schedule->priority < -1000 || $schedule->priority > 1000) {
                $this->violate(ViolationCode::InvalidPriority, "{$path}.priority", ['min' => -1000, 'max' => 1000]);
            }

            foreach (Weekday::ordered() as $weekday) {
                $ranges = $schedule->week->for($weekday);

                if (count($ranges) > Limits::rangesPerDay()) {
                    $this->violate(ViolationCode::LimitExceeded, $this->paths->scheduleDay($i, $weekday), ['limit' => Limits::rangesPerDay()]);
                }

                foreach ($ranges as $k => $range) {
                    $this->rangeFields($range, $this->paths->scheduleRange($i, $weekday, $k));
                }
            }

            foreach (WeekCoverage::overlaps($schedule->week) as [[$dayA, $indexA], [$dayB, $indexB]]) {
                $this->violate(ViolationCode::Overlap, $this->paths->scheduleRange($i, $dayB, $indexB), [
                    'other' => $this->paths->scheduleRange($i, $dayA, $indexA),
                ]);
            }

            if ($schedule->isBase()) {
                if ($baseSeen) {
                    $this->violate(ViolationCode::DuplicateBaseSchedule, $path);
                }

                $baseSeen = true;

                continue;
            }

            for ($j = 0; $j < $i; $j++) {
                $other = $data->schedules[$j];

                if ($other->isBase() || $other->priority !== $schedule->priority || $other->window === null || $schedule->window === null) {
                    continue;
                }

                if ($schedule->window->overlaps($other->window)) {
                    $this->violate(ViolationCode::AmbiguousScheduleWindow, $path, ['other' => $this->paths->schedule($j)]);
                }
            }
        }
    }

    private function exceptions(CalendarData $data): void
    {
        /** @var array<string, list<int>> $buckets */
        $buckets = [];

        foreach ($data->exceptions as $i => $exception) {
            $path = $this->paths->exception($i);
            $this->label($exception->label, "{$path}.label");
            $this->meta($exception->meta, "{$path}.meta");

            if (count($exception->ranges) > Limits::rangesPerDay()) {
                $this->violate(ViolationCode::LimitExceeded, "{$path}.ranges", ['limit' => Limits::rangesPerDay()]);
            }

            foreach ($exception->ranges as $k => $range) {
                $this->rangeFields($range, $this->paths->exceptionRange($i, $k));
            }

            $this->exceptionOverlaps($exception, $i);

            // Exceptions apply to real dates: an open bound is only valid on a schedule window.
            if ($exception->window instanceof AbsoluteWindow && ($exception->window->from === null || $exception->window->until === null)) {
                $this->violate(ViolationCode::InvalidStructure, $path);

                continue;
            }

            $key = $exception->recurrence()->value.':'.$exception->window->spanDays();

            foreach ($buckets[$key] ?? [] as $j) {
                $other = $data->exceptions[$j];

                if ($exception->window->equals($other->window)) {
                    $this->violate(ViolationCode::DuplicateException, $path, ['other' => $this->paths->exception($j)]);
                } elseif ($exception->window->overlaps($other->window)) {
                    $this->violate(ViolationCode::AmbiguousException, $path, ['other' => $this->paths->exception($j)]);
                }
            }

            $buckets[$key][] = $i;
        }
    }

    private function exceptionOverlaps(ExceptionData $exception, int $index): void
    {
        $ranges = $exception->ranges;
        $count = count($ranges);

        for ($a = 0; $a < $count; $a++) {
            for ($b = $a + 1; $b < $count; $b++) {
                if ($ranges[$a]->start->minutes < $ranges[$b]->endOffsetMinutes()
                    && $ranges[$b]->start->minutes < $ranges[$a]->endOffsetMinutes()) {
                    $this->violate(ViolationCode::Overlap, $this->paths->exceptionRange($index, $b), [
                        'other' => $this->paths->exceptionRange($index, $a),
                    ]);
                }
            }
        }
    }

    private function rangeFields(TimeRange $range, string $path): void
    {
        $this->label($range->label, "{$path}.label");
        $this->meta($range->meta, "{$path}.meta");
    }

    private function label(?string $label, string $path): void
    {
        if ($label !== null && mb_strlen($label) > Limits::labelLength()) {
            $this->violate(ViolationCode::LabelTooLong, $path, ['max' => Limits::labelLength()]);
        }
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    private function meta(?array $meta, string $path): void
    {
        if ($meta === null) {
            return;
        }

        $encoded = json_encode($meta);

        if ($encoded === false || strlen($encoded) > Limits::metaBytes()) {
            $this->violate(ViolationCode::MetaTooLarge, $path, ['max' => Limits::metaBytes()]);
        }
    }

    /**
     * @param  array<string, scalar>  $params
     */
    private function violate(ViolationCode $code, string $path, array $params = []): void
    {
        $this->violations[] = new Violation($code, $path, $params);
    }
}

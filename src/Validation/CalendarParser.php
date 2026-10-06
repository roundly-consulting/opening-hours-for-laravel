<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Validation;

use RoundlyConsulting\OpeningHours\Contracts\DateWindow;
use RoundlyConsulting\OpeningHours\DataTransferObjects\CalendarData;
use RoundlyConsulting\OpeningHours\DataTransferObjects\ExceptionData;
use RoundlyConsulting\OpeningHours\DataTransferObjects\ScheduleData;
use RoundlyConsulting\OpeningHours\DataTransferObjects\WeekData;
use RoundlyConsulting\OpeningHours\Enums\Recurrence;
use RoundlyConsulting\OpeningHours\Enums\ViolationCode;
use RoundlyConsulting\OpeningHours\Enums\Weekday;
use RoundlyConsulting\OpeningHours\Support\Limits;
use RoundlyConsulting\OpeningHours\Support\TimezoneResolver;
use RoundlyConsulting\OpeningHours\ValueObjects\AbsoluteWindow;
use RoundlyConsulting\OpeningHours\ValueObjects\LocalDate;
use RoundlyConsulting\OpeningHours\ValueObjects\MonthDay;
use RoundlyConsulting\OpeningHours\ValueObjects\Time;
use RoundlyConsulting\OpeningHours\ValueObjects\TimeRange;
use RoundlyConsulting\OpeningHours\ValueObjects\YearlyWindow;

/**
 * Parses the canonical (untrusted) array shape into a `CalendarData`, collecting
 * every structural violation with its exact input path, then runs the semantic
 * `DefinitionValidator`. Nothing past this parser ever sees a shape array.
 */
final class CalendarParser
{
    private const string BOUND_ABSOLUTE = 'absolute';

    private const string BOUND_YEARLY = 'yearly';

    /** @var list<Violation> */
    private array $violations = [];

    private Paths $paths;

    private function __construct(
        private readonly ParseOptions $options,
        private readonly bool $trusted,
    ) {
        $this->paths = new Paths;
    }

    /**
     * @param  array<mixed>  $input
     */
    public static function parse(array $input, ParseOptions $options = new ParseOptions, bool $trusted = false): ParseResult
    {
        return (new self($options, $trusted))->run($input);
    }

    /**
     * Parse one range — `"HH:MM-HH:MM"` or `{from, to, label?, capacity?, meta?}` —
     * and check it against the label and meta limits a save applies.
     *
     * @return array{?TimeRange, ViolationList}
     */
    public static function parseRangeValue(mixed $value, string $path = ''): array
    {
        $parser = new self(new ParseOptions, false);
        $range = $parser->range($value, $path);
        $violations = new ViolationList($parser->violations);

        return [$range, $range === null ? $violations : $violations->merge(DefinitionValidator::validateRange($range, $path))];
    }

    /**
     * @param  array<mixed>  $input
     */
    private function run(array $input): ParseResult
    {
        $timezone = $this->timezone($input['timezone'] ?? null);
        $label = $this->optionalString($input, 'label', 'label');
        $meta = $this->meta($input, 'meta', 'meta');
        $revision = isset($input['revision']) && is_int($input['revision']) ? $input['revision'] : null;

        $schedules = [];

        if (isset($input['week'])) {
            $week = $this->week($input['week'], 'week', 0);

            if ($week !== null) {
                $this->paths->schedules[0] = 'week';
                $schedules[] = new ScheduleData($week);
            }
        }

        if (isset($input['schedules'])) {
            foreach ($this->list($input['schedules'], 'schedules', Limits::schedules()) as $key => $raw) {
                $schedule = $this->schedule($raw, "schedules.{$key}", count($schedules));

                if ($schedule !== null) {
                    $this->paths->schedules[count($schedules)] = "schedules.{$key}";
                    $schedules[] = $schedule;
                }
            }
        }

        $exceptions = [];

        if (isset($input['exceptions'])) {
            foreach ($this->list($input['exceptions'], 'exceptions', Limits::exceptions()) as $key => $raw) {
                $exception = $this->exception($raw, "exceptions.{$key}", count($exceptions));

                if ($exception !== null) {
                    $this->paths->exceptions[count($exceptions)] = "exceptions.{$key}";
                    $exceptions[] = $exception;
                }
            }
        }

        $data = new CalendarData($timezone, $label, $schedules, $exceptions, $meta, $revision);

        if ($this->options->mergeOverlapping) {
            $data = RangeNormalizer::normalize($data);
            // Merged ranges no longer line up with input positions; report on the day instead.
            $this->paths->byDay = true;
        }

        $violations = new ViolationList($this->violations);

        if (! $this->trusted) {
            $violations = $violations->merge(DefinitionValidator::validate($data, $this->paths));
        }

        return new ParseResult($data, $violations);
    }

    private function timezone(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            if ($this->options->requireTimezone) {
                $this->violate(ViolationCode::TimezoneRequired, 'timezone');
            }

            return null;
        }

        if (! is_string($value) || ! TimezoneResolver::isValid($value)) {
            $this->violate(ViolationCode::InvalidTimezone, 'timezone', ['value' => is_scalar($value) ? (string) $value : get_debug_type($value)]);

            return null;
        }

        return $value;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function list(mixed $value, string $path, int $limit): array
    {
        if (! is_array($value)) {
            $this->violate(ViolationCode::InvalidStructure, $path);

            return [];
        }

        if (count($value) > $limit) {
            $this->violate(ViolationCode::LimitExceeded, $path, ['limit' => $limit]);

            return [];
        }

        return $value;
    }

    private function schedule(mixed $raw, string $path, int $index): ?ScheduleData
    {
        if (! is_array($raw)) {
            $this->violate(ViolationCode::InvalidStructure, $path);

            return null;
        }

        $before = count($this->violations);
        $id = $this->id($raw, $path);
        $label = $this->optionalString($raw, 'label', "{$path}.label");
        $meta = $this->meta($raw, 'meta', "{$path}.meta");
        $priority = $this->priority($raw['priority'] ?? null, "{$path}.priority");
        [$windowOk, $window] = $this->window($raw['window'] ?? null, "{$path}.window", 'from', 'until', allowOpen: true);
        $week = $this->week($raw['week'] ?? [], "{$path}.week", $index);

        if (! $windowOk || $week === null || $priority === null || count($this->violations) > $before && $this->onlyStructural($before)) {
            return null;
        }

        return new ScheduleData($week, $window, $priority, $label, $meta, $id);
    }

    /**
     * A schedule/exception whose own fields (not its ranges) failed is dropped
     * rather than half-built, so it cannot cause misleading follow-up violations.
     */
    private function onlyStructural(int $before): bool
    {
        foreach (array_slice($this->violations, $before) as $violation) {
            if ($violation->code === ViolationCode::InvalidStructure && ! str_contains($violation->path, '.week.') && ! str_contains($violation->path, '.ranges.')) {
                return true;
            }
        }

        return false;
    }

    private function exception(mixed $raw, string $path, int $index): ?ExceptionData
    {
        if (! is_array($raw)) {
            $this->violate(ViolationCode::InvalidStructure, $path);

            return null;
        }

        $hasDate = isset($raw['date']);
        $hasRange = isset($raw['from']) || isset($raw['until']);

        if ($hasDate && $hasRange) {
            $this->violate(ViolationCode::InvalidStructure, "{$path}.date");

            return null;
        }

        if (! $hasDate && ! isset($raw['from'])) {
            $this->violate(ViolationCode::InvalidStructure, $hasRange ? "{$path}.from" : "{$path}.date");

            return null;
        }

        $before = count($this->violations);
        $id = $this->id($raw, $path);
        $label = $this->optionalString($raw, 'label', "{$path}.label");
        $meta = $this->meta($raw, 'meta', "{$path}.meta");

        $window = $hasDate
            ? ['from' => $raw['date'], 'until' => $raw['date'], 'recurrence' => $raw['recurrence'] ?? null]
            : ['from' => $raw['from'], 'until' => $raw['until'] ?? $raw['from'], 'recurrence' => $raw['recurrence'] ?? null];

        [$windowOk, $dateWindow] = $this->window(
            $window,
            $path,
            $hasDate ? 'date' : 'from',
            $hasDate ? 'date' : (isset($raw['until']) ? 'until' : 'from'),
            allowOpen: false,
        );

        $ranges = [];
        $rawRanges = $raw['ranges'] ?? [];

        if (! is_array($rawRanges)) {
            $this->violate(ViolationCode::InvalidStructure, "{$path}.ranges");
        } elseif (count($rawRanges) > Limits::rangesPerDay()) {
            $this->violate(ViolationCode::LimitExceeded, "{$path}.ranges", ['limit' => Limits::rangesPerDay()]);
        } else {
            foreach ($rawRanges as $k => $rawRange) {
                $range = $this->range($rawRange, "{$path}.ranges.{$k}");

                if ($range !== null) {
                    $this->paths->exceptionRanges[$index][count($ranges)] = "{$path}.ranges.{$k}";
                    $ranges[] = $range;
                }
            }
        }

        if (! $windowOk || $dateWindow === null || (count($this->violations) > $before && $this->onlyStructural($before))) {
            return null;
        }

        return new ExceptionData($dateWindow, $ranges, $label, $meta, $id);
    }

    /**
     * @return array{bool, ?DateWindow} `[ok, window]` — a null window is "no window"
     */
    private function window(mixed $raw, string $path, string $fromKey, string $untilKey, bool $allowOpen): array
    {
        if ($raw === null || $raw === []) {
            return [true, null];
        }

        if (! is_array($raw)) {
            $this->violate(ViolationCode::InvalidStructure, $path);

            return [false, null];
        }

        $from = $raw['from'] ?? null;
        $until = $raw['until'] ?? null;
        $recurrenceRaw = $raw['recurrence'] ?? null;
        $prefix = $path === '' ? '' : $path.'.';

        $recurrence = null;

        if ($recurrenceRaw !== null) {
            $recurrence = is_string($recurrenceRaw) ? Recurrence::tryFrom($recurrenceRaw) : null;

            if ($recurrence === null) {
                $this->violate(ViolationCode::InvalidStructure, $prefix.'recurrence');

                return [false, null];
            }
        }

        if ($from === null && $until === null) {
            if ($recurrence === Recurrence::Yearly) {
                $this->violate(ViolationCode::YearlyWindowUnbounded, $path);

                return [false, null];
            }

            return [true, null];
        }

        $fromKind = $from === null ? null : $this->boundKind($from, $prefix.$fromKey);
        $untilKind = match (true) {
            $until === null => null,
            $until === $from && $untilKey === $fromKey => $fromKind,
            default => $this->boundKind($until, $prefix.$untilKey),
        };

        if (($from !== null && $fromKind === null) || ($until !== null && $untilKind === null)) {
            return [false, null];
        }

        $kinds = array_values(array_unique(array_filter([$fromKind, $untilKind])));

        if (count($kinds) > 1) {
            $this->violate(ViolationCode::RecurrenceMismatch, $path);

            return [false, null];
        }

        $inferred = $kinds[0] === self::BOUND_YEARLY ? Recurrence::Yearly : Recurrence::None;

        if ($recurrence !== null && $recurrence !== $inferred) {
            $this->violate(ViolationCode::RecurrenceMismatch, $prefix.'recurrence');

            return [false, null];
        }

        if ($inferred === Recurrence::Yearly) {
            if (! is_string($from) || ! is_string($until)) {
                $this->violate(ViolationCode::YearlyWindowUnbounded, $path);

                return [false, null];
            }

            return [true, new YearlyWindow(MonthDay::fromString($from), MonthDay::fromString($until))];
        }

        if (! $allowOpen && (! is_string($from) || ! is_string($until))) {
            $this->violate(ViolationCode::InvalidStructure, $path);

            return [false, null];
        }

        $fromDate = is_string($from) ? LocalDate::fromString($from) : null;
        $untilDate = is_string($until) ? LocalDate::fromString($until) : null;

        if ($fromDate !== null && $untilDate !== null && $untilDate->isBefore($fromDate)) {
            $this->violate(ViolationCode::WindowInverted, $path, ['from' => (string) $from, 'until' => (string) $until]);

            return [false, null];
        }

        return [true, new AbsoluteWindow($fromDate, $untilDate)];
    }

    private function boundKind(mixed $value, string $path): ?string
    {
        if (! is_string($value)) {
            $this->violate(ViolationCode::InvalidDate, $path, ['value' => is_scalar($value) ? (string) $value : get_debug_type($value)]);

            return null;
        }

        if (preg_match('/^\d{2}-\d{2}$/', $value) === 1) {
            if (! MonthDay::isValid($value)) {
                $this->violate(ViolationCode::InvalidMonthDay, $path, ['value' => $value]);

                return null;
            }

            return self::BOUND_YEARLY;
        }

        if (! LocalDate::isValid($value)) {
            $this->violate(ViolationCode::InvalidDate, $path, ['value' => $value]);

            return null;
        }

        return self::BOUND_ABSOLUTE;
    }

    private function week(mixed $raw, string $path, int $scheduleIndex): ?WeekData
    {
        $this->paths->weeks[$scheduleIndex] = $path;

        if (! is_array($raw)) {
            $this->violate(ViolationCode::InvalidStructure, $path);

            return null;
        }

        $ranges = [];

        foreach ($raw as $key => $dayRaw) {
            $dayPath = "{$path}.{$key}";
            $weekday = Weekday::tryFromKey($key);

            if ($weekday === null) {
                $this->violate(ViolationCode::UnknownWeekday, $dayPath, ['value' => (string) $key]);

                continue;
            }

            if ($dayRaw === null) {
                continue;
            }

            if (is_string($dayRaw)) {
                $dayRaw = [$dayRaw];
            }

            if (! is_array($dayRaw)) {
                $this->violate(ViolationCode::InvalidStructure, $dayPath);

                continue;
            }

            if (count($dayRaw) > Limits::rangesPerDay()) {
                $this->violate(ViolationCode::LimitExceeded, $dayPath, ['limit' => Limits::rangesPerDay()]);

                continue;
            }

            $iso = $weekday->iso();
            $this->paths->scheduleDays[$scheduleIndex * 10 + $iso] = $dayPath;

            foreach ($dayRaw as $k => $rangeRaw) {
                $range = $this->range($rangeRaw, "{$dayPath}.{$k}");

                if ($range !== null) {
                    $this->paths->scheduleRanges[$scheduleIndex][$iso][count($ranges[$iso] ?? [])] = "{$dayPath}.{$k}";
                    $ranges[$iso][] = $range;
                }
            }
        }

        return new WeekData($ranges);
    }

    private function range(mixed $raw, string $path): ?TimeRange
    {
        $label = null;
        $capacity = null;
        $meta = null;

        if (is_string($raw)) {
            if (preg_match('/^\s*(\S+?)\s*(?:-|–)\s*(\S+)\s*$/u', $raw, $m) !== 1) {
                $this->violate(ViolationCode::InvalidTime, $path, ['value' => $raw]);

                return null;
            }

            [$from, $to] = [$m[1], $m[2]];
            $fromPath = $toPath = $path;
        } elseif (is_array($raw)) {
            $from = $raw['from'] ?? null;
            $to = $raw['to'] ?? null;
            $fromPath = "{$path}.from";
            $toPath = "{$path}.to";

            if (! is_string($from) || ! is_string($to)) {
                $this->violate(ViolationCode::InvalidStructure, is_string($from) ? $toPath : $fromPath);

                return null;
            }

            $label = $this->optionalString($raw, 'label', "{$path}.label");
            $meta = $this->meta($raw, 'meta', "{$path}.meta");

            if (isset($raw['capacity'])) {
                if (! is_int($raw['capacity']) || $raw['capacity'] < 1 || $raw['capacity'] > 1000) {
                    $this->violate(ViolationCode::InvalidCapacity, "{$path}.capacity", ['min' => 1, 'max' => 1000]);

                    return null;
                }

                $capacity = $raw['capacity'];
            }
        } else {
            $this->violate(ViolationCode::InvalidStructure, $path);

            return null;
        }

        $valid = true;

        foreach ([[$from, $fromPath], [$to, $toPath]] as [$value, $valuePath]) {
            // A string range is one value: report it once, not once per half.
            if (! Time::isValid($value) && ($valid || $fromPath !== $toPath)) {
                $this->violate(ViolationCode::InvalidTime, $valuePath, ['value' => $value]);
                $valid = false;
            }
        }

        if (! $valid) {
            return null;
        }

        if ($from === '24:00') {
            $this->violate(ViolationCode::StartAt24, $fromPath);

            return null;
        }

        if ($from === $to) {
            $this->violate(ViolationCode::EmptyRange, $path, ['value' => "{$from}-{$to}"]);

            return null;
        }

        return new TimeRange(Time::fromString($from), Time::fromString($to), $label, $capacity, $meta);
    }

    /**
     * @param  array<mixed>  $raw
     */
    private function id(array $raw, string $path): ?int
    {
        $id = $raw['id'] ?? null;

        if ($id === null) {
            return null;
        }

        if (! is_int($id) || $id < 1) {
            $this->violate(ViolationCode::InvalidStructure, "{$path}.id");

            return null;
        }

        return $id;
    }

    private function priority(mixed $value, string $path): ?int
    {
        if ($value === null) {
            return 0;
        }

        if (! is_int($value) || $value < -1000 || $value > 1000) {
            $this->violate(ViolationCode::InvalidPriority, $path, ['min' => -1000, 'max' => 1000]);

            return null;
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $raw
     */
    private function optionalString(array $raw, string $key, string $path): ?string
    {
        $value = $raw[$key] ?? null;

        if ($value === null || is_string($value)) {
            return $value;
        }

        $this->violate(ViolationCode::InvalidStructure, $path);

        return null;
    }

    /**
     * @param  array<mixed>  $raw
     * @return array<string, mixed>|null
     */
    private function meta(array $raw, string $key, string $path): ?array
    {
        $value = $raw[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            $this->violate(ViolationCode::InvalidStructure, $path);

            return null;
        }

        $meta = [];

        foreach ($value as $metaKey => $metaValue) {
            $meta[(string) $metaKey] = $metaValue;
        }

        return $meta;
    }

    /**
     * @param  array<string, scalar>  $params
     */
    private function violate(ViolationCode $code, string $path, array $params = []): void
    {
        $this->violations[] = new Violation($code, $path, $params);
    }
}

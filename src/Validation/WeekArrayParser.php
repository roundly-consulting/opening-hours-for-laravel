<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Validation;

use RoundlyConsulting\OpeningHours\Enums\ViolationCode;
use RoundlyConsulting\OpeningHours\Enums\Weekday;

/**
 * Reads the widely used weekday-keyed week-array format (the shape many apps
 * store today) and hands it to `CalendarParser`:
 *
 * ```php
 * [
 *     'monday' => ['09:00-12:00', ['hours' => '13:00-18:00', 'data' => 'Afternoon']],
 *     'exceptions' => [
 *         '2026-12-24' => [],                              // closed
 *         '12-25' => ['data' => 'Christmas'],              // yearly, closed
 *         '2026-12-27 to 2026-12-30' => ['10:00-12:00'],   // one-off range
 *         '2026-10-17' => ['hours' => '10:00-12:00', 'data' => 'Short day'],
 *     ],
 *     'timezone' => 'Europe/Bratislava',
 * ]
 * ```
 *
 * `data` (string ⇒ label, array ⇒ meta) is kept, `overflow` is ignored (overnight
 * ranges always run past midnight), `filters` is rejected.
 */
final class WeekArrayParser
{
    /**
     * @param  array<mixed>  $input
     */
    public static function parse(array $input, ParseOptions $options = new ParseOptions): ParseResult
    {
        $violations = [];
        $canonical = ['week' => [], 'exceptions' => []];
        /** @var array<string, string> $pathMap canonical prefix => legacy prefix */
        $pathMap = [];

        foreach ($input as $key => $value) {
            $key = (string) $key;

            if ($key === 'filters') {
                $violations[] = new Violation(ViolationCode::UnsupportedWeekArrayFeature, 'filters', ['feature' => 'filters']);

                continue;
            }

            if ($key === 'overflow') {
                continue;
            }

            if ($key === 'timezone') {
                $canonical['timezone'] = $value;

                continue;
            }

            if ($key === 'exceptions') {
                if (! is_array($value)) {
                    $violations[] = new Violation(ViolationCode::InvalidStructure, 'exceptions');

                    continue;
                }

                foreach ($value as $when => $exceptionValue) {
                    $index = count($canonical['exceptions']);
                    $canonical['exceptions'][] = self::exception((string) $when, $exceptionValue);
                    $pathMap["exceptions.{$index}"] = 'exceptions.'.$when;
                }

                continue;
            }

            if (Weekday::tryFromKey($key) === null) {
                $violations[] = new Violation(ViolationCode::UnknownWeekday, $key, ['value' => $key]);

                continue;
            }

            [$ranges] = self::day($value);
            $canonical['week'][$key] = $ranges;
            $pathMap["week.{$key}"] = $key;
        }

        $result = CalendarParser::parse($canonical, $options);
        $mapped = [];

        foreach ($result->violations as $violation) {
            $mapped[] = $violation->withPath(self::legacyPath($violation->path, $pathMap));
        }

        return new ParseResult($result->data, new ViolationList([...$violations, ...$mapped]));
    }

    /**
     * @return array{list<mixed>, mixed} [ranges, data]
     */
    private static function day(mixed $value, bool $inherit = true): array
    {
        if (! is_array($value)) {
            return [is_string($value) ? [self::range($value, null)] : [$value], null];
        }

        $data = $value['data'] ?? null;
        $rangeData = $inherit ? $data : null;

        if (array_key_exists('hours', $value)) {
            $hours = $value['hours'];
            $list = is_array($hours) ? $hours : [$hours];

            return [array_values(array_map(static fn (mixed $range): mixed => self::range($range, $rangeData), $list)), $data];
        }

        $ranges = [];

        foreach ($value as $key => $range) {
            if ($key === 'data') {
                continue;
            }

            $ranges[] = self::range($range, $rangeData);
        }

        return [$ranges, $data];
    }

    /**
     * A week-array range: `'09:00-12:00'` or `['hours' => '09:00-12:00', 'data' => …]`;
     * day-level `data` applies to ranges without their own.
     */
    private static function range(mixed $range, mixed $dayData): mixed
    {
        $data = $dayData;

        if (is_array($range) && array_key_exists('hours', $range)) {
            $data = $range['data'] ?? $dayData;
            $range = $range['hours'];
        }

        if (! is_string($range) || ! preg_match('/^\s*(\S+?)\s*(?:-|–)\s*(\S+)\s*$/u', $range, $m)) {
            return $range;
        }

        return [
            'from' => $m[1],
            'to' => $m[2],
            'label' => is_string($data) ? $data : null,
            'meta' => is_array($data) ? $data : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function exception(string $when, mixed $value): array
    {
        $parts = preg_split('/\s+to\s+/i', trim($when)) ?: [$when];
        $window = count($parts) === 2 ? ['from' => $parts[0], 'until' => $parts[1]] : ['date' => $when];

        [$ranges, $data] = self::day($value, inherit: false);

        return [
            ...$window,
            'ranges' => $ranges,
            'label' => is_string($data) ? $data : null,
            'meta' => is_array($data) ? $data : null,
        ];
    }

    /**
     * @param  array<string, string>  $pathMap
     */
    private static function legacyPath(string $path, array $pathMap): string
    {
        foreach ($pathMap as $canonical => $legacy) {
            if ($path === $canonical || str_starts_with($path, $canonical.'.')) {
                $rest = substr($path, strlen($canonical));

                if (str_starts_with($canonical, 'exceptions.') && str_starts_with($rest, '.ranges')) {
                    $rest = substr($rest, strlen('.ranges'));
                }

                // Legacy ranges are strings; point at the range, not the synthesised from/to.
                return $legacy.(preg_replace('/\.(from|to)$/', '', $rest) ?? $rest);
            }
        }

        return $path;
    }
}

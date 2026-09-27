<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Validation;

use RoundlyConsulting\OpeningHours\Enums\Weekday;

/**
 * Turns a violation path into the name an editor recognises, in the given locale:
 * `week.wednesday.0` ⇒ "Wednesday, range 1", `schedules.1.week.monday.0.to` ⇒
 * "Schedule 2, Monday, range 1 (end time)", `exceptions.0.date` ⇒ "Exception 1 (date)".
 * Row numbers are 1-based. Reads the canonical and the week-array paths; anything
 * it does not recognise is returned unchanged.
 */
final class PathLabel
{
    private const array TOP_LEVEL = ['timezone', 'label', 'meta', 'key', 'revision', 'filters', 'week', 'schedules', 'exceptions'];

    private const array RANGE_FIELDS = ['from' => 'start_time', 'to' => 'end_time', 'capacity' => 'capacity', 'label' => 'label', 'meta' => 'meta'];

    private const array SCHEDULE_FIELDS = ['id' => 'id', 'label' => 'label', 'meta' => 'meta', 'priority' => 'priority', 'week' => 'week'];

    private const array WINDOW_FIELDS = ['from' => 'valid_from', 'until' => 'valid_until', 'recurrence' => 'recurrence'];

    private const array EXCEPTION_FIELDS = [
        'id' => 'id', 'label' => 'label', 'meta' => 'meta', 'date' => 'date', 'from' => 'start_date',
        'until' => 'end_date', 'recurrence' => 'recurrence', 'ranges' => 'ranges',
    ];

    /** @var list<string> */
    private array $parts = [];

    private function __construct(private readonly ?string $locale) {}

    public static function for(string $path, ?string $locale = null): string
    {
        return (new self($locale))->resolve($path) ?? $path;
    }

    /**
     * `$subject` (e.g. a form field's name) followed by the field a path inside one
     * range points at: `to` ⇒ "Lunch (end time)", `''` ⇒ "Lunch".
     */
    public static function forRange(string $subject, string $path, ?string $locale = null): string
    {
        $label = new self($locale);
        $label->parts = [$subject];

        return $label->field($path === '' ? [] : explode('.', $path), self::RANGE_FIELDS) ?? $subject;
    }

    private function resolve(string $path): ?string
    {
        if ($path === '') {
            return $this->line('root');
        }

        $segments = explode('.', $path);
        $head = array_shift($segments);

        return match (true) {
            $head === 'week' => $this->week($segments),
            $head === 'schedules' => $this->schedule($segments),
            $head === 'exceptions' => $this->exception($segments),
            // The week-array format keys days at the top level.
            $segments !== [] => Weekday::tryFromKey($head) === null ? null : $this->day($head, $segments, 'root'),
            in_array($head, self::TOP_LEVEL, true) => $this->line('top.'.$head),
            // A lone unknown top-level key is the week-array format's unknown weekday.
            default => $this->day($head, [], 'root'),
        };
    }

    /**
     * @param  list<string>  $segments
     */
    private function week(array $segments): ?string
    {
        if ($segments === []) {
            return $this->finish('top.week');
        }

        return $this->day(array_shift($segments), $segments, 'top.week');
    }

    /**
     * @param  list<string>  $segments
     */
    private function day(string $key, array $segments, string $parent): ?string
    {
        $weekday = Weekday::tryFromKey($key);

        // An unknown weekday: the message names it, the label names where it sits.
        if ($weekday === null) {
            return $this->parts === [] ? $this->line($parent) : $this->finish('fields.week');
        }

        $this->parts[] = $weekday->translated($this->locale);

        if ($segments === []) {
            return $this->finish();
        }

        return $this->range(array_shift($segments), $segments);
    }

    /**
     * @param  list<string>  $segments
     */
    private function range(string $position, array $segments): ?string
    {
        if (! ctype_digit($position)) {
            return null;
        }

        $this->parts[] = $this->line('range', ['number' => (int) $position + 1]);

        return $this->field($segments, self::RANGE_FIELDS);
    }

    /**
     * @param  list<string>  $segments
     */
    private function schedule(array $segments): ?string
    {
        $index = array_shift($segments);

        if ($index === null) {
            return $this->line('top.schedules');
        }

        if (! ctype_digit($index)) {
            return null;
        }

        $this->parts[] = $this->line('schedule', ['number' => (int) $index + 1]);
        $next = $segments[0] ?? null;

        if ($next === 'week' && count($segments) > 1) {
            return $this->day($segments[1], array_slice($segments, 2), 'top.week');
        }

        if ($next === 'window') {
            return count($segments) === 1 ? $this->finish('fields.window') : $this->field(array_slice($segments, 1), self::WINDOW_FIELDS);
        }

        return $this->field($segments, self::SCHEDULE_FIELDS);
    }

    /**
     * @param  list<string>  $segments
     */
    private function exception(array $segments): ?string
    {
        $key = array_shift($segments);

        if ($key === null) {
            return $this->line('top.exceptions');
        }

        // Canonical input is a list; the week-array format keys exceptions by date.
        $this->parts[] = ctype_digit($key)
            ? $this->line('exception', ['number' => (int) $key + 1])
            : $this->line('exception_on', ['date' => $key]);

        $next = $segments[0] ?? null;

        if ($next === 'ranges' && count($segments) > 1) {
            return $this->range($segments[1], array_slice($segments, 2));
        }

        if ($next !== null && ctype_digit($next)) {
            return $this->range($next, array_slice($segments, 1));
        }

        return $this->field($segments, self::EXCEPTION_FIELDS);
    }

    /**
     * @param  list<string>  $segments
     * @param  array<string, string>  $fields  path segment => label key
     */
    private function field(array $segments, array $fields): ?string
    {
        if ($segments === []) {
            return $this->finish();
        }

        if (count($segments) > 1 || ! isset($fields[$segments[0]])) {
            return null;
        }

        return $this->finish('fields.'.$fields[$segments[0]]);
    }

    private function finish(?string $field = null): string
    {
        $label = implode(', ', $this->parts);

        if ($field === null) {
            return $label;
        }

        $field = $this->line($field);

        return $label === '' ? $field : "{$label} ({$field})";
    }

    /**
     * @param  array<string, int|string>  $replace
     */
    private function line(string $key, array $replace = []): string
    {
        return (string) trans('opening-hours::validation.labels.'.$key, $replace, $this->locale);
    }
}

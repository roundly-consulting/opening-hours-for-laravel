<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\OpeningHours\DataTransferObjects\CalendarData;
use RoundlyConsulting\OpeningHours\DataTransferObjects\ExceptionData;
use RoundlyConsulting\OpeningHours\DataTransferObjects\ScheduleData;
use RoundlyConsulting\OpeningHours\Enums\ViolationCode;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidOpeningHoursException;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidOwnerException;
use RoundlyConsulting\OpeningHours\Exceptions\StaleOpeningHoursException;
use RoundlyConsulting\OpeningHours\Validation\DefinitionValidator;
use RoundlyConsulting\OpeningHours\Validation\Violation;
use RoundlyConsulting\OpeningHours\Validation\ViolationList;

/**
 * @internal the checks a write runs before it touches a row — shared by the
 * actions and `OpeningHoursFake`, so the fake refuses exactly what production refuses
 */
final class WriteChecks
{
    public const string KEY_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/';

    /**
     * A persisted owner, a well-formed calendar key and a valid definition.
     *
     * @throws InvalidOwnerException
     * @throws InvalidOpeningHoursException
     */
    public static function sync(Model $owner, CalendarData $data, string $key): void
    {
        if (! $owner->exists) {
            throw InvalidOwnerException::notPersisted();
        }

        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw InvalidOpeningHoursException::fromViolation(new Violation(ViolationCode::InvalidCalendarKey, 'key', ['value' => $key]));
        }

        self::definition($data);
    }

    /**
     * A new or restored calendar must fit under `limits.calendars` for its owner.
     *
     * @throws InvalidOpeningHoursException
     */
    public static function calendarLimit(Model $owner): void
    {
        $class = CalendarModel::class();
        $count = $class::query()->where('owner_type', $owner->getMorphClass())->where('owner_id', $owner->getKey())->count();

        if ($count >= Limits::calendars()) {
            throw InvalidOpeningHoursException::fromViolation(new Violation(ViolationCode::LimitExceeded, 'key', ['limit' => Limits::calendars()]));
        }
    }

    /**
     * `0` means "must not exist yet"; any other number must match the stored
     * revision of a live calendar. A new or soft-deleted calendar counts as fresh.
     *
     * @throws StaleOpeningHoursException
     */
    public static function revision(?int $expected, bool $fresh, int $current): void
    {
        if ($expected !== null && ($expected === 0 ? ! $fresh : ($fresh || $current !== $expected))) {
            throw StaleOpeningHoursException::make($expected, $fresh ? 0 : $current);
        }
    }

    /**
     * IDOR guard: an id in the payload must belong to this calendar.
     *
     * @param  array<int|string>  $known
     * @param  list<ScheduleData>|list<ExceptionData>  $items
     *
     * @throws InvalidOpeningHoursException
     */
    public static function ids(array $known, array $items, string $path): void
    {
        $violations = [];
        $known = array_map('intval', $known);

        foreach ($items as $index => $item) {
            if ($item->id !== null && ! in_array($item->id, $known, true)) {
                $violations[] = new Violation(ViolationCode::UnknownId, "{$path}.{$index}.id", ['id' => $item->id]);
            }
        }

        if ($violations !== []) {
            throw InvalidOpeningHoursException::withViolations(new ViolationList($violations));
        }
    }

    /**
     * One more exception, validated against the whole current definition
     * (overlaps, duplicates, limits). An `id` on it is dropped: adding always
     * creates a new rule.
     *
     * @throws InvalidOpeningHoursException
     */
    public static function exception(CalendarData $current, ExceptionData $data): ExceptionData
    {
        $data = $data->id === null ? $data : new ExceptionData($data->window, $data->ranges, $data->label, $data->meta);

        self::definition(new CalendarData($current->timezone, $current->label, $current->schedules, [...$current->exceptions, $data], $current->meta));

        return $data;
    }

    /**
     * @throws InvalidOpeningHoursException
     */
    private static function definition(CalendarData $data): void
    {
        $violations = DefinitionValidator::validate($data);

        if (! $violations->isEmpty()) {
            throw InvalidOpeningHoursException::withViolations($violations);
        }
    }
}

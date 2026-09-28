<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Facades;

use Closure;
use DateTimeZone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\OpeningHours\Builders\CalendarBuilder;
use RoundlyConsulting\OpeningHours\CalendarExceptions;
use RoundlyConsulting\OpeningHours\DataTransferObjects\CalendarData;
use RoundlyConsulting\OpeningHours\Models\Calendar;
use RoundlyConsulting\OpeningHours\OpeningHours as OpeningHoursQuery;
use RoundlyConsulting\OpeningHours\OpeningHoursManager;
use RoundlyConsulting\OpeningHours\Testing\OpeningHoursFake;
use RoundlyConsulting\OpeningHours\Testing\RecordedWrite;
use RoundlyConsulting\OpeningHours\Validation\ParseOptions;
use RoundlyConsulting\OpeningHours\Validation\ViolationList;

/**
 * The query object shares this short name; alias one of them when a file needs
 * both (`use …\Facades\OpeningHours as Hours;`). The `assert*()` / `writes()`
 * lines exist only after `OpeningHours::fake()`.
 *
 * @method static OpeningHoursQuery for(Model $owner, ?string $calendar = null)
 * @method static Calendar|null calendar(Model $owner, ?string $calendar = null)
 * @method static bool has(Model $owner, ?string $calendar = null)
 * @method static CalendarBuilder edit(Model $owner, ?string $calendar = null)
 * @method static OpeningHoursQuery sync(Model $owner, CalendarData|array<mixed> $data, ?string $calendar = null, ?int $expectedRevision = null)
 * @method static OpeningHoursQuery make(CalendarData|array<mixed> $data, DateTimeZone|string|null $timezone = null)
 * @method static bool delete(Model $owner, ?string $calendar = null, bool $force = false)
 * @method static CalendarExceptions exceptions(Model $owner, ?string $calendar = null)
 * @method static void refresh(Model $owner, ?string $calendar = null)
 * @method static ViolationList validate(array<mixed> $payload, ?ParseOptions $options = null)
 * @method static CalendarData definitionData(Calendar $calendar)
 * @method static void flushMemo()
 * @method static list<RecordedWrite> writes()
 * @method static void assertSynced(Model $owner, ?string $calendar = null, ?Closure $callback = null)
 * @method static void assertNothingSynced()
 * @method static void assertDeleted(Model $owner, ?string $calendar = null, ?bool $force = null)
 * @method static void assertNothingDeleted()
 * @method static void assertRefreshed(Model $owner, ?string $calendar = null)
 * @method static void assertNothingRefreshed()
 * @method static void assertExceptionAdded(Model $owner, ?string $calendar = null, ?Closure $callback = null)
 * @method static void assertNoExceptionsAdded()
 * @method static void assertExceptionRemoved(Model $owner, ?int $ruleId = null, ?string $calendar = null)
 * @method static void assertNoExceptionsRemoved()
 * @method static void assertNothingWritten()
 *
 * @see OpeningHoursManager
 * @see OpeningHoursFake
 */
final class OpeningHours extends Facade
{
    /**
     * Record every write instead of persisting it; reads still hit the database.
     */
    public static function fake(): OpeningHoursFake
    {
        $fake = app(OpeningHoursFake::class);
        self::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return OpeningHoursManager::class;
    }
}

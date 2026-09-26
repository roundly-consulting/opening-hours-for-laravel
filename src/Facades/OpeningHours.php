<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Facades;

use DateTimeZone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\OpeningHours\Builders\CalendarBuilder;
use RoundlyConsulting\OpeningHours\DataTransferObjects\CalendarData;
use RoundlyConsulting\OpeningHours\Models\Calendar;
use RoundlyConsulting\OpeningHours\OpeningHours as OpeningHoursQuery;
use RoundlyConsulting\OpeningHours\OpeningHoursManager;
use RoundlyConsulting\OpeningHours\Validation\ParseOptions;
use RoundlyConsulting\OpeningHours\Validation\ViolationList;

/**
 * The query object shares this short name; alias one of them when a file needs
 * both (`use …\Facades\OpeningHours as Hours;`).
 *
 * @method static OpeningHoursQuery for(Model $owner, ?string $calendar = null)
 * @method static Calendar|null calendar(Model $owner, ?string $calendar = null)
 * @method static bool has(Model $owner, ?string $calendar = null)
 * @method static CalendarBuilder edit(Model $owner, ?string $calendar = null)
 * @method static OpeningHoursQuery sync(Model $owner, CalendarData|array<mixed> $data, ?string $calendar = null, ?int $expectedRevision = null)
 * @method static OpeningHoursQuery make(CalendarData|array<mixed> $data, DateTimeZone|string|null $timezone = null)
 * @method static bool delete(Model $owner, ?string $calendar = null)
 * @method static void refresh(Model $owner, ?string $calendar = null)
 * @method static ViolationList validate(array<mixed> $payload, ?ParseOptions $options = null)
 * @method static void flushMemo()
 *
 * @see OpeningHoursManager
 */
final class OpeningHours extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return OpeningHoursManager::class;
    }
}

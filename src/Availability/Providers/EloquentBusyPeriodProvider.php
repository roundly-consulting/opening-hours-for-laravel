<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Availability\Providers;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use RoundlyConsulting\OpeningHours\Availability\BusyPeriod;
use RoundlyConsulting\OpeningHours\Contracts\BusyPeriodProvider;
use RoundlyConsulting\OpeningHours\Engine\WallClock;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidBusyPeriodException;
use RoundlyConsulting\OpeningHours\Exceptions\TooManyBusyPeriodsException;
use RoundlyConsulting\OpeningHours\Support\Limits;
use RoundlyConsulting\OpeningHours\Support\TimezoneResolver;
use RoundlyConsulting\OpeningHours\ValueObjects\LocalDate;

/**
 * Busy periods from any Eloquent query (bookings, appointments…) without the
 * package knowing the model. Rows with a NULL end last `durationColumn` minutes,
 * else `defaultDuration`; how far back such rows are looked for is the longest
 * stored duration (one MAX query) unless `maxNullEndMinutes` states it. Bindings are sent as strings in `storedIn`'s timezone
 * (a Carbon binding would be formatted in its own), and raw values are resolved
 * with the same first-occurrence DST rule as opening hours. Column names are
 * allowlist-validated; nothing is ever interpolated from user input.
 */
final class EloquentBusyPeriodProvider implements BusyPeriodProvider
{
    private const string IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/';

    private const int SLACK_HOURS = 3;

    private string $startColumn = 'starts_at';

    private string $endColumn = 'ends_at';

    private ?string $durationColumn = null;

    private ?string $weightColumn = null;

    private int $defaultDuration = 0;

    private ?int $maxNullEndMinutes = null;

    private ?DateTimeZone $storedIn = null;

    /**
     * @param  Builder<Model>  $query
     */
    private function __construct(private readonly Builder $query) {}

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    public static function for(Builder $query): self
    {
        /** @var Builder<Model> $query */
        return new self($query);
    }

    public function columns(string $start, string $end): self
    {
        $this->startColumn = self::identifier($start);
        $this->endColumn = self::identifier($end);

        return $this;
    }

    public function durationColumn(?string $column): self
    {
        $this->durationColumn = $column === null ? null : self::identifier($column);

        return $this;
    }

    public function weightColumn(?string $column): self
    {
        $this->weightColumn = $column === null ? null : self::identifier($column);

        return $this;
    }

    public function defaultDuration(int $minutes): self
    {
        if ($minutes < 1) {
            throw InvalidBusyPeriodException::invalidDuration($minutes);
        }

        $this->defaultDuration = $minutes;

        return $this;
    }

    /**
     * How far back to look for NULL-end rows. Must be at least the longest duration
     * a row can have; setting it skips the MAX(duration) query run on every read.
     */
    public function maxNullEndMinutes(int $minutes): self
    {
        if ($minutes < 1) {
            throw InvalidBusyPeriodException::invalidDuration($minutes);
        }

        $this->maxNullEndMinutes = $minutes;

        return $this;
    }

    /**
     * The timezone the raw column values are written in (default `app.timezone`).
     */
    public function storedIn(DateTimeZone|string $timezone): self
    {
        $this->storedIn = TimezoneResolver::validate($timezone);

        return $this;
    }

    public function busyPeriodsBetween(CarbonImmutable $start, CarbonImmutable $end): iterable
    {
        $zone = $this->storedIn ?? self::appTimezone();
        // Wall-clock bindings are ambiguous around DST changes; widen the SQL window
        // by a few hours and re-check every row precisely in PHP below.
        $lookback = $start->subMinutes($this->nullEndLookback($end, $zone))->subHours(self::SLACK_HOURS);
        $limit = Limits::busyPeriods();

        $columns = array_values(array_filter([$this->startColumn, $this->endColumn, $this->durationColumn, $this->weightColumn]));
        $rows = (clone $this->query)->toBase()
            ->select($columns)
            ->where($this->startColumn, '<', self::binding($end->addHours(self::SLACK_HOURS), $zone))
            ->where(function (QueryBuilder $overlap) use ($start, $lookback, $zone): void {
                $overlap->where($this->endColumn, '>', self::binding($start->subHours(self::SLACK_HOURS), $zone))
                    ->orWhere(function (QueryBuilder $open) use ($lookback, $zone): void {
                        $open->whereNull($this->endColumn)->where($this->startColumn, '>', self::binding($lookback, $zone));
                    });
            })
            ->limit($limit + 1)
            ->get();

        if ($rows->count() > $limit) {
            throw TooManyBusyPeriodsException::make($limit);
        }

        $clock = WallClock::for($zone);
        $periods = [];

        foreach ($rows as $row) {
            $values = (array) $row;
            $from = self::instant($values[self::column($this->startColumn)] ?? null, $clock);

            if ($from === null) {
                continue;
            }

            $until = self::instant($values[self::column($this->endColumn)] ?? null, $clock);

            if ($until === null) {
                $minutes = $this->durationColumn === null ? null : ($values[self::column($this->durationColumn)] ?? null);
                $until = $from + 60 * (is_numeric($minutes) ? (int) $minutes : $this->defaultDuration);
            }

            if ($until <= $from || $from >= $end->getTimestamp() || $until <= $start->getTimestamp()) {
                continue;
            }

            $weight = $this->weightColumn === null ? 1 : ($values[self::column($this->weightColumn)] ?? 1);

            $periods[] = new BusyPeriod(
                CarbonImmutable::createFromTimestamp($from, $zone),
                CarbonImmutable::createFromTimestamp($until, $zone),
                max(1, is_numeric($weight) ? (int) $weight : 1),
            );
        }

        return $periods;
    }

    /**
     * A NULL-end row can only reach the window if it started at most its duration
     * before it. Without a declared bound, the longest stored duration decides —
     * a fixed guess would silently drop long bookings and allow double booking.
     */
    private function nullEndLookback(CarbonImmutable $end, DateTimeZone $zone): int
    {
        if ($this->maxNullEndMinutes !== null) {
            return $this->maxNullEndMinutes;
        }

        if ($this->durationColumn === null) {
            return $this->defaultDuration;
        }

        $longest = (clone $this->query)->toBase()
            ->reorder()
            ->whereNull($this->endColumn)
            ->where($this->startColumn, '<', self::binding($end->addHours(self::SLACK_HOURS), $zone))
            ->max($this->durationColumn);

        return max($this->defaultDuration, is_numeric($longest) ? (int) $longest : 0);
    }

    /**
     * Eloquent writes datetimes in the app timezone; `opening-hours.timezone`
     * only decides where calendars are evaluated and never reinterprets rows.
     */
    private static function appTimezone(): DateTimeZone
    {
        $app = config('app.timezone');

        return TimezoneResolver::validate(is_string($app) && $app !== '' ? $app : 'UTC');
    }

    private static function identifier(string $column): string
    {
        if (preg_match(self::IDENTIFIER, $column) !== 1) {
            throw InvalidBusyPeriodException::invalidIdentifier($column);
        }

        return $column;
    }

    private static function column(string $identifier): string
    {
        $position = strrpos($identifier, '.');

        return $position === false ? $identifier : substr($identifier, $position + 1);
    }

    private static function binding(CarbonImmutable $instant, DateTimeZone $zone): string
    {
        return $instant->setTimezone($zone)->format('Y-m-d H:i:s');
    }

    private static function instant(mixed $value, WallClock $clock): ?int
    {
        if ($value instanceof DateTimeInterface) {
            return $value->getTimestamp();
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        // A value that carries its own offset (timestamptz) is already an instant.
        if (preg_match('/(Z|[+-]\d{2}(:?\d{2})?)$/', $value) === 1 && preg_match('/\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-])/', $value) === 1) {
            return CarbonImmutable::parse($value)->getTimestamp();
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/', $value, $m) !== 1) {
            return null;
        }

        $minute = (int) $m[2] * 60 + (int) $m[3];

        return $clock->boundary(LocalDate::fromString($m[1]), $minute) + (int) ($m[4] ?? 0);
    }
}

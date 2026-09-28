<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Http\Resources;

use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\OpeningHours\Enums\WeekMode;
use RoundlyConsulting\OpeningHours\OpeningHours;
use RoundlyConsulting\OpeningHours\Support\Clock;
use RoundlyConsulting\OpeningHours\Support\Settings;
use RoundlyConsulting\OpeningHours\ValueObjects\DayHours;
use RoundlyConsulting\OpeningHours\ValueObjects\TimeRange;

/**
 * The status shape: open now?, the current period, next open/close, today,
 * a week and upcoming exceptions. Instants are ISO-8601 with offset; only
 * `weekday_label` and `text` are translated.
 *
 * @property OpeningHours $resource
 */
final class OpeningStatusResource extends JsonResource
{
    private ?DateTimeInterface $at = null;

    /**
     * Evaluate at this instant instead of now.
     */
    public function at(?DateTimeInterface $at): self
    {
        $this->at = $at;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $hours = $this->resource;
        $at = $hours->instant(($this->at ?? Clock::now())->getTimestamp());
        $withMeta = Settings::exposeMeta();
        $locale = app()->getLocale();
        $today = $hours->forDate($at);
        $date = $hours->localDate($at);

        // A fixed seven days, not a caller-chosen span: max_query_days does not apply.
        $week = Settings::weekMode() === WeekMode::CalendarWeek
            ? $hours->forWeekOf($date)
            : array_map(static fn (int $offset): DayHours => $hours->forDate($date->addDays($offset)), range(0, 6));

        // Never ask for more days than max_query_days allows: the status would throw.
        $upcoming = $hours->exceptionsBetween($date, $date->addDays(min(Settings::upcomingExceptionsDays(), Settings::maxQueryDays() - 1)));

        return [
            'timezone' => $hours->timezone()->getName(),
            'at' => $at->toIso8601String(),
            'is_open' => $hours->isOpenAt($at),
            'current_period' => $hours->currentPeriod($at)?->toArray(),
            'next_open' => $hours->nextOpen($at)?->toIso8601String(),
            'next_close' => $hours->nextClose($at)?->toIso8601String(),
            'today' => $today->toArray($withMeta, $locale),
            'week' => array_map(static fn (DayHours $day): array => $day->toArray($withMeta, $locale), $week),
            'upcoming_exceptions' => array_map(static fn (DayHours $day): array => [
                'date' => $day->date?->toDateString(),
                'closed' => $day->isClosed(),
                'label' => $day->label,
                'ranges' => array_map(static fn (TimeRange $range): array => $range->toArray($withMeta), $day->ranges),
            ], $upcoming),
        ];
    }
}

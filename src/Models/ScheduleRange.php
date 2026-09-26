<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RoundlyConsulting\OpeningHours\Casts\TimeCast;
use RoundlyConsulting\OpeningHours\Casts\WeekdayCast;
use RoundlyConsulting\OpeningHours\Database\Factories\ScheduleRangeFactory;
use RoundlyConsulting\OpeningHours\Enums\Weekday;
use RoundlyConsulting\OpeningHours\Models\Concerns\BumpsCalendarRevision;
use RoundlyConsulting\OpeningHours\Models\Concerns\GuardsRangeInvariants;
use RoundlyConsulting\OpeningHours\Support\ScheduleModel;
use RoundlyConsulting\OpeningHours\ValueObjects\Time;
use RoundlyConsulting\OpeningHours\ValueObjects\TimeRange;

/**
 * One range of a weekly schedule. A value row: replaced wholesale on sync,
 * so it carries no soft deletes.
 *
 * @property int $id
 * @property int $schedule_id
 * @property Weekday $weekday
 * @property Time $start_minute
 * @property Time $end_minute
 * @property int|null $capacity
 * @property string|null $label
 * @property array<string, mixed>|null $meta
 * @property int $position
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
final class ScheduleRange extends Model
{
    use BumpsCalendarRevision;
    use GuardsRangeInvariants;

    /** @use HasFactory<ScheduleRangeFactory> */
    use HasFactory;

    protected $table = 'opening_hours_schedule_ranges';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weekday' => WeekdayCast::class,
            'start_minute' => TimeCast::class,
            'end_minute' => TimeCast::class,
            'capacity' => 'integer',
            'position' => 'integer',
            'meta' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Schedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ScheduleModel::class(), 'schedule_id');
    }

    public function toTimeRange(): TimeRange
    {
        return new TimeRange($this->start_minute, $this->end_minute, $this->label, $this->capacity, $this->meta);
    }

    protected function guardsWeekday(): bool
    {
        return true;
    }

    protected function calendarIdForRevision(): ?int
    {
        $class = ScheduleModel::class();
        $calendarId = $class::query()->withTrashed()->whereKey($this->schedule_id)->value('calendar_id');

        return $calendarId === null ? null : (int) $calendarId;
    }

    protected static function newFactory(): ScheduleRangeFactory
    {
        return ScheduleRangeFactory::new();
    }
}

<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\OpeningHours\Casts\LocalDateCast;
use RoundlyConsulting\OpeningHours\Contracts\DateWindow;
use RoundlyConsulting\OpeningHours\Database\Factories\ScheduleFactory;
use RoundlyConsulting\OpeningHours\DataTransferObjects\ScheduleData;
use RoundlyConsulting\OpeningHours\DataTransferObjects\WeekData;
use RoundlyConsulting\OpeningHours\Enums\Recurrence;
use RoundlyConsulting\OpeningHours\Models\Concerns\BumpsCalendarRevision;
use RoundlyConsulting\OpeningHours\Support\CalendarModel;
use RoundlyConsulting\OpeningHours\ValueObjects\AbsoluteWindow;
use RoundlyConsulting\OpeningHours\ValueObjects\LocalDate;
use RoundlyConsulting\OpeningHours\ValueObjects\YearlyWindow;

/**
 * A weekly schedule of a calendar; without a window it is the base schedule.
 * Swappable via `opening-hours.models.schedule` (hence not final).
 *
 * @property int $id
 * @property int $calendar_id
 * @property string|null $label
 * @property int $priority
 * @property Recurrence $recurrence
 * @property LocalDate|null $effective_from
 * @property LocalDate|null $effective_until
 * @property int $position
 * @property array<string, mixed>|null $meta
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 */
class Schedule extends Model
{
    use BumpsCalendarRevision;

    /** @use HasFactory<ScheduleFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'opening_hours_schedules';

    protected $guarded = [];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'recurrence' => 'none',
        'priority' => 0,
        'position' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'position' => 'integer',
            'recurrence' => Recurrence::class,
            'effective_from' => LocalDateCast::class,
            'effective_until' => LocalDateCast::class,
            'meta' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Calendar, $this>
     */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(CalendarModel::class(), 'calendar_id');
    }

    /**
     * @return HasMany<ScheduleRange, $this>
     */
    public function ranges(): HasMany
    {
        return $this->hasMany(ScheduleRange::class, 'schedule_id')->orderBy('weekday')->orderBy('position')->orderBy('id');
    }

    /**
     * The validity window, or null for the base schedule.
     */
    public function window(): ?DateWindow
    {
        $from = $this->effective_from;
        $until = $this->effective_until;

        if ($this->recurrence === Recurrence::Yearly && $from !== null && $until !== null) {
            return new YearlyWindow($from->monthDay(), $until->monthDay());
        }

        if ($from === null && $until === null) {
            return null;
        }

        return new AbsoluteWindow($from, $until);
    }

    public function toData(): ScheduleData
    {
        $ranges = [];

        foreach ($this->ranges as $range) {
            $ranges[$range->weekday->iso()][] = $range->toTimeRange();
        }

        return new ScheduleData(new WeekData($ranges), $this->window(), $this->priority, $this->label, $this->meta, $this->id);
    }

    protected function calendarIdForRevision(): ?int
    {
        return $this->calendar_id;
    }

    protected static function newFactory(): ScheduleFactory
    {
        return ScheduleFactory::new();
    }
}

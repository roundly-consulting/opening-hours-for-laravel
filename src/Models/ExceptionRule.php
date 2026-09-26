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
use RoundlyConsulting\OpeningHours\Database\Factories\ExceptionRuleFactory;
use RoundlyConsulting\OpeningHours\DataTransferObjects\ExceptionData;
use RoundlyConsulting\OpeningHours\Enums\Recurrence;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidDateException;
use RoundlyConsulting\OpeningHours\Models\Concerns\BumpsCalendarRevision;
use RoundlyConsulting\OpeningHours\Support\CalendarModel;
use RoundlyConsulting\OpeningHours\ValueObjects\AbsoluteWindow;
use RoundlyConsulting\OpeningHours\ValueObjects\LocalDate;
use RoundlyConsulting\OpeningHours\ValueObjects\YearlyWindow;

/**
 * A stored exception: closed or custom hours on a date, a span, or every year.
 * Swappable via `opening-hours.models.exception_rule` (hence not final).
 *
 * @property int $id
 * @property int $calendar_id
 * @property Recurrence $recurrence
 * @property LocalDate $starts_on
 * @property LocalDate $ends_on
 * @property string|null $label
 * @property int $position
 * @property array<string, mixed>|null $meta
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 */
class ExceptionRule extends Model
{
    use BumpsCalendarRevision;

    /** @use HasFactory<ExceptionRuleFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'opening_hours_exception_rules';

    protected $guarded = [];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'recurrence' => 'none',
        'position' => 0,
    ];

    protected static function booted(): void
    {
        static::saving(static function (ExceptionRule $rule): void {
            if ($rule->recurrence === Recurrence::None && $rule->ends_on->isBefore($rule->starts_on)) {
                throw InvalidDateException::inverted($rule->starts_on->toDateString(), $rule->ends_on->toDateString());
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recurrence' => Recurrence::class,
            'starts_on' => LocalDateCast::class,
            'ends_on' => LocalDateCast::class,
            'position' => 'integer',
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
     * @return HasMany<ExceptionRange, $this>
     */
    public function ranges(): HasMany
    {
        return $this->hasMany(ExceptionRange::class, 'exception_rule_id')->orderBy('position')->orderBy('id');
    }

    public function window(): DateWindow
    {
        if ($this->recurrence === Recurrence::Yearly) {
            return new YearlyWindow($this->starts_on->monthDay(), $this->ends_on->monthDay());
        }

        return new AbsoluteWindow($this->starts_on, $this->ends_on);
    }

    public function toData(): ExceptionData
    {
        return new ExceptionData(
            $this->window(),
            array_values($this->ranges->map(static fn (ExceptionRange $range) => $range->toTimeRange())->all()),
            $this->label,
            $this->meta,
            $this->id,
        );
    }

    protected function calendarIdForRevision(): ?int
    {
        return $this->calendar_id;
    }

    protected static function newFactory(): ExceptionRuleFactory
    {
        return ExceptionRuleFactory::new();
    }
}

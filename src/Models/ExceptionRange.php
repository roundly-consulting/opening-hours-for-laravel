<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RoundlyConsulting\OpeningHours\Casts\TimeCast;
use RoundlyConsulting\OpeningHours\Database\Factories\ExceptionRangeFactory;
use RoundlyConsulting\OpeningHours\Models\Concerns\BumpsCalendarRevision;
use RoundlyConsulting\OpeningHours\Models\Concerns\GuardsRangeInvariants;
use RoundlyConsulting\OpeningHours\Support\ExceptionRuleModel;
use RoundlyConsulting\OpeningHours\ValueObjects\Time;
use RoundlyConsulting\OpeningHours\ValueObjects\TimeRange;

/**
 * One range of an exception rule; a value row without soft deletes.
 *
 * @property int $id
 * @property int $exception_rule_id
 * @property Time $start_minute
 * @property Time $end_minute
 * @property int|null $capacity
 * @property string|null $label
 * @property array<string, mixed>|null $meta
 * @property int $position
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
final class ExceptionRange extends Model
{
    use BumpsCalendarRevision;
    use GuardsRangeInvariants;

    /** @use HasFactory<ExceptionRangeFactory> */
    use HasFactory;

    protected $table = 'opening_hours_exception_ranges';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_minute' => TimeCast::class,
            'end_minute' => TimeCast::class,
            'capacity' => 'integer',
            'position' => 'integer',
            'meta' => 'array',
        ];
    }

    /**
     * @return BelongsTo<ExceptionRule, $this>
     */
    public function exceptionRule(): BelongsTo
    {
        return $this->belongsTo(ExceptionRuleModel::class(), 'exception_rule_id');
    }

    public function toTimeRange(): TimeRange
    {
        return new TimeRange($this->start_minute, $this->end_minute, $this->label, $this->capacity, $this->meta);
    }

    protected function guardsWeekday(): bool
    {
        return false;
    }

    protected function calendarIdForRevision(): ?int
    {
        $class = ExceptionRuleModel::class();
        $calendarId = $class::query()->withTrashed()->whereKey($this->exception_rule_id)->value('calendar_id');

        return $calendarId === null ? null : (int) $calendarId;
    }

    protected static function newFactory(): ExceptionRangeFactory
    {
        return ExceptionRangeFactory::new();
    }
}

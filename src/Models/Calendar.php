<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\OpeningHours\Database\Factories\CalendarFactory;
use RoundlyConsulting\OpeningHours\DataTransferObjects\CalendarData;
use RoundlyConsulting\OpeningHours\DataTransferObjects\ExceptionData;
use RoundlyConsulting\OpeningHours\DataTransferObjects\ScheduleData;
use RoundlyConsulting\OpeningHours\Support\ExceptionRuleModel;
use RoundlyConsulting\OpeningHours\Support\ScheduleModel;

/**
 * One named opening-hours calendar of an owner. Swappable via
 * `opening-hours.models.calendar` (hence not final).
 *
 * @property int $id
 * @property string $owner_type
 * @property int|string $owner_id
 * @property string $key
 * @property string|null $label
 * @property string|null $timezone
 * @property int $revision
 * @property array<string, mixed>|null $meta
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 */
class Calendar extends Model
{
    /** @use HasFactory<CalendarFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'opening_hours_calendars';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'revision' => 'integer',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<Schedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(ScheduleModel::class(), 'calendar_id')->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<ExceptionRule, $this>
     */
    public function exceptionRules(): HasMany
    {
        return $this->hasMany(ExceptionRuleModel::class(), 'calendar_id')->orderBy('position')->orderBy('id');
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeForOwner(Builder $query, Model $owner): void
    {
        $query->where('owner_type', $owner->getMorphClass())->where('owner_id', $owner->getKey());
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeForKey(Builder $query, string $key): void
    {
        $query->where('key', $key);
    }

    /**
     * The stored definition as the engine's input — the only place models
     * become a `CalendarData`.
     */
    public function toData(): CalendarData
    {
        $this->loadMissing(['schedules.ranges', 'exceptionRules.ranges']);

        return new CalendarData(
            timezone: $this->timezone,
            label: $this->label,
            schedules: array_values($this->schedules->map(static fn (Schedule $schedule): ScheduleData => $schedule->toData())->all()),
            exceptions: array_values($this->exceptionRules->map(static fn (ExceptionRule $rule): ExceptionData => $rule->toData())->all()),
            meta: $this->meta,
            revision: $this->revision,
        );
    }

    protected static function newFactory(): CalendarFactory
    {
        return CalendarFactory::new();
    }
}

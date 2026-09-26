<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\OpeningHours\Database\Factories\IntervalFactory;

/**
 * A materialized, coalesced opening period (UTC) — derived data rebuilt by
 * `opening-hours:materialize`, so no soft deletes and no `updated_at`.
 *
 * @property int $id
 * @property int $calendar_id
 * @property string $owner_type
 * @property int|string $owner_id
 * @property string $calendar_key
 * @property string $opens_at
 * @property string $closes_at
 * @property CarbonInterface|null $created_at
 */
final class Interval extends Model
{
    /** @use HasFactory<IntervalFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'opening_hours_intervals';

    protected $guarded = [];

    public function opensAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->opens_at, 'UTC');
    }

    public function closesAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->closes_at, 'UTC');
    }

    protected static function newFactory(): IntervalFactory
    {
        return IntervalFactory::new();
    }
}

<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\OpeningHours\Support\Settings;
use RoundlyConsulting\OpeningHours\ValueObjects\DayHours;

/**
 * One day's hours: `{date, weekday, weekday_label, closed, all_day, source, label, ranges, text}`.
 *
 * @property DayHours $resource
 */
final class DayHoursResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray(Settings::exposeMeta(), app()->getLocale());
    }
}

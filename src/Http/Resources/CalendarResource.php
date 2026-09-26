<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\OpeningHours\DataTransferObjects\CalendarData;
use RoundlyConsulting\OpeningHours\Models\Calendar;
use RoundlyConsulting\OpeningHours\OpeningHoursManager;
use RoundlyConsulting\OpeningHours\Support\Settings;

/**
 * The definition shape: the canonical input plus ids, `key`, `revision` and
 * `updated_at` — `CalendarData::fromArray()` accepts it back unchanged, so a
 * client can edit and resubmit it (echoing `revision` for optimistic writes).
 * `meta` is included only with `api.expose_meta`.
 *
 * @property Calendar|CalendarData $resource
 */
final class CalendarResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $calendar = $this->resource instanceof Calendar ? $this->resource : null;
        $data = $this->resource instanceof CalendarData
            ? $this->resource
            : app(OpeningHoursManager::class)->definitionData($this->resource);

        return [
            'id' => $calendar?->id,
            'key' => $calendar?->key,
            'revision' => $calendar !== null ? $calendar->revision : $data->revision,
            'updated_at' => $calendar?->updated_at?->toIso8601String(),
            ...$data->toArray(Settings::exposeMeta()),
        ];
    }
}

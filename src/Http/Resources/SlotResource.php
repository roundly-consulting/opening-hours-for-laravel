<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\OpeningHours\Availability\Slot;

/**
 * `{start, end, available, remaining_capacity, reason}`.
 *
 * @property Slot $resource
 */
final class SlotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}

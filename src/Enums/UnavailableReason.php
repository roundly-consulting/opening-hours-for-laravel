<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Enums;

use RoundlyConsulting\Enums\Helpers;

/**
 * Why an availability check or a slot failed, in evaluation order.
 */
enum UnavailableReason: string
{
    use Helpers;

    case InvalidRange = 'invalid_range';
    case InPast = 'in_past';
    case TooSoon = 'too_soon';
    case TooFar = 'too_far';
    case Closed = 'closed';
    case Busy = 'busy';

    /**
     * The translated, user-facing explanation.
     */
    public function message(?string $locale = null): string
    {
        return (string) trans('opening-hours::messages.unavailable.'.$this->value, [], $locale);
    }
}

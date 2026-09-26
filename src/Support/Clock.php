<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/**
 * The package's single source of "now". Reads Laravel's date factory, so
 * `Carbon::setTestNow()` / `travelTo()` drive every query deterministically.
 */
final class Clock
{
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::instance(Date::now());
    }
}

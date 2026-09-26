<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Contracts;

use RoundlyConsulting\OpeningHours\DataTransferObjects\ExceptionData;
use RoundlyConsulting\OpeningHours\ValueObjects\LocalDate;

/**
 * Computes exceptions no stored rule can express (movable holidays, custom
 * recurrences). Consulted after one-off exceptions and before yearly ones;
 * the first provider returning non-null wins. The returned window is ignored —
 * the exception applies to `$date`.
 */
interface DynamicExceptionProvider
{
    public function exceptionFor(LocalDate $date): ?ExceptionData;
}

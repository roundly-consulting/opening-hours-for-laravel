<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\OpeningHours\OpeningHours;
use RoundlyConsulting\OpeningHours\Tests\Support\SwappedModelsTestCase;
use RoundlyConsulting\OpeningHours\Tests\Support\UuidKeysTestCase;
use RoundlyConsulting\OpeningHours\Tests\TestCase;
use RoundlyConsulting\OpeningHours\ValueObjects\LocalDate;
use RoundlyConsulting\OpeningHours\ValueObjects\MonthDay;

uses(TestCase::class)->in('Arch', 'Feature', 'Unit', 'Oracle');

// Model-swap and uuid-key proofs need their config applied BEFORE boot: own base cases.
uses(SwappedModelsTestCase::class)->in('ModelSwap');
uses(UuidKeysTestCase::class)->in('UuidKeys');

function md(string $value): MonthDay
{
    return MonthDay::fromString($value);
}

function ld(string $value): LocalDate
{
    return LocalDate::fromString($value);
}

/**
 * An instant written as local wall time in a zone.
 */
function at(string $local, string $timezone = 'Europe/Bratislava'): CarbonImmutable
{
    return CarbonImmutable::parse($local, $timezone);
}

/**
 * An instant with an explicit offset.
 */
function iso(string $iso): CarbonImmutable
{
    return CarbonImmutable::parse($iso);
}

/**
 * @param  array<mixed>  $definition
 */
function hours(array $definition, string $timezone = 'Europe/Bratislava'): OpeningHours
{
    return OpeningHours::make($definition, $timezone);
}

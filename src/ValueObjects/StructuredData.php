<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\ValueObjects;

/**
 * schema.org `OpeningHoursSpecification` items, ready for JSON-LD.
 */
final readonly class StructuredData
{
    /**
     * @param  list<array<string, string>>  $items
     */
    public function __construct(public array $items) {}

    /**
     * @return list<array<string, string>>
     */
    public function toArray(): array
    {
        return $this->items;
    }

    public function toJson(int $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE): string
    {
        return (string) json_encode($this->items, $flags | JSON_THROW_ON_ERROR);
    }
}

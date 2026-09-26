<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Validation;

use RoundlyConsulting\OpeningHours\Enums\ViolationCode;

/**
 * One problem in an opening-hours definition, located by a dot path into the
 * input (`schedules.0.week.monday.1`).
 */
final readonly class Violation
{
    /**
     * @param  array<string, scalar>  $params
     */
    public function __construct(
        public ViolationCode $code,
        public string $path,
        public array $params = [],
    ) {}

    public function message(?string $locale = null): string
    {
        return (string) trans(
            'opening-hours::validation.'.$this->code->value,
            ['path' => $this->path, ...array_map(static fn (mixed $value): string => (string) $value, $this->params)],
            $locale,
        );
    }

    public function withPath(string $path): self
    {
        return new self($this->code, $path, $this->params);
    }

    /**
     * @return array{code: string, path: string, params: array<string, scalar>}
     */
    public function toArray(): array
    {
        return ['code' => $this->code->value, 'path' => $this->path, 'params' => $this->params];
    }
}

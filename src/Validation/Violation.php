<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Validation;

use RoundlyConsulting\OpeningHours\Enums\ViolationCode;

/**
 * One problem in an opening-hours definition, located by a dot path into the
 * input (`schedules.0.week.monday.1`). Messages name the location the way an
 * editor sees it ("Schedule 1, Monday, range 2") — see `PathLabel`.
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

    /**
     * The translated message. `$subject` replaces the path's label, e.g. with the
     * form field's display name when the path is relative to a single value.
     */
    public function message(?string $locale = null, ?string $subject = null): string
    {
        $params = array_map(static fn (mixed $value): string => (string) $value, $this->params);

        if (isset($params['other'])) {
            $params['other'] = PathLabel::for($params['other'], $locale);
        }

        return (string) trans(
            'opening-hours::validation.'.$this->code->value,
            [...$params, 'path' => $subject ?? PathLabel::for($this->path, $locale)],
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

<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Validation;

use ArrayIterator;
use Countable;
use Illuminate\Support\MessageBag;
use IteratorAggregate;
use RoundlyConsulting\OpeningHours\Enums\ViolationCode;
use Traversable;

/**
 * Every violation found in a definition, in discovery order.
 *
 * @implements IteratorAggregate<int, Violation>
 */
final readonly class ViolationList implements Countable, IteratorAggregate
{
    /**
     * @param  list<Violation>  $violations
     */
    public function __construct(private array $violations = []) {}

    public function isEmpty(): bool
    {
        return $this->violations === [];
    }

    public function first(): ?Violation
    {
        return $this->violations[0] ?? null;
    }

    /**
     * @return list<Violation>
     */
    public function all(): array
    {
        return $this->violations;
    }

    public function has(ViolationCode $code): bool
    {
        foreach ($this->violations as $violation) {
            if ($violation->code === $code) {
                return true;
            }
        }

        return false;
    }

    public function merge(self $other): self
    {
        return new self([...$this->violations, ...$other->violations]);
    }

    public function count(): int
    {
        return count($this->violations);
    }

    /**
     * @return Traversable<int, Violation>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->violations);
    }

    /**
     * Translated messages keyed by `{prefix}.{path}`.
     */
    public function toMessageBag(string $prefix = '', ?string $locale = null): MessageBag
    {
        $bag = new MessageBag;

        foreach ($this->violations as $violation) {
            $key = trim($prefix.'.'.$violation->path, '.');
            $bag->add($key === '' ? 'opening_hours' : $key, $violation->message($locale));
        }

        return $bag;
    }
}

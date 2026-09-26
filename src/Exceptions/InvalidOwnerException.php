<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Exceptions;

/**
 * The owner model cannot hold opening hours: it is not persisted yet, or it
 * does not implement the owner contract.
 */
final class InvalidOwnerException extends OpeningHoursException
{
    public static function notPersisted(): self
    {
        return new self('Opening hours can only be written for a persisted owner model; save the owner first.');
    }

    public static function notAnOwner(string $class): self
    {
        return new self("[{$class}] does not implement the OpeningHoursOwner contract.");
    }

    public static function notFound(string $owner, string $id): self
    {
        return new self("No [{$owner}] with id [{$id}] was found.");
    }
}

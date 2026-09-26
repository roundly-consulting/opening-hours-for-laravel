<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Exceptions;

use RoundlyConsulting\OpeningHours\Validation\Violation;
use RoundlyConsulting\OpeningHours\Validation\ViolationList;

/**
 * An opening-hours definition failed validation. Carries every violation found,
 * never only the first.
 */
final class InvalidOpeningHoursException extends OpeningHoursException
{
    private ViolationList $violations;

    public static function withViolations(ViolationList $violations): self
    {
        $first = $violations->first();
        $message = 'The opening hours definition is invalid';

        if ($first !== null) {
            $path = $first->path === '' ? '' : " at [{$first->path}]";
            $message .= ": {$first->code->value}{$path}";

            if (count($violations) > 1) {
                $message .= ' (and '.(count($violations) - 1).' more)';
            }
        }

        $exception = new self($message.'.');
        $exception->violations = $violations;

        return $exception;
    }

    public static function fromViolation(Violation $violation): self
    {
        return self::withViolations(new ViolationList([$violation]));
    }

    public function violations(): ViolationList
    {
        return $this->violations;
    }
}

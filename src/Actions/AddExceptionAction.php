<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Actions;

use RoundlyConsulting\OpeningHours\DataTransferObjects\CalendarData;
use RoundlyConsulting\OpeningHours\DataTransferObjects\ExceptionData;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidOpeningHoursException;
use RoundlyConsulting\OpeningHours\Models\Calendar;
use RoundlyConsulting\OpeningHours\Models\ExceptionRule;
use RoundlyConsulting\OpeningHours\Support\CalendarWriter;
use RoundlyConsulting\OpeningHours\Support\ExceptionRuleModel;
use RoundlyConsulting\OpeningHours\Support\RevisionGuard;
use RoundlyConsulting\OpeningHours\Validation\DefinitionValidator;

/**
 * Adds one exception to a calendar, validated against the whole current
 * definition under a row lock, with a single revision bump.
 */
final readonly class AddExceptionAction
{
    public function __construct(private BumpRevisionAction $bumpRevision) {}

    /**
     * @throws InvalidOpeningHoursException
     */
    public function execute(Calendar $calendar, ExceptionData $data): ExceptionRule
    {
        return RevisionGuard::suppress(fn (): ExceptionRule => CalendarWriter::transaction(function () use ($calendar, $data): ExceptionRule {
            $locked = CalendarWriter::lock($calendar->id);
            $current = $locked->toData();
            $candidate = new CalendarData($current->timezone, $current->label, $current->schedules, [...$current->exceptions, $data], $current->meta);
            $violations = DefinitionValidator::validate($candidate);

            if (! $violations->isEmpty()) {
                throw InvalidOpeningHoursException::withViolations($violations);
            }

            [$recurrence, $from, $until] = CalendarWriter::windowColumns($data->window);
            $class = ExceptionRuleModel::class();
            $rule = $class::query()->create([
                'calendar_id' => $locked->id,
                'recurrence' => $recurrence,
                'starts_on' => $from,
                'ends_on' => $until,
                'label' => $data->label,
                'meta' => $data->meta,
                'position' => count($current->exceptions),
            ]);

            CalendarWriter::insertExceptionRanges($rule->id, $data->ranges);
            $this->bumpRevision->execute($locked->id);

            return $rule;
        }));
    }
}

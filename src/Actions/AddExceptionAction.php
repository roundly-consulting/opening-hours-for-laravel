<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Actions;

use RoundlyConsulting\OpeningHours\DataTransferObjects\CalendarData;
use RoundlyConsulting\OpeningHours\DataTransferObjects\ExceptionData;
use RoundlyConsulting\OpeningHours\Exceptions\CalendarNotFoundException;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidOpeningHoursException;
use RoundlyConsulting\OpeningHours\Models\Calendar;
use RoundlyConsulting\OpeningHours\Models\ExceptionRule;
use RoundlyConsulting\OpeningHours\Support\CalendarWriter;
use RoundlyConsulting\OpeningHours\Support\ExceptionRuleModel;
use RoundlyConsulting\OpeningHours\Support\RevisionGuard;
use RoundlyConsulting\OpeningHours\Validation\DefinitionValidator;

/**
 * Adds one exception to a calendar, validated against the whole current
 * definition under a row lock, with a single revision bump. Unlike a builder
 * `save()`, it never replaces the rest of the definition, so a concurrent
 * edit can neither be clobbered by it nor make it stale. An `id` on the data
 * is ignored: this always creates a new rule.
 */
final readonly class AddExceptionAction
{
    public function __construct(private BumpRevisionAction $bumpRevision) {}

    /**
     * @throws CalendarNotFoundException when the calendar was deleted meanwhile
     * @throws InvalidOpeningHoursException
     */
    public function execute(Calendar $calendar, ExceptionData $data): ExceptionRule
    {
        $data = $data->id === null ? $data : new ExceptionData($data->window, $data->ranges, $data->label, $data->meta);

        return RevisionGuard::suppress(fn (): ExceptionRule => CalendarWriter::transaction(function () use ($calendar, $data): ExceptionRule {
            $locked = CalendarWriter::lock($calendar->id);

            if ($locked->trashed()) {
                throw CalendarNotFoundException::forKey($locked->key);
            }

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

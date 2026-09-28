<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Actions;

use RoundlyConsulting\OpeningHours\Exceptions\CalendarNotFoundException;
use RoundlyConsulting\OpeningHours\Models\Calendar;
use RoundlyConsulting\OpeningHours\Support\CalendarWriter;
use RoundlyConsulting\OpeningHours\Support\ExceptionRuleModel;
use RoundlyConsulting\OpeningHours\Support\RevisionGuard;

/**
 * Soft-deletes one exception of THIS calendar (an id of another calendar is
 * simply not found), with a single revision bump.
 */
final readonly class RemoveExceptionAction
{
    public function __construct(private BumpRevisionAction $bumpRevision) {}

    /**
     * @throws CalendarNotFoundException when the calendar was deleted meanwhile
     */
    public function execute(Calendar $calendar, int $exceptionRuleId): bool
    {
        return RevisionGuard::suppress(fn (): bool => CalendarWriter::transaction(function () use ($calendar, $exceptionRuleId): bool {
            $locked = CalendarWriter::lock($calendar->id);

            if ($locked->trashed()) {
                throw CalendarNotFoundException::forKey($locked->key);
            }
            $class = ExceptionRuleModel::class();
            $rule = $class::query()->where('calendar_id', $locked->id)->whereKey($exceptionRuleId)->first();

            if ($rule === null) {
                return false;
            }

            $rule->delete();
            $this->bumpRevision->execute($locked->id);

            return true;
        }));
    }
}

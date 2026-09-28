<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Actions;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\OpeningHours\DataTransferObjects\PruneResult;
use RoundlyConsulting\OpeningHours\Enums\Recurrence;
use RoundlyConsulting\OpeningHours\Models\ExceptionRule;
use RoundlyConsulting\OpeningHours\Support\CalendarModel;
use RoundlyConsulting\OpeningHours\Support\Clock;
use RoundlyConsulting\OpeningHours\Support\ExceptionRuleModel;
use RoundlyConsulting\OpeningHours\Support\RevisionGuard;
use RoundlyConsulting\OpeningHours\Support\ScheduleModel;

/**
 * Housekeeping: soft-deletes one-off exceptions that ended more than N days ago
 * and purges soft-deleted rows older than M days. Chunked by id; each affected
 * live calendar gets exactly one revision bump.
 *
 * @internal driven by `opening-hours:prune`
 */
final readonly class PruneAction
{
    private const int CHUNK = 500;

    public function __construct(private BumpRevisionAction $bumpRevision) {}

    public function execute(?int $exceptionsAfterDays, ?int $trashedAfterDays, bool $dryRun = false): PruneResult
    {
        return RevisionGuard::suppress(function () use ($exceptionsAfterDays, $trashedAfterDays, $dryRun): PruneResult {
            $pruned = $exceptionsAfterDays === null ? 0 : $this->pruneExceptions($exceptionsAfterDays, $dryRun);
            $purged = $trashedAfterDays === null ? 0 : $this->purgeTrashed($trashedAfterDays, $dryRun);

            return new PruneResult($pruned, $purged);
        });
    }

    private function pruneExceptions(int $days, bool $dryRun): int
    {
        // One indexed comparison in UTC; a day of timezone slack is irrelevant for pruning.
        $cutoff = Clock::now()->setTimezone('UTC')->subDays($days)->toDateString();
        $class = ExceptionRuleModel::class();
        $query = $class::query()->where('recurrence', Recurrence::None->value)->where('ends_on', '<', $cutoff);

        if ($dryRun) {
            return $query->count();
        }

        $count = 0;
        $calendars = [];

        $query->chunkById(self::CHUNK, function (Collection $rules) use (&$count, &$calendars): void {
            /** @var Collection<int, ExceptionRule> $rules */
            foreach ($rules as $rule) {
                $rule->delete();
                $calendars[$rule->calendar_id] = true;
                $count++;
            }
        });

        foreach (array_keys($calendars) as $calendarId) {
            $this->bumpRevision->execute($calendarId);
        }

        return $count;
    }

    private function purgeTrashed(int $days, bool $dryRun): int
    {
        $cutoff = Clock::now()->subDays($days);
        $count = 0;

        foreach ([ExceptionRuleModel::class(), ScheduleModel::class(), CalendarModel::class()] as $class) {
            $query = $class::query()->onlyTrashed()->where('deleted_at', '<', $cutoff);

            if ($dryRun) {
                $count += $query->count();

                continue;
            }

            $query->chunkById(self::CHUNK, function (Collection $models) use (&$count): void {
                foreach ($models as $model) {
                    /** @var Model $model */
                    $model->forceDelete();
                    $count++;
                }
            });
        }

        return $count;
    }
}

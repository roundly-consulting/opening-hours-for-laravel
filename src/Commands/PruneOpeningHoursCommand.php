<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\OpeningHours\Actions\PruneAction;
use RoundlyConsulting\OpeningHours\Support\Settings;

/**
 * Housekeeping — schedule it daily. Options default to `opening-hours.prune.*`.
 */
final class PruneOpeningHoursCommand extends Command
{
    protected $signature = 'opening-hours:prune
        {--exceptions-after-days= : Soft-delete one-off exceptions that ended more than N days ago}
        {--trashed-after-days= : Permanently remove rows soft-deleted more than N days ago}
        {--dry-run : Only report what would be pruned}';

    protected $description = 'Prune past exceptions and purge old soft-deleted opening-hours rows';

    public function handle(PruneAction $prune): int
    {
        $exceptions = $this->days('exceptions-after-days') ?? Settings::pruneExceptionsAfterDays();
        $trashed = $this->days('trashed-after-days') ?? Settings::pruneTrashedAfterDays();

        if ($exceptions === null && $trashed === null) {
            $this->info('Nothing to prune: set opening-hours.prune.* or pass --exceptions-after-days / --trashed-after-days.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $result = $prune->execute($exceptions, $trashed, $dryRun);
        $verb = $dryRun ? 'Would prune' : 'Pruned';

        $this->info("{$verb} {$result->exceptionsPruned} past exception(s) and {$result->rowsPurged} soft-deleted row(s).");

        return self::SUCCESS;
    }

    private function days(string $option): ?int
    {
        $value = $this->option($option);

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }
}

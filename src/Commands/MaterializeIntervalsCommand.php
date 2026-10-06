<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Collection;
use RoundlyConsulting\OpeningHours\Actions\MaterializeIntervalsAction;
use RoundlyConsulting\OpeningHours\Models\Calendar;
use RoundlyConsulting\OpeningHours\Support\CalendarModel;
use RoundlyConsulting\OpeningHours\Support\Materialize;

/**
 * Rolls the materialized horizon forward — schedule it daily when
 * `materialize.enabled`. Queued per calendar unless `--sync`.
 */
final class MaterializeIntervalsCommand extends Command
{
    protected $signature = 'opening-hours:materialize
        {--calendar=* : Calendar ids (default: every live calendar)}
        {--sync : Run inline instead of queueing a job per calendar}';

    protected $description = 'Materialize opening-hours intervals for the SQL "open at" scopes';

    public function handle(MaterializeIntervalsAction $action, Dispatcher $bus, Repository $cache): int
    {
        if (! Materialize::enabled()) {
            $this->warn('Materialization is disabled; set opening-hours.materialize.enabled to true.');

            return self::SUCCESS;
        }

        $sync = (bool) $this->option('sync');
        /** @var list<string> $ids */
        $ids = (array) $this->option('calendar');
        $class = CalendarModel::class();
        $query = $class::query();

        if ($ids !== []) {
            $query->withTrashed()->whereKey(array_map('intval', $ids));
        }

        $count = 0;

        $query->chunkById(200, function (Collection $calendars) use ($sync, $action, $bus, $cache, &$count): void {
            foreach ($calendars as $calendar) {
                /** @var Calendar $calendar */
                if ($sync) {
                    [$from, $to] = Materialize::window(Materialize::hoursFor($calendar));
                    $action->execute($calendar, $from, $to);
                } else {
                    Materialize::dispatchJob($calendar->id, $bus, $cache);
                }

                $count++;
            }
        });

        $this->info(($sync ? 'Materialized' : 'Queued').' '.$count.' calendar(s).');

        return self::SUCCESS;
    }
}

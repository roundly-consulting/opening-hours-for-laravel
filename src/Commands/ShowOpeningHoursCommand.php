<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use RoundlyConsulting\OpeningHours\Contracts\OpeningHoursOwner;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidOwnerException;
use RoundlyConsulting\OpeningHours\OpeningHoursManager;
use RoundlyConsulting\OpeningHours\Support\Clock;
use RoundlyConsulting\OpeningHours\Support\Settings;
use RoundlyConsulting\OpeningHours\ValueObjects\DayHours;

/**
 * Prints an owner's status and the hours of the coming days.
 */
final class ShowOpeningHoursCommand extends Command
{
    protected $signature = 'opening-hours:show
        {owner : Morph alias or model class}
        {id : Owner key}
        {--calendar= : Calendar key (default: opening-hours.default_calendar)}
        {--date= : First date, Y-m-d (default: today)}
        {--days=7 : Number of days}
        {--at= : ISO instant to evaluate the status at}';

    protected $description = 'Show the opening hours and current status of an owner';

    public function handle(OpeningHoursManager $manager): int
    {
        try {
            $owner = $this->owner();
        } catch (InvalidOwnerException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $calendar = $this->option('calendar');
        $hours = $manager->for($owner, is_string($calendar) && $calendar !== '' ? $calendar : null);
        $at = is_string($this->option('at')) ? CarbonImmutable::parse($this->option('at')) : Clock::now();
        $at = $hours->instant($at->getTimestamp());

        if ($hours->isOpenAt($at)) {
            $close = $hours->nextClose($at);
            $this->info('Open'.($close === null ? '' : ' until '.$close->format('Y-m-d H:i')).' ('.$hours->timezone()->getName().')');
        } else {
            $open = $hours->nextOpen($at);
            $this->warn('Closed'.($open === null ? '' : ', opens '.$open->format('Y-m-d H:i')).' ('.$hours->timezone()->getName().')');
        }

        $date = is_string($this->option('date')) ? $this->option('date') : $hours->localDate($at);
        $start = $hours->localDate($date);
        $days = max(1, min((int) $this->option('days'), Settings::maxQueryDays()));

        $this->table(
            ['Date', 'Weekday', 'Source', 'Label', 'Hours'],
            array_map(static fn (DayHours $day): array => [
                $day->date?->toDateString(),
                $day->weekday->translated(),
                $day->source->value,
                (string) $day->label,
                $day->toString(),
            ], $hours->forPeriod($start, $start->addDays($days - 1))),
        );

        return self::SUCCESS;
    }

    /**
     * @return Model&OpeningHoursOwner
     */
    private function owner(): Model
    {
        $name = self::text($this->argument('owner'));
        $id = self::text($this->argument('id'));
        $class = Relation::getMorphedModel($name) ?? $name;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class) || ! is_subclass_of($class, OpeningHoursOwner::class)) {
            throw InvalidOwnerException::notAnOwner($name);
        }

        $owner = $class::query()->whereKey($id)->first();

        if ($owner === null) {
            throw InvalidOwnerException::notFound($name, $id);
        }

        return $owner;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}

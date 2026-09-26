<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours;

use Illuminate\Contracts\Events\Dispatcher;
use RoundlyConsulting\OpeningHours\Cache\DefinitionCache;
use RoundlyConsulting\OpeningHours\Commands\MaterializeIntervalsCommand;
use RoundlyConsulting\OpeningHours\Commands\PruneOpeningHoursCommand;
use RoundlyConsulting\OpeningHours\Commands\ShowOpeningHoursCommand;
use RoundlyConsulting\OpeningHours\Events\OpeningHoursDeleted;
use RoundlyConsulting\OpeningHours\Events\OpeningHoursUpdated;
use RoundlyConsulting\OpeningHours\Facades\OpeningHours as OpeningHoursFacade;
use RoundlyConsulting\OpeningHours\Listeners\QueueIntervalMaterialization;
use RoundlyConsulting\OpeningHours\Support\CalendarModel;
use RoundlyConsulting\OpeningHours\Support\ExceptionRuleModel;
use RoundlyConsulting\OpeningHours\Support\Materialize;
use RoundlyConsulting\OpeningHours\Support\ScheduleModel;
use RoundlyConsulting\OpeningHours\Support\Settings;
use RoundlyConsulting\PackageToolkit\Concerns\RegistersBlueprintMacros;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;
use RoundlyConsulting\PackageToolkit\Package;
use RoundlyConsulting\PackageToolkit\PackageServiceProvider;

final class OpeningHoursServiceProvider extends PackageServiceProvider
{
    use RegistersBlueprintMacros;

    public function configurePackage(Package $package): void
    {
        $package
            ->name('opening-hours')
            ->hasConfigFile()
            ->hasMigrations()
            ->hasTranslations()
            ->hasCommands([
                ShowOpeningHoursCommand::class,
                PruneOpeningHoursCommand::class,
                MaterializeIntervalsCommand::class,
            ])
            ->hasFacadeAlias(OpeningHoursFacade::class, 'opening-hours.facade_alias')
            ->contributesToAbout(static fn (): array => self::about());
    }

    public function register(): void
    {
        parent::register();

        $this->app->scoped(OpeningHoursManager::class);
        $this->app->singleton(DefinitionCache::class);
    }

    public function boot(): void
    {
        parent::boot();

        // morphKey() must exist before a host runs `migrate` on the published migrations.
        $this->registerBlueprintMacros();

        $events = $this->app->make(Dispatcher::class);
        $forget = function (OpeningHoursUpdated|OpeningHoursDeleted $event): void {
            if ($this->app->resolved(OpeningHoursManager::class)) {
                $this->app->make(OpeningHoursManager::class)->forgetCalendar($event->calendarId);
            }
        };

        $events->listen(OpeningHoursUpdated::class, $forget);
        $events->listen(OpeningHoursDeleted::class, $forget);
        $events->listen(OpeningHoursUpdated::class, QueueIntervalMaterialization::class);
    }

    /**
     * Presence and shape only — never the cache store's name or host class namespaces.
     *
     * @return array<string, string>
     */
    private static function about(): array
    {
        $timezone = config('opening-hours.timezone');
        $store = config('opening-hours.cache.store');
        $alias = config('opening-hours.facade_alias');

        return [
            'Calendar model' => class_basename(CalendarModel::class()),
            'Schedule model' => class_basename(ScheduleModel::class()),
            'Exception rule model' => class_basename(ExceptionRuleModel::class()),
            'Key type' => KeyType::fromConfig('opening-hours.key_type')->value,
            'Default calendar' => Settings::defaultCalendar(),
            'Default timezone' => is_string($timezone) && $timezone !== '' ? $timezone : 'app',
            'Search days' => (string) Settings::searchDays(),
            'Cache' => app(DefinitionCache::class)->enabled() ? 'ON' : 'OFF',
            'Cache store' => is_string($store) && $store !== '' ? 'custom' : 'default',
            'Materialize' => Materialize::enabled() ? 'ON' : 'OFF',
            'Facade alias' => is_string($alias) && $alias !== '' ? $alias : 'DISABLED',
        ];
    }
}

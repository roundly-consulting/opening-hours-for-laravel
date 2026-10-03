<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use RoundlyConsulting\OpeningHours\Cache\DefinitionCache;
use RoundlyConsulting\OpeningHours\Commands\MaterializeIntervalsCommand;
use RoundlyConsulting\OpeningHours\Commands\PruneOpeningHoursCommand;
use RoundlyConsulting\OpeningHours\Commands\ShowOpeningHoursCommand;
use RoundlyConsulting\OpeningHours\Events\OpeningHoursDeleted;
use RoundlyConsulting\OpeningHours\Events\OpeningHoursUpdated;
use RoundlyConsulting\OpeningHours\Exceptions\InvalidTimezoneException;
use RoundlyConsulting\OpeningHours\Facades\OpeningHours as OpeningHoursFacade;
use RoundlyConsulting\OpeningHours\Listeners\QueueIntervalMaterialization;
use RoundlyConsulting\OpeningHours\Support\CalendarModel;
use RoundlyConsulting\OpeningHours\Support\ExceptionRuleModel;
use RoundlyConsulting\OpeningHours\Support\Materialize;
use RoundlyConsulting\OpeningHours\Support\ScheduleModel;
use RoundlyConsulting\OpeningHours\Support\Settings;
use RoundlyConsulting\OpeningHours\Support\TimezoneResolver;
use RoundlyConsulting\PackageToolkit\Concerns\RegistersBlueprintMacros;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\PackageToolkit\Package;
use RoundlyConsulting\PackageToolkit\PackageServiceProvider;
use RoundlyConsulting\PackageToolkit\Support\Config;

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
        return [
            'Calendar model' => class_basename(CalendarModel::class()),
            'Schedule model' => class_basename(ScheduleModel::class()),
            'Exception rule model' => class_basename(ExceptionRuleModel::class()),
            'Key type' => self::orInvalid(static fn (): string => KeyType::fromConfig('opening-hours.key_type')->value),
            'Default calendar' => self::orInvalid(static fn (): string => Settings::defaultCalendar()),
            'Default timezone' => self::orInvalid(static fn (): string => Settings::isUnset('opening-hours.timezone')
                ? 'app'
                : TimezoneResolver::validate(TimezoneResolver::fallback())->getName()),
            'Search days' => self::orInvalid(static fn (): string => (string) Settings::searchDays()),
            'Cache' => self::orInvalid(static fn (): string => app(DefinitionCache::class)->enabled() ? 'ON' : 'OFF'),
            'Cache store' => self::orInvalid(static fn (): string => Settings::cacheStore() === null ? 'default' : 'custom'),
            'Materialize' => self::orInvalid(static fn (): string => Materialize::enabled() ? 'ON' : 'OFF'),
            'Facade alias' => self::aliasName(),
        ];
    }

    /**
     * The alias the toolkit registers for `opening-hours.facade_alias`: null or a false
     * spelling skips it, a true spelling keeps the declared `OpeningHours`, any other string
     * renames it.
     */
    private static function aliasName(): string
    {
        $alias = config('opening-hours.facade_alias', true);

        if (is_string($alias) && filter_var($alias, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) === null) {
            return $alias;
        }

        return self::orInvalid(static fn (): string => $alias !== null && Config::boolean('opening-hours.facade_alias', true) ? 'OpeningHours' : 'DISABLED');
    }

    /**
     * The value a strict read produces, or INVALID when the host's config is malformed:
     * `php artisan about` keeps rendering on a broken host, while the real read path throws.
     *
     * @param  Closure(): string  $read
     */
    private static function orInvalid(Closure $read): string
    {
        try {
            return $read();
        } catch (InvalidConfigurationException|InvalidTimezoneException) {
            return 'INVALID';
        }
    }
}

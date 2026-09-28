<?php

declare(strict_types=1);

use RoundlyConsulting\OpeningHours\Models\Calendar;
use RoundlyConsulting\OpeningHours\Models\ExceptionRule;
use RoundlyConsulting\OpeningHours\Models\Schedule;
use RoundlyConsulting\OpeningHours\OpeningHoursManager;
use RoundlyConsulting\Testing\Arch\ArchPresets;

ArchPresets::strictTypes('RoundlyConsulting\OpeningHours');

/**
 * The three swappable models are the deliberate extension points (pinned below),
 * and the facade root is extended by `OpeningHoursFake` (`toBeFakeable()` pins
 * that the fake subtypes it). Pest matches exemptions by PREFIX, so `Schedule::class` also silences
 * `ScheduleRange` — the auto-registered shadow check re-asserts it is final.
 * The abstract `OpeningHoursException` base needs no exemption: the preset
 * skips abstract classes.
 */
ArchPresets::finalByDefault('RoundlyConsulting\OpeningHours', [
    Calendar::class,
    Schedule::class,
    ExceptionRule::class,
    OpeningHoursManager::class,
]);

ArchPresets::swappableModelsAreNotFinal([
    Calendar::class => 'opening-hours.models.calendar',
    Schedule::class => 'opening-hours.models.schedule',
    ExceptionRule::class => 'opening-hours.models.exception_rule',
]);

/** No hash()-built cache keys, no random jitter — cache keys are integers only. */
ArchPresets::noLocalCryptoPrimitives('RoundlyConsulting\OpeningHours');

ArchPresets::modelsResolveThroughSeam(__DIR__.'/../../src', 'Support', [
    'opening-hours.models.calendar',
    'opening-hours.models.schedule',
    'opening-hours.models.exception_rule',
]);

/** Owner morph columns (calendars, intervals) go through the toolkit's morphKey seam. */
ArchPresets::morphColumnsUseTheSeam(__DIR__.'/../../database/migrations');

ArchPresets::runtimeRequireIsWhitelisted(__DIR__.'/../../composer.json');

ArchPresets::noDebuggingLeftovers();

/** The owner trait and the models' revision hooks reach behaviour through the manager. */
ArchPresets::modelsGoThroughTheFacade('RoundlyConsulting\OpeningHours');

arch('the engine is pure: no database access')
    ->expect('RoundlyConsulting\OpeningHours\Engine')
    ->not->toUse('Illuminate\Database');

/**
 * One clock seam: every "now" goes through Support\Clock, so Carbon::setTestNow
 * and travelTo() drive the whole package deterministically.
 */
it('reads now only through the clock seam', function (): void {
    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../src'));

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php' || str_ends_with($file->getPathname(), 'Support/Clock.php')) {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (preg_match('/(Date|Carbon|CarbonImmutable)::now\(|(?<![\w>:$])(?<!function )now\(\)/', $source) === 1) {
            $offenders[] = basename($file->getPathname());
        }
    }

    expect($offenders)->toBe([])
        ->and(file_get_contents(__DIR__.'/../../src/Support/Clock.php'))->toContain('Date::now()');
});

<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use RoundlyConsulting\OpeningHours\Actions\AddOpenDaysAction;
use RoundlyConsulting\OpeningHours\Actions\AddOpenTimeAction;
use RoundlyConsulting\OpeningHours\Exceptions\OpenTimeNotFoundException;
use RoundlyConsulting\OpeningHours\Facades\OpeningHours;
use RoundlyConsulting\OpeningHours\OpeningHours as OpeningHoursQuery;
use RoundlyConsulting\OpeningHours\OpeningHoursManager;
use RoundlyConsulting\OpeningHours\Tests\Fixtures\Clinic;

function office(): Clinic
{
    $office = Clinic::query()->create(['timezone' => 'Europe/Bratislava']);
    $weekdays = array_fill_keys(['monday', 'tuesday', 'wednesday', 'thursday', 'friday'], ['09:00-17:00']);

    OpeningHours::sync($office, ['week' => $weekdays, 'exceptions' => [['date' => '2026-11-09']]]);   // a holiday Monday
    OpeningHours::sync($office, ['week' => ['saturday' => ['10:00-14:00']]], 'weekend');

    return $office;
}

it('adds open days and open time through the facade', function (): void {
    $office = office();
    $friday = at('2026-11-06 10:00');

    expect(OpeningHours::addOpenDays($office, $friday, 2)->format('D Y-m-d H:i'))->toBe('Wed 2026-11-11 10:00')
        ->and(OpeningHours::addOpenTime($office, at('2026-11-06 16:00'), CarbonInterval::hours(2))->format('D Y-m-d H:i'))->toBe('Tue 2026-11-10 10:00')
        ->and(OpeningHours::addOpenDays($office, $friday, 1, 'weekend')->format('D Y-m-d H:i'))->toBe('Sat 2026-11-07 10:00')
        ->and(OpeningHours::addOpenTime($office, $friday, CarbonInterval::hours(5), 'weekend')->format('D Y-m-d H:i'))->toBe('Sat 2026-11-14 11:00');
});

it('answers through the model-scoped handle and the owner trait', function (): void {
    $office = office();
    $friday = at('2026-11-06 10:00');

    expect(OpeningHours::for($office)->addOpenDays($friday, 2)->format('D H:i'))->toBe('Wed 10:00')
        ->and($office->openingHours()->addOpenTime($friday, CarbonInterval::hours(8))->format('D H:i'))->toBe('Tue 10:00')
        ->and($office->addOpenDays($friday, 2)->format('D H:i'))->toBe('Wed 10:00')
        ->and($office->addOpenTime($friday, CarbonInterval::hours(8))->format('D H:i'))->toBe('Tue 10:00')
        ->and($office->addOpenDays($friday, 1, 'weekend')->format('D H:i'))->toBe('Sat 10:00')
        ->and($office->addOpenTime($friday, CarbonInterval::hour(), 'weekend')->format('D H:i'))->toBe('Sat 11:00');
});

it('answers through the injected manager and the raw actions', function (): void {
    $office = office();
    $friday = at('2026-11-06 10:00');
    $manager = app(OpeningHoursManager::class);
    $hours = $manager->for($office);

    expect($manager->addOpenDays($office, $friday, 2)->format('D H:i'))->toBe('Wed 10:00')
        ->and($manager->addOpenTime($office, $friday, CarbonInterval::hours(8))->format('D H:i'))->toBe('Tue 10:00')
        ->and(app(AddOpenDaysAction::class)->execute($hours, $friday, 2)->format('D H:i'))->toBe('Wed 10:00')
        ->and(app(AddOpenTimeAction::class)->execute($hours, $friday, CarbonInterval::hours(8))->format('D H:i'))->toBe('Tue 10:00');
});

it('lets a host rebind the actions behind every entry point', function (): void {
    $office = office();
    $friday = at('2026-11-06 10:00');
    $fixed = CarbonImmutable::parse('2030-01-01 00:00', 'UTC');

    app()->bind(AddOpenDaysAction::class, fn () => new class($fixed)
    {
        public function __construct(private CarbonImmutable $fixed) {}

        public function execute(OpeningHoursQuery $hours, DateTimeInterface $from, int $days): CarbonImmutable
        {
            return $this->fixed;
        }
    });

    expect(OpeningHours::addOpenDays($office, $friday, 2)->equalTo($fixed))->toBeTrue()
        ->and(OpeningHours::for($office)->addOpenDays($friday, 2)->equalTo($fixed))->toBeTrue()
        ->and($office->addOpenDays($friday, 2)->equalTo($fixed))->toBeTrue();
});

it('treats an owner without a calendar as never open', function (): void {
    $bare = Clinic::query()->create(['timezone' => 'UTC']);

    expect(fn () => OpeningHours::addOpenDays($bare, at('2026-11-06 10:00'), 1))->toThrow(OpenTimeNotFoundException::class)
        ->and(fn () => $bare->addOpenTime(at('2026-11-06 10:00'), CarbonInterval::hour()))->toThrow(OpenTimeNotFoundException::class);
});

it('still answers under the fake and records nothing', function (): void {
    $office = office();
    $fake = OpeningHours::fake();
    $friday = at('2026-11-06 10:00');

    expect(OpeningHours::addOpenDays($office, $friday, 2)->format('D H:i'))->toBe('Wed 10:00')
        ->and($office->addOpenTime($friday, CarbonInterval::hours(8))->format('D H:i'))->toBe('Tue 10:00')
        ->and($fake->writes())->toBe([]);

    $fake->assertNothingWritten();
});

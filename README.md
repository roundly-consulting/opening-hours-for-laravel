<!-- roundly-hero:start -->
<p align="center">
  <a href="https://roundly-consulting.com/open-source/docs/opening-hours-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=opening-hours-for-laravel">
    <img src="https://raw.githubusercontent.com/roundly-consulting/opening-hours-for-laravel/main/art/hero.png" alt="Opening Hours for Laravel — Roundly open source" width="100%">
  </a>
</p>
<!-- roundly-hero:end -->

<!-- roundly-badges:start -->
<p align="center">
  <a href="https://packagist.org/packages/roundly-consulting/opening-hours-for-laravel"><img src="https://img.shields.io/packagist/v/roundly-consulting/opening-hours-for-laravel?style=flat-square&label=release" alt="Latest release"></a>
  <a href="https://github.com/roundly-consulting/opening-hours-for-laravel/actions/workflows/run-tests.yml"><img src="https://img.shields.io/github/actions/workflow/status/roundly-consulting/opening-hours-for-laravel/run-tests.yml?branch=main&style=flat-square&label=tests" alt="Tests"></a>
  <a href="https://github.com/roundly-consulting/opening-hours-for-laravel/actions/workflows/fix-php-code-style-issues.yml"><img src="https://img.shields.io/github/actions/workflow/status/roundly-consulting/opening-hours-for-laravel/fix-php-code-style-issues.yml?branch=main&style=flat-square&label=code%20style" alt="Code style"></a>
  <a href="https://donate.stripe.com/dRmeVe8FX5PF1Qd9pXcEw00"><img src="https://img.shields.io/badge/donate-support%20our%20open%20source-F24E29?style=flat-square&logo=stripe&logoColor=white" alt="Donate"></a>
  <a href="https://www.patreon.com/cw/roundly"><img src="https://img.shields.io/badge/patreon-become%20a%20patron-F96854?style=flat-square&logo=patreon&logoColor=white" alt="Patreon"></a>
  <a href="https://roundly-consulting.com/support-us?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=opening-hours-for-laravel#crypto"><img src="https://img.shields.io/badge/crypto-BTC%20%C2%B7%20ETH%20%C2%B7%20BNB%20%C2%B7%20SOL-F7931A?style=flat-square&logo=bitcoin&logoColor=white" alt="Crypto"></a>
</p>
<!-- roundly-badges:end -->

# Opening Hours for Laravel

Opening hours, seasonal schedules, holidays and bookable availability for any Eloquent model —
clinics, shops, venues, pickup points. Overnight ranges, yearly and Easter-based exceptions, one
explicit DST rule, and capacity-aware booking slots fed by any busy-period source.

## Installation

Requires PHP 8.4, Laravel 12 or 13, and SQLite, PostgreSQL or MySQL.

```bash
composer require roundly-consulting/opening-hours-for-laravel
php artisan vendor:publish --tag="opening-hours-migrations"
php artisan migrate
```

If the models that own opening hours use UUID or ULID keys, set `OPENING_HOURS_KEY_TYPE`
(`uuid` / `ulid`) **before** running the migrations.

## Usage

Make a model an owner:

```php
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\OpeningHours\Concerns\HasOpeningHours;
use RoundlyConsulting\OpeningHours\Contracts\OpeningHoursOwner;

final class Clinic extends Model implements OpeningHoursOwner
{
    use HasOpeningHours;
}
```

Give it hours and exceptions, then ask questions:

```php
use RoundlyConsulting\OpeningHours\Facades\OpeningHours;

OpeningHours::sync($clinic, [
    'timezone' => 'Europe/Bratislava',
    'week' => [
        'monday' => ['08:00-12:00', '13:00-17:00'],
        'friday' => ['08:00-15:00'],
        'saturday' => ['22:00-03:00'],                              // overnight: ends 03:00 on Sunday
    ],
    'exceptions' => [['date' => '12-25', 'label' => 'Christmas']],  // every year, closed
]);

OpeningHours::exceptions($clinic)->closed('2026-10-16', label: 'Staff training');

$hours = OpeningHours::for($clinic);
$hours->isOpen();                                // right now
$hours->nextClose();                             // ?CarbonImmutable, in the calendar's timezone
$hours->forDate('2026-10-12')->toString();       // "08:00–12:00, 13:00–17:00"
$hours->forDate('2026-10-16')->toString();       // "Closed"
```

Offer bookable slots around the appointments already in your own table:

```php
use RoundlyConsulting\OpeningHours\Availability\Providers\EloquentBusyPeriodProvider;

$busy = EloquentBusyPeriodProvider::for(Appointment::query())->columns(start: 'starts_at', end: 'ends_at');

$slots = $hours->availability()
    ->withBusyPeriods($busy)
    ->minNotice(minutes: 120)
    ->slots('2026-10-12', '2026-10-16')->duration(30)->get();   // free 30-minute slots
```

<!-- roundly-docs:start -->
## Documentation

The full documentation — configuration, every feature and its API, and testing — lives on our
website: **[roundly-consulting.com/open-source/docs/opening-hours-for-laravel](https://roundly-consulting.com/open-source/docs/opening-hours-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=opening-hours-for-laravel)**

Release notes are in [CHANGELOG.md](CHANGELOG.md). To contribute, see the
[contributing guide](https://github.com/roundly-consulting/.github/blob/main/CONTRIBUTING.md).
<!-- roundly-docs:end -->

<!-- roundly-support:start -->
## Support our work

This package is free and open source, built and maintained by
[Roundly Consulting](https://roundly-consulting.com/open-source?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=opening-hours-for-laravel).
If it saves you time, please consider supporting our open-source work — a one-time donation, a
monthly pledge on Patreon or a crypto donation helps fund maintenance, new features and new
packages.

<a href="https://donate.stripe.com/dRmeVe8FX5PF1Qd9pXcEw00"><img src="https://img.shields.io/badge/Donate-Support%20Roundly%20open%20source-F24E29?style=for-the-badge&logo=stripe&logoColor=white" alt="Donate to Roundly open source"></a>
<a href="https://www.patreon.com/cw/roundly"><img src="https://img.shields.io/badge/Patreon-Become%20a%20patron-F96854?style=for-the-badge&logo=patreon&logoColor=white" alt="Become a patron on Patreon"></a>
<a href="https://roundly-consulting.com/support-us?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=opening-hours-for-laravel#crypto"><img src="https://img.shields.io/badge/Crypto-BTC%20%C2%B7%20ETH%20%C2%B7%20BNB%20%C2%B7%20SOL-F7931A?style=for-the-badge&logo=bitcoin&logoColor=white" alt="Donate crypto: BTC, ETH, BNB or SOL"></a>
<!-- roundly-support:end -->

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).

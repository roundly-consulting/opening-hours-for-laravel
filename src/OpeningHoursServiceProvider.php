<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours;

use RoundlyConsulting\PackageToolkit\Package;
use RoundlyConsulting\PackageToolkit\PackageServiceProvider;

final class OpeningHoursServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('opening-hours')
            ->hasConfigFile()
            ->hasTranslations();
    }
}

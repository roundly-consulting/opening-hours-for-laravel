<?php

declare(strict_types=1);

it('ships exactly the config keys it reads', function (): void {
    expect(__DIR__.'/../../config/opening-hours.php')->toSatisfyConfigContract(__DIR__.'/../../src');
});

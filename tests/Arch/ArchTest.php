<?php

declare(strict_types=1);

use RoundlyConsulting\Testing\Arch\ArchPresets;

ArchPresets::strictTypes('RoundlyConsulting\OpeningHours');
ArchPresets::finalByDefault('RoundlyConsulting\OpeningHours');
ArchPresets::noLocalCryptoPrimitives('RoundlyConsulting\OpeningHours');
ArchPresets::runtimeRequireIsWhitelisted(__DIR__.'/../../composer.json');
ArchPresets::noDebuggingLeftovers();

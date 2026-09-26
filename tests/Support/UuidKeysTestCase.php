<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Tests\Support;

use RoundlyConsulting\OpeningHours\Tests\TestCase;

/**
 * `key_type = uuid` set before the providers boot and the migrations run.
 */
abstract class UuidKeysTestCase extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    protected function configBeforeBoot(): array
    {
        return array_merge(parent::configBeforeBoot(), ['opening-hours.key_type' => 'uuid']);
    }
}

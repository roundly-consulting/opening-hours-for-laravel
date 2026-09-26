<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\OpeningHours\Concerns\HasOpeningHours;
use RoundlyConsulting\OpeningHours\Contracts\DynamicExceptionProvider;
use RoundlyConsulting\OpeningHours\Contracts\OpeningHoursOwner;

/**
 * A host-owned owner with soft deletes, a timezone column and dynamic providers.
 *
 * @property int $id
 * @property string|null $name
 * @property string|null $timezone
 */
class Clinic extends Model implements OpeningHoursOwner
{
    use HasOpeningHours;
    use SoftDeletes;

    /** @var list<DynamicExceptionProvider> */
    public static array $providers = [];

    protected $table = 'test_owners';

    protected $guarded = [];

    public function openingHoursTimezone(): ?string
    {
        return $this->timezone;
    }

    /**
     * @return list<DynamicExceptionProvider>
     */
    public function openingHoursDynamicExceptions(): array
    {
        return self::$providers;
    }
}

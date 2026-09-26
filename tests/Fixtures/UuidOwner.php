<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\OpeningHours\Concerns\HasOpeningHours;
use RoundlyConsulting\OpeningHours\Contracts\OpeningHoursOwner;

class UuidOwner extends Model implements OpeningHoursOwner
{
    use HasOpeningHours;
    use HasUuids;

    protected $table = 'uuid_owners';

    protected $guarded = [];
}

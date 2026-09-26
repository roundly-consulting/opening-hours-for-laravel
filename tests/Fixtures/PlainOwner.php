<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\OpeningHours\Concerns\HasOpeningHours;
use RoundlyConsulting\OpeningHours\Contracts\OpeningHoursOwner;

/**
 * An owner without soft deletes: deleting it is permanent.
 */
class PlainOwner extends Model implements OpeningHoursOwner
{
    use HasOpeningHours;

    protected $table = 'plain_owners';

    protected $guarded = [];
}

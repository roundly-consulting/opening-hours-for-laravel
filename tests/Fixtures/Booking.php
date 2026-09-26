<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A host booking table — the package never knows this model.
 */
class Booking extends Model
{
    public $timestamps = false;

    protected $table = 'test_bookings';

    protected $guarded = [];
}

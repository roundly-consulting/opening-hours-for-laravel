<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class NotAnOwner extends Model
{
    protected $table = 'plain_owners';
}

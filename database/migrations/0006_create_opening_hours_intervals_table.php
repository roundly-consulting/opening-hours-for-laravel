<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;

return new class extends Migration
{
    public function up(): void
    {
        $keyType = KeyType::fromConfig('opening-hours.key_type');

        Schema::create('opening_hours_intervals', function (Blueprint $table) use ($keyType): void {
            $table->id();
            $table->foreignId('calendar_id')->constrained('opening_hours_calendars')->cascadeOnDelete();
            // Denormalised so owner tables can correlate an EXISTS without a join.
            $table->morphKey('owner', $keyType);
            $table->string('calendar_key', 64);
            // UTC wall values; `dateTime`, not `timestamp`, so no session timezone conversion applies.
            $table->dateTime('opens_at');
            $table->dateTime('closes_at');
            $table->timestamp('created_at')->nullable();
            $table->unique(['calendar_id', 'opens_at'], 'oh_intervals_calendar_opens_unique');
            $table->index(['owner_type', 'owner_id', 'calendar_key', 'opens_at', 'closes_at'], 'oh_intervals_owner_window_idx');
            $table->index(['opens_at', 'closes_at'], 'oh_intervals_window_idx');
        });
    }
};

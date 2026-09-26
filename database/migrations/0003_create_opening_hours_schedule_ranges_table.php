<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opening_hours_schedule_ranges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('schedule_id')->constrained('opening_hours_schedules')->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->unsignedSmallInteger('start_minute');
            // 1..1440; at or before the start means the range runs overnight.
            $table->unsignedSmallInteger('end_minute');
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->string('label', 191)->nullable();
            $table->jsonb('meta')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->index(['schedule_id', 'weekday'], 'oh_schedule_ranges_schedule_weekday_idx');
        });
    }
};

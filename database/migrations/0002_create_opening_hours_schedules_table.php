<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opening_hours_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('calendar_id')->constrained('opening_hours_calendars')->cascadeOnDelete();
            $table->string('label', 191)->nullable();
            $table->smallInteger('priority')->default(0);
            $table->string('recurrence', 16)->default('none');
            // Yearly windows are stored in the canonical leap year 2000; the year is never read.
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->jsonb('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['calendar_id', 'priority'], 'oh_schedules_calendar_priority_idx');
        });
    }
};

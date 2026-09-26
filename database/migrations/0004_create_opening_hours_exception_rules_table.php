<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opening_hours_exception_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('calendar_id')->constrained('opening_hours_calendars')->cascadeOnDelete();
            $table->string('recurrence', 16)->default('none');
            // Yearly rules use the canonical leap year 2000; a yearly end may precede its start (year wrap).
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('label', 191)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->jsonb('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['calendar_id', 'starts_on'], 'oh_exception_rules_calendar_start_idx');
            $table->index(['recurrence', 'ends_on'], 'oh_exception_rules_prune_idx');
        });
    }
};

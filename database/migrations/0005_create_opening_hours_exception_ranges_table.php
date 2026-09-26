<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opening_hours_exception_ranges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exception_rule_id')->constrained('opening_hours_exception_rules')->cascadeOnDelete();
            $table->unsignedSmallInteger('start_minute');
            $table->unsignedSmallInteger('end_minute');
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->string('label', 191)->nullable();
            $table->jsonb('meta')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->index('exception_rule_id', 'oh_exception_ranges_rule_idx');
        });
    }
};

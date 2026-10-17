<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Host-owned owner tables (not shipped): owners are polymorphic, so their tables are the
 * host's business.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_owners', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('timezone')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('plain_owners', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });

        Schema::create('test_bookings', function (Blueprint $table): void {
            $table->id();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->unsignedSmallInteger('seats')->nullable();
            $table->string('status')->default('confirmed');
        });

        Schema::create('uuid_owners', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->timestamps();
        });
    }
};

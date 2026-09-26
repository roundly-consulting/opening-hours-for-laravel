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

        Schema::create('opening_hours_calendars', function (Blueprint $table) use ($keyType): void {
            $table->id();
            $table->morphKey('owner', $keyType);
            $table->string('key', 64)->default('default');
            $table->string('label', 191)->nullable();
            // IANA identifier; null defers to the owner hook, then config, then the app timezone.
            $table->string('timezone', 64)->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->jsonb('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['owner_type', 'owner_id', 'key'], 'opening_hours_calendars_owner_key_unique');
        });
    }
};

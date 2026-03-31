<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('invalid_events', function (Blueprint $table) {
            $table->id();
            $table->string('failure_type', 32); // invalid | failed
            $table->string('event_id', 64)->nullable()->index();
            $table->string('station_id', 64)->nullable()->index();
            $table->json('payload');
            $table->json('error')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invalid_events');
    }
};

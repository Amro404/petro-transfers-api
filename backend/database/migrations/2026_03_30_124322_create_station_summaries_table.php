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
        Schema::create('station_summaries', function (Blueprint $table) {
            $table->id();
            $table->string('station_id', 64)->unique();
            $table->unsignedBigInteger('events_count')->default(0);
            $table->decimal('total_approved_amount', 14, 2)->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('station_summaries');
    }
};

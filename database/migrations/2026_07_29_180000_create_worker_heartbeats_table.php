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
        Schema::create('worker_heartbeats', function (Blueprint $table) {
            $table->id();
            $table->string('worker_id')->unique();
            $table->string('hostname')->nullable();
            $table->string('os_info')->nullable();
            $table->string('python_version')->nullable();
            $table->timestamp('last_ping_at')->nullable();
            $table->json('payload')->nullable();
            $table->boolean('is_currently_offline')->default(false);
            $table->timestamp('last_outage_alert_at')->nullable();
            $table->string('current_outage_episode_id')->nullable();
            $table->timestamps();

            $table->index(['last_ping_at', 'is_currently_offline']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('worker_heartbeats');
    }
};
